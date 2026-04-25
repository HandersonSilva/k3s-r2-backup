#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backup manual do plano de controlo K3s (token + datastore SQLite em server/db/) para Cloudflare R2.
 *
 * Configuração alinhada ao disco `r2` do projeto database-backup (mesmas variáveis CLOUDFLARE_R2_*).
 *
 * --- Operação e consistência (SQLite / Kine) ---
 * Copiar `state.db` enquanto o K3s escreve pode gerar arquivo inconsistente. Antes do backup:
 *   sudo systemctl stop k3s
 *   php backup-k3s-to-r2.php
 *   sudo systemctl start k3s
 * Alternativa: snapshot de volume em repouso ou ferramenta de backup SQLite consistente.
 *
 * --- Permissões ---
 * Os caminhos predefinidos exigem leitura como root no nó de controlo:
 *   sudo cp .env.example .env   # edite com credenciais R2
 *   sudo php backup-k3s-to-r2.php
 *
 * --- Instalação (nesta pasta) ---
 *   composer install
 *
 * Isto faz backup apenas de metadados do cluster (API), não de PVCs, imagens nem workloads em disco.
 */

use Aws\Exception\MultipartUploadException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

require __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

/**
 * Lê variável de ambiente (ficheiro .env via $_ENV, ou export no shell via getenv).
 */
function envString(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_ENV)) {
        $v = $_ENV[$key];

        return is_string($v) ? $v : $default;
    }
    if (array_key_exists($key, $_SERVER)) {
        $v = $_SERVER[$key];

        return is_string($v) ? $v : $default;
    }
    $g = getenv($key);

    return ($g !== false && is_string($g)) ? $g : $default;
}

const MULTIPART_THRESHOLD_BYTES = 100 * 1024 * 1024;
const DEFAULT_TOKEN_PATH = '/var/lib/rancher/k3s/server/token';
const DEFAULT_DB_DIR = '/var/lib/rancher/k3s/server/db';

/**
 * @return array{0: string, 1: string}
 */
function resolvePaths(): array
{
    $token = envString('K3S_SERVER_TOKEN_PATH', DEFAULT_TOKEN_PATH);
    $dbDir = envString('K3S_SERVER_DB_DIR', DEFAULT_DB_DIR);

    return [$token, $dbDir];
}

/**
 * @return array{
 *     key: string,
 *     secret: string,
 *     region: string,
 *     bucket: string,
 *     endpoint: string,
 *     prefix: string
 * }
 */
function r2ConfigFromEnv(): array
{
    $key = envString('CLOUDFLARE_R2_ACCESS_KEY_ID');
    $secret = envString('CLOUDFLARE_R2_SECRET_ACCESS_KEY');
    $bucket = envString('CLOUDFLARE_R2_BUCKET');
    $endpoint = envString('CLOUDFLARE_R2_ENDPOINT');
    $prefix = envString('CLOUDFLARE_R2_PREFIX');
    $region = envString('CLOUDFLARE_R2_REGION', 'us-east-1');
    if (trim($region) === '') {
        $region = 'us-east-1';
    }

    return [
        'key' => $key,
        'secret' => $secret,
        'region' => $region,
        'bucket' => $bucket,
        'endpoint' => $endpoint,
        'prefix' => $prefix,
    ];
}

function requireNonEmpty(string $name, string $value): void
{
    if (trim($value) === '') {
        throw new RuntimeException("Variável de ambiente obrigatória em falta ou vazia: {$name}");
    }
}

/**
 * Normaliza o prefixo R2 (equivalente a `root` no Flysystem) para prefixo de chave S3.
 */
function normalizeKeyPrefix(string $prefix): string
{
    $p = trim($prefix);
    $p = trim($p, '/');

    return $p === '' ? '' : $p.'/';
}

/**
 * Sanitiza o hostname para usar em chaves de objeto S3.
 */
function safeHostSegment(): string
{
    $host = (string) gethostname();
    $host = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $host) ?? 'unknown-host';

    return $host === '' ? 'unknown-host' : $host;
}

/**
 * Cria arquivo tar.gz com token e diretório db.
 *
 * @return non-empty-string caminho do arquivo criado
 */
function createTarGzArchive(string $tokenPath, string $dbDir): string
{
    if (! is_readable($tokenPath) || ! is_file($tokenPath)) {
        throw new RuntimeException("Token não encontrado ou sem leitura: {$tokenPath}");
    }
    if (! is_readable($dbDir) || ! is_dir($dbDir)) {
        throw new RuntimeException("Diretório db não encontrado ou sem leitura: {$dbDir}");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'k3s-r2-');
    if ($tmp === false) {
        throw new RuntimeException('Não foi possível criar ficheiro temporário em sys_get_temp_dir().');
    }
    unlink($tmp);
    $archivePath = $tmp.'.tar.gz';

    if ($tokenPath === DEFAULT_TOKEN_PATH && $dbDir === DEFAULT_DB_DIR) {
        $cmd = [
            'tar', 'czf', $archivePath,
            '-C', '/',
            'var/lib/rancher/k3s/server/token',
            'var/lib/rancher/k3s/server/db',
        ];
    } else {
        $cmd = ['tar', 'czf', $archivePath, $tokenPath, $dbDir];
    }

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, '/', null);
    if (! is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar processo tar.');
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
        $err = trim((string) $stderr);

        throw new RuntimeException('tar falhou (código '.$code.')'.($err !== '' ? ': '.$err : '.'));
    }

    if (! is_file($archivePath) || filesize($archivePath) === 0) {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
        throw new RuntimeException('Arquivo compactado vazio ou não criado.');
    }

    return $archivePath;
}

/**
 * @return non-empty-string
 */
function sha256HexOfFile(string $path): string
{
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException('Não foi possível calcular SHA-256 do arquivo.');
    }

    return $hash;
}

/**
 * @param  array<string, string>  $metadata
 */
function uploadToR2(
    S3Client $client,
    string $bucket,
    string $key,
    string $localPath,
    array $metadata,
    int $multipartThresholdBytes
): void {
    $size = filesize($localPath);
    if ($size === false) {
        throw new RuntimeException('Não foi possível obter o tamanho do arquivo.');
    }

    $putObjectArgs = [
        'Bucket' => $bucket,
        'Key' => $key,
        'ContentType' => 'application/gzip',
        'Metadata' => $metadata,
    ];

    if ($size >= $multipartThresholdBytes) {
        $uploader = new MultipartUploader($client, $localPath, [
            'bucket' => $bucket,
            'key' => $key,
            'params' => [
                'ContentType' => 'application/gzip',
                'Metadata' => $metadata,
            ],
        ]);
        try {
            $uploader->upload();
        } catch (MultipartUploadException $e) {
            throw new RuntimeException('Upload multipart falhou: '.$e->getMessage(), 0, $e);
        }
    } else {
        $client->putObject(array_merge($putObjectArgs, [
            'SourceFile' => $localPath,
        ]));
    }
}

function main(): void
{
    $cfg = r2ConfigFromEnv();
    requireNonEmpty('CLOUDFLARE_R2_ACCESS_KEY_ID', $cfg['key']);
    requireNonEmpty('CLOUDFLARE_R2_SECRET_ACCESS_KEY', $cfg['secret']);
    requireNonEmpty('CLOUDFLARE_R2_BUCKET', $cfg['bucket']);
    requireNonEmpty('CLOUDFLARE_R2_ENDPOINT', $cfg['endpoint']);

    [$tokenPath, $dbDir] = resolvePaths();

    $archivePath = createTarGzArchive($tokenPath, $dbDir);
    $sha256 = sha256HexOfFile($archivePath);

    $prefix = normalizeKeyPrefix($cfg['prefix']);
    $timestamp = gmdate('Y-m-d\THis\Z');
    $hostSeg = safeHostSegment();
    $objectKey = $prefix.'k3s-control-plane/'.$hostSeg.'/'.$timestamp.'/k3s-server-backup.tar.gz';

    $client = new S3Client([
        'version' => 'latest',
        'region' => $cfg['region'],
        'endpoint' => $cfg['endpoint'],
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => $cfg['key'],
            'secret' => $cfg['secret'],
        ],
    ]);

    try {
        uploadToR2(
            $client,
            $cfg['bucket'],
            $objectKey,
            $archivePath,
            ['sha256' => $sha256],
            MULTIPART_THRESHOLD_BYTES
        );
    } finally {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
    }

    fwrite(STDOUT, "Upload concluído.\n");
    fwrite(STDOUT, 'Object key: '.$objectKey."\n");
    fwrite(STDOUT, 'SHA-256: '.$sha256."\n");
}

try {
    main();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

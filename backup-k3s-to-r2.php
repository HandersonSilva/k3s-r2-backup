#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backup manual do plano de controlo K3s (token + datastore SQLite em server/db/) para Cloudflare R2,
 * e opcionalmente volumes local-path de namespaces configurados (omissão: portainer, argocd).
 *
 * Configuração alinhada ao disco `r2` do projeto database-backup (mesmas variáveis CLOUDFLARE_R2_*).
 *
 * --- Operação e consistência (SQLite / Kine / volumes) ---
 * Copiar `state.db` ou PVCs enquanto o K3s escreve pode gerar arquivo inconsistente. Antes do backup:
 *   sudo systemctl stop k3s
 *   php backup-k3s-to-r2.php
 *   sudo systemctl start k3s
 * Alternativa: snapshot de volume em repouso ou ferramenta de backup SQLite consistente.
 *
 * --- Volumes local-path ---
 * K3S_BACKUP_STORAGE_NAMESPACES (omissão: portainer,argocd; vazio = não incluir storage)
 * K3S_STORAGE_DIR (omissão: /var/lib/rancher/k3s/storage)
 * Inclui dirs cujo nome casa com pvc-<uuid>_<namespace>_<claim> (segmento _<namespace>_).
 *
 * --- Permissões ---
 * Os caminhos predefinidos exigem leitura como root no nó de controlo:
 *   sudo cp .env.example .env   # edite com credenciais R2
 *   sudo php backup-k3s-to-r2.php
 *
 * --- Instalação (nesta pasta) ---
 *   composer install
 *
 * Inclui metadados do cluster (API) e, por omissão, dados em disco dos PVCs portainer/argocd.
 * Não inclui imagens de registo nem PVCs de outros namespaces (salvo configuração).
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
const DEFAULT_STORAGE_DIR = '/var/lib/rancher/k3s/storage';
const DEFAULT_STORAGE_NAMESPACES = 'portainer,argocd';

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
 * Namespaces cujos volumes local-path entram no backup.
 * Omissão: portainer,argocd. String vazia (após trim) = nenhum storage.
 *
 * @return list<string>
 */
function resolveStorageNamespaces(): array
{
    // Distinguir "não definido" (usa default) de "definido vazio" (desliga storage).
    if (array_key_exists('K3S_BACKUP_STORAGE_NAMESPACES', $_ENV)) {
        $v = $_ENV['K3S_BACKUP_STORAGE_NAMESPACES'];
        $raw = is_string($v) ? $v : '';
    } elseif (array_key_exists('K3S_BACKUP_STORAGE_NAMESPACES', $_SERVER)) {
        $v = $_SERVER['K3S_BACKUP_STORAGE_NAMESPACES'];
        $raw = is_string($v) ? $v : '';
    } else {
        $g = getenv('K3S_BACKUP_STORAGE_NAMESPACES');
        if ($g === false) {
            $raw = DEFAULT_STORAGE_NAMESPACES;
        } else {
            $raw = is_string($g) ? $g : '';
        }
    }

    $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
    $out = [];
    foreach ($parts as $ns) {
        $ns = trim($ns);
        if ($ns === '') {
            continue;
        }
        $out[] = $ns;
    }

    return array_values(array_unique($out));
}

function resolveStorageDir(): string
{
    $dir = envString('K3S_STORAGE_DIR', DEFAULT_STORAGE_DIR);

    return $dir !== '' ? $dir : DEFAULT_STORAGE_DIR;
}

/**
 * Directórios de 1.º nível em storage cujo nome contém _<namespace>_
 * (padrão local-path: pvc-<uuid>_<namespace>_<claim>).
 *
 * @param  list<string>  $namespaces
 * @return list<string> caminhos absolutos
 */
function findStoragePathsForNamespaces(string $storageDir, array $namespaces): array
{
    if ($namespaces === []) {
        return [];
    }
    if (! is_dir($storageDir)) {
        return [];
    }
    if (! is_readable($storageDir)) {
        throw new RuntimeException('Diretório de storage sem leitura: '.$storageDir);
    }

    $items = scandir($storageDir);
    if ($items === false) {
        throw new RuntimeException('Não foi possível listar: '.$storageDir);
    }

    $matched = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $storageDir.'/'.$item;
        if (! is_dir($full)) {
            continue;
        }
        foreach ($namespaces as $ns) {
            if (strpos($item, '_'.$ns.'_') !== false) {
                $matched[] = $full;
                break;
            }
        }
    }
    sort($matched);

    return $matched;
}

/**
 * Converte caminho absoluto sob / em path relativo para tar -C /.
 */
function absolutePathToTarRelative(string $absolutePath): string
{
    if ($absolutePath === '/') {
        throw new RuntimeException('Caminho inválido para tar: /');
    }
    if (stringStartsWith($absolutePath, '/')) {
        return ltrim($absolutePath, '/');
    }

    return $absolutePath;
}

function stringStartsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return substr($haystack, 0, strlen($needle)) === $needle;
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
 * Cria arquivo tar.gz com token, db e opcionalmente volumes local-path.
 *
 * @param  list<string>  $extraAbsolutePaths  caminhos absolutos adicionais (ex. dirs de storage)
 * @return non-empty-string caminho do arquivo criado
 */
function createTarGzArchive(string $tokenPath, string $dbDir, array $extraAbsolutePaths = []): string
{
    if (! is_readable($tokenPath) || ! is_file($tokenPath)) {
        throw new RuntimeException("Token não encontrado ou sem leitura: {$tokenPath}");
    }
    if (! is_readable($dbDir) || ! is_dir($dbDir)) {
        throw new RuntimeException("Diretório db não encontrado ou sem leitura: {$dbDir}");
    }
    foreach ($extraAbsolutePaths as $p) {
        if (! is_readable($p)) {
            throw new RuntimeException('Caminho extra sem leitura: '.$p);
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'k3s-r2-');
    if ($tmp === false) {
        throw new RuntimeException('Não foi possível criar ficheiro temporário em sys_get_temp_dir().');
    }
    unlink($tmp);
    $archivePath = $tmp.'.tar.gz';

    $useRelativeUnderRoot = ($tokenPath === DEFAULT_TOKEN_PATH && $dbDir === DEFAULT_DB_DIR);
    if ($useRelativeUnderRoot) {
        foreach ($extraAbsolutePaths as $p) {
            if (! stringStartsWith($p, '/')) {
                $useRelativeUnderRoot = false;
                break;
            }
        }
    }

    if ($useRelativeUnderRoot) {
        $cmd = [
            'tar', 'czf', $archivePath,
            '-C', '/',
            'var/lib/rancher/k3s/server/token',
            'var/lib/rancher/k3s/server/db',
        ];
        foreach ($extraAbsolutePaths as $p) {
            $cmd[] = absolutePathToTarRelative($p);
        }
    } else {
        $cmd = ['tar', 'czf', $archivePath, $tokenPath, $dbDir];
        foreach ($extraAbsolutePaths as $p) {
            $cmd[] = $p;
        }
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
    $storageNamespaces = resolveStorageNamespaces();
    $storageDir = resolveStorageDir();
    $storagePaths = findStoragePathsForNamespaces($storageDir, $storageNamespaces);

    if ($storageNamespaces !== []) {
        fwrite(STDOUT, 'Namespaces de storage: '.implode(', ', $storageNamespaces)."\n");
        fwrite(STDOUT, 'Volumes local-path incluídos: '.count($storagePaths)."\n");
        if ($storagePaths === []) {
            fwrite(STDERR, 'AVISO: nenhum diretório em '.$storageDir.' corresponde aos namespaces configurados.'."\n");
        } else {
            foreach ($storagePaths as $p) {
                fwrite(STDOUT, '  '.$p."\n");
            }
        }
    } else {
        fwrite(STDOUT, "Storage local-path: desligado (K3S_BACKUP_STORAGE_NAMESPACES vazio).\n");
    }

    $archivePath = createTarGzArchive($tokenPath, $dbDir, $storagePaths);
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

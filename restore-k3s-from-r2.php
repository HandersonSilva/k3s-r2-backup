#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Restaura o backup do plano de controlo K3s (token + server/db/) a partir de Cloudflare R2.
 *
 * Mesmas variáveis CLOUDFLARE_R2_* que backup-k3s-to-r2.php. Objeto: chave explícita (--key ou
 * K3S_RESTORE_OBJECT_KEY) ou o ficheiro k3s-server-backup.tar.gz mais recente (por LastModified)
 * sob o prefixo .../k3s-control-plane/.
 *
 * Fluxo recomendado no nó de controlo (root):
 *   sudo systemctl stop k3s
 *   sudo php restore-k3s-from-r2.php --yes
 *   sudo systemctl start k3s
 *
 * O script não inicia o K3s automaticamente. Sem --yes: em terminal interativo pede confirmação;
 * em modo não-interativo é obrigatório --yes.
 *
 * Instalação: composer install (na pasta do projeto).
 */

use Aws\S3\S3Client;

require __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

const BACKUP_OBJECT_SUFFIX = '/k3s-server-backup.tar.gz';

function stringStartsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return substr($haystack, 0, strlen($needle)) === $needle;
}

function stringEndsWith(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    return substr($haystack, -strlen($needle)) === $needle;
}

/**
 * @return array{key?: string, yes: bool}
 */
function parseCliArgs(array $argv): array
{
    $out = ['yes' => false];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--yes' || $arg === '-y') {
            $out['yes'] = true;

            continue;
        }
        if (stringStartsWith($arg, '--key=')) {
            $out['key'] = substr($arg, strlen('--key='));

            continue;
        }
        if ($arg === '--help' || $arg === '-h') {
            $usage = <<<'TXT'
Uso: php restore-k3s-from-r2.php [opções]

  --key=<chave-s3>   Objeto a restaurar (sobrepõe K3S_RESTORE_OBJECT_KEY).
  --yes, -y          Confirma restauro destrutivo (obrigatório se stdin não for TTY).
  -h, --help         Esta ajuda.

Sem --key: usa K3S_RESTORE_OBJECT_KEY ou o backup mais recente (LastModified) em .../k3s-control-plane/.
TXT;
            fwrite(STDOUT, $usage);

            exit(0);
        }
    }

    return $out;
}

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

function metadataSha256(?array $metadata): ?string
{
    if ($metadata === null || $metadata === []) {
        return null;
    }
    foreach (['sha256', 'SHA256', 'Sha256'] as $k) {
        if (isset($metadata[$k]) && is_string($metadata[$k]) && trim($metadata[$k]) !== '') {
            return strtolower(trim($metadata[$k]));
        }
    }

    return null;
}

/**
 * @return non-empty-string
 */
function findLatestBackupObjectKey(S3Client $client, string $bucket, string $listPrefix): string
{
    $bestKey = null;
    $bestTs = 0;
    $continuation = null;
    do {
        $args = [
            'Bucket' => $bucket,
            'Prefix' => $listPrefix,
        ];
        if ($continuation !== null) {
            $args['ContinuationToken'] = $continuation;
        }
        $result = $client->listObjectsV2($args);
        $contents = $result['Contents'] ?? null;
        if (is_array($contents)) {
            foreach ($contents as $obj) {
                $key = $obj['Key'] ?? null;
                if (! is_string($key) || ! stringEndsWith($key, BACKUP_OBJECT_SUFFIX)) {
                    continue;
                }
                $lm = $obj['LastModified'] ?? null;
                $ts = 0;
                if ($lm instanceof \DateTimeInterface) {
                    $ts = $lm->getTimestamp();
                }
                if ($ts >= $bestTs) {
                    $bestTs = $ts;
                    $bestKey = $key;
                }
            }
        }
        $continuation = ($result['IsTruncated'] ?? false) ? ($result['NextContinuationToken'] ?? null) : null;
    } while ($continuation !== null);

    if ($bestKey === null || $bestKey === '') {
        throw new RuntimeException(
            'Nenhum objeto terminado em '.BACKUP_OBJECT_SUFFIX.' encontrado com prefixo: '.$listPrefix
        );
    }

    return $bestKey;
}

function assertK3sInactive(): void
{
    $cmd = ['systemctl', 'is-active', 'k3s'];
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, '/', null);
    if (! is_resource($process)) {
        throw new RuntimeException('Não foi possível executar systemctl is-active k3s.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $state = strtolower(trim((string) $stdout));
    if ($state === 'active' || $state === 'activating') {
        throw new RuntimeException(
            'O serviço k3s parece ativo ('.$state.'). Pare antes de restaurar: sudo systemctl stop k3s'
        );
    }
}

function extractArchiveToRoot(string $archivePath): void
{
    $cmd = ['tar', 'xzf', $archivePath, '-C', '/'];
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, '/', null);
    if (! is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar processo tar para extração.');
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        $err = trim((string) $stderr);

        throw new RuntimeException('tar extrair falhou (código '.$code.')'.($err !== '' ? ': '.$err : '.'));
    }
}

function confirmDestructiveRestore(bool $yesFlag): void
{
    if ($yesFlag) {
        return;
    }
    if (function_exists('posix_isatty') && posix_isatty(STDIN)) {
        fwrite(STDOUT, 'Isto substitui server/token e server/db no disco. Continuar? [s/N] ');
        $line = fgets(STDIN);
        $answer = strtolower(trim((string) $line));
        if ($answer === 's' || $answer === 'sim' || $answer === 'y' || $answer === 'yes') {
            return;
        }
        throw new RuntimeException('Restauro cancelado.');
    }

    throw new RuntimeException(
        'Execução não-interativa: passe --yes (ou -y) após confirmar que o K3s está parado e que pretende sobrescrever os ficheiros.'
    );
}

function main(array $argv): void
{
    $cli = parseCliArgs($argv);

    $cfg = r2ConfigFromEnv();
    requireNonEmpty('CLOUDFLARE_R2_ACCESS_KEY_ID', $cfg['key']);
    requireNonEmpty('CLOUDFLARE_R2_SECRET_ACCESS_KEY', $cfg['secret']);
    requireNonEmpty('CLOUDFLARE_R2_BUCKET', $cfg['bucket']);
    requireNonEmpty('CLOUDFLARE_R2_ENDPOINT', $cfg['endpoint']);

    $objectKey = isset($cli['key']) ? trim((string) $cli['key']) : '';
    if ($objectKey === '') {
        $objectKey = trim(envString('K3S_RESTORE_OBJECT_KEY'));
    }

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

    if ($objectKey === '') {
        $listPrefix = normalizeKeyPrefix($cfg['prefix']).'k3s-control-plane/';
        $objectKey = findLatestBackupObjectKey($client, $cfg['bucket'], $listPrefix);
        fwrite(STDOUT, 'Objeto selecionado (mais recente por LastModified): '.$objectKey."\n");
    }

    $tmp = tempnam(sys_get_temp_dir(), 'k3s-r2-restore-');
    if ($tmp === false) {
        throw new RuntimeException('Não foi possível criar ficheiro temporário.');
    }
    unlink($tmp);
    $archivePath = $tmp.'.tar.gz';

    try {
        $result = $client->getObject([
            'Bucket' => $cfg['bucket'],
            'Key' => $objectKey,
            'SaveAs' => $archivePath,
        ]);
        $meta = $result->get('Metadata');
        $expectedSha = metadataSha256(is_array($meta) ? $meta : null);
        if ($expectedSha !== null) {
            $actual = hash_file('sha256', $archivePath);
            if ($actual === false) {
                throw new RuntimeException('Não foi possível calcular SHA-256 do arquivo descarregado.');
            }
            $actual = strtolower($actual);
            if ($actual !== $expectedSha) {
                throw new RuntimeException(
                    'SHA-256 do arquivo não coincide com o metadado do objeto. Esperado: '.$expectedSha.', obtido: '.$actual
                );
            }
            fwrite(STDOUT, "SHA-256 verificado (metadado do objeto).\n");
        }

        confirmDestructiveRestore($cli['yes']);
        assertK3sInactive();
        fwrite(STDOUT, "Destino de extração: / (raiz do sistema).\n");
        fwrite(STDOUT, "Isto repõe ficheiros em /var/lib/rancher/k3s/server/ conforme conteúdo do backup.\n");
        extractArchiveToRoot($archivePath);
    } finally {
        if (is_file($archivePath)) {
            unlink($archivePath);
        }
    }

    fwrite(STDOUT, "Restauro concluído.\n");
    fwrite(STDOUT, "Inicie o K3s quando estiver pronto: sudo systemctl start k3s\n");
}

try {
    main($argv);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

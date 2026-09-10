#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Backup manual do plano de controlo K3s (token + datastore SQLite em server/db/) para Cloudflare R2,
 * volumes local-path de namespaces configurados (omissão: portainer, argocd), e espelho de PVs NFS
 * para DR sem servidor NFS (omissão: activo).
 *
 * Configuração alinhada ao disco `r2` do projeto database-backup (mesmas variáveis CLOUDFLARE_R2_*).
 *
 * --- Operação e consistência ---
 * Com K3S_BACKUP_NFS_MIRROR activo (omissão):
 *   sudo php backup-k3s-to-r2.php
 *   # o script: kubectl get pv → systemctl stop k3s → mount NFS ro + rsync → tar → upload
 *   sudo systemctl start k3s
 * Sem mirror NFS: pare o K3s antes (como antes) e depois faça o backup.
 *
 * --- Volumes local-path ---
 * K3S_BACKUP_STORAGE_NAMESPACES (omissão: portainer,argocd; vazio = não incluir storage)
 * K3S_STORAGE_DIR (omissão: /var/lib/rancher/k3s/storage)
 *
 * --- Espelho NFS (DR) ---
 * K3S_BACKUP_NFS_MIRROR=1 (omissão) | 0 ou vazio = desliga
 * K3S_NFS_MIRROR_DIR (omissão: /var/lib/rancher/k3s/storage/nfs-mirror)
 * Requer nfs-common/mount.nfs e rede até aos exports. Falha de mount/cópia aborta o backup.
 *
 * --- Permissões ---
 *   sudo php backup-k3s-to-r2.php
 *
 * --- Instalação ---
 *   composer install
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
const DEFAULT_NFS_MIRROR_DIR = '/var/lib/rancher/k3s/storage/nfs-mirror';
const NFS_REWRITE_MANIFEST_PATH = '/var/lib/rancher/k3s/server/k3s-r2-nfs-rewrite.json';

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
 * K3S_BACKUP_NFS_MIRROR: omissão ligado. "0", "false", "no", "" (se definido) = desligado.
 */
function isNfsMirrorEnabled(): bool
{
    if (array_key_exists('K3S_BACKUP_NFS_MIRROR', $_ENV)) {
        $v = $_ENV['K3S_BACKUP_NFS_MIRROR'];
        $raw = is_string($v) ? $v : '';
    } elseif (array_key_exists('K3S_BACKUP_NFS_MIRROR', $_SERVER)) {
        $v = $_SERVER['K3S_BACKUP_NFS_MIRROR'];
        $raw = is_string($v) ? $v : '';
    } else {
        $g = getenv('K3S_BACKUP_NFS_MIRROR');
        if ($g === false) {
            return true;
        }
        $raw = is_string($g) ? $g : '';
    }
    $t = strtolower(trim($raw));

    return ! ($t === '' || $t === '0' || $t === 'false' || $t === 'no' || $t === 'off');
}

function resolveNfsMirrorDir(): string
{
    $dir = envString('K3S_NFS_MIRROR_DIR', DEFAULT_NFS_MIRROR_DIR);

    return $dir !== '' ? $dir : DEFAULT_NFS_MIRROR_DIR;
}

/**
 * @param  list<string>  $cmd
 * @return array{code: int, stdout: string, stderr: string}
 */
function runCommand(array $cmd, ?string $cwd = '/'): array
{
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptorspec, $pipes, $cwd, null);
    if (! is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar comando: '.implode(' ', $cmd));
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);

    return [
        'code' => $code,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function isK3sActive(): bool
{
    $r = runCommand(['systemctl', 'is-active', 'k3s']);
    $state = strtolower(trim($r['stdout']));

    return $state === 'active' || $state === 'activating';
}

function stopK3sService(): void
{
    fwrite(STDOUT, "A parar k3s (consistência SQLite + volumes NFS)...\n");
    $r = runCommand(['systemctl', 'stop', 'k3s']);
    if ($r['code'] !== 0) {
        throw new RuntimeException(
            'systemctl stop k3s falhou (código '.$r['code'].')'.
            ($r['stderr'] !== '' ? ': '.trim($r['stderr']) : '.')
        );
    }
    // Esperar a ficar inactivo
    for ($i = 0; $i < 60; $i++) {
        if (! isK3sActive()) {
            return;
        }
        usleep(500000);
    }
    throw new RuntimeException('k3s ainda activo após systemctl stop.');
}

/**
 * @return list<array{pvName: string, nfsServer: string, nfsPath: string, hostPath: string}>
 */
function discoverNfsPersistentVolumes(string $mirrorDir): array
{
    $r = runCommand(['k3s', 'kubectl', 'get', 'pv', '-o', 'json']);
    if ($r['code'] !== 0) {
        throw new RuntimeException(
            'k3s kubectl get pv falhou (K3s tem de estar a correr para descobrir PVs NFS): '.
            trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout'])
        );
    }
    $data = json_decode($r['stdout'], true);
    if (! is_array($data)) {
        throw new RuntimeException('Resposta JSON inválida de kubectl get pv.');
    }
    $items = $data['items'] ?? [];
    if (! is_array($items)) {
        return [];
    }

    $out = [];
    foreach ($items as $item) {
        if (! is_array($item)) {
            continue;
        }
        $spec = $item['spec'] ?? null;
        if (! is_array($spec) || ! isset($spec['nfs']) || ! is_array($spec['nfs'])) {
            continue;
        }
        $name = $item['metadata']['name'] ?? null;
        $server = $spec['nfs']['server'] ?? null;
        $path = $spec['nfs']['path'] ?? null;
        if (! is_string($name) || $name === '' || ! is_string($server) || $server === '' || ! is_string($path) || $path === '') {
            continue;
        }
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name) ?? $name;
        $out[] = [
            'pvName' => $name,
            'nfsServer' => $server,
            'nfsPath' => $path,
            'hostPath' => rtrim($mirrorDir, '/').'/'.$safe,
        ];
    }

    return $out;
}

/**
 * Remove conteúdo de um directório (mantém o próprio dir) ou cria-o.
 */
function ensureEmptyDir(string $dir): void
{
    if (! is_dir($dir)) {
        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar: '.$dir);
        }

        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        throw new RuntimeException('Não foi possível listar: '.$dir);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removePathRecursive($dir.DIRECTORY_SEPARATOR.$item);
    }
}

function removePathRecursive(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (! unlink($path)) {
            throw new RuntimeException('Não foi possível remover: '.$path);
        }

        return;
    }
    if (! is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        throw new RuntimeException('Não foi possível listar: '.$path);
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        removePathRecursive($path.DIRECTORY_SEPARATOR.$item);
    }
    if (! rmdir($path)) {
        throw new RuntimeException('Não foi possível remover directório: '.$path);
    }
}

/**
 * @param  array{pvName: string, nfsServer: string, nfsPath: string, hostPath: string}  $vol
 */
function mirrorOneNfsVolume(array $vol): void
{
    $mountSrc = $vol['nfsServer'].':'.$vol['nfsPath'];
    $tmpMount = sys_get_temp_dir().'/k3s-r2-nfs-'.getmypid().'-'.preg_replace('/[^a-zA-Z0-9._-]+/', '-', $vol['pvName']);
    if (! mkdir($tmpMount, 0755, true) && ! is_dir($tmpMount)) {
        throw new RuntimeException('Não foi possível criar mountpoint: '.$tmpMount);
    }
    ensureEmptyDir($vol['hostPath']);

    fwrite(STDOUT, '  NFS '.$mountSrc.' → '.$vol['hostPath']."\n");
    $mounted = false;
    try {
        $m = runCommand(['mount', '-t', 'nfs', '-o', 'ro,nolock,soft,timeo=30', $mountSrc, $tmpMount]);
        if ($m['code'] !== 0) {
            throw new RuntimeException(
                'mount NFS falhou para '.$mountSrc.': '.trim($m['stderr'] !== '' ? $m['stderr'] : $m['stdout'])
            );
        }
        $mounted = true;
        $rs = runCommand([
            'rsync', '-aHAX', '--delete',
            $tmpMount.'/',
            rtrim($vol['hostPath'], '/').'/',
        ]);
        if ($rs['code'] !== 0) {
            throw new RuntimeException(
                'rsync falhou para '.$vol['pvName'].': '.trim($rs['stderr'] !== '' ? $rs['stderr'] : $rs['stdout'])
            );
        }
    } finally {
        if ($mounted) {
            runCommand(['umount', '-f', $tmpMount]);
        }
        if (is_dir($tmpMount)) {
            @rmdir($tmpMount);
        }
    }
}

/**
 * @param  list<array{pvName: string, nfsServer: string, nfsPath: string, hostPath: string}>  $volumes
 */
function writeNfsRewriteManifest(string $path, array $volumes): void
{
    $dir = dirname($path);
    if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar: '.$dir);
    }
    $payload = [
        'version' => 1,
        'generatedAt' => gmdate('c'),
        'volumes' => $volumes,
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Falha ao serializar manifest NFS.');
    }
    if (file_put_contents($path, $json."\n") === false) {
        throw new RuntimeException('Não foi possível escrever manifest: '.$path);
    }
}

/**
 * @param  list<array{pvName: string, nfsServer: string, nfsPath: string, hostPath: string}>  $volumes
 * @return list<string> paths absolutos a incluir no tar (mirror dir + manifest)
 */
function runNfsMirrorBackup(string $mirrorDir): array
{
    fwrite(STDOUT, "Espelho NFS: activo. A descobrir PVs com spec.nfs...\n");
    if (! isK3sActive()) {
        throw new RuntimeException(
            'K3S_BACKUP_NFS_MIRROR exige K3s a correr para kubectl get pv. Inicie o K3s ou desligue o mirror (K3S_BACKUP_NFS_MIRROR=0).'
        );
    }
    $volumes = discoverNfsPersistentVolumes($mirrorDir);
    fwrite(STDOUT, 'PVs NFS encontrados: '.count($volumes)."\n");
    foreach ($volumes as $v) {
        fwrite(STDOUT, '  '.$v['pvName'].' ← '.$v['nfsServer'].':'.$v['nfsPath']."\n");
    }

    writeNfsRewriteManifest(NFS_REWRITE_MANIFEST_PATH, $volumes);
    stopK3sService();

    if ($volumes === []) {
        fwrite(STDOUT, "Nenhum PV NFS; manifest vazio gravado. A continuar com token/db/local-path.\n");
        ensureEmptyDir($mirrorDir);

        return [NFS_REWRITE_MANIFEST_PATH];
    }

    ensureEmptyDir($mirrorDir);
    foreach ($volumes as $vol) {
        mirrorOneNfsVolume($vol);
    }

    return [$mirrorDir, NFS_REWRITE_MANIFEST_PATH];
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
    $extraPaths = findStoragePathsForNamespaces($storageDir, $storageNamespaces);

    if ($storageNamespaces !== []) {
        fwrite(STDOUT, 'Namespaces de storage: '.implode(', ', $storageNamespaces)."\n");
        fwrite(STDOUT, 'Volumes local-path incluídos: '.count($extraPaths)."\n");
        if ($extraPaths === []) {
            fwrite(STDERR, 'AVISO: nenhum diretório em '.$storageDir.' corresponde aos namespaces configurados.'."\n");
        } else {
            foreach ($extraPaths as $p) {
                fwrite(STDOUT, '  '.$p."\n");
            }
        }
    } else {
        fwrite(STDOUT, "Storage local-path: desligado (K3S_BACKUP_STORAGE_NAMESPACES vazio).\n");
    }

    if (isNfsMirrorEnabled()) {
        $nfsPaths = runNfsMirrorBackup(resolveNfsMirrorDir());
        foreach ($nfsPaths as $p) {
            if (! in_array($p, $extraPaths, true)) {
                $extraPaths[] = $p;
            }
        }
    } else {
        fwrite(STDOUT, "Espelho NFS: desligado (K3S_BACKUP_NFS_MIRROR).\n");
    }

    $archivePath = createTarGzArchive($tokenPath, $dbDir, $extraPaths);
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
    fwrite(STDOUT, "Inicie o K3s quando estiver pronto: sudo systemctl start k3s\n");
}

try {
    main();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

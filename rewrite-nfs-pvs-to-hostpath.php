#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Após restore DR sem NFS: reescreve PVs com spec.nfs para hostPath apontando ao espelho
 * criado em backup (K3S_NFS_MIRROR_DIR / nfs-mirror/<pv>).
 *
 * Pré-requisito: K3s a correr; manifest e dados já extraídos pelo restore.
 *
 *   sudo systemctl start k3s
 *   sudo php rewrite-nfs-pvs-to-hostpath.php
 */

require __DIR__.'/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

const DEFAULT_MANIFEST_PATH = '/var/lib/rancher/k3s/server/k3s-r2-nfs-rewrite.json';
const DEFAULT_NFS_MIRROR_DIR = '/var/lib/rancher/k3s/storage/nfs-mirror';

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

/**
 * @param  list<string>  $cmd
 * @return array{code: int, stdout: string, stderr: string}
 */
function runCommandWithStdin(array $cmd, string $stdin, ?string $cwd = '/'): array
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
    fwrite($pipes[0], $stdin);
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

/**
 * @return list<array{pvName: string, nfsServer: string, nfsPath: string, hostPath: string}>
 */
function loadManifest(string $path): array
{
    if (! is_readable($path)) {
        throw new RuntimeException(
            'Manifest NFS não encontrado: '.$path.
            ' (faça restore de um backup com espelho NFS, ou crie o ficheiro).'
        );
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Não foi possível ler: '.$path);
    }
    $data = json_decode($raw, true);
    if (! is_array($data)) {
        throw new RuntimeException('Manifest JSON inválido: '.$path);
    }
    $volumes = $data['volumes'] ?? [];
    if (! is_array($volumes)) {
        return [];
    }
    $out = [];
    foreach ($volumes as $v) {
        if (! is_array($v)) {
            continue;
        }
        $pvName = $v['pvName'] ?? null;
        $hostPath = $v['hostPath'] ?? null;
        if (! is_string($pvName) || $pvName === '' || ! is_string($hostPath) || $hostPath === '') {
            continue;
        }
        $out[] = [
            'pvName' => $pvName,
            'nfsServer' => is_string($v['nfsServer'] ?? null) ? $v['nfsServer'] : '',
            'nfsPath' => is_string($v['nfsPath'] ?? null) ? $v['nfsPath'] : '',
            'hostPath' => $hostPath,
        ];
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function getPersistentVolume(string $name): array
{
    $r = runCommand(['k3s', 'kubectl', 'get', 'pv', $name, '-o', 'json']);
    if ($r['code'] !== 0) {
        throw new RuntimeException(
            'kubectl get pv '.$name.' falhou: '.trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout'])
        );
    }
    $data = json_decode($r['stdout'], true);
    if (! is_array($data)) {
        throw new RuntimeException('JSON inválido para PV '.$name);
    }

    return $data;
}

/**
 * @param  array<string, mixed>  $pv
 * @return array<string, mixed>
 */
function rewritePvSpecToHostPath(array $pv, string $hostPath): array
{
    if (! isset($pv['spec']) || ! is_array($pv['spec'])) {
        throw new RuntimeException('PV sem spec.');
    }
    unset($pv['spec']['nfs']);
    $pv['spec']['hostPath'] = [
        'path' => $hostPath,
        'type' => 'DirectoryOrCreate',
    ];
    // Campos geridos pelo API — limpar para replace
    unset($pv['metadata']['resourceVersion'], $pv['metadata']['uid'], $pv['metadata']['creationTimestamp']);
    unset($pv['metadata']['managedFields'], $pv['status']);
    if (isset($pv['metadata']['annotations']) && is_array($pv['metadata']['annotations'])) {
        unset(
            $pv['metadata']['annotations']['kubectl.kubernetes.io/last-applied-configuration'],
            $pv['metadata']['annotations']['pv.kubernetes.io/bound-by-controller']
        );
    }

    return $pv;
}

/**
 * @param  array<string, mixed>  $pv
 */
function replacePersistentVolume(array $pv): void
{
    $json = json_encode($pv, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Falha ao serializar PV.');
    }
    $r = runCommandWithStdin(['k3s', 'kubectl', 'replace', '--force', '-f', '-'], $json);
    if ($r['code'] !== 0) {
        throw new RuntimeException(
            'kubectl replace PV falhou: '.trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout'])
        );
    }
}

/**
 * Force-delete pods Pending/ContainerCreating que usam o PVC do claimRef do PV.
 *
 * @param  array<string, mixed>  $pv
 */
function forceDeletePodsForPvClaim(array $pv): void
{
    $claim = $pv['spec']['claimRef'] ?? null;
    if (! is_array($claim)) {
        return;
    }
    $ns = $claim['namespace'] ?? null;
    $pvc = $claim['name'] ?? null;
    if (! is_string($ns) || $ns === '' || ! is_string($pvc) || $pvc === '') {
        return;
    }

    $r = runCommand(['k3s', 'kubectl', 'get', 'pods', '-n', $ns, '-o', 'json']);
    if ($r['code'] !== 0) {
        fwrite(STDERR, 'AVISO: não foi possível listar pods em '.$ns.': '.trim($r['stderr'])."\n");

        return;
    }
    $data = json_decode($r['stdout'], true);
    if (! is_array($data) || ! isset($data['items']) || ! is_array($data['items'])) {
        return;
    }

    foreach ($data['items'] as $pod) {
        if (! is_array($pod)) {
            continue;
        }
        $podName = $pod['metadata']['name'] ?? null;
        if (! is_string($podName) || $podName === '') {
            continue;
        }
        $phase = $pod['status']['phase'] ?? '';
        $usesPvc = false;
        $vols = $pod['spec']['volumes'] ?? [];
        if (is_array($vols)) {
            foreach ($vols as $vol) {
                if (! is_array($vol)) {
                    continue;
                }
                $claimName = $vol['persistentVolumeClaim']['claimName'] ?? null;
                if ($claimName === $pvc) {
                    $usesPvc = true;
                    break;
                }
            }
        }
        if (! $usesPvc) {
            continue;
        }
        // Reiniciar pods que ainda montam ou estão presos
        $phaseStr = is_string($phase) ? $phase : '';
        fwrite(STDOUT, "  A force-delete pod {$ns}/{$podName} (phase={$phaseStr})...\n");
        runCommand([
            'k3s', 'kubectl', 'delete', 'pod', $podName, '-n', $ns,
            '--force', '--grace-period=0',
        ]);
    }
}

function main(): void
{
    $manifestPath = envString('K3S_NFS_REWRITE_MANIFEST', DEFAULT_MANIFEST_PATH);
    if ($manifestPath === '') {
        $manifestPath = DEFAULT_MANIFEST_PATH;
    }

    $volumes = loadManifest($manifestPath);
    if ($volumes === []) {
        fwrite(STDOUT, "Manifest sem volumes NFS. Nada a reescrever.\n");

        return;
    }

    fwrite(STDOUT, 'A reescrever '.count($volumes)." PV(s) NFS → hostPath...\n");

    foreach ($volumes as $vol) {
        $name = $vol['pvName'];
        $hostPath = $vol['hostPath'];
        if (! is_dir($hostPath)) {
            throw new RuntimeException(
                "Directório espelho em falta para {$name}: {$hostPath}. Confirme que o restore extraiu nfs-mirror/."
            );
        }
        fwrite(STDOUT, "PV {$name} → hostPath {$hostPath}\n");
        $pv = getPersistentVolume($name);
        $spec = $pv['spec'] ?? [];
        if (is_array($spec) && isset($spec['hostPath']) && ! isset($spec['nfs'])) {
            fwrite(STDOUT, "  já é hostPath; a saltar replace.\n");
            forceDeletePodsForPvClaim($pv);
            continue;
        }
        if (! is_array($spec) || ! isset($spec['nfs'])) {
            fwrite(STDERR, "  AVISO: PV {$name} não tem spec.nfs; a saltar.\n");
            continue;
        }
        $rewritten = rewritePvSpecToHostPath($pv, $hostPath);
        replacePersistentVolume($rewritten);
        fwrite(STDOUT, "  replace OK.\n");
        // Re-ler claimRef do objecto original
        forceDeletePodsForPvClaim($pv);
    }

    fwrite(STDOUT, "Concluído. Verifique: k3s kubectl get pv && k3s kubectl get pods -A\n");
}

try {
    main();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

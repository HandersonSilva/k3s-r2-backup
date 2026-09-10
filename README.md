# Backup K3s para Cloudflare R2

Scripts PHP autónomos (sem Laravel): **backup** compacta o **token** do servidor K3s, o diretório **`server/db/`** (datastore SQLite / Kine), volumes **local-path** dos namespaces `portainer` e `argocd` (por omissão), e um **espelho dos PVs NFS** para DR sem servidor NFS; envia o arquivo para **Cloudflare R2**. **restore** extrai para `/`; no DR, **`rewrite-nfs-pvs-to-hostpath.php`** converte PVs NFS → `hostPath` com os dados espelhados.

A configuração segue as mesmas variáveis `CLOUDFLARE_R2_*` do disco `r2` do projeto [database-backup](../../src/config/filesystems.php).

## Requisitos

- PHP 8.2+ com extensões habituais (`curl`, `hash`, etc.)
- `tar`, `gzip`, `rsync` no PATH
- Com espelho NFS (omissão): `mount.nfs` / pacote `nfs-common`, rede até aos exports NFS de produção
- [Composer](https://getcomposer.org/) para instalar dependências
- Root no nó de controlo (`/var/lib/rancher/k3s/...`)

## Instalação

```bash
cd tools/k3s-r2-backup   # ou copie esta pasta para a máquina desejada
composer install --no-dev
cp .env.example .env
```

Edite `.env` com as credenciais e o endpoint do R2.

## Variáveis de ambiente

| Variável | Obrigatória | Descrição |
|----------|-------------|-----------|
| `CLOUDFLARE_R2_ACCESS_KEY_ID` | Sim | Chave de API R2 |
| `CLOUDFLARE_R2_SECRET_ACCESS_KEY` | Sim | Segredo |
| `CLOUDFLARE_R2_BUCKET` | Sim | Nome do bucket |
| `CLOUDFLARE_R2_ENDPOINT` | Sim | Endpoint S3 do R2 |
| `CLOUDFLARE_R2_REGION` | Não | Omissão: `us-east-1` |
| `CLOUDFLARE_R2_PREFIX` | Não | Prefixo das chaves |
| `CLOUDFLARE_R2_URL` | Não | Não usado pelo script |
| `K3S_SERVER_TOKEN_PATH` | Não | Omissão: `/var/lib/rancher/k3s/server/token` |
| `K3S_SERVER_DB_DIR` | Não | Omissão: `/var/lib/rancher/k3s/server/db` |
| `K3S_BACKUP_STORAGE_NAMESPACES` | Não | Local-path: omissão `portainer,argocd`. Vazio = sem local-path |
| `K3S_STORAGE_DIR` | Não | Omissão: `/var/lib/rancher/k3s/storage` |
| `K3S_BACKUP_NFS_MIRROR` | Não | Omissão: ligado. `0` / vazio / `false` = desliga espelho NFS |
| `K3S_NFS_MIRROR_DIR` | Não | Omissão: `/var/lib/rancher/k3s/storage/nfs-mirror` |
| `K3S_NFS_REWRITE_MANIFEST` | Não | Path do manifest (rewrite). Omissão: `.../server/k3s-r2-nfs-rewrite.json` |
| `K3S_RESTORE_OBJECT_KEY` | Não | Restore: chave S3 explícita |

## Uso (backup em produção)

Com espelho NFS **activo** (omissão), o script precisa do K3s **a correr** para `kubectl get pv`, depois **para o K3s**, monta cada export NFS em modo `ro`, faz `rsync` para `nfs-mirror/`, cria o tar e envia ao R2:

```bash
sudo php backup-k3s-to-r2.php
sudo systemctl start k3s
```

Sem mirror NFS (`K3S_BACKUP_NFS_MIRROR=0`):

```bash
sudo systemctl stop k3s
sudo php backup-k3s-to-r2.php
sudo systemctl start k3s
```

Falha de mount/rsync num PV NFS **aborta** o backup (DR incompleto).

### Formato da chave no R2

```
{CLOUDFLARE_R2_PREFIX normalizado}k3s-control-plane/{hostname}/{timestamp UTC}/k3s-server-backup.tar.gz
```

Arquivos **≥ 100 MiB** usam upload **multipart**.

### Volumes local-path (Portainer / ArgoCD)

Pastas `pvc-<uuid>_<namespace>_<claim>` em `K3S_STORAGE_DIR` filtradas por `K3S_BACKUP_STORAGE_NAMESPACES`.

### Espelho NFS (DR sem NFS)

1. Lista todos os PVs com `spec.nfs`.
2. Copia o conteúdo para `K3S_NFS_MIRROR_DIR/<pv-name>/`.
3. Grava `/var/lib/rancher/k3s/server/k3s-r2-nfs-rewrite.json` (incluído no tar).

O cluster de **backup/DR não precisa** do servidor NFS (`10.0.x.x`); usa os dados espelhados em disco local.

**Limites:** cópia a frio (após stop do K3s), não é dump lógico de Postgres; o tar pode ficar grande; só PVs com `.spec.nfs` (não CSI NFS noutro campo).

## Restore a partir do R2

```bash
sudo systemctl stop k3s
sudo php restore-k3s-from-r2.php --yes
sudo systemctl start k3s
# Se o backup trouxe espelho NFS:
sudo php rewrite-nfs-pvs-to-hostpath.php
```

O restore extrai token, db, local-path e (se existir) `nfs-mirror/` + manifest; limpa `tls`/`cred` (não apaga o espelho NFS). O rewrite converte cada PV NFS → `hostPath` para o directório espelhado e force-delete dos pods que usam o PVC (para remontarem).

### Restore noutro nó / instalação fresca

1. Mesma versão do K3s; pare o serviço.
2. Hostname igual ao nó original (`hostnamectl`).
3. Restore + start + **rewrite NFS** (se aplicável).
4. Alinhe `--tls-san` se o IP/DNS mudou.

Ajuda: `php restore-k3s-from-r2.php --help`.

## Consistência e âmbito

- `server/db/` = metadados da API (ConfigMaps, Secrets, Deployments, PV/PVC, …) + `server/token`.
- Local-path: portainer/argocd por omissão.
- NFS: todos os PVs `nfs` espelhados (omissão).
- Sem imagens de registo; sem dump SQL lógico.

### Erro de host diferente

```bash
k3s kubectl delete node <nó-fantasma>
k3s kubectl get pods -A -o wide | awk '/<nó-fantasma>/ {print $1,$2}' | while read ns name; do
  k3s kubectl -n "$ns" delete pod "$name" --force --grace-period=0
done
```

### Erro MountVolume NFS no DR

Sinal de PVs ainda a apontar para o NFS de prod. Confirme que correu `rewrite-nfs-pvs-to-hostpath.php` após o start e que `nfs-mirror/` existe.

## Falhas

Erros em **stderr**; código de saída **1**.

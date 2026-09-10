# Backup K3s para Cloudflare R2

Scripts PHP autónomos (sem Laravel): **backup** compacta o **token** do servidor K3s, o diretório **`server/db/`** (datastore SQLite / Kine) e, por omissão, volumes **local-path** dos namespaces `portainer` e `argocd`; envia o arquivo para **Cloudflare R2** via API compatível com S3. **restore** descarrega esse arquivo e extrai para `/` no nó de controlo.

A configuração segue as mesmas variáveis `CLOUDFLARE_R2_*` do disco `r2` do projeto [database-backup](../../src/config/filesystems.php).

## Requisitos

- PHP 8.2+ com extensões habituais (`curl`, `hash`, etc.)
- `tar` e `gzip` disponíveis no PATH (Linux no nó de controlo)
- [Composer](https://getcomposer.org/) para instalar dependências
- Leitura dos ficheiros do K3s (normalmente **root** em `/var/lib/rancher/k3s/...`)

## Instalação

```bash
cd tools/k3s-r2-backup   # ou copie esta pasta para a máquina desejada
composer install --no-dev
cp .env.example .env
```

Edite `.env` com as credenciais e o endpoint do R2 (iguais às da aplicação Laravel, se aplicável).

## Variáveis de ambiente

| Variável | Obrigatória | Descrição |
|----------|-------------|-----------|
| `CLOUDFLARE_R2_ACCESS_KEY_ID` | Sim | Chave de API R2 |
| `CLOUDFLARE_R2_SECRET_ACCESS_KEY` | Sim | Segredo |
| `CLOUDFLARE_R2_BUCKET` | Sim | Nome do bucket |
| `CLOUDFLARE_R2_ENDPOINT` | Sim | Endpoint S3 do R2 |
| `CLOUDFLARE_R2_REGION` | Não | Omissão: `us-east-1` |
| `CLOUDFLARE_R2_PREFIX` | Não | Prefixo das chaves (como `root` no Flysystem) |
| `CLOUDFLARE_R2_URL` | Não | Não usado pelo script; pode ficar vazio |
| `K3S_SERVER_TOKEN_PATH` | Não | Omissão: `/var/lib/rancher/k3s/server/token` |
| `K3S_SERVER_DB_DIR` | Não | Omissão: `/var/lib/rancher/k3s/server/db` |
| `K3S_BACKUP_STORAGE_NAMESPACES` | Não | Namespaces cujos PVCs local-path entram no tar. Omissão: `portainer,argocd`. Vazio = sem storage |
| `K3S_STORAGE_DIR` | Não | Omissão: `/var/lib/rancher/k3s/storage` |
| `K3S_RESTORE_OBJECT_KEY` | Não | Restore: chave S3 completa do `.tar.gz` (alternativa a `--key=` ou ao mais recente) |

O script lê `.env` (Dotenv) e também variáveis **exportadas no shell** (`getenv`), útil em PHP CLI onde `$_ENV` não reflete o ambiente do sistema.

## Uso

Recomenda-se parar o K3s antes do backup para evitar cópia inconsistente do SQLite e dos volumes (ver secção seguinte).

```bash
sudo systemctl stop k3s
sudo php backup-k3s-to-r2.php
sudo systemctl start k3s
```

Em caso de sucesso, o script imprime a **chave do objeto** no bucket, o **SHA-256**, e a lista de volumes local-path incluídos (se houver).

### Formato da chave no R2

```
{CLOUDFLARE_R2_PREFIX normalizado}k3s-control-plane/{hostname}/{timestamp UTC}/k3s-server-backup.tar.gz
```

Arquivos **≥ 100 MiB** usam upload **multipart**; abaixo disso usa-se `PutObject`.

### Volumes local-path (Portainer / ArgoCD)

O backup procura em `K3S_STORAGE_DIR` pastas no formato local-path `pvc-<uuid>_<namespace>_<claim>` e inclui as que contêm `_<namespace>_` para cada entrada de `K3S_BACKUP_STORAGE_NAMESPACES`.

- Omissão: `portainer,argocd` (dados do Portainer e do ArgoCD no mesmo tar).
- Desligar: `K3S_BACKUP_STORAGE_NAMESPACES=` (só token + db).
- Acrescentar namespaces: `K3S_BACKUP_STORAGE_NAMESPACES=portainer,argocd,outro-ns` (cuidado com o tamanho — DBs grandes incham o arquivo).

ConfigMaps, Secrets e demais objectos da API já iam no `server/db/`; o storage acrescenta o **conteúdo em disco** dos PVCs escolhidos.

## Restore a partir do R2

O script `restore-k3s-from-r2.php` **substitui** no disco o `server/token` e o `server/db/` (e caminhos equivalentes se o backup tiver sido feito com caminhos customizados). Se o arquivo tiver volumes local-path, também os extrai sob `/var/lib/rancher/k3s/storage/`. Depois **remove** `server/tls/` e `server/cred/` (e `kine.sock` se existir), para o K3s os recriar a partir do datastore no próximo start. Isto evita o fatal `newer than datastore` típico ao restaurar sobre uma instalação fresca ou noutro nó. O restauro é **destrutivo** no nó de controlo; use apenas em recuperação ou migração planeada.

1. Pare o K3s: `sudo systemctl stop k3s`.
2. (Opcional, se arranques anteriores falharam) limpe processos leftover:
   `sudo pkill -9 containerd-shim; sudo pkill -9 k3s`
3. **Noutro nó:** alinhe o hostname ao do cluster original **antes** do restore/start (o segmento aparece na chave R2: `.../k3s-control-plane/{hostname}/...`):

```bash
sudo hostnamectl set-hostname k3s-prod-1   # exemplo: usar o hostname do nó original
```

4. Execute o restore como root (escrita em `/var/lib/rancher/k3s/...`):

```bash
sudo php restore-k3s-from-r2.php --yes
```

5. Inicie o K3s: `sudo systemctl start k3s` e acompanhe `journalctl -u k3s -f`.

- **`--yes` / `-y`**: confirma o restauro; em execução não-interativa (sem TTY) é **obrigatório**.
- Sem **`--key=`** e sem `K3S_RESTORE_OBJECT_KEY`: escolhe o objeto `.../k3s-server-backup.tar.gz` com **`LastModified` mais recente** entre todos sob `{prefixo}k3s-control-plane/`.
- **`--key=<chave>`** ou **`K3S_RESTORE_OBJECT_KEY`**: restaura esse objeto explicitamente.

Se o objeto tiver metadado `sha256` (como no backup), o script verifica o hash após o download. O script **não** inicia o K3s. Se o hostname local ≠ `{hostname}` do path do backup, imprime um **AVISO** com o `hostnamectl` sugerido (não altera o hostname sozinho).

Ver aplicações:

```bash
k3s kubectl get deployments -A
# ou
k3s kubectl get all -A
```

### Restore noutro nó / instalação fresca

1. Instale a **mesma versão** do K3s no nó novo.
2. Pare o serviço **antes** de depender do estado local (ou pare assim que a install terminar).
3. Defina o **mesmo hostname** do nó original (`sudo hostnamectl set-hostname ...`) para o K3s não registar um segundo control-plane e deixar pods presos no nó fantasma (`NotReady`).
4. Corra o restore: repõe `token`+`db` (+ storage do tar) e limpa `tls`/`cred` da install local.
5. Alinhe também `--tls-san` / flags do unit se o IP/DNS do API mudou.
6. `sudo systemctl start k3s` — o K3s reconcilia o bootstrap e recria certificados/credenciais a partir do DB.

Se já arrancou com hostname errado: `kubectl delete node <nó-fantasma>` e force-delete dos pods em `Terminating` no nó antigo.

Ajuda: `php restore-k3s-from-r2.php --help`.

## Consistência e âmbito do backup

- Com datastore **SQLite embutido**, o estado do plano de controlo está em `server/db/` (ConfigMaps, Secrets, Deployments, PV/PVC, etc.). O ficheiro **`server/token`** deve ser guardado em conjunto (recomendação K3s para restauro).
- Copiar `state.db` ou volumes com o serviço a escrever pode corromper o backup. **Pare o K3s** ou use **snapshot** de volume / ferramenta de backup SQLite consistente.
- Por omissão inclui dados em disco dos PVCs **portainer** e **argocd** sob `K3S_STORAGE_DIR`. Outros namespaces (ex. `databases`) **não** entram, salvo configuração explícita.
- **Não** inclui imagens em registo nem discos de workloads fora dos namespaces configurados.
- O backup **não** inclui `server/tls/` nem `server/cred/`; no restore esses caminhos são apagados de propósito para serem recriados a partir do datastore.

### Erro de host diferente

```bash
k3s kubectl get nodes -o wide
k3s kubectl get pods -A | grep -v Running | head -40
k3s kubectl get events -A --sort-by='.lastTimestamp' | tail -30
```

Corrigir (exemplo com nó fantasma `k3s-prod-1`):

```bash
# 1) Remover o nó antigo do cluster
k3s kubectl delete node k3s-prod-1

# 2) Forçar delete dos pods presos em Terminating (sem grace)
k3s kubectl get pods -A -o wide | awk '/k3s-prod-1/ {print $1,$2}' | while read ns name; do
  k3s kubectl -n "$ns" delete pod "$name" --force --grace-period=0
done
```

## Falhas

Mensagens de erro são escritas em **stderr**; o processo termina com código **1** em falha (permissões, `tar`, rede, credenciais, etc.).

# Backup K3s para Cloudflare R2

Script PHP autónomo (sem Laravel) que compacta o **token** do servidor K3s e o diretório **`server/db/`** (datastore SQLite / Kine) e envia o arquivo para **Cloudflare R2** via API compatível com S3.

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

O script lê `.env` (Dotenv) e também variáveis **exportadas no shell** (`getenv`), útil em PHP CLI onde `$_ENV` não reflete o ambiente do sistema.

## Uso

Recomenda-se parar o K3s antes do backup para evitar cópia inconsistente do SQLite (ver secção seguinte).

```bash
sudo systemctl stop k3s
sudo php backup-k3s-to-r2.php
sudo systemctl start k3s
```

Em caso de sucesso, o script imprime a **chave do objeto** no bucket e o **SHA-256** do arquivo enviado (também gravado como metadado `x-amz-meta-sha256` no objeto).

### Formato da chave no R2

```
{CLOUDFLARE_R2_PREFIX normalizado}k3s-control-plane/{hostname}/{timestamp UTC}/k3s-server-backup.tar.gz
```

Arquivos **≥ 100 MiB** usam upload **multipart**; abaixo disso usa-se `PutObject`.

## Consistência e âmbito do backup

- Com datastore **SQLite embutido**, o estado do plano de controlo está em `server/db/`. O ficheiro **`server/token`** deve ser guardado em conjunto (recomendação K3s para restauro).
- Copiar `state.db` com o serviço a escrever pode corromper o backup. **Pare o K3s** ou use **snapshot** de volume / ferramenta de backup SQLite consistente.
- Este backup cobre **metadados do cluster** (recursos persistidos no API server), **não** dados em PVCs, imagens em registo nem discos de workloads.

## Falhas

Mensagens de erro são escritas em **stderr**; o processo termina com código **1** em falha (permissões, `tar`, rede, credenciais, etc.).

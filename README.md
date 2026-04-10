# SkyGuard — Deploy Docker na Azure VM

## Estrutura do projeto

```
skyguard-docker/
├── app/                    ← Código fonte da aplicação
│   ├── api/
│   │   ├── config.php      ← Lê variáveis de ambiente
│   │   ├── db.php
│   │   ├── auth.php
│   │   ├── readings.php
│   │   ├── devices.php
│   │   ├── users.php
│   │   └── contact.php
│   ├── pages/
│   ├── css/
│   ├── js/
│   ├── vendor/             ← PHPMailer (já incluso)
│   ├── login.html
│   └── skyguard_db.sql
├── docker/
│   └── apache.conf         ← Config do Apache
├── docker-compose.yml
├── Dockerfile
├── .env                    ← Suas credenciais (NÃO suba pro Git)
└── .gitignore
```

---

## Pré-requisitos na VM Azure

```bash
# Instala Docker Engine
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
newgrp docker

# Instala Docker Compose plugin
sudo apt-get install -y docker-compose-plugin

# Verifica
docker --version
docker compose version
```

---

## 1. Subir o projeto na VM

### Opção A — Via SCP (upload direto)
```bash
# No seu computador local, envie o zip para a VM:
scp skyguard-docker.zip azureuser@<IP_DA_VM>:~/

# Na VM, descompacte:
ssh azureuser@<IP_DA_VM>
unzip skyguard-docker.zip
cd skyguard-docker
```

### Opção B — Via Git
```bash
git clone <seu-repositorio> skyguard-docker
cd skyguard-docker
```

---

## 2. Configurar o arquivo .env

```bash
# Edite com os dados reais de produção
nano .env
```

Campos obrigatórios:
| Variável | Descrição |
|---|---|
| `DB_ROOT_PASSWORD` | Senha root do MySQL |
| `DB_USER` | Usuário do banco |
| `DB_PASS` | Senha do usuário do banco |
| `MAIL_USERNAME` | Email Gmail remetente |
| `MAIL_PASSWORD` | App Password do Gmail (16 chars) |
| `MAIL_RECIPIENT` | Email que recebe as mensagens |
| `ESP_TOKEN` | Token secreto do ESP32 |

> **Gmail App Password:** Acesse myaccount.google.com → Segurança → Verificação em duas etapas → Senhas de app

---

## 3. Liberar portas no Azure (Network Security Group)

No portal Azure, vá em:
**VM → Rede → Adicionar regra de entrada**

| Porta | Protocolo | Descrição |
|---|---|---|
| 80 | TCP | Aplicação web |
| 8080 | TCP | phpMyAdmin (opcional) |
| 22 | TCP | SSH (já deve estar aberto) |

---

## 4. Subir os containers

```bash
cd skyguard-docker

# Sobe tudo em background
docker compose up -d

# Acompanha os logs (aguarde o banco inicializar ~30s)
docker compose logs -f

# Verifica se todos estão rodando
docker compose ps
```

Saída esperada:
```
NAME                  STATUS          PORTS
skyguard_app          Up              0.0.0.0:80->80/tcp
skyguard_db           Up (healthy)    3306/tcp
skyguard_phpmyadmin   Up              0.0.0.0:8080->80/tcp
```

---

## 5. Acessar o sistema

| Serviço | URL |
|---|---|
| Aplicação | `http://<IP_DA_VM>/login.html` |
| phpMyAdmin | `http://<IP_DA_VM>:8080` |

**Login padrão:**
- Admin: `admin@skyguard.com` / `admin123`
- Usuário: `user@skyguard.com` / `user123`

> ⚠️ **Troque as senhas após o primeiro acesso!**

---

## Comandos úteis

```bash
# Ver logs da aplicação PHP
docker compose logs app

# Ver logs do banco
docker compose logs db

# Reiniciar apenas a aplicação
docker compose restart app

# Parar tudo
docker compose down

# Parar e apagar os dados do banco (CUIDADO!)
docker compose down -v

# Acessar o terminal do container PHP
docker exec -it skyguard_app bash

# Acessar o MySQL direto
docker exec -it skyguard_db mysql -u skyuser -p skyguard_db
```

---

## Atualizar a aplicação

```bash
# Após alterar arquivos no código:
docker compose build app
docker compose up -d app
```

---

## Backup do banco de dados

```bash
# Exportar
docker exec skyguard_db mysqldump -u skyuser -p'SUA_SENHA' skyguard_db > backup_$(date +%Y%m%d).sql

# Importar
docker exec -i skyguard_db mysql -u skyuser -p'SUA_SENHA' skyguard_db < backup.sql
```

---

## Solução de problemas

### Container `app` não sobe
```bash
docker compose logs app
# Verifique se o Dockerfile está na raiz e o Apache está configurado
```

### Erro de conexão com banco
```bash
docker compose logs db
# Aguarde o healthcheck ficar "healthy" antes de testar
# Leva ~30 segundos na primeira vez (inicialização do MySQL)
```

### PHPMailer não envia e-mail
- Verifique se `MAIL_PASSWORD` é um **App Password** do Google (não sua senha normal)
- Confirme que a verificação em duas etapas está ativa na conta Google
- Teste acessando: `http://<IP>/test_db.php`

### Porta 80 bloqueada
```bash
# Verifique se o Apache está ouvindo
docker exec skyguard_app curl -s http://localhost | head -5
# Se funcionar localmente, o problema é no NSG do Azure
```

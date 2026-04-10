# SkyGuard — Deploy Docker na Azure VM

Sistema de monitoramento de qualidade do ar com ESP32 + SGP30 + MQTT.

## Instalacao do zero (VM Azure)

### Pre-requisitos
- VM Azure com Ubuntu 22.04
- Portas abertas no NSG: 22, 80, 443, 8080

### 3 comandos e pronto

    git clone https://github.com/Ryan-devzz/Tcc_skyguard.git ~/Tcc_Skyguard
    cd ~/Tcc_Skyguard
    bash setup.sh

O script setup.sh faz automaticamente:
1. Limpeza de espaco em disco
2. Instalacao do Docker e Docker Compose
3. Geracao do certificado SSL para o IP da VM
4. Configuracao do Apache com HTTPS
5. Build da imagem PHP com PHPMailer
6. Subida dos containers (app + banco + phpMyAdmin)
7. Verificacao se o site esta respondendo

---

## Acessar o sistema

| Servico       | URL                              |
|---------------|----------------------------------|
| Aplicacao     | https://<IP_DA_VM>/login.html    |
| phpMyAdmin    | http://<IP_DA_VM>:8080           |

Ao abrir no navegador pela primeira vez, clique em Avancado -> Continuar para o site (certificado autoassinado).

Logins padrao:
- Admin : admin@skyguard.com / admin123
- Usuario: user@skyguard.com  / user123

---

## Portas no Azure NSG

| Porta | Descricao                        |
|-------|----------------------------------|
| 22    | SSH                              |
| 80    | HTTP (redireciona para HTTPS)    |
| 443   | HTTPS (site principal)           |
| 8080  | phpMyAdmin                       |

---

## Problema com Apache2 nativo da VM

Se a porta 80 ou 8080 estiver ocupada:

    sudo systemctl stop apache2
    sudo systemctl disable apache2
    docker compose down
    docker compose up -d

---

## Resetar senhas do banco

    HASH=$(docker exec skyguard_app php -r "echo password_hash('admin123', PASSWORD_DEFAULT);")
    docker exec skyguard_db mysql -u root -p'SkyGuard@Root2025!' skyguard_db -e "UPDATE users SET password_hash = '$HASH' WHERE email = 'admin@skyguard.com';"

    HASH=$(docker exec skyguard_app php -r "echo password_hash('user123', PASSWORD_DEFAULT);")
    docker exec skyguard_db mysql -u root -p'SkyGuard@Root2025!' skyguard_db -e "UPDATE users SET password_hash = '$HASH' WHERE email = 'user@skyguard.com';"

---

## Comandos uteis

    docker compose ps                   # status dos containers
    docker compose logs -f              # logs em tempo real
    docker compose logs app --tail=30   # logs da aplicacao
    docker compose restart app          # reiniciar aplicacao
    docker compose down                 # parar tudo
    docker compose down -v              # parar e apagar banco (CUIDADO)
    docker exec -it skyguard_app bash   # terminal do container PHP

---

## Atualizar codigo apos git pull

    cd ~/Tcc_Skyguard
    git pull
    docker compose down
    docker compose build --no-cache
    docker compose up -d

---

## Backup do banco

    # Exportar
    docker exec skyguard_db mysqldump -u skyuser -p'SkyGuard@2025!' skyguard_db > backup_$(date +%Y%m%d).sql

    # Importar
    docker exec -i skyguard_db mysql -u skyuser -p'SkyGuard@2025!' skyguard_db < backup.sql

---

## Solucao de problemas

Banco nao conecta:
    docker compose logs db
    # Aguarde ate 2 minutos na primeira inicializacao

PHPMailer nao envia email:
- Use App Password do Google (16 caracteres), nao a senha normal
- Ative verificacao em duas etapas em myaccount.google.com

Site retorna 403:
    docker exec skyguard_app ls /var/www/html/

Porta ocupada:
    sudo fuser -k 80/tcp
    sudo fuser -k 443/tcp
    sudo fuser -k 8080/tcp
    docker compose up -d

---

## ESP32 — Endpoint para envio de leituras

    POST https://<IP_DA_VM>/api/readings.php
    Content-Type: application/json

    {
      "device_id": "SGP-001",
      "co2": 412,
      "tvoc": 45,
      "token": "skyguard_secret_token"
    }

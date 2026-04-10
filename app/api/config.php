<?php
/* ============================================================
   SKYGUARD — config.php
   Lê configurações de variáveis de ambiente (Docker / .env).
   Em desenvolvimento local sem Docker, crie um arquivo .env
   na raiz e carregue com vlucas/phpdotenv, ou defina as
   variáveis no seu servidor web (Apache SetEnv / php-fpm pool).
   ============================================================ */

// ── Banco de Dados ────────────────────────────────────────────
define('DB_HOST', getenv('DB_HOST') ?: 'db');
define('DB_USER', getenv('DB_USER') ?: 'skyuser');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'skyguard_db');

// ── Email ─────────────────────────────────────────────────────
define('MAIL_HOST',      getenv('MAIL_HOST')      ?: 'smtp.gmail.com');
define('MAIL_PORT',      getenv('MAIL_PORT')      ?: 587);
define('MAIL_USERNAME',  getenv('MAIL_USERNAME')  ?: '');
define('MAIL_PASSWORD',  getenv('MAIL_PASSWORD')  ?: '');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'SkyGuard Sistema');
define('MAIL_RECIPIENT', getenv('MAIL_RECIPIENT') ?: '');

// ── CORS ──────────────────────────────────────────────────────
define('CORS_ORIGIN', getenv('CORS_ORIGIN') ?: '*');

// ── Token ESP32 ───────────────────────────────────────────────
// Defina ESP_TOKEN como variável de ambiente em produção.
define('ESP_TOKEN', getenv('ESP_TOKEN') ?: 'skyguard_secret_token');

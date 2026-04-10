-- ============================================================
--  SKYGUARD — skyguard_db.sql
--  Script de criação do banco de dados
--
--  Como usar:
--  1. Abra o phpMyAdmin (http://localhost/phpmyadmin)
--  2. Clique em "Novo" para criar um banco chamado: skyguard_db
--  3. Selecione o banco skyguard_db
--  4. Vá em "SQL" e cole todo este arquivo
--  5. Clique em "Executar"
-- ============================================================

CREATE DATABASE IF NOT EXISTS skyguard_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE skyguard_db;

-- ============================================================
-- TABELA: users
-- Armazena os usuários da plataforma (admin e usuários comuns)
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(180) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  status        ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABELA: devices
-- Dispositivos SGP30/ESP32 cadastrados na plataforma
-- ============================================================
CREATE TABLE IF NOT EXISTS devices (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(30) NOT NULL UNIQUE,   -- Ex: SGP-001
  name        VARCHAR(100) NOT NULL,
  location    VARCHAR(150),
  mqtt_broker VARCHAR(150) DEFAULT 'broker.emqx.io',
  mqtt_port   SMALLINT UNSIGNED DEFAULT 1883,
  mqtt_topic  VARCHAR(200),                  -- Gerado automaticamente
  status      ENUM('online', 'offline') DEFAULT 'offline',
  last_seen   TIMESTAMP NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABELA: readings
-- Leituras dos sensores (CO2, TVOC, temperatura, umidade)
-- ============================================================
CREATE TABLE IF NOT EXISTS readings (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(30) NOT NULL,
  co2         DECIMAL(7,2) NOT NULL,          -- ppm
  tvoc        DECIMAL(7,2) NOT NULL,          -- ppb
  temperature DECIMAL(5,2),                  -- °C
  humidity    DECIMAL(5,2),                  -- %
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_device_time (device_id, created_at),
  FOREIGN KEY (device_id) REFERENCES devices(device_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TABELA: alerts
-- Registro de alertas gerados pela plataforma
-- ============================================================
CREATE TABLE IF NOT EXISTS alerts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   VARCHAR(30) NOT NULL,
  type        ENUM('co2_high', 'tvoc_high', 'device_offline') NOT NULL,
  value       DECIMAL(7,2),                  -- Valor que disparou o alerta
  message     VARCHAR(255),
  resolved    TINYINT(1) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

-- Usuário admin padrão (senha: admin123)
-- IMPORTANTE: Troque a senha após o primeiro acesso!
INSERT INTO users (name, email, password_hash, role, status) VALUES
(
  'Admin SkyGuard',
  'admin@skyguard.com',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- admin123
  'admin',
  'active'
),
(
  'João Silva',
  'user@skyguard.com',
  '$2y$10$TKh8H1.PyfSi8Ea4XHsnqe/3voyZW8LzZU3K5HvM/Ej7rBHWHXi.', -- user123
  'user',
  'active'
);

-- Dispositivos de exemplo
INSERT INTO devices (device_id, name, location, mqtt_topic) VALUES
('SGP-001', 'Sala Principal',   'Térreo — Bloco A',   'skyguard/SGP-001/data'),
('SGP-002', 'Lab. Química',     '1º Andar — Bloco B', 'skyguard/SGP-002/data'),
('SGP-003', 'Corredor Norte',   'Térreo — Bloco C',   'skyguard/SGP-003/data');

-- ============================================================
-- VIEWS ÚTEIS (consultas prontas)
-- ============================================================

-- Última leitura de cada dispositivo
CREATE OR REPLACE VIEW view_latest_readings AS
SELECT
  d.device_id,
  d.name,
  d.location,
  d.status,
  r.co2,
  r.tvoc,
  r.temperature,
  r.humidity,
  r.created_at AS last_reading
FROM devices d
LEFT JOIN readings r ON r.id = (
  SELECT id FROM readings
  WHERE device_id = d.device_id
  ORDER BY created_at DESC
  LIMIT 1
);

-- Média diária por dispositivo
CREATE OR REPLACE VIEW view_daily_averages AS
SELECT
  device_id,
  DATE(created_at)   AS dia,
  ROUND(AVG(co2), 1) AS avg_co2,
  ROUND(AVG(tvoc),1) AS avg_tvoc,
  ROUND(AVG(temperature),1) AS avg_temp,
  ROUND(AVG(humidity),1)    AS avg_hum,
  COUNT(*) AS total_readings
FROM readings
GROUP BY device_id, DATE(created_at)
ORDER BY dia DESC;

<?php
/* ============================================================
   SKYGUARD — readings.php
   Recebe e retorna leituras dos sensores SGP30

   POST /api/readings.php           → salva leitura (ESP32 envia)
   GET  /api/readings.php?device_id=SGP-001&limit=50 → histórico
   GET  /api/readings.php?device_id=SGP-001&latest=1 → última leitura
   ============================================================

   No ESP32, publique no tópico MQTT: skyguard/{device_id}/data
   O script MQTT subscriber (mqtt_subscriber.php) deve estar rodando
   para escutar e chamar este endpoint.

   Formato do payload JSON enviado pelo ESP32:
   {
     "device_id": "SGP-001",
     "co2": 412,
     "tvoc": 45,
     "token": "skyguard_secret_token"
   }
   Nota: temperature e humidity são aceitos mas opcionais (sensor SGP30 não os fornece).
   ============================================================ */

require_once 'db.php';
setHeaders();

$method = $_SERVER['REQUEST_METHOD'];

// ---- SALVAR LEITURA (ESP32 → PHP) ----
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    // Valida token (segurança básica)
    if (($body['token'] ?? '') !== ESP_TOKEN) {
        jsonResponse(['error' => 'Token inválido'], 401);
    }

    $device_id  = trim($body['device_id'] ?? '');
    $co2        = floatval($body['co2'] ?? 0);
    $tvoc       = floatval($body['tvoc'] ?? 0);
    $temp       = floatval($body['temperature'] ?? 0);
    $humidity   = floatval($body['humidity'] ?? 0);

    if (!$device_id) jsonResponse(['error' => 'device_id é obrigatório'], 400);

    $conn = getConnection();

    // Verifica se dispositivo existe
    $stmt = $conn->prepare("SELECT id FROM devices WHERE device_id = ?");
    $stmt->bind_param('s', $device_id);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_assoc()) {
        jsonResponse(['error' => 'Dispositivo não cadastrado: ' . $device_id], 404);
    }

    // Salva leitura
    $stmt = $conn->prepare(
        "INSERT INTO readings (device_id, co2, tvoc, temperature, humidity) VALUES (?,?,?,?,?)"
    );
    $stmt->bind_param('sdddd', $device_id, $co2, $tvoc, $temp, $humidity);

    if ($stmt->execute()) {
        // Atualiza last_seen do dispositivo
        $upd = $conn->prepare("UPDATE devices SET last_seen = NOW(), status = 'online' WHERE device_id = ?");
        $upd->bind_param('s', $device_id);
        $upd->execute();

        jsonResponse(['success' => true, 'id' => $conn->insert_id]);
    } else {
        jsonResponse(['error' => 'Erro ao salvar leitura'], 500);
    }
}

// ---- BUSCAR LEITURAS ----
if ($method === 'GET') {
    session_start();
    if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Não autenticado'], 401);

    $device_id = trim($_GET['device_id'] ?? '');
    $latest    = isset($_GET['latest']);
    $limit     = min(intval($_GET['limit'] ?? 50), 500);
    $from      = $_GET['from'] ?? null; // ex: "2025-01-01"

    $conn = getConnection();

    if ($latest && $device_id) {
        $stmt = $conn->prepare(
            "SELECT * FROM readings WHERE device_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->bind_param('s', $device_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        jsonResponse($row ?? ['error' => 'Nenhuma leitura encontrada']);
    }

    if ($device_id) {
        $sql = "SELECT id, device_id, co2, tvoc, temperature, humidity, created_at
                FROM readings WHERE device_id = ?";
        $params = [$device_id];
        $types = 's';

        if ($from) {
            $sql .= " AND created_at >= ?";
            $params[] = $from;
            $types .= 's';
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        jsonResponse($rows);
    }

    // Sem filtro: últimas leituras de cada dispositivo
    $result = $conn->query(
        "SELECT r.* FROM readings r
         INNER JOIN (
           SELECT device_id, MAX(id) as max_id FROM readings GROUP BY device_id
         ) latest ON r.id = latest.max_id
         ORDER BY r.created_at DESC"
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    jsonResponse($rows);
}

jsonResponse(['error' => 'Método não suportado'], 405);

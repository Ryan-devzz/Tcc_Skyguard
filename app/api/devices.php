<?php
/* ============================================================
   SKYGUARD — devices.php
   CRUD de dispositivos

   GET    /api/devices.php           → lista todos
   GET    /api/devices.php?id=SGP-001 → busca por ID
   POST   /api/devices.php           → cadastra (admin)
   PUT    /api/devices.php?id=SGP-001 → atualiza (admin)
   DELETE /api/devices.php?id=SGP-001 → remove (admin)
   ============================================================ */

require_once 'db.php';
setHeaders();
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Não autenticado'], 401);

$method   = $_SERVER['REQUEST_METHOD'];
$isAdmin  = $_SESSION['role'] === 'admin';
$id       = trim($_GET['id'] ?? '');
$conn     = getConnection();

// ---- GET ----
if ($method === 'GET') {
    if ($id) {
        $stmt = $conn->prepare("SELECT * FROM devices WHERE device_id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        jsonResponse($row ?? ['error' => 'Dispositivo não encontrado']);
    }
    $result = $conn->query("SELECT * FROM devices ORDER BY device_id");
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    jsonResponse($rows);
}

// ---- POST ----
if ($method === 'POST') {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $body     = json_decode(file_get_contents('php://input'), true);
    $devId    = strtoupper(trim($body['device_id'] ?? ''));
    $name     = trim($body['name'] ?? '');
    $location = trim($body['location'] ?? '');
    $broker   = trim($body['mqtt_broker'] ?? 'broker.emqx.io');
    $port     = intval($body['mqtt_port'] ?? 1883);
    $topic    = "skyguard/{$devId}/data";

    if (!$devId || !$name) jsonResponse(['error' => 'device_id e nome são obrigatórios'], 400);

    $stmt = $conn->prepare(
        "INSERT INTO devices (device_id, name, location, mqtt_broker, mqtt_port, mqtt_topic) VALUES (?,?,?,?,?,?)"
    );
    $stmt->bind_param('ssssss', $devId, $name, $location, $broker, $port, $topic);

    if ($stmt->execute()) jsonResponse(['success' => true]);
    else jsonResponse(['error' => 'ID de dispositivo já existe'], 409);
}

// ---- PUT ----
if ($method === 'PUT' && $id) {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $body     = json_decode(file_get_contents('php://input'), true);
    $name     = trim($body['name'] ?? '');
    $location = trim($body['location'] ?? '');
    $broker   = trim($body['mqtt_broker'] ?? 'broker.emqx.io');
    $port     = intval($body['mqtt_port'] ?? 1883);
    $status   = in_array($body['status'] ?? '', ['online','offline']) ? $body['status'] : 'offline';

    $stmt = $conn->prepare(
        "UPDATE devices SET name=?, location=?, mqtt_broker=?, mqtt_port=?, status=? WHERE device_id=?"
    );
    $stmt->bind_param('ssssss', $name, $location, $broker, $port, $status, $id);
    $stmt->execute();
    jsonResponse(['success' => true]);
}

// ---- DELETE ----
if ($method === 'DELETE' && $id) {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $stmt = $conn->prepare("DELETE FROM devices WHERE device_id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    jsonResponse(['success' => true, 'deleted' => $stmt->affected_rows]);
}

$conn->close();
jsonResponse(['error' => 'Método não suportado'], 405);

<?php
/* ============================================================
   SKYGUARD — devices.php
   Admin vê e gerencia todos os dispositivos.
   Usuário comum vê apenas os dispositivos atribuídos a ele.
   ============================================================ */

require_once 'db.php';
setHeaders();
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Não autenticado'], 401);

$method  = $_SERVER['REQUEST_METHOD'];
$isAdmin = $_SESSION['role'] === 'admin';
$userId  = intval($_SESSION['user_id']);
$id      = trim($_GET['id'] ?? '');
$conn    = getConnection();

// ── GET ──────────────────────────────────────────────────────
if ($method === 'GET') {

    // Atribuições de usuário (admin) — GET ?assignments=1
    if ($isAdmin && isset($_GET['assignments'])) {
        $devId = trim($_GET['device_id'] ?? '');
        if ($devId) {
            $stmt = $conn->prepare("
                SELECT u.id, u.name, u.email
                FROM user_devices ud
                JOIN users u ON u.id = ud.user_id
                WHERE ud.device_id = ?
            ");
            $stmt->bind_param('s', $devId);
            $stmt->execute();
            $rows = [];
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $rows[] = $row;
            jsonResponse($rows);
        }
        // Lista todos os usuários com seus dispositivos
        $result = $conn->query("
            SELECT ud.user_id, ud.device_id, u.name, u.email
            FROM user_devices ud
            JOIN users u ON u.id = ud.user_id
            ORDER BY u.name
        ");
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        jsonResponse($rows);
    }

    // Busca dispositivo específico
    if ($id) {
        $stmt = $conn->prepare("SELECT * FROM devices WHERE device_id = ?");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) jsonResponse(['error' => 'Dispositivo não encontrado'], 404);

        // Usuário comum só pode ver se tiver acesso
        if (!$isAdmin) {
            $check = $conn->prepare("SELECT id FROM user_devices WHERE user_id = ? AND device_id = ?");
            $check->bind_param('is', $userId, $id);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) jsonResponse(['error' => 'Acesso negado'], 403);
        }
        jsonResponse($row);
    }

    // Lista dispositivos
    if ($isAdmin) {
        // Admin vê todos
        $result = $conn->query("SELECT * FROM devices ORDER BY device_id");
    } else {
        // Usuário vê apenas os atribuídos a ele
        $stmt = $conn->prepare("
            SELECT d.* FROM devices d
            INNER JOIN user_devices ud ON ud.device_id = d.device_id
            WHERE ud.user_id = ?
            ORDER BY d.device_id
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    jsonResponse($rows);
}

// ── POST — Cadastrar dispositivo (admin) ──────────────────────
if ($method === 'POST') {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $body     = json_decode(file_get_contents('php://input'), true);

    // Atribuir dispositivo a usuário — POST ?assign=1
    if (isset($_GET['assign'])) {
        $devId  = trim($body['device_id'] ?? '');
        $uid    = intval($body['user_id'] ?? 0);
        if (!$devId || !$uid) jsonResponse(['error' => 'device_id e user_id são obrigatórios'], 400);
        $stmt = $conn->prepare("INSERT IGNORE INTO user_devices (user_id, device_id) VALUES (?,?)");
        $stmt->bind_param('is', $uid, $devId);
        $stmt->execute();
        jsonResponse(['success' => true]);
    }

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

// ── DELETE — Remover atribuição (admin) — DELETE ?assign=1 ────
if ($method === 'DELETE' && isset($_GET['assign'])) {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $body  = json_decode(file_get_contents('php://input'), true);
    $devId = trim($body['device_id'] ?? '');
    $uid   = intval($body['user_id'] ?? 0);
    $stmt  = $conn->prepare("DELETE FROM user_devices WHERE user_id = ? AND device_id = ?");
    $stmt->bind_param('is', $uid, $devId);
    $stmt->execute();
    jsonResponse(['success' => true, 'removed' => $stmt->affected_rows]);
}

// ── PUT — Atualizar dispositivo (admin) ───────────────────────
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

// ── DELETE — Remover dispositivo (admin) ──────────────────────
if ($method === 'DELETE' && $id) {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin'], 403);
    $stmt = $conn->prepare("DELETE FROM devices WHERE device_id = ?");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    jsonResponse(['success' => true, 'deleted' => $stmt->affected_rows]);
}

$conn->close();
jsonResponse(['error' => 'Método não suportado'], 405);

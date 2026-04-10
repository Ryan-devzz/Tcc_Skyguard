<?php
/* ============================================================
   SKYGUARD — users.php
   CRUD de usuários (somente admin)
   
   GET    /api/users.php           → lista todos
   GET    /api/users.php?id=1      → busca por ID
   POST   /api/users.php           → cria usuário
   PUT    /api/users.php?id=1      → atualiza usuário
   DELETE /api/users.php?id=1      → remove usuário
   ============================================================ */

require_once 'db.php';
setHeaders();
$method = $_SERVER['REQUEST_METHOD'];
$id = intval($_GET['id'] ?? 0);

// Listar e buscar: usuário logado pode ver apenas seu próprio perfil
// Admin pode ver todos
session_start();
if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Não autenticado'], 401);
$isAdmin = $_SESSION['role'] === 'admin';

$conn = getConnection();

// ---- GET ----
if ($method === 'GET') {
    if ($id) {
        // Usuário comum só vê o próprio
        if (!$isAdmin && $id !== intval($_SESSION['user_id'])) {
            jsonResponse(['error' => 'Acesso negado'], 403);
        }
        $stmt = $conn->prepare("SELECT id, name, email, role, status, created_at FROM users WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        jsonResponse($u ?? ['error' => 'Usuário não encontrado']);
    }
    if (!$isAdmin) jsonResponse(['error' => 'Acesso restrito'], 403);
    $result = $conn->query("SELECT id, name, email, role, status, created_at FROM users ORDER BY created_at DESC");
    $users = [];
    while ($row = $result->fetch_assoc()) $users[] = $row;
    jsonResponse($users);
}

// ---- POST (criar) ----
if ($method === 'POST') {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin pode criar usuários'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $name   = trim($body['name'] ?? '');
    $email  = trim($body['email'] ?? '');
    $pass   = $body['password'] ?? '';
    $role   = in_array($body['role'] ?? '', ['admin','user']) ? $body['role'] : 'user';
    $status = in_array($body['status'] ?? '', ['active','inactive']) ? $body['status'] : 'active';

    if (!$name || !$email || !$pass) jsonResponse(['error' => 'Nome, e-mail e senha são obrigatórios'], 400);
    if (strlen($pass) < 6) jsonResponse(['error' => 'Senha mínima: 6 caracteres'], 400);

    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?,?,?,?,?)");
    $stmt->bind_param('sssss', $name, $email, $hash, $role, $status);

    if ($stmt->execute()) {
        jsonResponse(['success' => true, 'id' => $conn->insert_id]);
    } else {
        jsonResponse(['error' => 'E-mail já cadastrado'], 409);
    }
}

// ---- PUT (atualizar) ----
if ($method === 'PUT' && $id) {
    // Admin edita qualquer um; usuário só edita a si mesmo (sem trocar role)
    if (!$isAdmin && $id !== intval($_SESSION['user_id'])) jsonResponse(['error' => 'Acesso negado'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $name   = trim($body['name'] ?? '');
    $email  = trim($body['email'] ?? '');
    $role   = $isAdmin && isset($body['role']) ? $body['role'] : null;
    $status = $isAdmin && isset($body['status']) ? $body['status'] : null;
    $pass   = $body['password'] ?? '';

    if (!$name || !$email) jsonResponse(['error' => 'Nome e e-mail são obrigatórios'], 400);

    if ($pass) {
        if (strlen($pass) < 6) jsonResponse(['error' => 'Senha mínima: 6 caracteres'], 400);
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, password_hash=? WHERE id=?");
        $stmt->bind_param('sssi', $name, $email, $hash, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
        $stmt->bind_param('ssi', $name, $email, $id);
    }
    $stmt->execute();

    if ($isAdmin && $role) {
        $stmt2 = $conn->prepare("UPDATE users SET role=?, status=? WHERE id=?");
        $stmt2->bind_param('ssi', $role, $status, $id);
        $stmt2->execute();
    }

    jsonResponse(['success' => true]);
}

// ---- DELETE ----
if ($method === 'DELETE' && $id) {
    if (!$isAdmin) jsonResponse(['error' => 'Somente admin pode excluir'], 403);
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    jsonResponse(['success' => true, 'deleted' => $stmt->affected_rows]);
}

$conn->close();
jsonResponse(['error' => 'Método não suportado'], 405);

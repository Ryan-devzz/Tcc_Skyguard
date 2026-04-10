<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once 'db.php';
setHeaders();
session_start();

$action = $_GET['action'] ?? '';

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $body = json_decode(file_get_contents('php://input'), true);

    $email = trim($body['email'] ?? '');
    $pass  = $body['password'] ?? '';

    if (!$email || !$pass) {
        jsonResponse(['error' => 'E-mail e senha são obrigatórios'], 400);
    }

    $conn = getConnection();

    $stmt = $conn->prepare("SELECT id, name, email, password_hash, role, status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();
    $conn->close();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        jsonResponse(['error' => 'E-mail ou senha incorretos'], 401);
    }

    if ($user['status'] !== 'active') {
        jsonResponse(['error' => 'Conta desativada'], 403);
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role'];

    jsonResponse([
        'success' => true,
        'user' => $user
    ]);
}

if ($action === 'logout') {
    session_destroy();
    jsonResponse(['success' => true]);
}

if ($action === 'me') {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['authenticated' => false], 401);
    }

    jsonResponse([
        'authenticated' => true,
        'user' => [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['name'],
            'email' => $_SESSION['email'],
            'role' => $_SESSION['role']
        ]
    ]);
}

jsonResponse(['error' => 'Ação inválida'], 400);

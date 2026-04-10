<?php
/* ============================================================
   SKYGUARD — contact.php
   Recebe mensagens do formulário de contato e envia por e-mail

   POST /api/contact.php
   Body JSON: { subject, message }

   DEPENDÊNCIA: PHPMailer
   Instale via Composer na raiz do projeto:
     composer require phpmailer/phpmailer

   Depois ajuste as configurações SMTP abaixo.
   ============================================================ */

require_once 'db.php';
require_once 'config.php';
setHeaders();

// ── Só aceita POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método não suportado'], 405);
}

// ── Lê o body ─────────────────────────────────────────────────
$body    = json_decode(file_get_contents('php://input'), true);
$subject = trim($body['subject'] ?? '');
$message = trim($body['message'] ?? '');

// ── Autenticação via dados enviados pelo front-end ────────────
// O site usa sessionStorage no JS (não $_SESSION PHP).
// O front envia o e-mail do usuário logado no body para identificação.
$senderEmail = trim($body['sender_email'] ?? '');
$senderName  = trim($body['sender_name']  ?? 'Usuário SkyGuard');

// Valida o e-mail do remetente consultando o banco
if ($senderEmail) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT name, email FROM users WHERE email = ? AND status = 'active'");
    $stmt->bind_param('s', $senderEmail);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user) {
        $senderName  = $user['name'];
        $senderEmail = $user['email'];
    } else {
        jsonResponse(['error' => 'Usuário não encontrado. Faça login novamente.'], 401);
    }
} else {
    jsonResponse(['error' => 'Não autenticado. Faça login para enviar mensagens.'], 401);
}

if (!$subject || !$message) {
    jsonResponse(['error' => 'Assunto e mensagem são obrigatórios'], 400);
}

// ============================================================
//  ▶ CONFIGURAÇÕES DE E-MAIL — DEFINIDAS EM config.php
// ============================================================

// ── Carrega PHPMailer ─────────────────────────────────────────
// O Composer gera este autoload após "composer require phpmailer/phpmailer"
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    // Fallback: tenta mail() nativo se PHPMailer não estiver instalado
    enviarComMailNativo($senderName, $senderEmail, $subject, $message);
} else {
    require_once $autoload;
    enviarComPHPMailer($senderName, $senderEmail, $subject, $message);
}

// ============================================================
//  ENVIO COM PHPMAILER (recomendado)
// ============================================================
function enviarComPHPMailer($senderName, $senderEmail, $subject, $message) {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // Servidor SMTP
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        // Remetente e destinatário
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress(MAIL_RECIPIENT);
        $mail->addReplyTo($senderEmail, $senderName); // Responder vai para o usuário

        // Conteúdo
        $mail->isHTML(true);
        $mail->Subject = '[SkyGuard] ' . $subject;
        $mail->Body    = gerarHTMLEmail($senderName, $senderEmail, $subject, $message);
        $mail->AltBody = gerarTextoPlano($senderName, $senderEmail, $subject, $message);

        $mail->send();
        jsonResponse(['success' => true, 'method' => 'phpmailer']);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Falha ao enviar e-mail: ' . $mail->ErrorInfo], 500);
    }
}

// ============================================================
//  ENVIO COM MAIL() NATIVO (fallback sem PHPMailer)
// ============================================================
function enviarComMailNativo($senderName, $senderEmail, $subject, $message) {
    $to      = MAIL_RECIPIENT;
    $headers = implode("\r\n", [
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_USERNAME . '>',
        'Reply-To: ' . $senderName . ' <' . $senderEmail . '>',
        'Content-Type: text/html; charset=UTF-8',
        'MIME-Version: 1.0',
    ]);

    $body = gerarHTMLEmail($senderName, $senderEmail, $subject, $message);
    $ok   = mail($to, '[SkyGuard] ' . $subject, $body, $headers);

    if ($ok) {
        jsonResponse(['success' => true, 'method' => 'mail']);
    } else {
        jsonResponse(['error' => 'Falha ao enviar via mail(). Configure PHPMailer para maior confiabilidade.'], 500);
    }
}

// ============================================================
//  TEMPLATE HTML DO E-MAIL
// ============================================================
function gerarHTMLEmail($nome, $email, $assunto, $mensagem) {
    $mensagemEsc = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
    $nomeEsc     = htmlspecialchars($nome,    ENT_QUOTES, 'UTF-8');
    $emailEsc    = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
    $assuntoEsc  = htmlspecialchars($assunto, ENT_QUOTES, 'UTF-8');
    $dataHora    = date('d/m/Y \à\s H:i');

    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#0a0f1a;font-family:'Segoe UI',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0f1a;padding:32px 16px;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">

        <!-- HEADER -->
        <tr>
          <td style="background:#0d1526;border-radius:12px 12px 0 0;padding:28px 32px;
                     border-bottom:1px solid #1e2d45;text-align:center;">
            <div style="font-family:Georgia,serif;font-size:26px;font-weight:800;
                        color:#38bdf8;letter-spacing:1px;">SkyGuard</div>
            <div style="font-size:11px;color:#64748b;letter-spacing:3px;
                        text-transform:uppercase;margin-top:4px;">Air Quality System</div>
          </td>
        </tr>

        <!-- BODY -->
        <tr>
          <td style="background:#0f1a2e;padding:32px;">

            <div style="font-size:13px;color:#38bdf8;text-transform:uppercase;
                        letter-spacing:2px;font-weight:600;margin-bottom:6px;">
              Nova mensagem de suporte
            </div>
            <div style="font-size:22px;font-weight:700;color:#e8f0fe;margin-bottom:24px;">
              {$assuntoEsc}
            </div>

            <!-- Remetente -->
            <table width="100%" cellpadding="0" cellspacing="0"
                   style="background:#0d1526;border:1px solid #1e2d45;
                          border-radius:8px;margin-bottom:20px;">
              <tr>
                <td style="padding:16px 20px;">
                  <div style="font-size:11px;color:#64748b;text-transform:uppercase;
                              letter-spacing:1px;margin-bottom:8px;">Enviado por</div>
                  <div style="font-size:15px;font-weight:600;color:#e8f0fe;">{$nomeEsc}</div>
                  <div style="font-size:13px;color:#38bdf8;margin-top:3px;">
                    <a href="mailto:{$emailEsc}" style="color:#38bdf8;text-decoration:none;">{$emailEsc}</a>
                  </div>
                </td>
              </tr>
            </table>

            <!-- Mensagem -->
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;
                        letter-spacing:1px;margin-bottom:10px;">Mensagem</div>
            <div style="background:#0d1526;border:1px solid #1e2d45;border-left:3px solid #38bdf8;
                        border-radius:0 8px 8px 0;padding:20px;
                        font-size:14px;color:#cbd5e1;line-height:1.7;">
              {$mensagemEsc}
            </div>

          </td>
        </tr>

        <!-- FOOTER -->
        <tr>
          <td style="background:#0a0f1a;border-top:1px solid #1e2d45;
                     border-radius:0 0 12px 12px;padding:18px 32px;text-align:center;">
            <div style="font-size:11px;color:#334155;">
              Recebido em {$dataHora} · SkyGuard Air Quality System
            </div>
            <div style="font-size:11px;color:#334155;margin-top:4px;">
              Para responder, use o botão "Responder" — a mensagem irá diretamente para {$emailEsc}
            </div>
          </td>
        </tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ============================================================
//  VERSÃO TEXTO PLANO (para clientes sem HTML)
// ============================================================
function gerarTextoPlano($nome, $email, $assunto, $mensagem) {
    $data = date('d/m/Y \à\s H:i');
    return "Nova mensagem via SkyGuard\n"
         . "==========================\n\n"
         . "Assunto : {$assunto}\n"
         . "De      : {$nome} <{$email}>\n"
         . "Data    : {$data}\n\n"
         . "Mensagem:\n{$mensagem}\n\n"
         . "--\nSkyGuard Air Quality System";
}

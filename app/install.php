<?php
$ip = $_SERVER["HTTP_HOST"];
$siteUrl = "https://" . $ip . "/login.html";
$certUrl = "https://" . $ip . "/skyguard.crt";
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SkyGuard — Instalar</title>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #0a0f1a; color: #e8f0fe; font-family: "Segoe UI", sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .container { max-width: 480px; width: 100%; text-align: center; }
    .logo { font-size: 32px; font-weight: 800; color: #38bdf8; letter-spacing: 1px; margin-bottom: 4px; }
    .sub { font-size: 12px; color: #64748b; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 40px; }
    .card { background: #0d1526; border: 1px solid #1e2d45; border-radius: 16px; padding: 32px; margin-bottom: 16px; }
    .card-title { font-size: 14px; font-weight: 600; color: #38bdf8; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 20px; }
    .qr-box { background: #fff; border-radius: 12px; padding: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px auto; width: fit-content; }
    .url { font-size: 13px; color: #64748b; word-break: break-all; margin-bottom: 20px; }
    .btn { display: block; width: 100%; padding: 14px; border-radius: 10px; font-size: 15px; font-weight: 600; text-decoration: none; margin-bottom: 12px; cursor: pointer; border: none; }
    .btn-primary { background: #38bdf8; color: #0a0f1a; }
    .btn-secondary { background: transparent; border: 1px solid #1e2d45; color: #e8f0fe; }
    .steps { text-align: left; }
    .step { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 16px; }
    .step-num { background: #38bdf8; color: #0a0f1a; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; flex-shrink: 0; }
    .step-text { font-size: 14px; color: #cbd5e1; line-height: 1.5; }
    .step-text strong { color: #e8f0fe; }
    .badge { display: inline-block; background: #0f2a1a; border: 1px solid #34d399; color: #34d399; padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-bottom: 16px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="logo">SkyGuard</div>
    <div class="sub">Air Quality System</div>

    <!-- QR DO SITE -->
    <div class="card">
      <div class="card-title">📱 Acesse o Sistema</div>
      <div class="qr-box">
        <div id="qr-site"></div>
      </div>
      <div class="url"><?= $siteUrl ?></div>
      <a href="<?= $siteUrl ?>" class="btn btn-primary">🚀 Abrir SkyGuard</a>
    </div>

    <!-- PASSOS DE INSTALAÇÃO -->
    <div class="card">
      <div class="card-title">⚙️ Instalar no Celular (Android)</div>
      <div class="steps">
        <div class="step">
          <div class="step-num">1</div>
          <div class="step-text">Baixe e instale o certificado de segurança:<br>
            <a href="<?= $certUrl ?>" class="btn btn-secondary" style="margin-top:8px;font-size:13px;padding:10px;">⬇️ Baixar Certificado</a>
            <br>Depois vá em <strong>Configurações → Segurança → Instalar certificado → Certificado CA</strong>
          </div>
        </div>
        <div class="step">
          <div class="step-num">2</div>
          <div class="step-text">Aponte a câmera para o QR code acima ou toque em <strong>"Abrir SkyGuard"</strong></div>
        </div>
        <div class="step">
          <div class="step-num">3</div>
          <div class="step-text">No Chrome, toque nos <strong>3 pontinhos</strong> e selecione <strong>"Adicionar à tela inicial"</strong></div>
        </div>
        <div class="step">
          <div class="step-num">4</div>
          <div class="step-text">Pronto! O SkyGuard aparece como app na sua tela inicial 🎉</div>
        </div>
      </div>
    </div>

    <!-- QR DO CERTIFICADO -->
    <div class="card">
      <div class="card-title">🔒 QR do Certificado</div>
      <div class="badge">✓ Necessário apenas uma vez</div>
      <div class="qr-box">
        <div id="qr-cert"></div>
      </div>
      <div class="url"><?= $certUrl ?></div>
    </div>

  </div>

  <script>
    new QRCode(document.getElementById("qr-site"), {
      text: "<?= $siteUrl ?>",
      width: 180, height: 180,
      colorDark: "#0a0f1a", colorLight: "#ffffff"
    });
    new QRCode(document.getElementById("qr-cert"), {
      text: "<?= $certUrl ?>",
      width: 160, height: 160,
      colorDark: "#0a0f1a", colorLight: "#ffffff"
    });
  </script>
</body>
</html>
<?php
// ─── Carregar PHPMailer ─────────────────────────────────────────────────────
require_once dirname(__DIR__) . '/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/phpmailer/src/SMTP.php';
require_once dirname(__DIR__) . '/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ─── Função de Log ───────────────────────────────────────────────────────────
function log_webhook($msg) {
    $logFile = __DIR__ . '/debug.log';
    $time = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
}

// ─── Carregar .env ────────────────────────────────────────────────────────────
 $envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || 
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        
        $_ENV[$key] = $value;
    }
}

// ─── Configurações ────────────────────────────────────────────────────────────
 $eupagoApiKey   = $_ENV['EUPAGO_API_KEY'] ?? '';
 $eupagoEndpoint = 'https://clientes.eupago.pt';

 $txDir = __DIR__ . '/tx_data';
if (!is_dir($txDir)) mkdir($txDir, 0777, true);

// ─── FUNÇÃO DE ENVIO DE EMAIL (ATUALIZADA) ────────────────────────────────────
function enviar_emails_pagamento($dados) {
    $mail = new PHPMailer(true);
    
    $smtpHost = $_ENV['SMTP_HOST'] ?? '';
    $smtpPort = $_ENV['SMTP_PORT'] ?? 587;
    $smtpUser = $_ENV['SMTP_USER'] ?? '';
    $smtpPass = $_ENV['SMTP_PASS'] ?? '';
    
    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        log_webhook("ERRO EMAIL: Configurações SMTP incompletas no .env");
        return false;
    }

    // Formatar valor para exibição (ex: 10,00)
    $valor_display = number_format((float)($dados['valor'] ?? 0), 2, ',', '.');

    try {
        $mail->isSMTP();
        $mail->Host       = $smtpHost;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtpUser;
        $mail->Password   = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort;
        $mail->CharSet    = 'UTF-8';

        // Remetente atualizado
        $mail->setFrom($smtpUser, 'Loja');

        // --- EMAIL 1: Para o Cliente ---
        $mail->addAddress($dados['email'], $dados['nome']);
        $mail->isHTML(true);
        
        // Assunto Cliente atualizado
        $mail->Subject = 'Pagamento Confirmado | ' . $valor_display . '€';
        
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <h2 style='color: #c8102e; text-align: center;'>Pagamento Confirmado</h2>
                <p>Olá <strong>{$dados['nome']}</strong>,</p>
                <p>O seu pagamento de <strong>€ {$valor_display}</strong> foi recebido com sucesso.</p>
                <p><strong>ID da Transação:</strong> {$dados['id']}</p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #888; text-align: center;'>Obrigado por escolher a Loja.</p>
            </div>
        ";
        $mail->send();
        
        $mail->clearAddresses();

        // --- EMAIL 2: Para a Loja (Admin) ---
        $mail->addAddress('admin@seudominio.pt', 'Admin');
        
        // Assunto Admin atualizado
        $mail->Subject = 'Recebido | MBWAY ' . $valor_display . '€ | ' . $dados['nome'];
        
        $mail->Body    = "
            <div style='font-family: Arial, sans-serif;'>
                <h3>Novo Pagamento Recebido</h3>
                <ul>
                    <li><strong>Cliente:</strong> {$dados['nome']}</li>
                    <li><strong>Email:</strong> {$dados['email']}</li>
                    <li><strong>Telefone:</strong> {$dados['telefone']}</li>
                    <li><strong>Valor:</strong> € {$valor_display}</li>
                    <li><strong>ID Transação:</strong> {$dados['id']}</li>
                </ul>
            </div>
        ";
        $mail->send();
        
        log_webhook("SUCESSO EMAIL: Emails enviados para " . $dados['email'] . " e admin.");
        return true;

    } catch (Exception $e) {
        log_webhook("ERRO EMAIL: Falha ao enviar. Erro: {$mail->ErrorInfo}");
        return false;
    }
}

// ─── 1. HANDLERS DE CALLBACK ──────────────────────────────────────────────────

 $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
 $inputRaw = file_get_contents('php://input');

// A. Webhook 2.0 (JSON)
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode($inputRaw, true);
    
    if (isset($data['transaction'])) {
        $tx = $data['transaction'];
        $ref = $tx['reference'] ?? '';
        $status = strtolower($tx['status'] ?? '');
        
        if ($ref && $status === 'paid') {
            $mapFile = $txDir . '/map_ref_' . $ref . '.json';
            if (file_exists($mapFile)) {
                $mapData = json_decode(file_get_contents($mapFile), true);
                $originalId = $mapData['id'] ?? '';
                
                if ($originalId) {
                    $file = $txDir . '/' . $originalId . '.json';
                    if (file_exists($file)) {
                        $currentData = json_decode(file_get_contents($file), true);
                        
                        if ($currentData['status'] === 'pending') {
                            $currentData['status'] = 'paid';
                            $currentData['timestamp'] = time();
                            file_put_contents($file, json_encode($currentData));
                            
                            log_webhook("SUCESSO: Pagamento atualizado via Webhook para ID $originalId");
                            
                            enviar_emails_pagamento([
                                'nome'     => $currentData['customer_name'] ?? 'Cliente',
                                'email'    => $currentData['customer_email'] ?? '',
                                'telefone' => $currentData['customer_phone'] ?? '',
                                'valor'    => $currentData['amount'] ?? '0.00',
                                'id'       => $originalId
                            ]);
                        }
                    }
                }
            }
        }
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }
}

// B. Redirect do Browser (Backup)
if (isset($_GET['eupago_confirm']) && isset($_GET['tid'])) {
    $tid = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['tid']);
    if ($tid) {
        $file = $txDir . '/' . $tid . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            
            if ($data['status'] === 'pending') {
                $data['status'] = 'paid';
                $data['timestamp'] = time();
                file_put_contents($file, json_encode($data));
                
                enviar_emails_pagamento([
                    'nome'     => $data['customer_name'] ?? 'Cliente',
                    'email'    => $data['customer_email'] ?? '',
                    'telefone' => $data['customer_phone'] ?? '',
                    'valor'    => $data['amount'] ?? '0.00',
                    'id'       => $tid
                ]);
            }
        }
    }
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ─── 2. VERIFICAÇÃO DE ESTADO (AJAX) ─────────────────────────────────────────
if (isset($_GET['check_status']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['id']);
    $file = $txDir . '/' . $id . '.json';
    
    if (file_exists($file)) {
        $tx = json_decode(file_get_contents($file), true);
        echo json_encode(['status' => $tx['status'] ?? 'pending']);
    } else {
        echo json_encode(['status' => 'pending']);
    }
    exit;
}

// ─── Valor fixo via URL (/5.90) ──────────────────────────────────────────────
$valorFixo = null;
if (isset($_GET['valor'])) {
    $vRaw = $_GET['valor'];
    if (preg_match('/^\d+\.\d{1,2}$/', $vRaw) && (float)$vRaw > 0) {
        $valorFixo = number_format((float)$vRaw, 2, '.', '');
    }
}

// ─── 3. PROCESSAMENTO DO FORMULÁRIO ───────────────────────────────────────────
 $erro               = '';
 $sucesso            = false;
 $resultadoPagamento = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['metodo'])) {
    $nome     = trim($_POST['nome']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $valor    = $valorFixo ?? trim($_POST['valor'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    $tel = preg_replace('/[^\d]/', '', $telefone);
    if (str_starts_with($tel, '351') && strlen($tel) > 9) {
        $tel = substr($tel, 3);
    }

    if (!$eupagoApiKey) {
        $erro = 'Chave de API não encontrada no .env';
    } elseif (!$nome) {
        $erro = 'Por favor introduza o seu nome.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Por favor introduza um email válido.';
    } elseif (!is_numeric($valor) || (float)$valor <= 0) {
        $erro = 'Por favor introduza um valor válido.';
    } elseif (!$telefone) {
        $erro = 'O telefone é obrigatório para pagamentos MB WAY.';
    } elseif (!preg_match('/^9[1236]\d{7}$/', $tel)) {
        $erro = 'Número de telemóvel inválido.';
    } else {
        $valorFormatado = number_format((float)$valor, 2, '.', '');
        $valorNumerico  = (float)$valorFormatado;
        $idPagamento    = 'JP' . time();

        $startTime = time();
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $currentUrl = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
        $successUrl = $currentUrl . '?eupago_confirm=1&tid=' . $idPagamento;

        $url = $eupagoEndpoint . '/api/v1.02/mbway/create';
        $payload = [
            "payment" => [
                "amount"        => ["value" => $valorNumerico, "currency" => "EUR"],
                "transactionId" => $idPagamento,
                "alias"         => '351#' . $tel,
                "customerPhone" => $tel,
                "customer"      => ["name" => $nome, "email" => $email, "phone" => $tel],
                "successUrl" => $successUrl,
                "cancelUrl"  => $currentUrl,
                "errorUrl"   => $currentUrl,
            ]
        ];

        $jsonData = json_encode($payload);
        $headers  = [
            'Content-Type: application/json', 
            'Accept: application/json', 
            'Authorization: ApiKey ' . $eupagoApiKey
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $erro = 'Erro de conexão: ' . curl_error($ch);
        } else {
            $resultado = json_decode($response, true);
            $isSuccess = ($httpCode >= 200 && $httpCode < 300) && 
                         (isset($resultado['transactionStatus']) && $resultado['transactionStatus'] === 'Success');

            if ($isSuccess) {
                $sucesso = true;
                
                $refRetornada = $resultado['reference'] ?? '';

                $fileData = [
                    'status'        => 'pending', 
                    'start_time'    => $startTime,
                    'reference'     => $refRetornada,
                    'customer_name' => $nome,
                    'customer_email'=> $email,
                    'customer_phone'=> '351' . $tel,
                    'amount'        => $valorFormatado
                ];
                file_put_contents($txDir . '/' . $idPagamento . '.json', json_encode($fileData));

                if ($refRetornada) {
                    file_put_contents($txDir . '/map_ref_' . $refRetornada . '.json', json_encode(['id' => $idPagamento]));
                }

                $resultadoPagamento = [
                    'transaction' => $resultado['transactionID'] ?? '',
                    'reference'   => $refRetornada,
                    'amount'      => $valorFormatado,
                    'id'          => $idPagamento
                ];
            } else {
                $erro = $resultado['text'] ?? $resultado['message'] ?? "Erro HTTP $httpCode";
            }
        }
        curl_close($ch);
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Loja - Pagamento MB WAY</title>
  <link rel="icon" type="image/png" href="/assets/logo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root { --ink: #0f0f0f; --ink-2: #4a4a4a; --ink-3: #8a8a8a; --paper: #f7f5f0; --white: #ffffff; --accent: #c8102e; --border: #e0ddd8; --radius: 10px; --mono: 'DM Mono', monospace; --sans: 'Sora', sans-serif; }
    body { font-family: var(--sans); background: var(--paper); color: var(--ink); min-height: 100vh; display: grid; grid-template-rows: auto 1fr auto; }
    header { padding: 20px 40px; border-bottom: 1px solid var(--border); background: var(--white); display: flex; align-items: center; }
    .header-logo img { height: 48px; }
    main { display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 16px; padding: 48px; width: 100%; max-width: 460px; box-shadow: 0 4px 40px rgba(0,0,0,0.06); animation: fadeUp 0.4s ease both; }
    .card-header { margin-bottom: 28px; text-align: center; }
    .card-title { font-size: 26px; font-weight: 600; letter-spacing: -0.03em; color: var(--ink); }
    .field { margin-bottom: 20px; }
    label { display: block; font-size: 12px; font-weight: 500; text-transform: uppercase; color: var(--ink-3); margin-bottom: 8px; }
    input { width: 100%; padding: 13px 16px; border: 1.5px solid var(--border); border-radius: var(--radius); font-family: var(--sans); font-size: 15px; background: var(--white); transition: border-color 0.2s; }
    input:focus { border-color: var(--ink); outline: none; }
    .phone-wrap { display: flex; border: 1.5px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .phone-wrap:focus-within { border-color: var(--ink); }
    .phone-prefix { padding: 13px 14px; background: var(--paper); border-right: 1.5px solid var(--border); color: var(--ink-2); font-family: var(--mono); font-size: 14px; display: flex; align-items: center; }
    .phone-wrap input { border: none; padding: 13px 14px; flex: 1; }
    .input-wrap { position: relative; }
    .input-prefix { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--ink-3); pointer-events: none; }
    .input-wrap input { padding-left: 34px; }
    .erro-msg, .sucesso-msg { padding: 20px; border-radius: var(--radius); margin-bottom: 24px; text-align: center; }
    .erro-msg { background: #fff5f5; border: 1px solid #fecaca; color: #991b1b; text-align: left; }
    .sucesso-msg { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
    
    .timer-container { margin: 24px 0; text-align: center; }
    .timer-circle { width: 120px; height: 120px; border-radius: 50%; background: white; border: 6px solid var(--accent); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; box-shadow: 0 4px 15px rgba(200, 16, 46, 0.2); }
    .timer-value { font-family: var(--mono); font-size: 32px; font-weight: 600; }
    .status-text { font-size: 14px; color: var(--ink-2); }
    
    .payment-success .timer-circle { border-color: #16a34a; background: #f0fdf4; animation: pulse 2s infinite; }
    .payment-success .timer-value { color: #16a34a; font-size: 18px; }
    @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0.4); } 70% { box-shadow: 0 0 0 20px rgba(22, 163, 74, 0); } 100% { box-shadow: 0 0 0 0 rgba(22, 163, 74, 0); } }
    
    .btn { width: 100%; padding: 15px 24px; background: var(--ink); color: var(--white); border: none; border-radius: var(--radius); font-size: 15px; font-weight: 500; cursor: pointer; transition: background 0.2s; }
    .btn:hover { background: #2a2a2a; }
    .detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed rgba(0,0,0,0.1); font-family: var(--mono); font-size: 14px; }
    footer { padding: 24px 40px; border-top: 1px solid var(--border); font-size: 12px; color: var(--ink-3); text-align: center; background: var(--white); }
  </style>
</head>
<body>

<header>
  <a href="/" class="header-logo" title="LojaShop">
    <img src="/assets/logo.png" alt="Logo">
  </a>
</header>

<main>
  <div class="card">

    <?php if (!$sucesso): ?>
      <form method="POST" novalidate id="paymentForm">
        <div class="card-header"><h1 class="card-title">Pagamento MB WAY</h1></div>

        <?php if ($erro): ?>
          <div class="erro-msg"><?= $erro ?></div>
        <?php endif; ?>

        <div class="field">
          <label for="nome">Nome completo</label>
          <input type="text" id="nome" name="nome" placeholder="João Silva" required value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
        </div>

        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="joao@exemplo.pt" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="field">
          <label for="telefone">Telemóvel</label>
          <div class="phone-wrap">
            <span class="phone-prefix">🇵🇹 +351</span>
            <input type="tel" id="telefone" name="telefone" placeholder="912 345 678" maxlength="9" inputmode="numeric" value="<?= htmlspecialchars($_POST['telefone'] ?? '') ?>">
          </div>
        </div>

        <div class="field">
          <label for="valor">Valor a pagar</label>
          <div class="input-wrap">
            <span class="input-prefix">€</span>
            <?php if ($valorFixo): ?>
              <input type="number" id="valor" name="valor" min="0.01" step="0.01" required
                value="<?= htmlspecialchars($valorFixo) ?>"
                readonly style="background:#f3f4f6; color:#6b7280; cursor:not-allowed;">
            <?php else: ?>
              <input type="number" id="valor" name="valor" placeholder="0.00" min="0.01" step="0.01" required value="<?= htmlspecialchars($_POST['valor'] ?? '') ?>">
            <?php endif; ?>
          </div>
        </div>

        <button type="submit" class="btn">Gerar Pagamento</button>
      </form>

    <?php else: ?>
      <div id="payment-status-container">
        <div class="card-header">
          <h1 class="card-title">Verifique o seu Telemóvel</h1>
        </div>

        <div class="sucesso-msg" id="info-msg">
          <p>Pedido enviado para:</p>
          <strong style="font-size: 18px; display:block; margin: 10px 0;">351 <?= htmlspecialchars(substr(preg_replace('/[^\d]/', '', $_POST['telefone']), -9)) ?></strong>
          <p style="font-size: 13px; color: #666;">Transação: <?= htmlspecialchars($resultadoPagamento['transaction']) ?></p>
        </div>

        <div class="timer-container" id="timer-box">
          <div class="timer-circle">
            <span class="timer-value" id="timer">04:00</span>
          </div>
          <div class="status-text" id="status-text">A aguardar autorização...</div>
        </div>

        <div class="detail-row" style="margin-top:12px; padding-top:12px; border-top-color:#d1d5db;">
          <span>Valor:</span> <span class="detail-val">€ <?= htmlspecialchars($resultadoPagamento['amount']) ?></span>
        </div>
      </div>
    <?php endif; ?>

  </div>
</main>

<footer>
  <span>&copy; <?= date('Y') ?> Loja &nbsp;·&nbsp; Seguro via Eupago</span>
</footer>

<?php if ($sucesso): ?>
<script>
  const transactionId = "<?= $resultadoPagamento['id'] ?>";
  const startTimeServer = <?= $startTime ?? 'null' ?>;
  const timerDuration = 4 * 60;
  
  const display = document.getElementById('timer');
  const container = document.getElementById('payment-status-container');
  const statusText = document.getElementById('status-text');
  const timerValue = document.getElementById('timer');
  const infoMsg = document.getElementById('info-msg');
  
  let timerInterval;
  let checkInterval;

  function updateTimer() {
      if (!startTimeServer) return;
      
      const now = Math.floor(Date.now() / 1000);
      const elapsed = now - startTimeServer;
      let remaining = timerDuration - elapsed;

      if (remaining <= 0) {
          clearInterval(timerInterval);
          clearInterval(checkInterval);
          statusText.textContent = "Tempo expirado. Tente novamente.";
          statusText.style.color = "#c8102e";
          display.textContent = "00:00";
          return;
      }

      let minutes = parseInt(remaining / 60, 10);
      let seconds = parseInt(remaining % 60, 10);
      minutes = minutes < 10 ? "0" + minutes : minutes;
      seconds = seconds < 10 ? "0" + seconds : seconds;
      display.textContent = minutes + ":" + seconds;
  }

  timerInterval = setInterval(updateTimer, 1000);
  updateTimer();

  checkInterval = setInterval(() => {
    fetch('?check_status=1&id=' + transactionId)
      .then(response => response.json())
      .then(data => {
        if (data.status === 'paid') {
          clearInterval(timerInterval);
          clearInterval(checkInterval);
          
          container.classList.add('payment-success');
          timerValue.innerHTML = '✓<br>Pago';
          statusText.textContent = "Pagamento confirmado com sucesso!";
          statusText.style.color = "#16a34a";
          infoMsg.style.background = "#dcfce7";
          infoMsg.style.borderColor = "#86efac";
          infoMsg.innerHTML = "<strong style='font-size:18px; color:#14532d'>Pagamento Confirmado!</strong>";
        }
      });
  }, 3000);
</script>
<?php endif; ?>

</body>
</html>
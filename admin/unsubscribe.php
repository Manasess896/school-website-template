<?php

session_start();
require_once '../vendor/autoload.php';

use MongoDB\Client;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

const CODE_EXPIRY_SECONDS = 600;
const MAX_CODE_ATTEMPTS   = 5;
const RESEND_COOLDOWN_SEC = 60;
$appEnv = strtolower($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? '');
$isDev = in_array($appEnv, ['local', 'dev', 'development']);

try {
  $url = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $dbName = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  if (!$url || !$dbName) throw new Exception('Missing DB env vars');
  $client = new Client($url);
  $collection = $client->selectCollection($dbName, 'newsletter');
} catch (Exception $e) {
  http_response_code(500);
  if ($isDev) {
    die('Database connection failed: ' . $e->getMessage());
  }
  die('Database connection failed.');
}


if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

function json_out($ok, $message, $extra = [])
{
  echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
  exit;
}

function dev_error($public, $e, $isDev)
{
  if ($isDev && $e instanceof Throwable) {
    return $public . ' :: ' . $e->getMessage();
  }
  return $public;
}

function send_code_email($to, $code)
{
  $smtpHost = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?? '';
  $smtpPort = (int)($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?? 587);
  $smtpUser = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME') ?? '';
  $smtpPass = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD') ?? '';
  $smtpSecure = $_ENV['MAIL_ENCRYPTION'] ?? getenv('MAIL_ENCRYPTION') ?? 'tls';
  $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?? $smtpUser;
  $fromName = $_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?? 'Newsletter';
  if (!$smtpHost || !$smtpUser || !$smtpPass || !$fromEmail) throw new Exception('Mail server not configured');
  $mail = new PHPMailer(true);
  $mail->isSMTP();
  $mail->Host = $smtpHost;
  $mail->SMTPAuth = true;
  $mail->Username = $smtpUser;
  $mail->Password = $smtpPass;
  if ($smtpSecure) {
    $mail->SMTPSecure = $smtpSecure;
  }
  $mail->Port = $smtpPort;
  $mail->CharSet = 'UTF-8';
  $mail->setFrom($fromEmail, $fromName);
  $mail->addAddress($to);
  $mail->isHTML(true);
  $mail->Subject = 'Your Unsubscribe Code';
  $mail->Body = '<p>Your code to unsubscribe is: <strong style="font-size:22px;">' . htmlspecialchars($code) . '</strong></p><p>This code expires in 10 minutes.</p>';
  $mail->AltBody = 'Your unsubscribe code is: ' . $code . ' (expires in 10 minutes)';
  $mail->send();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json');
  $csrf = $_POST['csrf_token'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    json_out(false, 'Security token invalid. Refresh page.');
  }
  $action = $_POST['action'] ?? '';
  $email = trim((string)($_POST['email'] ?? ''));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(false, 'Please enter a valid email.');
  }
  try {
    $subscriber = $collection->findOne(['email' => $email]);
  } catch (Exception $e) {
    json_out(false, dev_error('Database error.', $e, $isDev));
  }

  if ($action === 'request_code') {
    if (!$subscriber) {
      usleep(random_int(10000, 40000));
      json_out(true, 'If the email is subscribed, a code has been sent. Check inbox (and spam).');
    }
    $now = time();
    $lastReq = (int)($subscriber['pending_unsub_requested_at'] ?? 0);
    if ($lastReq && ($now - $lastReq) < RESEND_COOLDOWN_SEC) {
      $wait = RESEND_COOLDOWN_SEC - ($now - $lastReq);
      json_out(false, 'Please wait ' . $wait . 's before requesting another code.');
    }
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = $now + CODE_EXPIRY_SECONDS;
    try {
      $collection->updateOne(['_id' => $subscriber['_id']], [
        '$set' => [
          'pending_unsub_code_hash' => $hash,
          'pending_unsub_expires' => $expiresAt,
          'pending_unsub_attempts' => 0,
          'pending_unsub_requested_at' => $now
        ],
        '$unset' => ['pending_unsub_code' => '']
      ]);
    } catch (Exception $e) {
      json_out(false, dev_error('Server error saving code.', $e, $isDev));
    }
    try {
      send_code_email($email, $code);
    } catch (Exception $e) {
      json_out(false, dev_error('Failed to send email.', $e, $isDev));
    }
    json_out(true, 'If the email is subscribed, a code has been sent. Check inbox (and spam).', ['step' => 'code_sent']);
  }

  if ($action === 'verify_code') {
    $genericFail = 'Invalid or expired code. Request a new one.';
    if (!$subscriber) {
      json_out(false, $genericFail);
    }
    $codeInput = trim((string)($_POST['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $codeInput)) {
      json_out(false, $genericFail);
    }
    $expires = (int)($subscriber['pending_unsub_expires'] ?? 0);
    $attempts = (int)($subscriber['pending_unsub_attempts'] ?? 0);
    $hash = $subscriber['pending_unsub_code_hash'] ?? '';
    $legacyPlain = $subscriber['pending_unsub_code'] ?? null;
    if (!$expires || time() > $expires || (!$hash && !$legacyPlain)) {
      json_out(false, $genericFail);
    }
    if ($attempts >= MAX_CODE_ATTEMPTS) {
      json_out(false, 'Too many attempts. Request a new code.');
    }
    $match = false;
    if ($hash && password_verify($codeInput, $hash)) {
      $match = true;
    } elseif ($legacyPlain && $codeInput === (string)$legacyPlain) {
      $match = true;
    }
    if (!$match) {
      try {
        $collection->updateOne(['_id' => $subscriber['_id']], ['$inc' => ['pending_unsub_attempts' => 1]]);
      } catch (Exception $e) {
      }
      $attempts++;
      if ($attempts >= MAX_CODE_ATTEMPTS) {
        json_out(false, 'Too many attempts. Request a new code.');
      }
      json_out(false, $genericFail);
    }
    try {
      $del = $collection->deleteOne(['_id' => $subscriber['_id']]);
      if ($del->getDeletedCount() === 1) {
        json_out(true, 'You have been unsubscribed successfully.');
      }
      json_out(false, 'Unexpected error removing subscription.');
    } catch (Exception $e) {
      json_out(false, dev_error('Server error while unsubscribing.', $e, $isDev));
    }
  }

  json_out(false, 'Unknown action.');
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Unsubscribe from Newsletter</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
  <div class="container mt-5">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card">
          <div class="card-header">
            <h2>Unsubscribe from our Newsletter</h2>
          </div>
          <div class="card-body">
            <p class="small text-muted">Enter your email to receive a 6-digit code. Then enter the code to unsubscribe.</p>
            <form id="emailForm" autocomplete="off" class="mb-3">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input type="email" class="form-control" id="email" name="email" required>
              </div>
              <button type="submit" class="btn btn-primary w-100" id="sendCodeBtn">Send Code</button>
            </form>
            <form id="codeForm" autocomplete="off" style="display:none;">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="email" id="codeEmailHolder">
              <div class="mb-3">
                <label for="code" class="form-label">6-digit Code</label>
                <input type="text" maxlength="6" pattern="\d{6}" class="form-control" id="code" name="code" required>
                <div class="form-text">Check your email inbox (and spam folder).</div>
              </div>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger flex-grow-1" id="unsubscribeBtn">Unsubscribe</button>
                <button type="button" class="btn btn-secondary" id="resendBtn">Resend</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const emailForm = document.getElementById('emailForm');
    const codeForm = document.getElementById('codeForm');
    const codeEmailHolder = document.getElementById('codeEmailHolder');
    const resendBtn = document.getElementById('resendBtn');

    const ENDPOINT = <?= json_encode($_SERVER['PHP_SELF']); ?>;

    async function postForm(dataObj) {
      const fd = new FormData();
      Object.entries(dataObj).forEach(([k, v]) => fd.append(k, v));
      try {
        const res = await fetch(ENDPOINT, {
          method: 'POST',
          body: fd,
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        const text = await res.text();
        let json;
        try {
          json = JSON.parse(text);
        } catch (e) {
          throw new Error('Invalid JSON (status ' + res.status + '): ' + text.slice(0, 200));
        }
        if (!res.ok) {
          throw new Error('HTTP ' + res.status + ': ' + (json.message || 'Unknown error'));
        }
        return json;
      } catch (err) {
        console.error('Request failed:', err);
        throw err;
      }
    }

    emailForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const email = emailForm.email.value.trim();
      if (!email) {
        Swal.fire('Required', 'Please enter your email.', 'warning');
        return;
      }
      Swal.showLoading();
      postForm({
        action: 'request_code',
        email: email,
        csrf_token: emailForm.querySelector('[name=csrf_token]').value
      }).then(data => {
        if (data.ok) {
          Swal.fire('Code Sent', data.message, 'success');
          codeEmailHolder.value = email;
          emailForm.style.display = 'none';
          codeForm.style.display = '';
          document.getElementById('code').focus();
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      }).catch((err) => Swal.fire('Error', err.message || 'Request failed.', 'error'));
    });

    codeForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const code = codeForm.code.value.trim();
      if (!/^\d{6}$/.test(code)) {
        Swal.fire('Invalid', 'Code must be 6 digits.', 'warning');
        return;
      }
      Swal.showLoading();
      postForm({
        action: 'verify_code',
        email: codeEmailHolder.value,
        code: code,
        csrf_token: codeForm.querySelector('[name=csrf_token]').value
      }).then(data => {
        if (data.ok) {
          Swal.fire('Unsubscribed', data.message, 'success');
          codeForm.querySelectorAll('input,button').forEach(el => el.disabled = true);
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      }).catch((err) => Swal.fire('Error', err.message || 'Request failed.', 'error'));
    });

    resendBtn.addEventListener('click', function() {
      const email = codeEmailHolder.value;
      Swal.showLoading();
      postForm({
        action: 'request_code',
        email: email,
        csrf_token: codeForm.querySelector('[name=csrf_token]').value
      }).then(data => {
        if (data.ok) {
          Swal.fire('Code Re-sent', data.message, 'success');
        } else {
          Swal.fire('Error', data.message, 'error');
        }
      }).catch((err) => Swal.fire('Error', err.message || 'Request failed.', 'error'));
    });
  </script>
</body>

</html>
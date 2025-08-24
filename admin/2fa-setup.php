<?php
session_start();
require_once '../vendor/autoload.php';

use OTPHP\TOTP;
use MongoDB\Client;
use MongoDB\BSON\ObjectId;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// the user must have  came successfully  from login and requires setup
if (empty($_SESSION['2fa_requires_setup']) || empty($_SESSION['2fa_user_id']) || empty($_SESSION['2fa_email'])) {
  header('Location: login');
  exit;
}

$userId = $_SESSION['2fa_user_id'];
$user_email = $_SESSION['2fa_email'];

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
  $url = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($url);
  $collection = $client->selectCollection($mydatabase, 'admin');
  $user = $collection->findOne(['_id' => new ObjectId($userId)]);
  if (!$user) {
    
    session_unset();
    header('Location: login');
    exit;
  }
  // If user  has a 2fa secret  redirect to verify
  if (!empty($user['twofa_secret'])) {
    unset($_SESSION['2fa_requires_setup']);
    $_SESSION['2fa_id'] = $userId;
    header('Location: 2fa-verify');
    exit;
  }
} catch (Exception $e) {
  die('Failed to connect to the database. Please contact the developer.');
}

if (empty($_SESSION['twofa_setup_secret'])) {
  $totp = TOTP::create();
  $totp->setLabel($user['username'] ?? ($user_email ?: 'User'));
  $totp->setIssuer($_ENV['APP_NAME'] ?? getenv('APP_NAME') ?? 'YourApp');
  $_SESSION['twofa_setup_secret'] = $totp->getSecret();
  $_SESSION['twofa_setup_uri'] = $totp->getProvisioningUri();
}

$secret = $_SESSION['twofa_setup_secret'];
$qrUri = $_SESSION['twofa_setup_uri'];
$qrImg = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrUri);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid CSRF token.');
  }
  $code = trim($_POST['code'] ?? '');
  $verifyTotp = TOTP::create($secret);
  $isValid = $code !== '' && $verifyTotp->verify($code);
  if ($isValid) {
    $collection->updateOne(
      ['_id' => new ObjectId($userId)],
      ['$set' => ['twofa_secret' => $secret]]
    );
    unset($_SESSION['twofa_setup_secret'], $_SESSION['twofa_setup_uri']);
   //now lets redirect to login for fresh login
    
    $_SESSION['2fa_id'] = $userId;
    //remove thes sessions created for 2fa setup
    unset($_SESSION['2fa_requires_setup']);
    unset($_SESSION['2fa_email']);
    unset($_SESSION['2fa_id']);
    unset($_SESSION);
    header('Location: login?2fa?is?setup');
    exit;
  } else {
    $error = 'Invalid code, please try again.';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set up 2FA</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body>
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-8 col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header">
            <h4 class="mb-0">Enable Two‑Factor Authentication</h4>
          </div>
          <div class="card-body">
            <?php if (!isset($success)): ?>
              <p class="mb-3">Scan this QR code with Google Authenticator, Microsoft Authenticator, or any TOTP app. If you prefer, you can enter the secret manually.</p>
              <div class="text-center mb-3">
                <div id="qr-container" class="d-inline-block border rounded p-2" style="width: 220px; height: 220px;">
                  <!-- QR rendered by JS; fallback image below if JS blocked -->
                </div>
                <noscript>
                  <img src="<?php echo htmlspecialchars($qrImg); ?>" alt="2FA QR Code" class="img-fluid border rounded p-2">
                </noscript>
              </div>
              <div class="mb-3">
                <label class="form-label">Manual secret</label>
                <div class="input-group">
                  <input type="text" id="secretInput" class="form-control" value="<?php echo htmlspecialchars($secret); ?>" readonly>
                  <button type="button" id="copySecretBtn" class="btn btn-outline-secondary">
                    <i class="bi bi-clipboard"></i> Copy
                  </button>
                </div>
                <div id="copyFeedback" class="form-text"></div>
              </div>
              <form method="POST" class="mt-3">
                <div class="mb-3">
                  <label for="code" class="form-label">6‑digit code</label>
                  <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="form-control" id="code" name="code" placeholder="Enter code from your app" required>
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                </div>
                <?php if (isset($error)): ?>
                  <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <div class="d-flex gap-2">
                  <button type="submit" class="btn btn-success">Verify & Enable</button>
                  <a href="login" class="btn btn-outline-secondary">Cancel</a>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
    // Render QR code client-side for better reliability
    (function() {
      try {
        var uri = <?php echo json_encode($qrUri); ?>;
        var el = document.getElementById('qr-container');
        if (el && uri) {
          new QRCode(el, {
            text: uri,
            width: 220,
            height: 220,
            correctLevel: QRCode.CorrectLevel.M
          });
        }
      } catch (e) {
        // Fallback
        var el = document.getElementById('qr-container');
        if (el) {
          var img = document.createElement('img');
          img.src = <?php echo json_encode($qrImg); ?>;
          img.alt = '2FA QR Code';
          img.className = 'img-fluid';
          el.innerHTML = '';
          el.appendChild(img);
        }
      }
    })();

    // Copy manual secret to clipboard
    (function() {
      var btn = document.getElementById('copySecretBtn');
      var input = document.getElementById('secretInput');
      var feedback = document.getElementById('copyFeedback');
      if (btn && input) {
        btn.addEventListener('click', async function() {
          try {
            await navigator.clipboard.writeText(input.value);
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied';
            if (feedback) {
              feedback.textContent = 'Secret copied to clipboard.';
            }
            setTimeout(function() {
              btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
              if (feedback) {
                feedback.textContent = '';
              }
            }, 1800);
          } catch (err) {
            // Fallback select/copy
            input.removeAttribute('readonly');
            input.select();
            document.execCommand('copy');
            input.setAttribute('readonly', 'readonly');
            btn.innerHTML = '<i class="bi bi-clipboard-check"></i> Copied';
            if (feedback) {
              feedback.textContent = 'Secret copied.';
            }
            setTimeout(function() {
              btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy';
              if (feedback) {
                feedback.textContent = '';
              }
            }, 1800);
          }
        });
      }
    })();
  </script>
</body>

</html>
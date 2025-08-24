<?php
session_start();
require_once '../vendor/autoload.php';

use OTPHP\TOTP;
use MongoDB\Client;
use MongoDB\BSON\ObjectId;

if (empty($_SESSION['2fa_id']) || empty($_SESSION['2fa_email'])) {
  header('Location: login');
  exit;
}


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
  $url = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($url);
  
  $collection = $client->selectCollection($mydatabase, 'admin');
} catch (Exception $e) {
  die('Failed to connect to the database. Please contact the developer.');
}


$user = $collection->findOne(['_id' => new ObjectId($_SESSION['2fa_id'])]);
if (!$user) {
  session_unset();
  header('Location: login');
  exit;
}
if (empty($user['twofa_secret'])) {
  // force setup if secret missing
  $_SESSION['2fa_requires_setup'] = true;
  $_SESSION['2fa_user_id'] = (string)$user['_id'];
  header('Location: 2fa-setup');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die('Invalid CSRF token.');
  }
  $code = trim($_POST['code'] ?? '');
  $totp = TOTP::create($user['twofa_secret']);
  if ($code !== '' && $totp->verify($code)) {

    session_regenerate_id(true);

    // now we create sessions required for the dashboard 
    $_SESSION['user_id'] = (string)$user['_id'];
    $_SESSION['admin_id'] = (string)$user['_id'];
    $_SESSION['username'] = $user['username'] ?? '';
    $_SESSION['email'] = $user['email'] ?? '';
    $_SESSION['user_role'] = $user['role'] ?? 'admin';
    $_SESSION['is_admin'] = true;
    unset($_SESSION['2fa_id'], $_SESSION['2fa_email']);
    $_SESSION['2fa_passed'] = bin2hex(random_bytes(32));
    header('Location: dashboard');
    exit;
  } else {
    $error = 'Invalid code. Please try again.';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Two‑Factor Verification</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-6">
        <div class="card shadow-sm">
          <div class="card-header">
            <h4 class="mb-0">Two‑Factor Verification</h4>
          </div>
          <div class="card-body">
            <p>Enter the 6‑digit code from your authenticator app to continue.</p>
            <?php if (isset($error)): ?>
              <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
              <div class="mb-3">
                <label for="code" class="form-label">6‑digit code</label>
                <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" class="form-control" id="code" name="code" required>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              </div>
              <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Verify</button>
                <a href="login" class="btn btn-outline-secondary">Back to Login</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
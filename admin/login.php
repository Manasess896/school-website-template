<?php
session_start();
require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use MongoDB\Client;
use Dotenv\Dotenv;

try {
  $url = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($url);
  $db = $client->$mydatabase;

  $collection = $db->admin;
} catch (Exception $e) {
  die('Failed to connect to the database. Please contact the developer.');
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === 'POST') {
  //recaptcha verification 
  $recaptchakey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? getenv('RECAPTCHA_SECRET_KEY');
  $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';
  $verifyUrl = "https://www.google.com/recaptcha/api/siteverify";
  $data = [
    'secret' => $recaptchakey,
    'response' => $recaptchaResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR']
  ];

  $options = [
    "http" => [
      "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
      "method"  => "POST",
      "content" => http_build_query($data)
    ]
  ];
  $context  = stream_context_create($options);
  $verify = @file_get_contents($verifyUrl, false, $context);
  $captchaSuccess = $verify ? json_decode($verify) : null;

  //check if recaptcha verification was successfull if yes continue with form  submission
  if ($captchaSuccess && !empty($captchaSuccess->success)) {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      die('Invalid CSRF token.');
    }

    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = "Invalid email address.";
    } else {

      $user = $collection->findOne([
        'email' => strtolower($email)
      ]);

      if (!$user) {
        $error = "User not found.";
      } else {
        // Account locked?
        if (!empty($user['is_locked'])) {
          $error = "Your account is locked .";
        }
        if (!isset($error) && !password_verify($password, $user['password'])) {
          $error = "Invalid password.";
        }

        if (!isset($error)) {
          session_regenerate_id(true);
          // If user has NO set up 2FA yet, send them to the setup page.
          //twofa-secret is set in mongodb and is a string e.g {
 
  //"twofa_secret": "DGY554HXBPXIRRQYOC5BZRQUZIYUDUMG2H"if this in the database it will prompt a set up this is additional security for the admin panel 

          if (empty($user['twofa_secret'])) {
            $_SESSION['2fa_user_id'] = (string)$user['_id'];
            $_SESSION['2fa_email'] = $user['email'];
            $_SESSION['2fa_requires_setup'] = true; // flag to allow setup page
            header('Location: 2fa-setup');
            //if you dont want to enforce 2fa do this uncomment this piece of code  then comment the above piece of code 
    //           $_SESSION['user_id'] = (string)$user['_id'];
    // $_SESSION['admin_id'] = (string)$user['_id'];
    // $_SESSION['username'] = $user['username'] ?? '';
    // $_SESSION['email'] = $user['email'] ?? '';
    // $_SESSION['user_role'] = $user['role'] ?? 'admin';
    // $_SESSION['is_admin'] = true;
    //header('location:login');
            exit;
          }
          // 2FA secret exists -> go to verification screen
          $_SESSION['2fa_id'] = (string)$user['_id'];
          $_SESSION['2fa_email'] = $user['email'];
          header('Location: 2fa-verify');
          exit;
        }
      }
    }
  } else {
    $error = "Captcha verification failed.";
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Login</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>

<body class="admin-login">
  <div class="login-container">
    <div class="login-card card">
      <div class="card-header">
        <h4>Admin Panel Login</h4>
      </div>
      <div class="card-body">
        <form method="POST">
          <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" class="form-control" id="email" name="email" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" class="form-control" id="password" name="password" required>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
          </div>
          <div class="g-recaptcha" data-sitekey="<?= $_ENV['RECAPTCHA_SITE_KEY'] ?? getenv('RECAPTCHA_SITE_KEY') ?>"></div>
          <button type="submit" name="login" class="btn btn-primary w-100 mt-3">Login</button>
        </form>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <script>
    <?php if (isset($error)): ?>
      Swal.fire({
        icon: 'error',
        title: 'Oops...',
        text: '<?= $error ?>'
      })
    <?php endif; ?>
  </script>
</body>

</html>
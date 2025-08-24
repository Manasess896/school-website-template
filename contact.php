<?php
session_start();
require_once 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

use MongoDB\Client;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
  $url = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($url);
  $collection = $client->selectCollection($mydatabase, 'contacts');
} catch (Exception $e) {
  die('Failed to connect to the database. Please contact the developer.');
}

// csrf token generation
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  //valifate recaptcha first before use
  $recaptchakey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? getenv('RECAPTCHA_SECRET_KEY');
  $recaptchaResponse = $_POST['g-recaptcha-response'];
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
  $verify = file_get_contents($verifyUrl, false, $context);
  $captchaSuccess = json_decode($verify);


  if ($captchaSuccess->success) {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      die('CSRF token validation failed');
    }
    function clean_input($data)
    {
      return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }

    $name = clean_input($_POST['name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $subject = clean_input($_POST['subject'] ?? '');
    $message = clean_input($_POST['message'] ?? '');
    $consent = isset($_POST['consent']);
    $ip = $_SERVER['REMOTE_ADDR'];
    $error = '';

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
      $error = 'All fields are required.';
    } elseif (!$consent) {
      $error = 'You must agree to the privacy policy.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = 'Invalid email address.';
    } elseif (strlen($message) > 1000) {
      $error = 'Message is too long (maximum 1000 characters).';
    } elseif (strlen($subject) > 100) {
      $error = 'Subject is too long (maximum 100 characters).';
    } elseif (strlen($name) > 100) {
      $error = 'Name is too long (maximum 100 characters).';
    }

    if (empty($error)) {
      try {
        $collection->insertOne([
          'name' => $name,
          'email' => strtolower($email),
          'subject' => $subject,
          'message' => $message,
          'ip' => $ip,
          'created_at' => new MongoDB\BSON\UTCDateTime()
        ]);

        $mail = new PHPMailer(true);
        try {

          $mail->isSMTP();
          $mail->Host = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST');
          $mail->SMTPAuth = true;
          $mail->Username = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME');
          $mail->Password = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD');
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
          $mail->Port = $_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT');

          //Recipients
          $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
          $mail->addAddress($email, $name);

          //Content
          $mail->isHTML(true);
          $mail->Subject = 'Thank you for contacting us';
          $mail->Body    = "<p>Hi {$name},</p>
                            <p>Thank you for your message. We have received it and will get back to you shortly.</p>
                            <p><b>Your Subject:</b> {$subject}</p>
                            <p><b>Your Message:</b></p>
                            <p>{$message}</p>
                            <br>
                            <p>Best regards,</p>
                            <p>The Team</p>";

          $mail->send();
          $success = 'Message sent successfully!';
        } catch (Exception $e) {

          $error = 'Message was sent , but failed to send a confirmation email.';
        }
      } catch (Exception $e) {
        $error = 'Failed to send message. Please try again later.';
      }
    }
  } else {
    $error = 'reCAPTCHA verification failed. Please try again.';
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contact Us - Greenfield Academy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.css" rel="stylesheet" />
  <style>
    :root {
      --gf-green: #198754;
      --gf-green-dark: #146c43;
    }

    body {
      background: #f5f8f6;
    }

    .hero-mini {
      background: var(--gf-green);
      color: #fff;
      padding: 3.5rem 0 2.5rem;
      position: relative;
    }

    .hero-mini:after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.06), rgba(0, 0, 0, 0.15));
      pointer-events: none;
    }

    .contact-card {
      border: 0;
      border-radius: 1rem;
      box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .08);
      overflow: hidden;
    }

    .contact-card .card-header {
      background: linear-gradient(135deg, var(--gf-green), var(--gf-green-dark));
      color: #fff;
    }

    .info-item i {
      font-size: 1.25rem;
      width: 2.25rem;
      height: 2.25rem;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--gf-green);
      color: #fff;
      border-radius: 50%;
      margin-right: .75rem;
    }

    .required:after {
      content: ' *';
      color: #dc3545;
    }

    .privacy-hint {
      font-size: .8rem;
      color: #6c757d;
    }

    .form-floating>textarea {
      height: 160px;
    }

    .map-placeholder {
      background: #e2ede7;
      border: 2px dashed var(--gf-green);
      border-radius: .75rem;
      height: 100%;
      min-height: 200px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--gf-green-dark);
      font-weight: 500;
    }

    .footer-lite {
      background: var(--gf-green);
      color: #fff;
    }
  </style>
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="index.php">Greenfield Academy</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="home">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="news&events">News & Events</a></li>
          <li class="nav-item"><a class="nav-link active" href="contact-us">Contact</a></li>
        </ul>
      </div>
    </div>
  </nav>

 
  <section class="hero-mini text-center">
    <div class="container">
      <h1 class="fw-bold mb-2">Get in Touch</h1>
      <p class="lead mb-0">We'd love to hear from you. Reach out with any questions about admissions, programs, or partnerships.</p>
    </div>
  </section>

  <div class="container py-5">
    <div class="row g-4">
     
      <div class="col-lg-7">
        <div class="card contact-card h-100">
          <div class="card-header py-3">
            <h2 class="h5 mb-0"><i class="bi bi-envelope-paper me-2"></i>Send Us a Message</h2>
          </div>
          <div class="card-body p-4">
            <form action="contact.php" method="POST" novalidate>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>" />
              <div class="row g-3 mb-1">
                <div class="col-md-6">
                  <label for="name" class="form-label required">Name</label>
                  <input type="text" class="form-control" id="name" name="name" maxlength="100" required />
                </div>
                <div class="col-md-6">
                  <label for="email" class="form-label required">Email</label>
                  <input type="email" class="form-control" id="email" name="email" maxlength="120" required />
                </div>
              </div>
              <div class="mb-3">
                <label for="subject" class="form-label required">Subject</label>
                <input type="text" class="form-control" id="subject" name="subject" maxlength="100" required />
              </div>
              <div class="mb-3">
                <label for="message" class="form-label required">Message</label>
                <textarea class="form-control" id="message" name="message" rows="6" maxlength="1000" required></textarea>
                <div class="form-text">Maximum 1000 characters.</div>
              </div>
              <div class="mb-3">
                <div class="g-recaptcha" data-sitekey="<?= $_ENV['RECAPTCHA_SITE_KEY'] ?? getenv('RECAPTCHA_SITE_KEY') ?>"></div>
              </div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="consent" name="consent" required />
                <label class="form-check-label" for="consent">I agree to the <a href="#" class="text-success text-decoration-none">privacy policy</a>.</label>
              </div>
              <div class="d-grid d-sm-flex justify-content-sm-end gap-2">
                <button type="reset" class="btn btn-outline-secondary">Reset</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-send me-1"></i>Send</button>
              </div>
            </form>
          </div>
        </div>
      </div>
     
      <div class="col-lg-5">
        <div class="card contact-card mb-4">
          <div class="card-header py-3">
            <h2 class="h6 mb-0"><i class="bi bi-building me-2"></i>School Information</h2>
          </div>
          <div class="card-body">
            <div class="d-flex align-items-start info-item mb-3"><i class="bi bi-geo-alt-fill"></i>
              <div><strong>Address</strong><br />123 Greenfield Lane, Nairobi, Kenya</div>
            </div>
            <div class="d-flex align-items-start info-item mb-3"><i class="bi bi-telephone-fill"></i>
              <div><strong>Phone</strong><br />+254 700 123456</div>
            </div>
            <div class="d-flex align-items-start info-item mb-3"><i class="bi bi-envelope-fill"></i>
              <div><strong>Email</strong><br />info@greenfieldacademy.ac.ke</div>
            </div>
            <div class="d-flex align-items-start info-item"><i class="bi bi-clock-fill"></i>
              <div><strong>Hours</strong><br />Mon - Fri: 8:00 AM - 5:00 PM</div>
            </div>
          </div>
        </div>
       
      </div>
    </div>
  </div>

  <footer class="footer-lite py-4 mt-5">
    <div class="container text-center">
      <p class="mb-1 small">&copy; <?= date('Y'); ?> Greenfield Academy. All rights reserved.</p>
    </div>
  </footer>

  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function() {
      const err = <?= isset($error) ? json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null' ?>;
      const ok = <?= isset($success) ? json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null' ?>;
      if (err) {
        Swal.fire({
          icon: 'error',
          title: 'Message Failed',
          text: err,
          confirmButtonColor: '#d33'
        });
      } else if (ok) {
        Swal.fire({
          icon: 'success',
          title: 'Message Sent',
          text: ok,
          confirmButtonColor: '#198754'
        });
      }
    })();
  </script>
</body>

</html>
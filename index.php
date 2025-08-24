<?php
session_start();
require_once 'pexels-api.php';

require_once 'vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Connect to MongoDB
try {
  $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($uri);
  $collection = $client->$mydatabase->users; // "users" collection for accounts
  $attemptsCollection = $client->$mydatabase->attempts; // separate collection for login attempts
  $newsletterCollection = $client->$mydatabase->newsletter; // newsletter collection for email subscriptions
} catch (\Throwable $e) {
  die('Database connection error');
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function respondJson($success, $message)
{
  header('Content-Type: application/json');
  echo json_encode(['success' => $success, 'message' => $message]);
  exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_signup'])) {
  $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
  $ipAddress = $_SERVER['REMOTE_ADDR'];
  $maxAttempts = 3;
  $timeWindow = 3600;
  $currentTime = time();
  $recentAttempts = $attemptsCollection->countDocuments([
    'ip' => $ipAddress,
    'type' => 'newsletter',
    'timestamp' => ['$gte' => $currentTime - $timeWindow]
  ]);
  if ($recentAttempts >= $maxAttempts) {
    if ($isAjax) {
      respondJson(false, 'Too many newsletter signup attempts. Please try again later.');
    }
    $error = 'Too many newsletter signup attempts. Please try again later.';
  } else {
    // reCAPTCHA verification before csrf verification
    $recaptchakey = $_ENV['RECAPTCHA_SECRET_KEY'] ?? getenv('RECAPTCHA_SECRET_KEY');
    $recaptchaResponse = $_POST['g-recaptcha-response'] ?? '';

    if (empty($recaptchaResponse)) {
      if ($isAjax) {
        respondJson(false, 'Please complete the reCAPTCHA verification.');
      }
      $error = 'Please complete the reCAPTCHA verification.';
    } else {
      $verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
      $data = [
        'secret' => $recaptchakey,
        'response' => $recaptchaResponse,
        'remoteip' => $_SERVER['REMOTE_ADDR']
      ];

      $options = [
        'http' => [
          'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
          'method'  => 'POST',
          'content' => http_build_query($data)
        ]
      ];
      $context  = stream_context_create($options);
      $verify = @file_get_contents($verifyUrl, false, $context);

      if ($verify === false) {
        if ($isAjax) {
          respondJson(false, 'Unable to reach reCAPTCHA verification service. Please try again.');
        }
        $error = 'Unable to reach reCAPTCHA verification service. Please try again.';
      } else {
        $captchaDecoded = json_decode($verify, true);
        if (!empty($captchaDecoded['success'])) {

          if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            if ($isAjax) {
              respondJson(false, 'Invalid CSRF token.');
            }
            $error = 'Invalid CSRF token.';
          } else {

            $email = trim($_POST['email'] ?? '');

            if (empty($email)) {
              if ($isAjax) {
                respondJson(false, 'Email address is required.');
              }
              $error = 'Email address is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
              if ($isAjax) {
                respondJson(false, 'Please enter a valid email address.');
              }
              $error = 'Please enter a valid email address.';
            } else {
              try {
                $existingEmail = $newsletterCollection->findOne(['email' => $email]);

                if ($existingEmail) {
                  if ($isAjax) {
                    respondJson(false, 'This email address is already subscribed to our newsletter.');
                  }
                  $error = 'This email address is already subscribed to our newsletter.';
                } else {

                  $newsletterData = [
                    'email' => $email,
                    'subscribed_at' => new MongoDB\BSON\UTCDateTime(),
                    'ip_address' => $ipAddress,
                    'status' => 'active',
                    'source' => 'homepage_signup'
                  ];

                  $insertResult = $newsletterCollection->insertOne($newsletterData);

                  if ($insertResult->getInsertedId()) {

                    $attemptsCollection->insertOne([
                      'ip' => $ipAddress,
                      'type' => 'newsletter',
                      'timestamp' => $currentTime,
                      'success' => true
                    ]);

                    if ($isAjax) {
                      respondJson(true, 'Thank you for subscribing to our newsletter!');
                    }
                    $success = 'Thank you for subscribing to our newsletter!';
                  } else {
                    if ($isAjax) {
                      respondJson(false, 'Failed to subscribe. Please try again.');
                    }
                    $error = 'Failed to subscribe. Please try again.';
                  }
                }
              } catch (\Throwable $e) {
                $attemptsCollection->insertOne([
                  'ip' => $ipAddress,
                  'type' => 'newsletter',
                  'timestamp' => $currentTime,
                  'success' => false
                ]);

                if ($isAjax) {
                  respondJson(false, 'Database error occurred. Please try again later.');
                }
                $error = 'Database error occurred. Please try again later.';
              }
            }
          }
        } else {
          if ($isAjax) {
            respondJson(false, 'reCAPTCHA verification failed. Please try again.');
          }
          $error = 'reCAPTCHA verification failed. Please try again.';
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Greenfield Academy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .hero {
      min-height: 60vh;
      display: flex;
      align-items: center;
      position: relative;
    }

    .hero img {
      object-fit: cover;
      width: 100%;
      height: 60vh;
      filter: brightness(0.7);
    }

    .hero-content {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: #fff;
      z-index: 2;
    }

    .gallery-img {
      object-fit: cover;
      height: 200px;
      width: 100%;
      border-radius: 8px;
    }
  </style>
</head>

<body>
  <!-- Navbar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="#">Greenfield Academy</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link active" href="#">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="#about">About</a></li>
          <li class="nav-item"><a class="nav-link" href="#programs">Programs</a></li>
          <li class="nav-item"><a class="nav-link" href="#gallery">Gallery</a></li>
          <li class="nav-item"><a class="nav-link" href="news&events">News & Events</a></li>
          <li class="nav-item"><a class="nav-link" href="contact-us">Contact</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <section class="hero">
    <div id="hero-carousel" class="carousel slide w-100" data-bs-ride="carousel">
      <div class="carousel-inner">
        <?php foreach ($hero_images as $i => $img): ?>
          <div class="carousel-item <?php if ($i === 0) echo 'active'; ?>">
            <img src="<?php echo $img; ?>" alt="Hero Image">
            <div class="hero-content">
              <h1 class="display-3 fw-bold mb-3">Welcome to Greenfield Academy</h1>
              <p class="lead mb-4">Empowering students to become tomorrow's leaders through quality education, innovation,
                and character development.</p>
              <a href="#contact" class="btn btn-lg btn-light text-success fw-bold shadow">Enroll Now</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-control-prev" type="button" data-bs-target="#hero-carousel" data-bs-slide="prev">
        <span class="carousel-control-prev-icon"></span>
      </button>
      <button class="carousel-control-next" type="button" data-bs-target="#hero-carousel" data-bs-slide="next">
        <span class="carousel-control-next-icon"></span>
      </button>
    </div>
  </section>

  <!-- About Section -->
  <section id="about" class="py-5 bg-light">
    <div class="container">
      <div class="row align-items-center">
        <div class="col-md-6 mb-4 mb-md-0">
          <img src="<?php echo $about_us_image; ?>" class="img-fluid rounded shadow" alt="About Greenfield Academy">
        </div>
        <div class="col-md-6">
          <h2 class="fw-bold mb-3">About Our School</h2>
          <p>Greenfield Academy is a modern, inclusive school dedicated to academic excellence and holistic development.
            Our experienced faculty, state-of-the-art facilities, and vibrant community create an environment where every
            child can thrive.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Programs Section -->
  <section id="programs" class="py-5 bg-light">
    <div class="container">
      <h2 class="fw-bold text-center mb-5">Our Programs</h2>
      <div class="row g-4">
        <?php foreach ($our_programs_images as $img): ?>
          <div class="col-md-6">
            <div class="card h-100 shadow-sm">
              <img src="<?php echo $img; ?>" class="card-img-top" alt="Program">
              <div class="card-body">
                <h5 class="card-title">Academic Excellence</h5>
                <p class="card-text">A nurturing environment for learners to build strong academic and social foundations.
                </p>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>


  <section id="gallery" class="py-5">
    <div class="container">
      <h2 class="fw-bold text-center mb-5">School Gallery</h2>
      <div class="row g-3">
        <?php foreach ($gallery_images as $img): ?>
          <div class="col-6 col-md-4 col-lg-3">
            <img src="<?php echo $img; ?>" class="gallery-img shadow-sm" alt="Gallery">
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section id="activities" class="py-5 bg-light">
    <div class="container">
      <h2 class="fw-bold text-center mb-5">Co-curricular & Extra-curricular Activities</h2>
      <div class="row g-4">
        <div class="col-md-4">
          <div class="card h-100 shadow-sm">
            <img src="<?php echo isset($facilities_images[0]) ? $facilities_images[0] : 'assets/images/placeholder.jpg'; ?>" class="card-img-top" alt="Sports">
            <div class="card-body">
              <h5 class="card-title">Sports & Athletics</h5>
              <p class="card-text">We offer a variety of sports including football, basketball, athletics, and swimming to promote teamwork and physical fitness.</p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 shadow-sm">
            <img src="<?php echo isset($facilities_images[1]) ? $facilities_images[1] : 'assets/images/placeholder.jpg'; ?>" class="card-img-top" alt="Music & Arts">
            <div class="card-body">
              <h5 class="card-title">Music & Performing Arts</h5>
              <p class="card-text">Our music, drama, and dance clubs nurture creativity and self-expression through regular performances and competitions.</p>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card h-100 shadow-sm">
            <img src="<?php echo isset($facilities_images[2]) ? $facilities_images[2] : 'assets/images/placeholder.jpg'; ?>" class="card-img-top" alt="Clubs & Societies">
            <div class="card-body">
              <h5 class="card-title">Clubs & Societies</h5>
              <p class="card-text">Students can join clubs such as Debate, Science, Environment, and ICT to develop leadership, teamwork, and specialized skills.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="contact" class="py-5">
    <div class="container">
      <h2 class="fw-bold text-center mb-5">Contact Us</h2>
      <div class="row justify-content-center">
        <div class="col-md-6">
          <div class="container py-5">
            <div class="row justify-content-center">
              <div class="col-lg-6 text-center">
                <h2 class="mb-3">Subscribe to Our Newsletter</h2>
                <p>Stay up to date with the latest news, announcements, and articles.</p>
                <form id="newsletterForm" action="" method="POST">
                  <div class="input-group mb-3">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="email" name="email" id="emailInput" class="form-control" placeholder="Enter your email" required>
                    <button class="btn btn-primary" type="submit" name="newsletter_signup">Subscribe</button>
                  </div>
                  <div class="g-recaptcha mb-3" data-sitekey="<?= $_ENV['RECAPTCHA_SITE_KEY'] ?? getenv('RECAPTCHA_SITE_KEY') ?>"></div>
                </form>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-5 ms-auto mt-4 mt-md-0">
          <div class="bg-light p-4 rounded shadow-sm h-100">
            <h5 class="fw-bold mb-3">School Address</h5>
            <p class="mb-1"><i class="bi bi-geo-alt-fill text-success me-2"></i>123 Greenfield Lane, Nairobi, Kenya
            </p>
            <p class="mb-1"><i class="bi bi-telephone-fill text-success me-2"></i>+254 700 123456</p>
            <p class="mb-1"><i class="bi bi-envelope-fill text-success me-2"></i>info@greenfieldacademy.ac.ke</p>
            <p><i class="bi bi-clock-fill text-success me-2"></i>Mon - Fri: 8:00 AM - 5:00 PM</p>
          </div>
        </div>
      </div>
    </div>
  </section>


  <footer class="bg-success text-white py-4 mt-5">
    <div class="container text-center">
      <p class="mb-1">&copy; <?php echo date('Y'); ?> Greenfield Academy. All rights reserved.</p>
      <div>
        <a href="#" class="text-white me-3"><i class="bi bi-facebook"></i></a>
        <a href="#" class="text-white me-3"><i class="bi bi-twitter"></i></a>
        <a href="#" class="text-white me-3"><i class="bi bi-instagram"></i></a>
        <a href="#" class="text-white"><i class="bi bi-linkedin"></i></a>
      </div>
    </div>
  </footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const newsletterForm = document.getElementById('newsletterForm');
      const emailInput = document.getElementById('emailInput');

      if (newsletterForm) {
        newsletterForm.addEventListener('submit', function(e) {
          e.preventDefault();

          // Check if reCAPTCHA is completed
          const recaptchaResponse = grecaptcha.getResponse();
          if (!recaptchaResponse) {
            Swal.fire({
              icon: 'warning',
              title: 'reCAPTCHA Required',
              text: 'Please complete the reCAPTCHA verification.'
            });
            return;
          }

          // Get form data
          const formData = new FormData(newsletterForm);
          // Ensure submit flag is sent for server-side condition
          formData.set('newsletter_signup', '1');

          // Disable submit button and show loading
          const submitBtn = newsletterForm.querySelector('button[type="submit"]');
          const originalText = submitBtn.innerHTML;
          submitBtn.innerHTML = 'Subscribing...';
          submitBtn.disabled = true;

          // Make AJAX request
          fetch(window.location.href, {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              }
            })
            .then(async response => {
              // Attempt JSON parse; if HTML returned (e.g., due to PHP error), throw a helpful error
              const text = await response.text();
              try {
                return JSON.parse(text);
              } catch (e) {
                throw new Error('Unexpected response from server.');
              }
            })
            .then(data => {
              if (data.success) {
                Swal.fire({
                  icon: 'success',
                  title: 'Subscription Successful!',
                  text: data.message,
                  confirmButtonColor: '#198754'
                });

                // Reset form
                newsletterForm.reset();
                grecaptcha.reset();
              } else {
                Swal.fire({
                  icon: 'error',
                  title: 'Subscription Failed',
                  text: data.message,
                  confirmButtonColor: '#dc3545'
                });

                // Reset reCAPTCHA
                grecaptcha.reset();
              }
            })
            .catch(error => {
              console.error('Error:', error);
              Swal.fire({
                icon: 'error',
                title: 'Network Error',
                text: 'Something went wrong. Please try again later.',
                confirmButtonColor: '#dc3545'
              });

              // Reset reCAPTCHA
              grecaptcha.reset();
            })
            .finally(() => {
              // Re-enable submit button
              submitBtn.innerHTML = originalText;
              submitBtn.disabled = false;
            });
        });
      }
    });

    // Display server-side messages if any (for non-AJAX requests)
    <?php if (isset($error) && !empty($error)): ?>
      Swal.fire({
        icon: 'error',
        title: 'Newsletter Signup Failed',
        text: '<?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>'
      });
    <?php elseif (isset($success) && !empty($success)): ?>
      Swal.fire({
        icon: 'success',
        title: 'Newsletter Signup Successful!',
        text: '<?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>'
      });
    <?php endif; ?>
  </script>
</body>

</html>
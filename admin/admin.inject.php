<?php
//this is for developers only use this when you need to add a base CEO  then please dont include this in production as it doesnt have any security it is meant to add an admin to exsistance when none exsists 
//FOR DEVELOPMENT PURPOSES ONLY 
require '../vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__ . '../'));
$dotenv->load();

$success = false;
$error = '';

try {
  $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($uri);
  $collection = $client->$mydatabase->admin;
} catch (Exception $e) {
  die('Database connection error');
}

// $uri = $_ENV['MONGODB_URI'];
// $client = new Client($uri);

// $db = $client->selectDatabase('LIME');
// $collection = $db->admin;
// $success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email']);
  $password = $_POST['password'];
  $username = isset($_POST['username']) ? trim($_POST['username']) : '';
  $role = isset($_POST['role']) ? trim($_POST['role']) : '';

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "Invalid email address. Please try again.";
  } else if (strlen($password) < 6) {
    $error = "Password must be at least 6 characters long.";
  } else if (empty($username)) {
    $error = "Username is required.";
  } else if (empty($role)) {
    $error = "Role is required.";
  } else if ($role !== 'CEO') {
    $error = 'You don`t get it this is not meant to add other users except the base admin the CEO this script is useless after the admin has been created.';
    exit;
  } else {
    $uniqueRoles = ['CEO']; 

    if (in_array($role, $uniqueRoles)) {
      $existingRole = $collection->findOne(['role' => $role]);
      if ($existingRole) {
        $error = "Error: The role '$role' already exists. You cannot register another user with this role.";
        header('Location: admin.inject.php');
        exit;
      }
    }
    $existingUser = $collection->findOne(['email' => $email]);
    if ($existingUser) {
      $error = "User already exists with this email address.";
    } else {

      $insert = $collection->insertOne([
        'email' => $email,
        'username' => $username,
        'role' => $role,
        'password' => password_hash($password, PASSWORD_BCRYPT),
        'created_at' => new MongoDB\BSON\UTCDateTime()
      ]);

      if ($insert->getInsertedCount() > 0) {
        $success = true;
        //remove this file after successfull admin additiiton 

        unlink('admin.inject.php');
        header('Location: login');
      } else {
        $error = "Failed to create user. Please try again.";
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
  <title>Admin inject</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.min.css" rel="stylesheet">
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: black;
      min-height: 100vh;
      line-height: 1.6;
      color:white;
    }

    .admin-wrapper {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

  
    .navbar-brand {
      font-weight: 700;
      font-size: 1.5rem;
      color: white !important;
      text-decoration: none;
    }

    .navbar-brand:hover {
      color: var(--warning-color) !important;
    }

    .nav-link {
      color: rgba(255, 255, 255, 0.9) !important;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .nav-link:hover {
      color: orange;
      transform: translateY(-1px);
    }

    .admin-main {
      flex: 1;
      padding: 3rem 0;
    }

    .admin-card {
      background: white;
      
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .admin-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    .card-header {
     
      color: white;
      padding: 2rem;
      text-align: center;
      border: none;
      color:black;
    }

    .card-title {
      font-size: 1.8rem;
      font-weight: 700;
      margin: 0;
      color: black;
    }

    .card-subtitle {
      margin: 0.5rem 0 0 0;
      opacity: 0.9;
      font-size: 1rem;
    }

    .card-body {
      padding: 2rem;
    }


    .form-label {
      font-weight: 600;
      color: black;
      margin-bottom: 0.5rem;
    }

    .form-control {
      border: 2px solid #e9ecef;
      border-radius: 8px;
      padding: 0.75rem 1rem;
      font-size: 1rem;
      transition: all 0.3s ease;
    }

    .form-control:focus {
    
      box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
    }

    .form-control.is-invalid {
      border-color: red;
    }

    .form-control.is-valid {
      border-color: green;
    }

    .input-group-text {
      background: black;
      border: 2px solid #e9ecef;
      border-left: none;
    }

    .btn-outline-secondary {
      border: 2px solid #e9ecef;
      border-left: none;
    }

    .btn {
      border-radius: 8px;
      font-weight: 600;
      padding: 0.75rem 1.5rem;
      transition: all 0.3s ease;
      border: none;
      background-color:green;
    }

    .btn-primary {
 
      border: none;
    }

    .btn-primary:hover {
 
      transform: translateY(-2px);
    
    }

    .btn-outline-secondary {
      background: transparent;
    }

    .btn-outline-secondary:hover {
      background: var(--secondary-color);
      color: white;
      transform: translateY(-2px);
    }

    .btn-lg {
      padding: 1rem 2rem;
      font-size: 1.1rem;
    }

    .info-card .card {
      border: none;
      background: rgba(255, 255, 255, 0.9);
      backdrop-filter: blur(10px);
      border-radius: var(--border-radius);
    }

    .info-card .card-body {
      padding: 1.5rem;
    }

    .info-card .card-title {
      color:orange;
      font-weight: 600;
      margin-bottom: 1rem;
    }

    .info-card .list-unstyled li {
      padding: 0.25rem 0;
      font-size: 0.9rem;
    }

    .form-text {
      font-size: 0.875rem;
      color: black;
      margin-top: 0.5rem;
    }

    .invalid-feedback {
      font-weight: 500;
    }

    #togglePassword {
      border-left: none;
      cursor: pointer;
    }

    #togglePassword:hover {
      background: black;
    }

    .btn.loading {
      position: relative;
      color: transparent;
    }

    .btn.loading::after {
      content: '';
      position: absolute;
      width: 16px;
      height: 16px;
      top: 50%;
      left: 50%;
      margin-left: -8px;
      margin-top: -8px;
      border: 2px solid transparent;
      border-top-color: #ffffff;
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    

   
    .swal2-popup {
      border-radius: 0.6vw;
    }

    .swal2-success .swal2-success-ring {
      border-color: green;
    }

    .swal2-error .swal2-x-mark {
      border-color: red;
    }


   
  </style>
</head>

<body>
  <div class="admin-wrapper">

    <header class="admin-header">
      <div class="container-fluid">
        <nav class="navbar navbar-expand-lg navbar-dark">
          <div class="container">
            <a class="navbar-brand" href="../home">
              <i class="fas fa-leaf me-2"></i> Admin injector
            </a>
            <div class="navbar-nav ms-auto">
              <a class="nav-link" href="../home">
                <i class="fas fa-home me-1"></i>Home
              </a>
              <a class="nav-link" href="login.php">
                <i class="fas fa-sign-in-alt me-1"></i>Login
              </a>
            </div>
          </div>
        </nav>
      </div>
    </header>
    <p style="text-align: center;color:red;font-size:1.3rem;background-color:white;padding:1vh;">I cannot stress this enough this file is for injecting a base admin to the database .FOR DEVELOPMENT ONLY DON SUBMIT THIS TO PRODUCTION.</p>
    <main class="admin-main">
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-lg-6 col-md-8">
            <div class="admin-card">
              <div class="card-header">
                <h2 class="card-title">
                  <i class="fas fa-user-plus me-2"></i>
                  Add New User
                </h2>
                <p class="card-subtitle">Inject a new user into the database</p>
              </div>
              <div class="card-body">
                <form method="POST" id="userForm" class="needs-validation" novalidate>
                  <div class="mb-4">
                    <label for="username" class="form-label"> <i class="fas fa-user me-2"></i>Username</label>
                    <input
                      type="text"
                      class="form-control"
                      id="username"
                      name="username"
                      required
                      placeholder="Enter username"
                      value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    <div class="invalid-feedback">
                      Please provide a username.
                    </div>
                  </div>
                  <div class="mb-4">
                    <label for="role" class="form-label"> <i class="fas fa-user-tag me-2"></i>Role</label>
                    <input type="text" placeholder="Enter role" class="form-control" id="role" name="role" value="CEO" readonly required>

                    <div class="invalid-feedback">
                      for development purpose only .
                    </div>
                  </div>
                  <div class="mb-4">
                    <label for="email" class="form-label">
                      <i class="fas fa-envelope me-2"></i>Email Address
                    </label>
                    <input
                      type="email"
                      class="form-control"
                      id="email"
                      name="email"
                      required
                      placeholder="Enter user email"
                      value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    <div class="invalid-feedback">
                      Please provide a valid email address.
                    </div>
                  </div>
                  <div class="mb-4">
                    <label for="password" class="form-label">
                      <i class="fas fa-lock me-2"></i>Password
                    </label>
                    <div class="input-group">
                      <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        required
                        minlength="6"
                        placeholder="Enter password (min 6 characters)"> <br>
                      <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                        <i class="fas fa-eye"></i>
                      </button>
                    </div>
                    <br
                      <div class="invalid-feedback">
                    Password must be at least 6 characters long.
                  </div>
                  <div class="form-text">
                    Password will be securely hashed before storage.
                  </div>
              </div>
              <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg">
                  <i class="fas fa-plus me-2"></i>
                  Create User
                </button>
                <button type="reset" class="btn btn-outline-secondary">
                  <i class="fas fa-undo me-2"></i>
                  Reset Form
                </button>
              </div>
              </form>
            </div>
          </div>


        </div>
      </div>
  </div>
  </main>
  </div>
  <div class="container mt-5">
    <div class="row justify-content-center">
      <div class="col-lg-6 col-md-8">
        <div class="card info-card">
          <div class="card-body">
            <h4 class="card-title text-info"><i class="fas fa-info-circle me-2"></i>Important: Read Before Using</h4>
            <p style="color: #b22222; font-weight: bold;">
              <i class="fas fa-exclamation-triangle me-2"></i>
              <strong>This page is for development use only. Do <u>not</u> use or deploy this file in production!</strong>
            </p>
            <ol class="list-group list-group-numbered">
              <li class="list-group-item">
                <strong>This tool is for injecting the initial CEO (super admin) account into the database.</strong>
                Only the CEO can add or manage other admins from within the admin panel.
              </li>
              <li class="list-group-item">
                <strong>You can only add the CEO using this page, and only once.</strong>
                If a CEO already exists, this page will not allow you to add another.
              </li>
              <li class="list-group-item">
                <strong>No other admin or user can be created using this page.</strong>
                This is strictly for the first-time setup for those who may not be familiar with MongoDB or backend programming.
              </li>
              <li class="list-group-item">
                <strong>After successfully adding the CEO, this file will automatically delete itself.</strong>
                This prevents any future unauthorized access or security risks.
              </li>
            </ol>
            <p class="mt-3" style="color: #b22222; font-weight: bold;">
              <i class="fas fa-ban me-2"></i>
              <strong>Never leave this file on your server after setup. It is a critical security risk if left accessible.</strong>
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.12/dist/sweetalert2.all.min.js"></script>

  <!-- <script src="admin-inject.js"></script> -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {

      initializeFormValidation();


      initializePasswordToggle();

      initializeFormSubmission();

      initializeTooltips();
    });

    function initializeFormValidation() {
      const forms = document.querySelectorAll('.needs-validation');

      Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
          if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();

            Swal.fire({
              icon: 'warning',
              title: 'Validation Error',
              text: 'Please fill in all required fields correctly.',
              confirmButtonText: 'OK',
              confirmButtonColor: '#ffc107'
            });
          }

          form.classList.add('was-validated');
        }, false);
      });

      const inputs = document.querySelectorAll('.form-control');
      inputs.forEach(input => {
        input.addEventListener('input', function() {
          if (this.checkValidity()) {
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
          } else {
            this.classList.remove('is-valid');
            this.classList.add('is-invalid');
          }
        });
      });
    }


    function initializePasswordToggle() {
      const toggleButton = document.getElementById('togglePassword');
      const passwordInput = document.getElementById('password');

      if (toggleButton && passwordInput) {
        toggleButton.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);

          const icon = this.querySelector('i');
          if (type === 'password') {
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
          } else {
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
          }
        });
      }
    }


    function initializeFormSubmission() {
      const form = document.getElementById('userForm');
      const submitButton = form.querySelector('button[type="submit"]');

      if (form && submitButton) {
        form.addEventListener('submit', function(e) {
          if (form.checkValidity()) {
            // Add loading state
            submitButton.classList.add('loading');
            submitButton.disabled = true;

            // Show loading message
            Swal.fire({
              title: 'Creating User...',
              text: 'Please wait while we create the user account.',
              allowOutsideClick: false,
              allowEscapeKey: false,
              showConfirmButton: false,
              didOpen: () => {
                Swal.showLoading();
              }
            });

            setTimeout(() => {
              submitButton.classList.remove('loading');
              submitButton.disabled = false;
            }, 1000);
          }
        });
      }
    }

    function initializeTooltips() {
      const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      const tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
      });
    }


    function validateEmail(email) {
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      return emailRegex.test(email);
    }


    function checkPasswordStrength(password) {
      let strength = 0;
      let feedback = [];

      if (password.length >= 6) strength += 1;
      else feedback.push('At least 6 characters');

      if (password.match(/[a-z]/)) strength += 1;
      else feedback.push('Lowercase letter');

      if (password.match(/[A-Z]/)) strength += 1;
      else feedback.push('Uppercase letter');

      if (password.match(/[0-9]/)) strength += 1;
      else feedback.push('Number');

      if (password.match(/[^a-zA-Z0-9]/)) strength += 1;
      else feedback.push('Special character');

      return {
        strength: strength,
        feedback: feedback
      };
    }

    function addPasswordStrengthIndicator() {
      const passwordInput = document.getElementById('password');
      const strengthIndicator = document.createElement('div');
      strengthIndicator.className = 'password-strength mt-2';
      strengthIndicator.innerHTML = `
        <div class="progress" style="height: 5px;">
            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
        </div>
        <small class="text-muted">Password strength: <span class="strength-text">Weak</span></small>
    `;

      passwordInput.parentNode.insertBefore(strengthIndicator, passwordInput.nextSibling);

      passwordInput.addEventListener('input', function() {
        const password = this.value;
        const result = checkPasswordStrength(password);
        const progressBar = strengthIndicator.querySelector('.progress-bar');
        const strengthText = strengthIndicator.querySelector('.strength-text');

        const percentage = (result.strength / 5) * 100;
        progressBar.style.width = percentage + '%';

        if (percentage < 40) {
          progressBar.className = 'progress-bar bg-danger';
          strengthText.textContent = 'Weak';
          strengthText.className = 'text-danger';
        } else if (percentage < 80) {
          progressBar.className = 'progress-bar bg-warning';
          strengthText.textContent = 'Medium';
          strengthText.className = 'text-warning';
        } else {
          progressBar.className = 'progress-bar bg-success';
          strengthText.textContent = 'Strong';
          strengthText.className = 'text-success';
        }
      });
    }

    function resetFormWithConfirmation() {
      const resetButton = document.querySelector('button[type="reset"]');

      if (resetButton) {
        resetButton.addEventListener('click', function(e) {
          e.preventDefault();

          Swal.fire({
            title: 'Reset Form?',
            text: 'This will clear all entered data.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, reset',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#6c757d',
            cancelButtonColor: '#dc3545'
          }).then((result) => {
            if (result.isConfirmed) {
              document.getElementById('userForm').reset();

              const inputs = document.querySelectorAll('.form-control');
              inputs.forEach(input => {
                input.classList.remove('is-valid', 'is-invalid');
              });

              document.querySelector('.needs-validation').classList.remove('was-validated');

              Swal.fire({
                title: 'Form Reset!',
                text: 'The form has been cleared.',
                icon: 'success',
                timer: 1500,
                showConfirmButton: false
              });
            }
          });
        });
      }
    }


    function addKeyboardShortcuts() {
      document.addEventListener('keydown', function(e) {

        if (e.ctrlKey && e.key === 'Enter') {
          e.preventDefault();
          const form = document.getElementById('userForm');
          if (form.checkValidity()) {
            form.submit();
          }
        }
        if (e.key === 'Escape') {
          const form = document.getElementById('userForm');
          if (form.querySelector('input').value !== '') {
            resetFormWithConfirmation();
          }
        }
      });
    }

    function initializeAdditionalFeatures() {
      addPasswordStrengthIndicator();
      resetFormWithConfirmation();
      addKeyboardShortcuts();
    }

    document.addEventListener('DOMContentLoaded', initializeAdditionalFeatures);
    const SweetAlertConfig = {
      success: {
        icon: 'success',
        confirmButtonColor: '#28a745',
        timer: 3000,
        timerProgressBar: true
      },
      error: {
        icon: 'error',
        confirmButtonColor: '#dc3545'
      },
      warning: {
        icon: 'warning',
        confirmButtonColor: '#ffc107'
      },
      info: {
        icon: 'info',
        confirmButtonColor: '#17a2b8'
      }
    };

  
    function showNotification(type, title, text, options = {}) {
      const config = {
        ...SweetAlertConfig[type],
        title,
        text,
        ...options
      };
      return Swal.fire(config);
    }
    window.AdminInject = {
      showNotification,
      validateEmail,
      checkPasswordStrength
    };
  </script>
  <?php if ($success): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
          icon: 'success',
          title: 'Success!',
          text: 'User has been successfully created.',
          confirmButtonText: 'OK',
          confirmButtonColor: '#28a745'
        }).then((result) => {
          if (result.isConfirmed) {
            // Clear form
            document.getElementById('userForm').reset();
          }
        });
      });
    </script>
  <?php endif; ?>

  <?php if ($error): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
          icon: 'error',
          title: 'Error!',
          text: '<?php echo addslashes($error); ?>',
          confirmButtonText: 'Try Again',
          confirmButtonColor: '#dc3545'
        });
      });
    </script>
  <?php endif; ?>
</body>

</html>
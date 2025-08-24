<?php
http_response_code(404);
// Basic routing suggestions map (add more as site grows)
$suggestions = [
  'contact' => 'contact.php',
  'contact-us' => 'contact.php',
  'news' => 'news-events.php',
  'events' => 'news-events.php',
  'news-events' => 'news-events.php',
  'login' => 'admin/login.php',
  'admin' => 'admin/login.php',
  'home' => 'index.php',
  'index' => 'index.php',
  'newsletter' => 'index.php#contact'
];

$requestedUri = $_SERVER['REQUEST_URI'] ?? '';
$path = strtolower(trim(parse_url($requestedUri, PHP_URL_PATH), '/'));
$pathKey = preg_replace('/\.[a-z0-9]+$/i', '', $path); // strip extension
$autoRedirect = null;
if ($pathKey && isset($suggestions[$pathKey])) {
  $autoRedirect = $suggestions[$pathKey];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Page Not Found - Greenfield Academy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" />
  <style>
   
    body {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: #f5f8f6;
    }

    .hero-404 {
   text-align:center;
    }

  h1{
    text-align: center;
    color:green;
    font-size: 4rem;
    font-weight: 800;
    letter-spacing: -2px;
  }
   h2{
    text-align: center;
    color: #333;
    font-size: 3rem;
    font-weight: 600;
    margin-top: 1rem;
   }
.links a{
  text-decoration: none;
  color: #333;
  opacity: 0.75;
  line-height: 1.5;
  border: 1px solid black;
  padding: 0.5rem 1rem;
  margin: 0.5rem;
  border-radius:1vh;
}
.links a:hover{
  background-color:black;
  color:white;
}

  

    

   

    

 
    .footer-lite {
      background: green;
      color: #fff;
    }

  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="index.php">Greenfield Academy</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav404"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="nav404">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="home">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="news&events">News & Events</a></li>
          <li class="nav-item"><a class="nav-link" href="contact-us">Contact</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <main>
    <div class="blob-bg">
     
    </div>
    <div>
      <div >
        <div class="banner">
          <div >
            <h1>404</h1>
            <div class="text-end small">
              <div class="opacity-75">Page not found</div>
              <code class="opacity-50"><?= htmlspecialchars($requestedUri, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
          </div>
        </div>
        <div class="p-4 p-md-5">
          <h2 class="h4 fw-bold mb-3">Oops! We couldn't find that page.</h2>
          <p style="text-align: center;font-size: 1.5rem;">The page may have been moved, renamed, or no longer exists. Let's get you back on track.</p>

          <div class="mb-4">
            <h3 style="text-align:center; font-size: 1.3rem;font-weight: 600;">Popular Destinations</h3> <br>

            <div class="links" style="text-align:center;margin-top:2vh; margin:1vh;">
              <a class="pill-link" href="home"><i class="bi bi-house"></i> Home</a>
              <a class="pill-link" href="news&events"><i class="bi bi-newspaper"></i> News & Events</a>
              <a class="pill-link" href="contact-us"><i class="bi bi-envelope"></i> Contact</a>
              <a class="pill-link" href="home#programs"><i class="bi bi-mortarboard"></i> Programs</a>
              <a class="pill-link" href="home#gallery"><i class="bi bi-images"></i> Gallery</a>
            </div>
          </div>

          <div class="mb-2 small text-muted">Need help? <a href="contact-us" class="text-success text-decoration-none">Contact the school office</a>.</div>
        </div>
      </div>
    </div>
  </main>

  <footer class="footer-lite py-4 mt-auto">
    <div class="container text-center">
      <p class="mb-1 small">&copy; <?= date('Y'); ?> Greenfield Academy. All rights reserved.</p>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const autoTarget = <?= $autoRedirect ? json_encode($autoRedirect) : 'null' ?>;
    if (autoTarget) {
      let seconds = 5;
      const el = document.getElementById('countdown');
      const timer = setInterval(() => {
        seconds--;
        if (el) el.textContent = seconds;
        if (seconds <= 0) {
          clearInterval(timer);
          window.location.href = autoTarget;
        }
      }, 1000);
    }

    const pagesIndex = [{
        k: 'home index main welcome',
        url: 'index.php'
      },
      {
        k: 'contact message email phone address',
        url: 'contact.php'
      },
      {
        k: 'news event newsletter update happenings',
        url: 'news-events.php'
      },
      {
        k: 'programs academics curriculum subjects',
        url: 'index.php#programs'
      },
      {
        k: 'gallery photos images campus',
        url: 'index.php#gallery'
      },
      {
        k: 'login admin dashboard',
        url: 'admin/login.php'
      }
    ];

    function performSearch(e) {
      e.preventDefault();
      const q = (document.getElementById('searchInput').value || '').toLowerCase().trim();
      if (!q) return false;
      let best = null;
      let bestScore = 0;
      for (const p of pagesIndex) {
        let score = 0;
        for (const term of q.split(/\s+/)) {
          if (p.k.indexOf(term) !== -1) score++;
        }
        if (score > bestScore) {
          bestScore = score;
          best = p;
        }
      }
      if (best && bestScore > 0) {
        window.location.href = best.url;
      } else {
        alert('No direct match found. Try different keywords.');
      }
      return false;
    }
  </script>
</body>

</html>
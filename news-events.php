<?php

session_start();
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

try {
  $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $dbName = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($uri);
  $db = $client->$dbName;
  $postsCol = $db->news_events;
} catch (Throwable $e) {
  http_response_code(500);
  die('Database unavailable');
}

$idParam = $_GET['id'] ?? null;
$typeFilter = strtolower(trim($_GET['type'] ?? ''));
if (!in_array($typeFilter, ['news', 'event'], true)) {
  $typeFilter = '';
}

if ($idParam) {
  if (!preg_match('/^[a-f0-9]{24}$/i', $idParam)) {
    http_response_code(400);
    $detailError = 'Invalid post id.';
  } else {
    $doc = $postsCol->findOne(['_id' => new ObjectId($idParam)]);
    if (!$doc) {
      http_response_code(404);
      $detailError = 'Post not found.';
    }
  }
}

$posts = [];
$totalPages = 1;
$page = 1;
$limit = 12;
$total = 0;
if (!$idParam || isset($detailError)) {
  $page = max(1, (int)($_GET['page'] ?? 1));
  $filter = [];
  if ($typeFilter) {
    $filter['type'] = $typeFilter;
  }
  try {
    $total = $postsCol->countDocuments($filter);
    $totalPages = max(1, (int)ceil($total / $limit));
    if ($page > $totalPages) {
      $page = $totalPages;
    }
    $skip = ($page - 1) * $limit;
    $cursor = $postsCol->find($filter, [
      'sort' => ['created_at' => -1],
      'limit' => $limit,
      'skip' => $skip
    ]);
    foreach ($cursor as $p) {
      $posts[] = $p;
    }
  } catch (Throwable $e) {
    $listError = 'Failed to load posts.';
  }
}

function formatDate($v)
{
  if ($v instanceof UTCDateTime) return $v->toDateTime()->format('Y-m-d');
  return '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>News & Events - Greenfield Academy</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .hero-mini {
      background: #198754;
      color: #fff;
      padding: 3rem 0;
    }

    .post-card-title {
      font-size: 1.05rem;
      font-weight: 600;
      min-height: 2.4em;
    }

    .badge-event {
      background: #0d6efd;
    }

    .post-image-thumb {
      object-fit: cover;
      height: 180px;
      width: 100%;
      border-radius: .5rem .5rem 0 0;
    }

    .content-wrapper {
      white-space: pre-wrap;
    }
  </style>
</head>

<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm">
    <div class="container">
      <a class="navbar-brand fw-bold" href="home">Greenfield Academy</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="home">Home</a></li>
          <li class="nav-item"><a class="nav-link active" href="news-events.php">News & Events</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <section class="hero-mini text-center">
    <div class="container">
      <h1 class="fw-bold mb-2">News & Events</h1>
      <p class="mb-0">Stay updated with the latest happenings at Greenfield Academy.</p>
    </div>
  </section>

  <div class="container py-5">
    <?php if ($idParam): ?>
      <?php if (isset($detailError)): ?>
        <div class="alert alert-danger"><?= h($detailError) ?></div>
        <a href="news-events.php" class="btn btn-secondary">Back</a>
      <?php else: ?>
        <a href="news-events.php" class="btn btn-outline-success mb-3"><i class="bi bi-arrow-left"></i> Back to list</a>
        <div class="card shadow-sm mb-4">
          <?php if (isset($doc['image_file_id'])): ?>
            <img src="media.php?file=<?= h($doc['image_file_id']) ?>" class="card-img-top" style="max-height:420px; object-fit:cover;" alt="Post Image" />
          <?php endif; ?>
          <div class="card-body">
            <h2 class="card-title h4 mb-2"><?= h($doc['title'] ?? '') ?></h2>
            <div class="mb-2">
              <span class="badge <?= (($doc['type'] ?? '') === 'event') ? 'badge-event' : 'bg-primary' ?> text-light"><?= h(ucfirst($doc['type'] ?? '')) ?></span>
              <?php if (!empty($doc['event_date'])): ?><span class="badge bg-info text-dark">Event: <?= h(formatDate($doc['event_date'])) ?></span><?php endif; ?>
              <span class="badge bg-secondary">Posted: <?= h(isset($doc['created_at']) && $doc['created_at'] instanceof UTCDateTime ? $doc['created_at']->toDateTime()->format('Y-m-d') : '') ?></span>
            </div>
            <div class="content-wrapper"><?= nl2br(h($doc['content'] ?? '')) ?></div>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-3 mb-lg-0">Latest Posts</h2>
        <form class="d-flex gap-2" method="get" action="">
          <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="" <?= $typeFilter === '' ? 'selected' : '' ?>>All</option>
            <option value="news" <?= $typeFilter === 'news' ? 'selected' : '' ?>>News</option>
            <option value="event" <?= $typeFilter === 'event' ? 'selected' : '' ?>>Events</option>
          </select>
          <?php if ($typeFilter !== ''): ?><a href="news-events.php" class="btn btn-sm btn-outline-secondary">Reset</a><?php endif; ?>
        </form>
      </div>
      <?php if (isset($listError)): ?>
        <div class="alert alert-danger"><?= h($listError) ?></div>
      <?php endif; ?>
      <?php if (empty($posts)): ?>
        <div class="alert alert-info">No posts found.</div>
      <?php else: ?>
        <div class="row g-4 mb-4">
          <?php foreach ($posts as $p):
            $pid = (string)$p['_id'];
            $title = $p['title'] ?? '';
            $type = $p['type'] ?? '';
            $eventDate = isset($p['event_date']) && $p['event_date'] instanceof UTCDateTime ? $p['event_date']->toDateTime()->format('Y-m-d') : '';
            $createdDate = isset($p['created_at']) && $p['created_at'] instanceof UTCDateTime ? $p['created_at']->toDateTime()->format('Y-m-d') : '';
            $hasImg = isset($p['image_file_id']);
            $snippet = mb_strimwidth($p['content'] ?? '', 0, 160, '...');
          ?>
            <div class="col-md-4">
              <div class="card h-100 shadow-sm">
                <?php if ($hasImg): ?>
                  <img src="media.php?file=<?= h($p['image_file_id']) ?>" class="post-image-thumb" alt="Post image" />
                <?php else: ?>
                  <div class="post-image-thumb d-flex align-items-center justify-content-center bg-light text-muted">No Image</div>
                <?php endif; ?>
                <div class="card-body d-flex flex-column">
                  <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="badge <?= ($type === 'event') ? 'badge-event text-light' : 'bg-primary' ?>"><?= h(ucfirst($type)) ?></span>
                    <?php if ($eventDate): ?><span class="badge bg-info text-dark"><?= h($eventDate) ?></span><?php endif; ?>
                  </div>
                  <h3 class="post-card-title"><?= h($title) ?></h3>
                  <p class="small text-muted flex-grow-1 mb-2"><?= h($snippet) ?></p>
                  <div class="d-flex justify-content-between align-items-center mt-auto">
                    <small class="text-muted"><?= h($createdDate) ?></small>
                    <a href="news-events.php?id=<?= h($pid) ?>" class="btn btn-sm btn-outline-success">Read</a>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($totalPages > 1): ?>
          <nav>
            <ul class="pagination">
              <?php $base = 'news-events.php?' . http_build_query(array_filter(['type' => $typeFilter ?: null])); ?>
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page > 1 ? $base . '&page=' . ($page - 1) : '#' ?>">Prev</a>
              </li>
              <?php for ($i = 1; $i <= $totalPages && $i <= 10; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="<?= $base . '&page=' . $i ?>"><?= $i ?></a></li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page < $totalPages ? $base . '&page=' . ($page + 1) : '#' ?>">Next</a>
              </li>
            </ul>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <footer class="bg-success text-white py-4 mt-5">
    <div class="container text-center">
      <p class="mb-1">&copy; <?= date('Y'); ?> Greenfield Academy. All rights reserved.</p>
    </div>
  </footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
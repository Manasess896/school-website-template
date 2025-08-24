<?php
session_start();

require_once '../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

use MongoDB\Client;
use Dotenv\Dotenv;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (!isset($_SESSION['user_id']) && !isset($_SESSION['username'])) {
  header('Location: login');
  exit;
}


//  session & Logout Handling

if (isset($_GET['logout'])) {
  session_unset();
  session_destroy();
  setcookie(session_name(), '', time() - 3600, '/');
  header('Location: login');
  exit;
}
//  atabase Connections & Collections

try {
  $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $mydatabase = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($uri);
  $db = $client->$mydatabase;
  $collection = $db->admin;
  $attemptsCollection = $db->attempts;
  $newsletterCollection = $db->newsletter;
  $contactsCollection = $db->contacts;
  $newsEventsCollection = $db->news_events;
  $imageMetadataCollection = $db->image_metadata;
} catch (\Throwable $e) {
  die('Database connection error');
}

try {
  $adminUser = $collection->findOne(['_id' => new \MongoDB\BSON\ObjectId($_SESSION['user_id'])]);

  if ($adminUser) {
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_id'] = (string) $adminUser['_id'];
    $_SESSION['user_role'] = $adminUser['role'] ?? 'ADMIN';
    $_SESSION['user_name'] = $adminUser['username'];
    $_SESSION['user_email'] = $adminUser['email'];
    $_SESSION['2fa_passed'] = $adminUser['2fa_passed'] ?? false;

    if (isset($adminUser['allowed_permissions'])) {
      $ap = $adminUser['allowed_permissions'];
      if ($ap instanceof \MongoDB\Model\BSONArray) {
        $_SESSION['allowed_permissions'] = $ap->getArrayCopy();
      } elseif (is_array($ap)) {
        $_SESSION['allowed_permissions'] = array_values($ap);
      } else {
        $_SESSION['allowed_permissions'] = [];
      }
    } else {
      $_SESSION['allowed_permissions'] = [];
    }
  } else {
    unset($_SESSION['is_admin'], $_SESSION['admin_id'], $_SESSION['user_role']);
    header('Location: login?error=auth');
    exit;
  }
} catch (\Throwable $e) {
  error_log("Admin verification failed: " . $e->getMessage());
  die("A critical error occurred during admin verification.");
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['user_name'];
$useremail = $_SESSION['user_email'];
$adminId = $_SESSION['admin_id'];


if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

//  permissions

$PERMISSION_DEFS = [

  'send_newsletter'    => 'Send Newsletter',
  'resolve_contacts'   => 'Resolve Contact Messages',
  'manage_news_events' => 'Manage News & Events'
];

function hasPermission(string $perm): bool
{
  $role = $_SESSION['user_role'] ?? '';
  if (strtoupper($role) === 'CEO') return true;
  $perms = $_SESSION['allowed_permissions'] ?? [];
  if ($perms instanceof \MongoDB\Model\BSONArray) {
    $perms = $perms->getArrayCopy();
  }
  if (!is_array($perms)) {
    return false;
  }
  return in_array($perm, $perms, true);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_event_news') {
  header('Content-Type: application/json');
  try {
    if (empty($_SESSION['is_admin']) || empty($_SESSION['admin_id'])) {
      echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
      exit;
    }
    if (!hasPermission('manage_news_events')) {
      echo json_encode(['ok' => false, 'error' => 'Permission denied']);
      exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
      exit;
    }
    $title = trim($_POST['event_title'] ?? '');
    $content = trim($_POST['event_content'] ?? '');
    $type = strtolower(trim($_POST['event_type'] ?? ''));
    $eventDateRaw = trim($_POST['event_date'] ?? '');
    $allowedTypes = ['event', 'news'];
    if ($title === '' || $content === '') {
      echo json_encode(['ok' => false, 'error' => 'Title and content required']);
      exit;
    }
    if (!in_array($type, $allowedTypes, true)) {
      $type = 'news';
    }
    $eventDate = null;
    if ($eventDateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateRaw)) {
      try {
        $eventDate = new UTCDateTime((new DateTime($eventDateRaw . ' 00:00:00'))->getTimestamp() * 1000);
      } catch (\Throwable $dtEx) {
        $eventDate = null;
      }
    }
    $imageFileId = null;
    $extractedMeta = null;
    if (!empty($_FILES['image']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
      if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['image']['tmp_name'];
        $origName = basename($_FILES['image']['name']);
        $size = (int)$_FILES['image']['size'];
        if ($size > 5 * 1024 * 1024) {
          echo json_encode(['ok' => false, 'error' => 'Image exceeds 5MB']);
          exit;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
          echo json_encode(['ok' => false, 'error' => 'Unsupported image type']);
          exit;
        }
        //extract image metadata
        $extractedMeta = [
          'original_name' => $origName,
          'mime' => $mime,
          'filesize' => $size,
          'width' => null,
          'height' => null,
          'exif' => null,
          'iptc' => null,
        ];
      
        $imgSize = @getimagesize($tmp, $info);
        if (is_array($imgSize)) {
          $extractedMeta['width'] = $imgSize[0] ?? null;
          $extractedMeta['height'] = $imgSize[1] ?? null;
       
          if (!empty($info['APP13'])) {
            $iptc = @iptcparse($info['APP13']);
            if ($iptc && is_array($iptc)) {
             
              $trimmed = [];
              foreach ($iptc as $k => $v) {
                $trimmed[$k] = is_array($v) ? array_slice($v, 0, 20) : $v;
              }
              $extractedMeta['iptc'] = $trimmed;
            }
          }
        }
      
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
          $exif = @exif_read_data($tmp, 'ANY_TAG', true, false);
          if ($exif && is_array($exif)) {
          
            $cleanExif = [];
            foreach ($exif as $section => $data) {
              if (!is_array($data)) continue;
              $sec = [];
              foreach ($data as $k => $v) {
                if (is_string($v)) {
                  $sec[$k] = mb_strimwidth($v, 0, 512, '...');
                } elseif (is_numeric($v) || is_bool($v)) {
                  $sec[$k] = $v;
                } elseif (is_array($v)) {
                  $sec[$k] = array_slice($v, 0, 20);
                }
              }
              if ($sec) $cleanExif[$section] = $sec;
            }
            if ($cleanExif) $extractedMeta['exif'] = $cleanExif;
          }
        }

       
        $cleanTmp = $tmp; 
        try {
          switch ($mime) {
            case 'image/jpeg':
              if (function_exists('imagecreatefromjpeg')) {
                $im = @imagecreatefromjpeg($tmp);
                if ($im) {
                  $cleanTmp = tempnam(sys_get_temp_dir(), 'imgclean_');
                  @imagejpeg($im, $cleanTmp, 90); 
                  imagedestroy($im);
                }
              }
              break;
            case 'image/png':
              if (function_exists('imagecreatefrompng')) {
                $im = @imagecreatefrompng($tmp);
                if ($im) {
                  $cleanTmp = tempnam(sys_get_temp_dir(), 'imgclean_');
               
                  imagesavealpha($im, true);
                  $pngQuality = 6; // 0-9
                  @imagepng($im, $cleanTmp, $pngQuality);
                  imagedestroy($im);
                }
              }
              break;
            case 'image/gif':
              if (function_exists('imagecreatefromgif')) {
                $im = @imagecreatefromgif($tmp);
                if ($im) {
                  $cleanTmp = tempnam(sys_get_temp_dir(), 'imgclean_');
                  @imagegif($im, $cleanTmp);
                  imagedestroy($im);
                }
              }
              break;
            case 'image/webp':
              if (function_exists('imagecreatefromwebp')) {
                $im = @imagecreatefromwebp($tmp);
                if ($im) {
                  $cleanTmp = tempnam(sys_get_temp_dir(), 'imgclean_');
                  @imagewebp($im, $cleanTmp, 90);
                  imagedestroy($im);
                }
              }
              break;
          }
        } catch (\Throwable $ignore) {
          $cleanTmp = $tmp;
        }

        $bucket = $db->selectGridFSBucket();
        $stream = fopen($cleanTmp, 'rb');
        $imageFileId = $bucket->uploadFromStream($origName, $stream, [
          'metadata' => [
            'uploaded_by' => $_SESSION['admin_id'],
            'mime' => $mime,
            'type' => $type,
            'stripped' => $cleanTmp !== $tmp
          ]
        ]);
        if (is_resource($stream)) fclose($stream);
        if ($cleanTmp !== $tmp && file_exists($cleanTmp)) @unlink($cleanTmp);
      } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        echo json_encode(['ok' => false, 'error' => 'File upload error']);
        exit;
      }
    }
    $doc = [
      'title' => $title,
      'content' => $content,
      'type' => $type,
      'created_at' => new UTCDateTime(),
      'created_by' => new ObjectId($_SESSION['admin_id']),
      'creator_name' => $_SESSION['user_name'] ?? 'Admin'
    ];
    if ($eventDate) {
      $doc['event_date'] = $eventDate;
    }
    if ($imageFileId) {
      $doc['image_file_id'] = $imageFileId;
    }
    $insertRes = $newsEventsCollection->insertOne($doc);
    $doc['_id'] = $insertRes->getInsertedId();
    // 4) Persist extracted metadata in separate collection
    if ($imageFileId && is_array($extractedMeta)) {
      try {
        $imageMetadataCollection->insertOne([
          'file_id' => $imageFileId,
          'post_id' => $doc['_id'],
          'uploaded_by' => new ObjectId($_SESSION['admin_id']),
          'type' => $type,
          'collected_at' => new UTCDateTime(),
          'metadata' => $extractedMeta
        ]);
      } catch (\Throwable $mx) {
        error_log('Image metadata save failed: ' . $mx->getMessage());
      }
    }
    $resp = [
      'ok' => true,
      'id' => (string)$doc['_id'],
      'title' => $doc['title'],
      'type' => $doc['type'],
      'content' => $doc['content'],
      'created_at' => date('Y-m-d H:i'),
      'creator' => $doc['creator_name'],
      'has_image' => (bool)$imageFileId
    ];
    if (isset($doc['event_date'])) {
      try {
        $resp['event_date'] = $doc['event_date']->toDateTime()->format('Y-m-d');
      } catch (\Throwable $e) {
      }
    }
    echo json_encode($resp);
  } catch (\Throwable $e) {
    error_log('Add event/news error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_event_news') {
  header('Content-Type: application/json');
  try {
    if (empty($_SESSION['is_admin']) || empty($_SESSION['admin_id'])) {
      echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
      exit;
    }
    if (!hasPermission('manage_news_events')) {
      echo json_encode(['ok' => false, 'error' => 'Permission denied']);
      exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
      exit;
    }
    $id = $_POST['id'] ?? '';
    if (!preg_match('/^[a-f0-9]{24}$/i', $id)) {
      echo json_encode(['ok' => false, 'error' => 'Invalid id']);
      exit;
    }
    $objId = new ObjectId($id);
    $doc = $newsEventsCollection->findOne(['_id' => $objId]);
    if (!$doc) {
      echo json_encode(['ok' => false, 'error' => 'Not found']);
      exit;
    }
    $newsEventsCollection->deleteOne(['_id' => $objId]);
    if (isset($doc['image_file_id']) && $doc['image_file_id'] instanceof ObjectId) {
      try {
        $bucket = $db->selectGridFSBucket();
        $bucket->delete($doc['image_file_id']);
        // Also delete associated metadata documents
        try {
          $imageMetadataCollection->deleteMany([
            '$or' => [
              ['file_id' => $doc['image_file_id']],
              ['post_id' => $objId]
            ]
          ]);
        } catch (\Throwable $mx) {
          error_log('Image metadata delete fail: ' . $mx->getMessage());
        }
      } catch (\Throwable $dx) {
        error_log('GridFS delete fail: ' . $dx->getMessage());
      }
    }
    echo json_encode(['ok' => true]);
  } catch (\Throwable $e) {
    error_log('Delete event/news error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
  }
  exit;
}


try {
  $newsEvents = $newsEventsCollection->find([], ['sort' => ['created_at' => -1], 'limit' => 50]);
} catch (Exception $e) {
  $newsEvents = [];
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_resolved') {
  header('Content-Type: application/json');
  try {
    if (empty($_SESSION['is_admin']) || empty($_SESSION['admin_id'])) {
      echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
      exit;
    }
    if (!hasPermission('resolve_contacts')) {
      echo json_encode(['ok' => false, 'error' => 'Permission denied']);
      exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
      exit;
    }
    $contactId = $_POST['contact_id'] ?? '';
    if (!is_string($contactId) || !preg_match('/^[a-f0-9]{24}$/i', $contactId)) {
      echo json_encode(['ok' => false, 'error' => 'Invalid contact id']);
      exit;
    }
    $result = $contactsCollection->updateOne(
      ['_id' => new ObjectId($contactId)],
      ['$set' => ['is_resolved' => true, 'resolved_at' => new UTCDateTime()]]
    );
    if ($result->getModifiedCount() === 0 && $result->getMatchedCount() === 0) {
      echo json_encode(['ok' => false, 'error' => 'Contact not found']);
      exit;
    }
    echo json_encode(['ok' => true]);
  } catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
  }
  exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_newsletter') {
  header('Content-Type: application/json');
  try {
    if (empty($_SESSION['is_admin']) || empty($_SESSION['admin_id'])) {
      echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
      exit;
    }
    if (!hasPermission('send_newsletter')) {
      echo json_encode(['ok' => false, 'error' => 'Permission denied']);
      exit;
    }
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
      exit;
    }
    $unsubscribe = $_ENV['UNSUBSCRIBE_NEWSLETTER_URL'] ?? getenv('UNSUBSCRIBE_NEWSLETTER_URL') ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    if ($subject === '' || $body === '') {
      echo json_encode(['ok' => false, 'error' => 'Subject and message are required']);
      exit;
    }


    $smtpHost = $_ENV['MAIL_HOST'] ?? getenv('MAIL_HOST') ?? '';
    $smtpPort = (int)($_ENV['MAIL_PORT'] ?? getenv('MAIL_PORT') ?? 587);
    $smtpUser = $_ENV['MAIL_USERNAME'] ?? getenv('MAIL_USERNAME') ?? '';
    $smtpPass = $_ENV['MAIL_PASSWORD'] ?? getenv('MAIL_PASSWORD') ?? '';
    $smtpSecure = $_ENV['MAIL_ENCRYPTION'] ?? getenv('MAIL_ENCRYPTION') ?? 'tls';
    $fromEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?? $smtpUser;
    $fromName = 'newsletter';

    if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
      echo json_encode(['ok' => false, 'error' => 'Mail server is not configured']);
      exit;
    }


    $cursor = $newsletterCollection->find([], ['projection' => ['email' => 1]]);
    $emails = [];
    foreach ($cursor as $sub) {
      if (!empty($sub['email']) && filter_var($sub['email'], FILTER_VALIDATE_EMAIL)) {
        $emails[strtolower((string)$sub['email'])] = true;
      }
    }
    $emails = array_keys($emails);
    if (count($emails) === 0) {
      echo json_encode(['ok' => false, 'error' => 'No subscribers found']);
      exit;
    }


    $mailer = new PHPMailer(true);
    $successCount = 0;
    $failureCount = 0;
    $failedEmails = [];

    try {
      $mailer->isSMTP();
      $mailer->Host = $smtpHost;
      $mailer->SMTPAuth = true;
      $mailer->Username = $smtpUser;
      $mailer->Password = $smtpPass;
      if ($smtpSecure) {
        $mailer->SMTPSecure = $smtpSecure;
      }
      $mailer->Port = $smtpPort;
      $mailer->CharSet = 'UTF-8';
      $mailer->setFrom($fromEmail, $fromName);
      $mailer->isHTML(true);
      $mailer->Subject = $subject;

      $baseHtmlBody = nl2br($body);
      $baseAltBody  = $body;
      $unsubscribeFooterHtml = '';
      $unsubscribeFooterText = '';
      if ($unsubscribe) {
        $safeUnsub = htmlspecialchars($unsubscribe, ENT_QUOTES, 'UTF-8');
        $unsubscribeFooterHtml = '<hr style="margin-top:25px;" />'
          . '<p style="font-size:12px;color:#666;margin:8px 0 0;">If you no longer wish to receive these emails you can '
          . '<a href="' . $safeUnsub . '" target="_blank" rel="noopener">unsubscribe here</a>. </p>'
          . '<p>You will be asked to confirm your email.</p>';
        $unsubscribeFooterText = "\n\nUnsubscribe: " . $unsubscribe;
      }

      foreach ($emails as $em) {
        try {
          $mailer->clearAddresses();
          $mailer->addAddress($em);
          $mailer->Body    = $baseHtmlBody . $unsubscribeFooterHtml;
          $mailer->AltBody = $baseAltBody . $unsubscribeFooterText;
          $mailer->send();
          $successCount++;
        } catch (PHPMailerException $mex) {
          $failureCount++;
          $failedEmails[] = $em;
        }
      }

      echo json_encode(['ok' => true, 'success' => $successCount, 'failed' => $failureCount, 'failed_emails' => $failedEmails]);
    } catch (PHPMailerException $mex) {
      echo json_encode(['ok' => false, 'error' => 'Mailer error: ' . $mex->getMessage()]);
    }
  } catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Unexpected error']);
  }
  exit;
}

try {
  $messages = $contactsCollection->find([], [
    'sort' => ['submitted_at' => -1]
  ]);
} catch (Exception $e) {
  die('Failed to load contact messages');
}

try {
  $subscribers = $newsletterCollection->find([], ['projection' => ['email' => 1], 'sort' => ['email' => 1]]);
} catch (Exception $e) {
  $subscribers = [];
}

$userEmail = $_SESSION['user_email'];
$user = $collection->findOne(['email' => $userEmail]);

$userRole = $user['role'] ?? $_SESSION['user_role'] ?? null;
$userName = $user['username'] ?? $_SESSION['user_name'] ?? 'User';


$canManageNewsEvents = hasPermission('manage_news_events');
$canSendNewsletter = hasPermission('send_newsletter');
$canResolveContacts = hasPermission('resolve_contacts');

if (!$userRole) {
  header('Location: /login?error=norole');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die("CSRF validation failed.");
  }


  if (isset($_POST['delete_admin_id'])) {
    if (!hasPermission('manage_admins')) {
      $error = 'Permission denied.';
    } else {
      $deleteId = $_POST['delete_admin_id'];
      try {
        $objectId = new MongoDB\BSON\ObjectId($deleteId);
        $adminToDelete = $collection->findOne(['_id' => $objectId]);
        if ($adminToDelete) {
          $targetRole = strtoupper($adminToDelete['role'] ?? '');

          if ($targetRole === 'CEO') {
            $error = 'Cannot delete CEO account.';
          } else {
            $deleteResult = $collection->deleteOne(['_id' => $objectId]);
            $contactsCollection->deleteMany(['admin_id' => $deleteId]);
            $attemptsCollection->deleteMany(['admin_id' => $deleteId]);
            if ($deleteResult->getDeletedCount() > 0) {
              $success = "Admin user '" . htmlspecialchars($adminToDelete['username'] ?? 'Unknown') . "' deleted.";
            } else {
              $error = 'Delete failed.';
            }
          }
        } else {
          $error = 'Admin not found.';
        }
      } catch (\Throwable $e) {
        $error = 'Failed to delete admin: ' . $e->getMessage();
      }
    }
  }

  if (isset($_POST['email'])) {
    if (!hasPermission('manage_admins')) {
      $error = 'Permission denied.';
    }
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
    } else if ($role === 'CEO') {

      $existingCEO = $collection->findOne(['role' => 'CEO']);
      if ($existingCEO) {
        $error = "A CEO already exists. You cannot add another CEO.";
      } else {

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
            $success = "Admin user '" . htmlspecialchars($username) . "' has been successfully created with role '" . htmlspecialchars($role) . "'.";
          } else {
            $error = "Failed to create user. Please try again.";
          }
        }
      }
    } else {

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
          $success = "Admin user '" . htmlspecialchars($username) . "' has been successfully created with role '" . htmlspecialchars($role) . "'.";
        } else {
          $error = "Failed to create user. Please try again.";
        }
      }
    }
  }
}

//sweet alert
if (isset($error) && !empty($error)) {
  echo "<script>
    Swal.fire({ 
      icon: 'error', 
      title: 'Error', 
      text: " . json_encode($error) . ", 
      confirmButtonColor: '#d33',
      confirmButtonText: 'OK'
    });
  </script>";
} elseif (isset($success) && !empty($success)) {
  echo "<script>
    Swal.fire({ 
      icon: 'success', 
      title: 'Success', 
      text: " . json_encode($success) . ", 
      confirmButtonColor: '#198754',
      confirmButtonText: 'OK'
    }).then(() => { 
      window.location.reload(); 
    });
  </script>";
}


//show admins
try {
  $admins = $collection->find([], [
    'projection' => ['username' => 1, 'email' => 1, 'role' => 1, 'allowed_permissions' => 1],
    'sort' => ['username' => 1]
  ])->toArray();
} catch (Exception $e) {
  $admins = [];
}

//only ceo can update the admins permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
  header('Content-Type: application/json');
  if (strtoupper($userRole) !== 'CEO') {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
  }
  if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
    exit;
  }
  $targetId = $_POST['admin_id'] ?? '';
  if (!preg_match('/^[a-f0-9]{24}$/i', $targetId)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid admin id']);
    exit;
  }
  $perms = $_POST['perms'] ?? [];
  if (!is_array($perms)) $perms = [];
  $validKeys = array_keys($PERMISSION_DEFS);
  $filtered = [];
  foreach (array_unique($perms) as $p) {
    if (in_array($p, $validKeys, true)) $filtered[] = $p;
  }
  $target = $collection->findOne(['_id' => new ObjectId($targetId)]);
  if (!$target) {
    echo json_encode(['ok' => false, 'error' => 'Admin not found']);
    exit;
  }
  if (isset($target['role']) && strtoupper($target['role']) === 'CEO') {
    echo json_encode(['ok' => false, 'error' => 'Cannot modify CEO']);
    exit;
  }
  try {
    $collection->updateOne(['_id' => new ObjectId($targetId)], ['$set' => ['allowed_permissions' => $filtered]]);
    echo json_encode(['ok' => true, 'permissions' => $filtered]);
  } catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
  }
  exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-bootstrap-4/bootstrap-4.css" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>

<body class="admin-dashboard">
  <nav class="navbar navbar-expand-lg navbar-dark bg-success shadow-sm mb-4">
    <div class="container">
      <a class="navbar-brand fw-bold" href="#">Greenfield Academy Admin</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="adminNavbar">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <span class="navbar-text me-3">
              Welcome, <?= htmlspecialchars($userName) ?> (<?= htmlspecialchars($userRole) ?>)
            </span>
          </li>
          <li class="nav-item">
            <a href="?logout=1" class="btn btn-light">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container py-4">
    <?php if (strtoupper($userRole) === 'CEO'): ?>
      <div class="row mt-4">
        <div class="col-lg-6">
          <div class="card shadow">
            <div class="card-header">
              <h4><i class="fas fa-user-plus me-2"></i>Add New Admin User</h4>
            </div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>" />
                <div class="mb-3">
                  <label for="username" class="form-label">Username</label>
                  <input type="text" class="form-control" name="username" required />
                </div>
                <div class="mb-3">
                  <label for="role" class="form-label">Role</label>
                  <input type="text" class="form-control" name="role" required value="ADMIN" readonly />
                </div>
                <div class="mb-3">
                  <label for="email" class="form-label">Email Address</label>
                  <input type="email" class="form-control" name="email" required />
                </div>
                <div class="mb-3">
                  <label for="password" class="form-label">Password</label>
                  <input type="password" class="form-control" name="password" required minlength="6" />
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-plus"></i> Add Admin</button>
              </form>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="card shadow">
            <div class="card-header">
              <h4><i class="fas fa-users-cog me-2"></i>Admin Users</h4>
            </div>
            <div class="card-body">
              <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Username</th>
                      <th>Email</th>
                      <th>Role</th>
                      <th>Permissions</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($admins as $admin): ?>
                      <tr>
                        <td><?= htmlspecialchars($admin['username'] ?? '') ?></td>
                        <td><?= htmlspecialchars($admin['email'] ?? '') ?></td>
                        <td>
                          <span class="badge bg-<?= (strtoupper($admin['role'] ?? '') === 'CEO') ? 'danger' : 'secondary' ?>"><?= htmlspecialchars($admin['role'] ?? '') ?></span>
                        </td>
                        <td style="max-width:260px;">
                          <?php
                          $aperms = $admin['allowed_permissions'] ?? [];
                          if (strtoupper($admin['role'] ?? '') === 'CEO') {
                            echo '<span class="badge bg-danger">ALL</span>';
                          } elseif (!$aperms) {
                            echo '<span class="text-muted small">none</span>';
                          } else {
                            foreach ($aperms as $p) {
                              echo '<span class="badge bg-success me-1 mb-1">' . htmlspecialchars($p) . '</span>';
                            }
                          }
                          ?>
                        </td>
                        <td>
                          <div class="d-flex flex-wrap gap-1">
                            <?php if (strtoupper($userRole) === 'CEO' && strtoupper($admin['role'] ?? '') !== 'CEO'): ?>
                              <button type="button" class="btn btn-outline-primary btn-sm manage-perms-btn" data-admin='<?= json_encode([
                                                                                                                          'id' => (string)$admin['_id'],
                                                                                                                          'username' => $admin['username'] ?? '',
                                                                                                                          'perms' => $admin['allowed_permissions'] ?? []
                                                                                                                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>'><i class="fas fa-key">add permissions</i></button>
                            <?php endif; ?>
                            <?php if (($admin['_id'] ?? null) && ($admin['role'] ?? '') !== 'CEO' && ($admin['email'] ?? '') !== $useremail): ?>
                              <form method="POST" class="d-inline delete-admin-form" data-username="<?= htmlspecialchars($admin['username'] ?? '') ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>" />
                                <input type="hidden" name="delete_admin_id" value="<?= htmlspecialchars($admin['_id']) ?>" />
                                <button type="button" class="btn btn-danger btn-sm delete-admin-btn"><i class="bi bi-trash">delete</i></button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-info">Your permissions:
        <?php
        $myPerms = $_SESSION['allowed_permissions'] ?? [];
        if (!$myPerms) {
          echo '<span class="text-muted">none</span>';
        } else {
          foreach ($myPerms as $p) {
            echo '<span class="badge bg-success me-1">' . htmlspecialchars($p) . '</span>';
          }
        }
        ?>
      </div>
    <?php endif; ?>

    <?php if (!$canManageNewsEvents && !$canSendNewsletter && !$canResolveContacts && strtoupper($userRole) !== 'CEO'): ?>
      <div class="alert alert-warning">You currently have no administrative actions available. Please contact the CEO if you believe this is in error.</div>
    <?php endif; ?>



    <?php if ($canManageNewsEvents): ?>
      <div class="card mb-4 shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h4 class="mb-0"><i class="fas fa-calendar-plus me-2"></i>Add Event or News</h4>
        </div>
        <div class="card-body">
          <form id="event-news-form" method="POST" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>" />
            <input type="hidden" name="action" value="add_event_news" />
            <div class="col-12">
              <label class="form-label" for="eventTitle">Title</label>
              <input type="text" class="form-control" id="eventTitle" name="event_title" required maxlength="150" />
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eventType">Type</label>
              <select class="form-select" id="eventType" name="event_type">
                <option value="event">Event</option>
                <option value="news" selected>News</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eventDate">Event Date (optional)</label>
              <input type="date" class="form-control" id="eventDate" name="event_date" />
            </div>
            <div class="col-md-4">
              <label class="form-label" for="eventImage">Image (optional, max 5MB)</label>
              <input type="file" class="form-control" id="eventImage" name="image" accept="image/*" />
            </div>
            <div class="col-12">
              <label class="form-label" for="eventContent">Content</label>
              <textarea class="form-control" id="eventContent" name="event_content" rows="4" required></textarea>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2">
              <button type="reset" class="btn btn-outline-secondary">Reset</button>
              <button type="submit" class="btn btn-success" id="btn-add-post"><i class="fas fa-plus me-1"></i>Add Post</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb-4 shadow">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Latest News & Events</h5>
        <small class="text-muted">Showing most recent (max 50)</small>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-striped table-hover align-middle mb-0" id="posts-table">
            <thead class="table-light">
              <tr>
                <th>Title</th>
                <th>Type</th>
                <th>Event Date</th>
                <th>Created</th>
                <th>Image</th>
                <th>By</th>
                <?php if ($canManageNewsEvents): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php
              $hasPostRows = false;
              foreach ($newsEvents as $post):
                $hasPostRows = true;
                $pid = (string)$post['_id'];
                $ptitle = htmlspecialchars($post['title'] ?? '', ENT_QUOTES, 'UTF-8');
                $ptype = htmlspecialchars($post['type'] ?? '', ENT_QUOTES, 'UTF-8');
                $pcontent = htmlspecialchars(mb_strimwidth($post['content'] ?? '', 0, 120, '...'), ENT_QUOTES, 'UTF-8');
                $pcreated = isset($post['created_at']) && $post['created_at'] instanceof UTCDateTime ? $post['created_at']->toDateTime()->format('Y-m-d H:i') : '';
                $peventDate = isset($post['event_date']) && $post['event_date'] instanceof UTCDateTime ? $post['event_date']->toDateTime()->format('Y-m-d') : '';
                $pimg = isset($post['image_file_id']);
                $pby = htmlspecialchars($post['creator_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
              ?>
                <tr id="post-row-<?= $pid ?>">
                  <td title="<?= $pcontent ?>"><?= $ptitle ?></td>
                  <td class="text-capitalize"><?= $ptype ?></td>
                  <td><?= $peventDate ?: '-' ?></td>
                  <td><?= $pcreated ?></td>
                  <td><?= $pimg ? '<span class="badge bg-info">Yes</span>' : '-' ?></td>
                  <td><?= $pby ?></td>
                  <?php if ($canManageNewsEvents): ?>
                    <td>
                      <button class="btn btn-sm btn-danger btn-delete-post" data-id="<?= $pid ?>"><i class="fas fa-trash"></i></button>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
              <?php if (!$hasPostRows): ?>
                <tr>
                  <td colspan="<?= $canManageNewsEvents ? '7' : '6' ?>" class="text-center py-4">No posts yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- ===================== MODULE: Newsletter ===================== -->
    <?php if ($canSendNewsletter): ?>
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Send Newsletter</strong>
          <div>
            <button class="btn btn-sm btn-outline-light" id="toggle-subscribers">Show subscribers</button>
          </div>
        </div>
        <div class="card-body">
          <form id="newsletter-form" class="row gy-3">
            <div class="col-12">
              <label class="form-label">Subject</label>
              <input type="text" name="subject" class="form-control" placeholder="Newsletter subject" required />
            </div>
            <div class="col-12">
              <label class="form-label">Message</label>
              <textarea name="body" class="form-control" rows="6" placeholder="Write your newsletter message..." required></textarea>
            </div>
            <div class="col-12" id="subscribers-wrapper" style="display:none;">
              <label class="form-label">Subscribers (read-only)</label>
              <select class="form-select" size="6" multiple disabled>
                <?php if ($subscribers): foreach ($subscribers as $s): $eml = htmlspecialchars((string)($s['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                    if ($eml === '') continue; ?>
                    <option><?= $eml ?></option>
                <?php endforeach;
                endif; ?>
              </select>
              <div class="form-text">List is hidden by default to save space.</div>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary" id="btn-send-newsletter">Send to all subscribers</button>
              <span class="text-muted" id="newsletter-hint">Uses SMTP settings from .env</span>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($canResolveContacts): ?>
      <div class="card">
        <div class="card-header">
          <strong>Contact Messages</strong>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>From</th>
                  <th>Email</th>
                  <th>Subject</th>
                  <th>Message</th>
                  <th>Submitted</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $hasRows = false;
                foreach ($messages as $msg):
                  $hasRows = true;
                  $id = (string)$msg['_id'];
                  $name = htmlspecialchars($msg['name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                  $email = htmlspecialchars($msg['email'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
                  $subject = htmlspecialchars($msg['subject'] ?? '-', ENT_QUOTES, 'UTF-8');
                  $message = htmlspecialchars($msg['message'] ?? '-', ENT_QUOTES, 'UTF-8');
                  $submittedAt = isset($msg['submitted_at']) && $msg['submitted_at'] instanceof UTCDateTime
                    ? $msg['submitted_at']->toDateTime()->format('Y-m-d H:i')
                    : htmlspecialchars((string)($msg['submitted_at'] ?? '-'), ENT_QUOTES, 'UTF-8');
                  $isResolved = !empty($msg['is_resolved']);
                ?>
                  <tr id="row-<?= $id ?>">
                    <td><?= $name ?></td>
                    <td><a href="mailto:<?= $email ?>"><?= $email ?></a></td>
                    <td><?= $subject ?></td>
                    <td style="max-width: 360px; white-space: normal;"><?= $message ?></td>
                    <td><?= $submittedAt ?></td>
                    <td>
                      <?php if ($isResolved): ?>
                        <span class="badge bg-success" id="status-<?= $id ?>">Resolved</span>
                      <?php else: ?>
                        <span class="badge bg-warning text-dark" id="status-<?= $id ?>">Open</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <button
                        class="btn btn-sm btn-primary btn-mark-resolved"
                        data-id="<?= $id ?>"
                        <?= $isResolved ? 'disabled' : '' ?>>Mark Resolved</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (!$hasRows): ?>
                  <tr>
                    <td colspan="7" class="text-center py-4">No contact messages found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
    // Submit news/event post via AJAX
    const postForm = document.getElementById('event-news-form');
    if (postForm) {
      postForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = document.getElementById('btn-add-post');
        btn.disabled = true;
        const fd = new FormData(postForm);
        try {
          const res = await fetch(window.location.href, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
          });
          const data = await res.json().catch(() => ({
            ok: false,
            error: 'Invalid server response'
          }));
          if (data.ok) {
            await Swal.fire({
              title: 'Saved',
              text: 'Post added successfully.',
              icon: 'success'
            });
            // prepend new row
            const tbody = document.querySelector('#posts-table tbody');
            if (tbody) {
              const tr = document.createElement('tr');
              tr.id = 'post-row-' + data.id;
              tr.innerHTML = `
                <td>${escapeHtml(data.title)}</td>
                <td class="text-capitalize">${escapeHtml(data.type)}</td>
                <td>${data.event_date ? escapeHtml(data.event_date) : '-'}</td>
                <td>${escapeHtml(data.created_at)}</td>
                <td>${data.has_image ? '<span class="badge bg-info">Yes</span>' : '-'}</td>
                <td>${escapeHtml(data.creator)}</td>
                <td><button class="btn btn-sm btn-danger btn-delete-post" data-id="${data.id}"><i class="fas fa-trash"></i></button></td>`;
              tbody.prepend(tr);
            }
            postForm.reset();
          } else {
            await Swal.fire({
              title: 'Error',
              text: data.error || 'Failed to add post',
              icon: 'error'
            });
          }
        } catch (err) {
          await Swal.fire({
            title: 'Error',
            text: 'Network error',
            icon: 'error'
          });
        } finally {
          btn.disabled = false;
        }
      });
    }

    document.addEventListener('click', async (e) => {
      const delBtn = e.target.closest('.btn-delete-post');
      if (!delBtn) return;
      const id = delBtn.getAttribute('data-id');
      if (!id) return;
      const confirm = await Swal.fire({
        title: 'Delete post?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#d33'
      });
      if (!confirm.isConfirmed) return;
      delBtn.disabled = true;
      try {
        const fd = new FormData();
        fd.append('action', 'delete_event_news');
        fd.append('id', id);
        fd.append('csrf_token', CSRF_TOKEN);
        const res = await fetch(window.location.href, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });
        const data = await res.json().catch(() => ({
          ok: false,
          error: 'Invalid server response'
        }));
        if (data.ok) {
          const row = document.getElementById('post-row-' + id);
          if (row) row.remove();
          await Swal.fire('Deleted', 'Post removed.', 'success');
        } else {
          await Swal.fire('Error', data.error || 'Failed to delete post', 'error');
          delBtn.disabled = false;
        }
      } catch (err) {
        await Swal.fire('Error', 'Network error', 'error');
        delBtn.disabled = false;
      }
    });

    function escapeHtml(str) {
      if (str === undefined || str === null) return '';
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
    // Toggle subscribers dropdown
    document.getElementById('toggle-subscribers').addEventListener('click', (e) => {
      const w = document.getElementById('subscribers-wrapper');
      const btn = e.currentTarget;
      const visible = w.style.display !== 'none';
      w.style.display = visible ? 'none' : '';
      btn.textContent = visible ? 'Show subscribers' : 'Hide subscribers';
    });

    document.getElementById('newsletter-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const formEl = e.currentTarget;
      const subject = formEl.subject.value.trim();
      const body = formEl.body.value.trim();
      if (!subject || !body) {
        await Swal.fire({
          title: 'Missing fields',
          text: 'Subject and message are required.',
          icon: 'warning'
        });
        return;
      }
      const confirm = await Swal.fire({
        title: 'Send to all subscribers?',
        text: 'This will send the newsletter to all current subscribers.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Send',
      });
      if (!confirm.isConfirmed) return;

      const btn = document.getElementById('btn-send-newsletter');
      btn.disabled = true;
      btn.classList.add('disabled');
      try {
        const form = new FormData();
        form.append('action', 'send_newsletter');
        form.append('subject', subject);
        form.append('body', body);
        form.append('csrf_token', CSRF_TOKEN);
        const res = await fetch(window.location.href, {
          method: 'POST',
          body: form,
          credentials: 'same-origin'
        });
        const data = await res.json().catch(() => ({
          ok: false,
          error: 'Invalid server response'
        }));
        if (data.ok) {
          await Swal.fire({
            title: 'Sent',
            text: `Newsletter sent to ${data.total} subscribers.`,
            icon: 'success'
          });
          formEl.reset();
        } else {
          await Swal.fire({
            title: 'Error',
            text: data.error || 'Failed to send newsletter.',
            icon: 'error'
          });
        }
      } catch (err) {
        await Swal.fire({
          title: 'Error',
          text: 'Network error. Please try again.',
          icon: 'error'
        });
      } finally {
        btn.disabled = false;
        btn.classList.remove('disabled');
      }
    });

    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('.btn-mark-resolved');
      if (!btn) return;
      const id = btn.getAttribute('data-id');
      if (!id) return;

      const confirm = await Swal.fire({
        title: 'Mark as resolved?',
        text: 'This will mark the conversation as resolved.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, mark resolved',
      });
      if (!confirm.isConfirmed) return;

      btn.disabled = true;
      btn.classList.add('disabled');
      try {
        const form = new FormData();
        form.append('action', 'mark_resolved');
        form.append('contact_id', id);
        form.append('csrf_token', CSRF_TOKEN);
        const res = await fetch(window.location.href, {
          method: 'POST',
          body: form,
          credentials: 'same-origin'
        });
        const data = await res.json().catch(() => ({
          ok: false,
          error: 'Invalid server response'
        }));
        if (data.ok) {
          const statusEl = document.getElementById(`status-${id}`);
          if (statusEl) {
            statusEl.textContent = 'Resolved';
            statusEl.classList.remove('bg-warning', 'text-dark');
            statusEl.classList.add('bg-success');
          }

          await Swal.fire('Success', 'Message marked as resolved.', 'success');
        } else {
          await Swal.fire('Error', data.error || 'Failed to update status.', 'error');
          btn.disabled = false;
          btn.classList.remove('disabled');
        }
      } catch (err) {
        await Swal.fire('Error', 'A network error occurred.', 'error');
        btn.disabled = false;
        btn.classList.remove('disabled');
      }
    });

    document.querySelectorAll('.delete-admin-form').forEach(form => {
      form.addEventListener('submit', function(e) {
        e.preventDefault();
        const username = this.dataset.username;
        Swal.fire({
          title: `Delete ${username}?`,
          text: "This will permanently delete the admin user. This action cannot be undone.",
          icon: 'warning',
          showCancelButton: true,
          confirmButtonColor: '#d33',
          cancelButtonColor: '#3085d6',
          confirmButtonText: 'Yes, delete it!'
        }).then((result) => {
          if (result.isConfirmed) {
            this.submit();
          }
        });
      });
    });
    const permDefs = <?= json_encode($PERMISSION_DEFS, JSON_PRETTY_PRINT) ?>;
    const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
    const manageBtns = document.querySelectorAll('.manage-perms-btn');
    manageBtns.forEach(btn => {
      btn.addEventListener('click', () => {
        const payload = JSON.parse(btn.getAttribute('data-admin'));
        openPermsModal(payload);
      });
    });

    function openPermsModal(data) {
      const current = new Set(data.perms || []);
      let html = '<form id="perms-edit-form" class="mt-3">';
      html += '<div class="row">';
      Object.keys(permDefs).forEach(key => {
        const checked = current.has(key) ? 'checked' : '';
        html += `<div class="col-12 col-md-6 mb-2"><div class="form-check">
          <input class="form-check-input" type="checkbox" id="perm-${key}" value="${key}" ${checked} />
          <label class="form-check-label small" for="perm-${key}"><strong>${key}</strong><br/><span class="text-muted">${permDefs[key]}</span></label>
        </div></div>`;
      });
      html += '</div></form>';
      Swal.fire({
        title: `Permissions: ${data.username}`,
        html,
        width: 700,
        showCancelButton: true,
        confirmButtonText: 'Save',
        focusConfirm: false,
        preConfirm: () => {
          const selected = Array.from(document.querySelectorAll('#perms-edit-form input[type=checkbox]:checked')).map(i => i.value);
          return selected;
        }
      }).then(res => {
        if (!res.isConfirmed) return;
        savePermissions(data.id, res.value);
      });
    }

    async function savePermissions(adminId, perms) {
      try {
        const form = new FormData();
        form.append('action', 'update_permissions');
        form.append('csrf_token', csrfToken);
        form.append('admin_id', adminId);
        perms.forEach(p => form.append('perms[]', p));
        const resp = await fetch(window.location.href, {
          method: 'POST',
          body: form,
          credentials: 'same-origin'
        });
        const data = await resp.json().catch(() => ({
          ok: false,
          error: 'Bad JSON'
        }));
        if (data.ok) {
          await Swal.fire('Saved', 'Permissions updated', 'success');
          window.location.reload();
        } else {
          Swal.fire('Error', data.error || 'Update failed', 'error');
        }
      } catch (e) {
        Swal.fire('Error', 'Network error', 'error');
      }
    }
  </script>
</body>

</html>
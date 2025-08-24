<?php
if(realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])){
  header('location:404');
  exit;
}
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\ObjectId;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$fileId = $_GET['file'] ?? '';
if (!preg_match('/^[a-f0-9]{24}$/i', $fileId)) {
  http_response_code(400);
  echo 'Invalid file id';
  exit;
}
try {
  $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
  $dbName = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');
  $client = new Client($uri);
  $db = $client->$dbName;
  $bucket = $db->selectGridFSBucket();
  $objectId = new ObjectId($fileId);

  $fileDoc = $db->selectCollection('fs.files')->findOne(['_id' => $objectId]);
  if (!$fileDoc) {
    http_response_code(404);
    echo 'Not found';
    exit;
  }
  $mime = $fileDoc['metadata']['mime'] ?? 'application/octet-stream';
  header('Content-Type: ' . $mime);
  header('Cache-Control: public, max-age=86400');
  header('ETag: "' . $fileId . '"');
  if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"' . $fileId . '"') {
    http_response_code(304);
    exit;
  }
  $stream = $bucket->openDownloadStream($objectId);
  while (!feof($stream)) {
    echo fread($stream, 8192);
  }
  fclose($stream);
} catch (Throwable $e) {
  http_response_code(500);
  echo 'Server error';
}
?>


<?php
//some thing is wrong my site is  not fetching api keys fro,m render envromental variables so am testing something 
require_once __DIR__ . '/vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

echo "<h2>Environment Variable Debug</h2>";

$vars = ['MONGODB_URI', 'MONGODB_DATABASE'];
foreach ($vars as $var) {
    $val = $_ENV[$var] ?? getenv($var) ?? null;
    if ($val) {
        echo "<p><b>$var</b>: " . htmlspecialchars($val) . "</p>";
    } else {
        echo "<p style='color:red;'><b>$var</b> is NOT set</p>";
    }
}

echo "<h2>MongoDB Connection Test</h2>";

try {
    $uri = $_ENV['MONGODB_URI'] ?? getenv('MONGODB_URI');
    $dbName = $_ENV['MONGODB_DATABASE'] ?? getenv('MONGODB_DATABASE');

    if (!$uri || !$dbName) {
        throw new Exception("Missing environment variables");
    }

    $client = new Client($uri);
    $db = $client->$dbName;

    $collections = $db->listCollections();
    echo "<p>✅ Connected successfully to MongoDB. Collections:</p><ul>";
    foreach ($collections as $coll) {
        echo "<li>" . htmlspecialchars($coll->getName()) . "</li>";
    }
    echo "</ul>";

} catch (\Throwable $e) {
    echo "<p style='color:red;'>❌ Connection failed: " . $e->getMessage() . "</p>";
}

?>
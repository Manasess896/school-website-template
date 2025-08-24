<?php
require_once 'vendor/autoload.php';
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
  header('location:404');
  exit;
}
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

function searchPexels($queries, $perPage = 1, $orientation = 'landscape')
{
  //its PEXELS_API_KEY NOT PEXEL_API_KEY
  $pexel_api_key = $_ENV['PEXELS_API_KEY'] ?? getenv('PEXELS_API_KEY');
  if (empty($pexel_api_key)) {
    return array_fill_keys(array_keys($queries), null);
  }

  $multi_handle = curl_multi_init();
  $handles = [];
  $results = [];

  foreach ($queries as $key => $query) {
    $url = "https://api.pexels.com/v1/search?query=" . urlencode($query) . "&per_page=" . intval($perPage) . "&orientation=" . $orientation;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      "Authorization: " . $pexel_api_key
    ]);
    $handles[$key] = $ch;
    curl_multi_add_handle($multi_handle, $ch);
  }

  $running = null;
  do {
    curl_multi_exec($multi_handle, $running);
  } while ($running);

  foreach ($handles as $key => $ch) {
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($http_code !== 200) {
      $results[$key] = null;
    } else {
      $results[$key] = json_decode(curl_multi_getcontent($ch), true);
    }
    curl_multi_remove_handle($multi_handle, $ch);
  }

  curl_multi_close($multi_handle);
  return $results;
}

function get_image_urls($result, $count = 1)
{
  $urls = [];
  if ($result && isset($result['photos'])) {
    for ($i = 0; $i < $count && $i < count($result['photos']); $i++) {
      $urls[] = $result['photos'][$i]['src']['large2x'];
    }
  }
  // return a placeholder if no images are found
  if (empty($urls)) {
    return ['assets/images/placeholder.jpg'];
  }
  return $urls;
}

// define all image queries
$image_queries = [
  'hero_slider' => 'school campus',
  'about_us' => 'diverse students group',
  'academics' => 'lecture hall',
  'admissions' => 'university admissions office',
  'facilities' => 'school sports facilities',
  'news_events' => 'students in an event',
  'gallery' => 'art class',
  'contact_us' => 'school reception',
  'why_choose_us' => 'teacher with students',
  'our_programs' => 'students in a lab',
  'student_life' => 'students collaborating',
  'testimonials' => 'graduating students'
];

// fetch all images in parallel, fetching more for sections that need more images
$pexel_results = searchPexels([
  'hero_slider' => 'school campus',
  'about_us' => 'diverse students group',
  'academics' => 'lecture hall',
  'admissions' => 'university admissions office',
  'facilities' => 'school sports facilities',
  'news_events' => 'students in an event',
  'gallery' => 'art class',
  'contact_us' => 'school reception',
  'why_choose_us' => 'teacher with students',
  'our_programs' => 'students in a lab',
  'student_life' => 'students collaborating',
  'testimonials' => 'graduating students',
  'classroom' => 'modern classroom'
], 10);
// assign images for each section
$hero_images = get_image_urls($pexel_results['hero_slider'] ?? null, 5);
$about_us_image = get_image_urls($pexel_results['about_us'] ?? null, 1)[0];
$academics_images = get_image_urls($pexel_results['academics'] ?? null, 2);
$admissions_image = get_image_urls($pexel_results['admissions'] ?? null, 1)[0];
$facilities_images = get_image_urls($pexel_results['facilities'] ?? null, 3);
$news_events_images = get_image_urls($pexel_results['news_events'] ?? null, 3);
$gallery_images = get_image_urls($pexel_results['gallery'] ?? null, 6);
$contact_us_image = get_image_urls($pexel_results['contact_us'] ?? null, 1)[0];
$why_choose_us_images = get_image_urls($pexel_results['why_choose_us'] ?? null, 3);
$our_programs_images = get_image_urls($pexel_results['our_programs'] ?? null, 2);
$student_life_images = get_image_urls($pexel_results['student_life'] ?? null, 2);
$testimonial_images = get_image_urls($pexel_results['testimonials'] ?? null, 3);
$classroom_images = get_image_urls($pexel_results['classroom'] ?? null, 2);

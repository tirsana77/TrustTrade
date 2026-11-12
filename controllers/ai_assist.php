<?php
// JSON endpoint behind the hint box on the create-listing page.
session_start();
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/AiInsights.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}
$u = (new User())->findById($_SESSION['user_id']);
if (!$u || empty($u['student_verified'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150);
$desc  = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 2000);
$cat   = mb_substr(trim((string) ($_POST['category'] ?? '')), 0, 60);
$price = isset($_POST['price']) && is_numeric($_POST['price']) ? (float) $_POST['price'] : null;

if (mb_strlen($title) < 3) {
    echo json_encode(['price' => null, 'assessment' => 'unknown', 'category' => null, 'warnings' => []]);
    exit;
}
try {
    echo json_encode((new AiInsights())->assist($title, $desc, $cat, $price));
} catch (Throwable $e) {
    echo json_encode(['price' => null, 'assessment' => 'unknown', 'category' => null, 'warnings' => []]);
}
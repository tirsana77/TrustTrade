<?php
// Shows a face photo to the person it belongs to, and to administrators. Nobody else can open it.
session_start();

require_once __DIR__ . '/../models/User.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$user = new User();
$me   = $user->findById((int) $_SESSION['user_id']);
$targetId = (int) ($_GET['user'] ?? 0);

if ($me === null || ($targetId !== (int) $me['id'] && empty($me['is_admin']))) {
    http_response_code(403);
    exit('Forbidden');
}

$target = $user->findById($targetId);
if ($target === null || empty($target['profile_photo'])) {
    http_response_code(404);
    exit('Not found');
}

$path = __DIR__ . '/../storage/student_ids/' . basename($target['profile_photo']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
readfile($path);
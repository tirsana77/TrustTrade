<?php
// Shows the evidence photo of a dispute. Only the administrator and the two people on the order may see it.
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Dispute.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$me = (new User())->findById((int) $_SESSION['user_id']);
$d  = (new Dispute())->findById((int) ($_GET['id'] ?? 0));
if ($d === null || empty($d['evidence_photo'])) {
    http_response_code(404);
    exit('Not found');
}

$db = (new Database())->getConnection();
$st = $db->prepare('SELECT buyer_id, seller_id FROM orders WHERE id = :o');
$st->execute(['o' => $d['order_id']]);
$o = $st->fetch();
$uid = (int) $_SESSION['user_id'];
if (empty($me['is_admin']) && (!$o || ($uid !== (int) $o['buyer_id'] && $uid !== (int) $o['seller_id']))) {
    http_response_code(403);
    exit('Forbidden');
}

$path = __DIR__ . '/../storage/dispute_evidence/' . basename($d['evidence_photo']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}
$info = @getimagesize($path);
$mime = $info ? $info['mime'] : '';
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit('Not found');
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
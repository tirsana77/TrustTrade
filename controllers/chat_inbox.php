<?php
/**
 * The chat pop-ups ask this page what is unread. It answers with JSON only.
 *   GET chat_inbox.php   returns one entry per conversation that has unread messages for the logged-in person
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/chat_ui.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in again.']);
    exit;
}
$me = (int) $_SESSION['user_id'];

$db = (new Database())->getConnection();
$stmt = $db->prepare(
    "SELECT m.listing_id, m.sender_id AS other_id, u.full_name, l.title, x.last_id, x.cnt, m.body
     FROM (SELECT listing_id, sender_id, MAX(id) AS last_id, COUNT(*) AS cnt
           FROM messages
           WHERE receiver_id = :me AND is_read = 0 AND kind = 'text'
           GROUP BY listing_id, sender_id) x
     JOIN messages m ON m.id = x.last_id
     JOIN users u ON u.id = m.sender_id
     JOIN listings l ON l.id = m.listing_id
     ORDER BY x.last_id DESC
     LIMIT 10"
);
$stmt->execute(['me' => $me]);

$items = [];
foreach ($stmt->fetchAll() as $r) {
    $items[] = [
        'listing'  => (int) $r['listing_id'],
        'with'     => (int) $r['other_id'],
        'name'     => (string) $r['full_name'],
        'initials' => chatInitials((string) $r['full_name']),
        'title'    => (string) $r['title'],
        'snippet'  => mb_strimwidth((string) $r['body'], 0, 90, '...'),
        'last_id'  => (int) $r['last_id'],
        'count'    => (int) $r['cnt'],
    ];
}
echo json_encode(['items' => $items]);
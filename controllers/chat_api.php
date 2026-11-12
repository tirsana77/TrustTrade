<?php
/**
 * The live chat talks to this page. It answers with JSON only.
 *   GET  chat_api.php?listing=L&with=W&after=ID&sig=SIG   returns messages newer than ID (and marks them read)
 *   POST chat_api.php  listing, with, body, after         saves a new message, then returns the newer messages
 */
session_start();
require_once __DIR__ . '/../includes/chat_ui.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

[$currentUser, $me, $listing, $other, $listingId, $withId] = chatContext(true);
$messages = new Message();
$orders   = new Order();
$error    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim((string) ($_POST['body'] ?? ''));
    if (empty($currentUser['student_verified'])) {
        http_response_code(403);
        $error = 'Only verified students can send messages.';
    } elseif ($body === '') {
        http_response_code(422);
        $error = 'Please write a message first.';
    } elseif (mb_strlen($body) > 2000) {
        http_response_code(422);
        $error = 'The message is too long (up to 2000 characters).';
    } else {
        $messages->send($listingId, $me, $withId, $body);
    }
}

$after = (int) ($_POST['after'] ?? $_GET['after'] ?? 0);
$new   = $messages->since($listingId, $me, $withId, $after);
$messages->markThreadRead($listingId, $me, $withId);

$order = $orders->pairOrder($listingId, $me, $withId);
$sig   = chatOrderSignature($order);
$out = [
    'messages' => array_map(fn($m) => chatMessageData($m, $me), $new),
    'seen'     => $messages->seenUpTo($listingId, $me, $withId),
    'sig'      => $sig,
    'unread'   => $messages->unreadCount($me),
];
if ($error !== null) {
    $out['error'] = $error;
}
if ($sig !== (string) ($_POST['sig'] ?? $_GET['sig'] ?? '')) {
    $out['order_html'] = chatOrderCard($order, $me, (string) $other['full_name'], $listingId, $withId);
}
echo json_encode($out);
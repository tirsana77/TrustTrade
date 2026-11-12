<?php
session_start();

require_once __DIR__ . '/../includes/chat_ui.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];

$conversations = (new Message())->conversations($me);
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content chat-page">
        <div class="chat-app">
            <aside class="chat-side"><?= chatSidebar($conversations, $me) ?></aside>
            <section class="chat-main chat-placeholder">
                <div class="chat-placeholder-text">
                    <strong>Your messages</strong>
                    <p class="muted">Choose a chat on the left. To start a new one, open a listing and press "Message the seller".</p>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
<?php
session_start();

require_once __DIR__ . '/../includes/chat_ui.php';

[$currentUser, $me, $listing, $other, $listingId, $withId] = chatContext(false);

$messageModel = new Message();
$orderModel   = new Order();
$url = 'conversation.php?listing=' . $listingId . '&with=' . $withId;

// Plain form post (used when JavaScript is off). With JavaScript the page sends through chat_api.php instead.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = trim($_POST['body'] ?? '');
    if (empty($currentUser['student_verified'])) {
        $_SESSION['flash'] = ['error', 'Only verified students can send messages.'];
    } elseif ($body === '') {
        $_SESSION['flash'] = ['error', 'Please write a message first.'];
    } elseif (mb_strlen($body) > 2000) {
        $_SESSION['flash'] = ['error', 'The message is too long (up to 2000 characters).'];
    } else {
        $messageModel->send($listingId, $me, $withId, $body);
    }
    header('Location: ' . $url);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$messageModel->markThreadRead($listingId, $me, $withId);
$thread        = $messageModel->thread($listingId, $me, $withId);
$conversations = $messageModel->conversations($me);
$order         = $orderModel->pairOrder($listingId, $me, $withId);
$lastId        = $thread ? (int) end($thread)['id'] : 0;
$seen          = $messageModel->seenUpTo($listingId, $me, $withId);
$otherFirst    = explode(' ', trim((string) $other['full_name']))[0];
$canSend       = !empty($currentUser['student_verified']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Chat with <?= htmlspecialchars($otherFirst) ?> - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content chat-page">
        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
        <?php endif; ?>

        <div class="chat-app chat-open">
            <aside class="chat-side"><?= chatSidebar($conversations, $me, $listingId, $withId) ?></aside>

            <section class="chat-main" id="chat"
                     data-api="chat_api.php" data-listing="<?= $listingId ?>" data-with="<?= $withId ?>"
                     data-initials="<?= htmlspecialchars(chatInitials((string) $other['full_name'])) ?>" data-last="<?= $lastId ?>" data-seen="<?= $seen ?>" data-sig="<?= htmlspecialchars(chatOrderSignature($order)) ?>">
                <header class="chat-head">
                    <a class="chat-back" href="messages.php" aria-label="All chats">←</a>
                    <?= chatAvatar((string) $other['full_name'], 'avatar-lg') ?>
                    <div class="chat-head-text">
                        <strong><?= htmlspecialchars($other['full_name']) ?></strong>
                        <a href="view_listing.php?id=<?= $listingId ?>" class="chat-head-item"><?= htmlspecialchars($listing['title']) ?> · <?= number_format((float) $listing['price'], 0) ?> DKK</a>
                    </div>
                </header>

                <div class="chat-order" id="chatOrder"><?= chatOrderCard($order, $me, (string) $other['full_name'], $listingId, $withId) ?></div>

                <div class="chat-scroll" id="chatScroll">
                    <div class="chat-list-msgs" id="chatList">
                        <?php if (empty($thread)): ?>
                            <p class="muted chat-empty" id="chatEmpty">No messages yet. Say hello to <?= htmlspecialchars($otherFirst) ?>.</p>
                        <?php endif; ?>
                        <?php foreach ($thread as $m): ?>
                            <?php $system = (($m['kind'] ?? 'text') === 'system'); $mine = ((int) $m['sender_id'] === $me); ?>
                            <div class="msg <?= $system ? 'msg-system' : ($mine ? 'msg-me' : 'msg-them') ?>" data-id="<?= (int) $m['id'] ?>" data-ts="<?= htmlspecialchars($m['created_at']) ?>">
                                <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['body'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="chat-chips" id="chatChips">
                    <button type="button" data-text="Is this still available?">Is this still available?</button>
                    <button type="button" data-text="Can we meet on campus?">Can we meet on campus?</button>
                    <button type="button" data-text="What time works for you?">What time works for you?</button>
                    <button type="button" data-text="Thank you! 😊">Thank you! 😊</button>
                    <button type="button" data-text="👍">👍</button>
                </div>

                <?php if ($canSend): ?>
                    <form method="POST" class="chat-composer" id="chatForm" action="<?= htmlspecialchars($url) ?>">
                        <textarea name="body" id="chatInput" rows="1" maxlength="2000" placeholder="Write a message" aria-label="Write a message"></textarea>
                        <button type="submit" class="chat-send" id="chatSend" aria-label="Send">Send</button>
                    </form>
                <?php else: ?>
                    <p class="muted chat-locked">Only verified students can send messages.</p>
                <?php endif; ?>
                <div class="chat-error" id="chatError" role="status"></div>
            </section>
        </div>
    </div>
    <script src="../public/js/chat.js"></script>
</body>
</html>
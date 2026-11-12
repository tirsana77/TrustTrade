<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Notification.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];

$notificationModel = new Notification();
$items = $notificationModel->forUser($me);
// Mark as read after loading, so this visit still shows which ones were new
$notificationModel->markAllRead($me);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Notifications - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <h2>Notifications</h2>
        <?php if (empty($items)): ?>
            <p class="muted">Nothing yet. You will be told here when someone requests your item, when an order changes, and when an administrator decides on a problem report.</p>
        <?php else: ?>
            <ul class="notif-list">
                <?php foreach ($items as $n): ?>
                    <li class="notif-item <?= $n['is_read'] ? '' : 'notif-new' ?>">
                        <?php if (!empty($n['link'])): ?>
                            <a href="<?= htmlspecialchars($n['link']) ?>"><?= htmlspecialchars($n['message']) ?></a>
                        <?php else: ?>
                            <span><?= htmlspecialchars($n['message']) ?></span>
                        <?php endif; ?>
                        <small class="muted"><?= htmlspecialchars($n['created_at']) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</body>
</html>
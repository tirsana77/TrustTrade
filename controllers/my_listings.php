<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];

$listingModel = new Listing();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['listing_id'] ?? 0);
    $target = $listingModel->findById($id);
    $flash  = ['error', 'Something went wrong. Please try again.'];

    if ($target === null || (int) $target['user_id'] !== $me) {
        $flash = ['error', 'That listing was not found.'];
    } elseif ($target['status'] === 'held' && in_array($action, ['mark_sold', 'relist'], true)) {
        $flash = ['error', 'This listing is under review. An administrator will put it back when it has been checked.'];
    } elseif ($action === 'mark_sold') {
        $listingModel->setStatus($id, 'sold');
        $flash = ['success', 'Marked as sold.'];
    } elseif ($action === 'relist') {
        $listingModel->setStatus($id, 'active');
        $flash = ['success', 'Your listing is active again.'];
    } elseif ($action === 'delete') {
        if ($listingModel->delete($id, $me)) {
            $flash = ['success', 'Listing deleted.'];
        } else {
            $flash = ['error', 'This listing has buy or trade requests, so it cannot be deleted. Mark it as sold instead.'];
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: my_listings.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$listings = $listingModel->findByUser($me);
$isVerified = !empty($currentUser['student_verified']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>My listings - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
        <?php endif; ?>

        <div class="page-header-row">
            <h2>My listings</h2>
            <?php if ($isVerified): ?>
                <a href="create_listing.php" class="btn-inline">+ List an item</a>
            <?php endif; ?>
        </div>

        <?php if (!$isVerified): ?>
            <p class="muted">You need a verified account to create listings. <a href="upload_id.php">Verify now</a></p>
        <?php endif; ?>

        <?php if (empty($listings)): ?>
            <p class="muted">You have not listed anything yet.</p>
        <?php else: ?>
            <table class="data-table">
                <tr>
                    <th></th>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($listings as $l): ?>
                    <tr>
                        <td>
                            <?php if (!empty($l['images'])): ?>
                                <img src="../public/uploads/listings/<?= htmlspecialchars($l['images'][0]) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:6px;">
                            <?php endif; ?>
                        </td>
                        <td><a href="view_listing.php?id=<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['title']) ?></a></td>
                        <td><?= number_format((float) $l['price'], 2) ?> DKK</td>
                        <td><?= htmlspecialchars($l['category']) ?></td>
                        <td><span class="pill pill-<?= htmlspecialchars($l['status']) ?>"><?= htmlspecialchars($l['status']) ?></span></td>
                        <td>
                            <a href="edit_listing.php?id=<?= (int) $l['id'] ?>" class="btn-small btn-ghost">Edit</a>

                            <form method="POST" class="inline-form">
                                <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                                <?php if ($l['status'] === 'held'): ?>
                                    <span class="muted">Under review<?= !empty($l['hold_reason']) ? ': ' . htmlspecialchars($l['hold_reason']) : '' ?></span>
                                <?php elseif ($l['status'] === 'active'): ?>
                                    <input type="hidden" name="action" value="mark_sold">
                                    <button type="submit" class="btn-small">Mark as sold</button>
                                <?php else: ?>
                                    <input type="hidden" name="action" value="relist">
                                    <button type="submit" class="btn-small">Relist</button>
                                <?php endif; ?>
                            </form>

                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this listing permanently?');">
                                <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn-small btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
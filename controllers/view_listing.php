<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Automation.php';
require_once __DIR__ . '/../models/AiInsights.php';

// Guests (not logged in) can look at a listing. $currentUser stays null for them.
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = (new User())->findById((int) $_SESSION['user_id']);
    if (!$currentUser) {
        unset($_SESSION['user_id'], $_SESSION['user_name']);
    }
}
$isGuest = ($currentUser === null);

$listingModel = new Listing();
$listingId = (int) ($_GET['id'] ?? 0);
$listing = $listingModel->findById($listingId);

if ($listing === null) {
    http_response_code(404);
    $errorMessage = 'Listing not found.';
    include __DIR__ . '/../includes/error_page.php';
    exit;
}

$me         = $isGuest ? 0 : (int) $currentUser['id'];
$sellerId   = (int) $listing['user_id'];
$isVerified = !$isGuest && !empty($currentUser['student_verified']);
$isOwner    = !$isGuest && ($sellerId === $me);
$isActive   = ($listing['status'] === 'active');
$isHeld     = ($listing['status'] === 'held');

// Where a guest should come back to after logging in
$loginUrl = 'login.php?next=' . urlencode('view_listing.php?id=' . $listingId);

// Guests only see the seller's first name
$sellerName = (string) $listing['seller_name'];
if ($isGuest) {
    $sellerName = strtok($sellerName, ' ') ?: $sellerName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Buy, trade, message and report all need an account
    if ($isGuest) {
        header('Location: ' . $loginUrl);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $flash  = ['error', 'Something went wrong. Please try again.'];

    if ($action === 'report') {
        $reason = trim($_POST['reason'] ?? '');
        if ($isOwner) {
            $flash = ['error', 'You cannot report your own listing.'];
        } elseif ($reason === '') {
            $flash = ['error', 'Please describe what is wrong with this listing.'];
        } elseif (mb_strlen($reason) > 1000) {
            $flash = ['error', 'The report is too long (max 1000 characters).'];
        } else {
            (new Report())->create($me, $listingId, $sellerId, $reason);
            $flash = ['success', 'Thank you. Your report has been sent to the administrators.'];
            try {
                // several different students reporting the same listing puts it under review automatically
                (new Automation())->checkListing($listingId);
            } catch (Throwable $e) {
            }
        }
    } elseif (in_array($action, ['buy', 'trade', 'message'], true)) {
        if (!$isVerified) {
            $flash = ['error', 'Only verified students can buy, trade or send messages.'];
        } elseif ($isOwner) {
            $flash = ['error', 'This is your own listing.'];
        } elseif ($action === 'message') {
            $body = trim($_POST['body'] ?? '');
            if ($body === '') {
                $flash = ['error', 'Please write a message first.'];
            } else {
                (new Message())->send($listingId, $me, $sellerId, $body);
                $flash = ['success', 'Message sent. You can continue the conversation under Messages.'];
            }
        } else {
            $orderModel = new Order();
            if ($isHeld) {
                $flash = ['error', 'This listing is under review and cannot be bought right now.'];
            } elseif (!$isActive) {
                $flash = ['error', 'This item has already been sold.'];
            } elseif ($orderModel->hasOpenRequest($listingId, $me)) {
                $flash = ['error', 'You already have an open request for this item. See Orders.'];
            } else {
                $offer = null;
                if ($action === 'trade') {
                    $offer = substr(trim($_POST['offer'] ?? ''), 0, 255);
                }
                if ($action === 'trade' && $offer === '') {
                    $flash = ['error', 'Please say what you would offer in exchange.'];
                } else {
                    $newId = $orderModel->create($listingId, $me, $sellerId, $action, $offer);
                    (new Notification())->add($sellerId, 'New ' . ($action === 'trade' ? 'trade offer' : 'purchase request') . ' for "' . $listing['title'] . '". Open it to accept or decline.', 'order.php?id=' . $newId);
                    $flash = ['success', 'Request sent. The seller has been notified. You will see here what happens next.'];
                    $goto  = 'order.php?id=' . $newId;
                }
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: ' . ($goto ?? 'view_listing.php?id=' . $listingId));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Seller record and price check shown to everyone who opens the listing
$sellerStats = null;
$priceNote   = null;
try {
    $sellerStats = (new Automation())->sellerStats($sellerId);
    if ($isActive) {
        $priceNote = (new AiInsights())->priceNote($listingId);
    }
} catch (Throwable $e) {
}

// Views feed the recommender, so only logged-in students (not the owner) are recorded
if (!$isGuest && !$isOwner) {
    $listingModel->recordView($me, $listingId);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?= htmlspecialchars($listing['title']) ?> - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <p><a href="dashboard.php" class="back-link">← Back to listings</a></p>

        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
        <?php endif; ?>

        <div class="detail-card">
            <h1><?= htmlspecialchars($listing['title']) ?></h1>
            <p class="detail-price"><?= number_format((float) $listing['price'], 2) ?> DKK</p>
            <p class="listing-category">
                <?= htmlspecialchars($listing['category']) ?>
                <?php if ($isHeld): ?><span class="tag tag-muted">Under review</span>
                <?php elseif (!$isActive): ?><span class="tag tag-sold">Sold</span><?php endif; ?>
            </p>

            <?php if (!empty($listing['images'])): ?>
                <div class="photo-grid">
                    <?php foreach ($listing['images'] as $img): ?>
                        <a href="../public/uploads/listings/<?= htmlspecialchars($img) ?>" target="_blank">
                            <img src="../public/uploads/listings/<?= htmlspecialchars($img) ?>" alt="Photo of <?= htmlspecialchars($listing['title']) ?>">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3>Description</h3>
            <p><?= nl2br(htmlspecialchars($listing['description'])) ?></p>
            <p class="listing-seller">Sold by <?= htmlspecialchars($sellerName) ?></p>

            <?php if ($sellerStats): ?>
                <div class="trust-box">
                    <span class="badge-level badge-<?= htmlspecialchars($sellerStats['level']) ?>"><?= $sellerStats['level'] === 'trusted' ? '✓ ' : '' ?><?= htmlspecialchars($sellerStats['label']) ?></span>
                    <span>Verified student</span>
                    <span><?= (int) $sellerStats['completed'] ?> completed sale<?= (int) $sellerStats['completed'] === 1 ? '' : 's' ?></span>
                    <span><?= (int) $sellerStats['refunded'] ?> refunded after dispute<?= (int) $sellerStats['refunded'] === 1 ? '' : 's' ?></span>
                    <?php if (!empty($sellerStats['since'])): ?><span>Member since <?= date('M Y', strtotime($sellerStats['since'])) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($priceNote && $priceNote['assessment'] !== 'unknown'): ?>
                <p class="price-note<?= $priceNote['assessment'] === 'underpriced' ? ' warn' : '' ?>">
                    <strong>AI price check:</strong>
                    <?php if ($priceNote['assessment'] === 'underpriced'): ?>
                        priced far below similar listings (usually <?= (int) $priceNote['low'] ?>&ndash;<?= (int) $priceNote['high'] ?> kr). Check the item carefully before you pay.
                    <?php elseif ($priceNote['assessment'] === 'overpriced'): ?>
                        priced above similar listings (usually <?= (int) $priceNote['low'] ?>&ndash;<?= (int) $priceNote['high'] ?> kr).
                    <?php else: ?>
                        a fair price, based on <?= (int) $priceNote['n'] ?> similar listings (<?= (int) $priceNote['low'] ?>&ndash;<?= (int) $priceNote['high'] ?> kr).
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($isGuest): ?>
                <div class="gate-notice gate-warning">
                    Log in with a verified student account to buy, trade or message the seller.
                    <a href="<?= htmlspecialchars($loginUrl) ?>">Log in</a>
                    <a href="register.php">Sign up</a>
                </div>
            <?php elseif ($isOwner): ?>
                <div class="gate-notice gate-info">
                    This is your own listing. <a href="my_listings.php">Manage it under My listings</a>
                </div>
            <?php elseif (!$isVerified): ?>
                <div class="gate-notice gate-warning">
                    Only verified students can buy, trade or send messages.
                    <a href="upload_id.php">Verify your account</a>
                </div>
            <?php elseif ($isHeld): ?>
                <div class="gate-notice gate-held">This listing is under review by an administrator and cannot be bought right now.</div>
            <?php elseif (!$isActive): ?>
                <div class="gate-notice gate-info">This item has been sold.</div>
            <?php else: ?>
                <div class="action-box">
                    <h3>Buy this item</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="buy">
                        <button type="submit" class="btn-inline">Request to buy for <?= number_format((float) $listing['price'], 2) ?> DKK</button>
                    </form>
                    <p class="escrow-line">You pay through TrustTrade. The money is held in escrow until you confirm you received the item. <a href="how_it_works.php">How it works</a></p>
                </div>

                <div class="action-box">
                    <h3>Propose a trade</h3>
                    <form method="POST" class="form-stack">
                        <input type="hidden" name="action" value="trade">
                        <input type="text" name="offer" maxlength="255" placeholder="What would you offer in exchange?">
                        <button type="submit" class="btn-outline">Send trade offer</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if (!$isGuest && !$isOwner && $isVerified): ?>
                <div class="action-box">
                    <h3>Message the seller</h3>
                    <form method="POST" class="form-stack">
                        <input type="hidden" name="action" value="message">
                        <textarea name="body" rows="3" placeholder="Ask about the condition, pickup, anything else"></textarea>
                        <button type="submit" class="btn-small">Send message</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($isGuest): ?>
                <p class="muted" style="margin-top:1.5rem;"><a href="<?= htmlspecialchars($loginUrl) ?>">Log in</a> to report this listing.</p>
            <?php elseif (!$isOwner): ?>
                <details class="report-box">
                    <summary>Report this listing</summary>
                    <form method="POST" class="form-stack" style="margin-top:0.6rem;">
                        <input type="hidden" name="action" value="report">
                        <textarea name="reason" rows="3" placeholder="What is wrong with this listing?"></textarea>
                        <button type="submit" class="btn-small btn-danger">Submit report</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
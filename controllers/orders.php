<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../includes/delivery_ui.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/order_status.php';

/** The photo for a problem report is mandatory. Returns an error text, or null when the file is fine (and sets $ext). */
function disputePhotoError(?array $file, &$ext): ?string
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return 'Please add a photo that shows the problem. It is required.';
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return 'The photo could not be uploaded. Please try again.';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return 'The photo is too large (max 5 MB).';
    }
    $info = @getimagesize($file['tmp_name']);   // reads the real image header, not the file name
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        return 'The photo must be a JPG or PNG image.';
    }
    $ext = ($info[2] === IMAGETYPE_PNG) ? 'png' : 'jpg';
    return null;
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];

$orderModel   = new Order();
$disputeModel = new Dispute();
$listingModel = new Listing();
$notify       = new Notification();
$chat         = new Message();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $action  = $_POST['action'] ?? '';
    $order   = $orderModel->findById($orderId);
    $flash   = ['error', 'Something went wrong. Please try again.'];

    if ($order === null || ((int) $order['buyer_id'] !== $me && (int) $order['seller_id'] !== $me)) {
        $flash = ['error', 'That order was not found.'];
    } else {
        $isBuyer  = ((int) $order['buyer_id'] === $me);
        $isSeller = ((int) $order['seller_id'] === $me);
        $status   = $order['status'];
        $payment  = $order['payment_status'] ?? 'unpaid';
        $title    = $order['listing_title'];
        $buyerId  = (int) $order['buyer_id'];
        $sellerId = (int) $order['seller_id'];

        if ($action === 'accept' && $isSeller && $status === 'pending') {
            $orderModel->updateStatus($orderId, 'accepted');
            $orderModel->addEvent($orderId, 'Seller accepted the request');
            $notify->add($buyerId, 'Your request for "' . $title . '" was accepted. Pay in Orders within ' . Automation::CANCEL_DAYS . ' days, or the request is cancelled automatically.', 'order.php?id=' . $orderId);
            $flash = ['success', 'Request accepted. Now agree with the buyer how the item will be handed over (see Delivery below, or use Messages).'];

        } elseif ($action === 'decline' && $isSeller && $status === 'pending') {
            $orderModel->updateStatus($orderId, 'declined');
            $orderModel->addEvent($orderId, 'Seller declined the request');
            $notify->add($buyerId, 'Your request for "' . $title . '" was declined by the seller.', 'order.php?id=' . $orderId);
            $flash = ['success', 'Request declined.'];

        } elseif ($action === 'cancel' && $isBuyer && in_array($status, ['pending', 'accepted'], true)) {
            if ($payment === 'held') {
                $flash = ['error', 'Your payment is being held. If something is wrong, use "Report a problem" and an administrator will decide.'];
            } else {
                $orderModel->updateStatus($orderId, 'cancelled');
                $orderModel->addEvent($orderId, 'Buyer cancelled the request');
                $notify->add($sellerId, 'The buyer cancelled the request for "' . $title . '".', 'order.php?id=' . $orderId);
                $flash = ['success', 'Request cancelled.'];
            }

        } elseif ($action === 'pay' && $isBuyer && StripeClient::enabled()
                 && $status === 'accepted' && $payment === 'unpaid' && ($order['type'] ?? '') === 'buy') {
            // Real checkout in Stripe test mode: the buyer pays on Stripe's page, we never see card details
            $listing = $listingModel->findById((int) $order['listing_id']);
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base    = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $session = $listing ? StripeClient::createCheckout(
                $orderId, $me, $title, (float) $listing['price'],
                $base . '/pay_return.php?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
                $base . '/order.php?id=' . $orderId
            ) : [];
            if (!empty($session['url'])) {
                header('Location: ' . $session['url']);
                exit;
            }
            $flash = ['error', 'Could not start the payment: ' . ($session['error']['message'] ?? 'unknown error')];

        } elseif ($action === 'pay' && $isBuyer) {
            // Simulated payment (used when no Stripe test key is set): no card details are collected or stored
            $method = $_POST['method'] ?? '';
            if ($orderModel->pay($orderId, $me, $method)) {
                $paid = $orderModel->findById($orderId);
                $until = !empty($paid['auto_release_at']) ? date('j M Y', strtotime($paid['auto_release_at'])) : '';
                $orderModel->addEvent($orderId, 'Buyer paid ' . number_format((float) ($paid['payment_amount'] ?? 0), 2) . ' DKK (simulated). The money is held in escrow');
                $notify->add($sellerId, 'The buyer has paid for "' . $title . '". The money is held by TrustTrade: hand over the item.', 'order.php?id=' . $orderId);
                $flash = ['success', 'Payment received (simulated) and held by TrustTrade. It is released to the seller when you confirm that you received the item' . ($until ? ', or automatically on ' . $until . ' if you report no problem' : '') . '.'];
            } else {
                $flash = ['error', 'This order cannot be paid right now.'];
            }

        } elseif ($action === 'confirm_received' && $isBuyer) {
            if ($disputeModel->existsForOrder($orderId)) {
                $flash = ['error', 'A problem report is open for this order. The payment is frozen until an administrator decides.'];
            } elseif ($orderModel->releasePayment($orderId, $me)) {
                $orderModel->addEvent($orderId, 'Buyer confirmed receipt. Payment released to the seller');
                $listingModel->setStatus((int) $order['listing_id'], 'sold');
                $notify->add($sellerId, 'The buyer confirmed receipt of "' . $title . '". The payment has been released to you.', 'order.php?id=' . $orderId);
                $flash = ['success', 'Thank you. The payment has been released to the seller and the order is completed.'];
            } else {
                $flash = ['error', 'There is no held payment to release for this order.'];
            }

        } elseif ($action === 'complete' && $isSeller && $status === 'accepted') {
            if ($payment === 'held') {
                $flash = ['error', 'The buyer has paid. The payment is released when the buyer confirms receipt.'];
            } else {
                $orderModel->updateStatus($orderId, 'completed');
                $orderModel->addEvent($orderId, 'Seller marked the order as completed');
                $listingModel->setStatus((int) $order['listing_id'], 'sold');
                $notify->add($buyerId, 'The seller marked "' . $title . '" as completed.', 'order.php?id=' . $orderId);
                $flash = ['success', 'Marked as completed. The listing is now marked as sold.'];
            }

        } elseif (in_array($action, ['delivery_propose', 'delivery_accept', 'delivery_shipped'], true)) {
            // The delivery step: one person suggests, the other accepts. Only while the order is accepted.
            $other     = $isBuyer ? $sellerId : $buyerId;
            $otherName = $isBuyer ? $order['seller_name'] : $order['buyer_name'];
            $myName    = $isBuyer ? $order['buyer_name'] : $order['seller_name'];
            $first     = fn($n) => explode(' ', trim((string) $n))[0];
            $dState    = $order['delivery_status'] ?? 'none';
            $lid       = (int) $order['listing_id'];

            if ($status !== 'accepted') {
                $flash = ['error', 'Delivery can only be arranged while the order is accepted.'];

            } elseif ($action === 'delivery_propose') {
                $method = $_POST['method'] ?? '';
                $place  = trim($_POST['place'] ?? '');
                $note   = trim($_POST['note'] ?? '');
                $timeIn = trim($_POST['time'] ?? '');
                $time   = null;
                $err    = null;
                if (!isset(deliveryMethods()[$method])) {
                    $err = 'Please choose how the item will be handed over.';
                } elseif ($place === '' || mb_strlen($place) > 160) {
                    $err = 'Please write where (up to 160 characters).';
                } elseif (mb_strlen($note) > 255) {
                    $err = 'The note is too long (up to 255 characters).';
                } elseif ($timeIn !== '') {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $timeIn);
                    $now = $orderModel->dbNow();
                    if (!$dt) {
                        $err = 'That date and time is not valid.';
                    } elseif ($dt < $now->modify('-5 minutes') || $dt > $now->modify('+90 days')) {
                        $err = 'Please choose a time in the next 90 days.';
                    } else {
                        $time = $dt->format('Y-m-d H:i:00');
                    }
                } elseif ($method !== 'post') {
                    $err = 'Please choose a day and time for the meeting.';
                }
                if ($err !== null) {
                    $flash = ['error', $err];
                } else {
                    $orderModel->proposeDelivery($orderId, $method, $place, $time, $note, $me);
                    $fresh = $orderModel->findById($orderId);
                    $sum   = deliverySummary($fresh);
                    $orderModel->addEvent($orderId, $first($myName) . ' suggested delivery: ' . $sum);
                    $notify->add($other, $first($myName) . ' suggested how to hand over "' . $title . '": ' . $sum . '. Open Orders to accept or suggest something else.', 'order.php?id=' . $orderId);
                    $chat->sendSystem($lid, $me, $other, $first($myName) . ' suggested: ' . $sum . '.' . ($note !== '' ? ' Note: ' . $note : ''));
                    $flash = ['success', 'Suggestion sent. ' . $first($otherName) . ' can now accept it.'];
                }

            } elseif ($action === 'delivery_accept') {
                if ($dState !== 'proposed' || (int) ($order['delivery_by'] ?? 0) === $me) {
                    $flash = ['error', 'There is no suggestion from the other person to accept.'];
                } else {
                    $orderModel->agreeDelivery($orderId);
                    $sum = deliverySummary($order);
                    $orderModel->addEvent($orderId, $first($myName) . ' accepted the delivery plan: ' . $sum);
                    $notify->add($other, $first($myName) . ' accepted the delivery plan for "' . $title . '": ' . $sum . '.', 'order.php?id=' . $orderId);
                    $chat->sendSystem($lid, $me, $other, $first($myName) . ' accepted the plan: ' . $sum . '.');
                    $flash = ['success', 'Delivery plan agreed.'];
                }

            } else {   // delivery_shipped
                $tracking = mb_substr(trim($_POST['tracking'] ?? ''), 0, 60);
                if (!$isSeller || $dState !== 'agreed' || ($order['delivery_method'] ?? '') !== 'post') {
                    $flash = ['error', 'Only the seller can mark an agreed parcel as sent.'];
                } else {
                    $orderModel->markShipped($orderId, $tracking);
                    $orderModel->addEvent($orderId, 'Seller sent the parcel' . ($tracking !== '' ? ' (tracking ' . $tracking . ')' : ''));
                    $notify->add($other, 'The seller sent "' . $title . '".' . ($tracking !== '' ? ' Tracking number: ' . $tracking . '.' : '') . ' Press "I received the item" when it arrives.', 'order.php?id=' . $orderId);
                    $chat->sendSystem($lid, $me, $other, $first($myName) . ' sent the parcel.' . ($tracking !== '' ? ' Tracking number: ' . $tracking . '.' : ''));
                    $flash = ['success', 'Marked as sent. The buyer was told.'];
                }
            }

        } elseif ($action === 'dispute_reply' && $isSeller) {
            $text = trim($_POST['reply'] ?? '');
            $dispute = $disputeModel->forOrder($orderId);
            if ($text === '') {
                $flash = ['error', 'Please write your reply.'];
            } elseif (mb_strlen($text) > 2000) {
                $flash = ['error', 'Your reply is too long (max 2000 characters).'];
            } elseif ($dispute && $disputeModel->sellerReply((int) $dispute['id'], $me, $text, $orderModel->dbNow()->format('Y-m-d H:i:s'))) {
                $orderModel->addEvent($orderId, 'Seller replied to the problem report');
                $notify->add($buyerId, 'The seller replied to your problem report on "' . $title . '". An administrator will now decide.', 'order.php?id=' . $orderId);
                $flash = ['success', 'Your reply was sent. An administrator will decide.'];
            } else {
                $flash = ['error', 'This problem report can no longer be answered.'];
            }

        } elseif ($action === 'dispute' && in_array($status, ['accepted', 'completed'], true)) {
            $description = trim($_POST['description'] ?? '');
            if ($description === '') {
                $flash = ['error', 'Please describe the problem.'];
            } elseif (mb_strlen($description) > 2000) {
                $flash = ['error', 'The description is too long (max 2000 characters).'];
            } elseif ($disputeModel->existsForOrder($orderId)) {
                $flash = ['error', 'A dispute has already been raised for this order.'];
            } elseif (($evErr = disputePhotoError($_FILES['evidence'] ?? null, $evExt)) !== null) {
                $flash = ['error', $evErr];
            } else {
                // Save the mandatory photo outside the public folder
                $evDir = __DIR__ . '/../storage/dispute_evidence/';
                if (!is_dir($evDir)) { mkdir($evDir, 0775, true); }
                $evName = 'dispute_' . $orderId . '_' . bin2hex(random_bytes(8)) . '.' . $evExt;
                if (!move_uploaded_file($_FILES['evidence']['tmp_name'], $evDir . $evName)) {
                    $flash = ['error', 'The photo could not be saved' . (is_writable($evDir) ? '. Please try again.' : ' because the folder storage/dispute_evidence is not writable. Give it Read & Write permission.')];
                    $_SESSION['flash'] = $flash;
                    header('Location: ' . (preg_match('/^order\.php\?id=\d+$/', (string) ($_POST['back'] ?? '')) ? $_POST['back'] : 'orders.php'));
                    exit;
                }
                // A buyer's dispute on a held payment starts the seller's reply deadline
                $deadline = null;
                if ($isBuyer && $payment === 'held') {
                    $deadline = $orderModel->dbNow()->modify('+' . Automation::DISPUTE_REPLY_DAYS . ' days')->format('Y-m-d H:i:s');
                }
                $disputeModel->create($orderId, $me, $description, $deadline, $evName);
                $orderModel->addEvent($orderId, ($isBuyer ? 'Buyer' : 'Seller') . ' reported a problem.' . ($payment === 'held' ? ' The payment is frozen' : ''));
                $frozen = ($payment === 'held') ? ' The payment is frozen until it is decided.' : '';
                $notify->add($isBuyer ? $sellerId : $buyerId, 'A problem was reported on the order for "' . $title . '".' . $frozen . ($deadline ? ' Reply in Orders within ' . Automation::DISPUTE_REPLY_DAYS . ' days, or the buyer is refunded automatically.' : ' An administrator will review it.'), 'order.php?id=' . $orderId);
                $notify->add($me, 'Your problem report on "' . $title . '" was sent to the administrators.' . $frozen . ' You will be notified of the decision.', 'order.php?id=' . $orderId);
                $flash = ['success', 'Dispute sent to the administrators for review.' . $frozen];
            }
        }
    }

    $_SESSION['flash'] = $flash;
    // Actions started inside a chat return to that chat
    $back = (string) ($_POST['back'] ?? '');
    if (preg_match('/^(conversation\.php\?listing=\d+&with=\d+|order\.php\?id=\d+)$/', $back)) {
        header('Location: ' . $back);
        exit;
    }
    // Otherwise go to the order's own page (or the list if the order was not found)
    header('Location: ' . (($order !== null && ((int) $order['buyer_id'] === $me || (int) $order['seller_id'] === $me)) ? 'order.php?id=' . $orderId : 'orders.php'));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$sections = [
    ['title' => 'Requests I made',     'rows' => $orderModel->forBuyer($me),  'role' => 'buyer',  'empty' => 'You have not requested anything yet.'],
    ['title' => 'Requests I received', 'rows' => $orderModel->forSeller($me), 'role' => 'seller', 'empty' => 'Nobody has requested your items yet.'],
];
$methodLabels = ['card' => 'Card', 'mobilepay' => 'MobilePay'];
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orders - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .order-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); padding: 1.1rem 1.2rem; margin-bottom: 1.1rem; }
        .order-card.your-turn { border: 2px solid var(--ink); border-top: 6px solid var(--hl); }
        .oc-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem 1rem; align-items: flex-start; }
        .oc-title { font-size: 1.1rem; font-weight: 700; }
        .oc-title a { color: var(--ink); text-decoration: none; }
        .oc-sub { color: var(--muted); font-size: 0.88rem; margin-top: 0.15rem; }
        .oc-price { font-weight: 700; font-size: 1.05rem; text-align: right; }
        .oc-badge { display: inline-block; background: var(--hl); color: var(--ink-deep); font-weight: 700; font-size: 0.75rem; padding: 0.15rem 0.6rem; border-radius: 999px; margin-left: 0.4rem; vertical-align: middle; }
        .oc-next { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 0.8rem 1rem; margin: 0.6rem 0; }
        .oc-next p { margin: 0 0 0.6rem; font-size: 0.95rem; line-height: 1.45; }
        .oc-next p:last-child { margin-bottom: 0; }
        .oc-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .oc-actions form { margin: 0; }
        .oc-actions .btn-small { font-size: 0.95rem; padding: 0.55rem 1.1rem; }
        .oc-foot { display: flex; flex-wrap: wrap; gap: 0.5rem 0.8rem; align-items: flex-start; margin-top: 0.7rem; padding-top: 0.7rem; border-top: 1px solid var(--line-soft); }
        .oc-foot details { font-size: 0.9rem; }
        .oc-foot summary { cursor: pointer; color: var(--muted); }
        .oc-more { margin-top: 0.4rem; }
        .oc-section-note { color: var(--muted); font-size: 0.9rem; margin: -0.3rem 0 0.8rem; }
        .pay-line { color: var(--muted); font-size: 0.85rem; margin-top: 0.3rem; }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
        <?php endif; ?>

        <h2>Orders</h2>
        <p class="muted"><?= StripeClient::enabled() ? 'Payments run through Stripe in test mode: no real money is used, and TrustTrade never sees card details.' : 'Payments in this prototype are simulated: no real money or card details are used.' ?> The money is held by TrustTrade until the buyer confirms receipt. Rules that run automatically are explained on <a href="how_it_works.php">How it works</a>.</p>

        <?php foreach ($sections as $section): ?>
            <h3 class="section-title"><?= $section['title'] ?></h3>

            <?php if (empty($section['rows'])): ?>
                <p class="muted"><?= $section['empty'] ?></p>
            <?php else: ?>
                <?php foreach ($section['rows'] as $o): ?>
                    <?php
                    $role      = $section['role'];
                    $isBuyer   = ($role === 'buyer');
                    $otherId   = $isBuyer ? (int) $o['seller_id'] : (int) $o['buyer_id'];
                    $otherFirst = explode(' ', trim((string) $o['other_name']))[0];
                    $payStatus = $o['payment_status'] ?? 'unpaid';
                    $isBuy     = ($o['type'] === 'buy');
                    $status    = $o['status'];
                    $amount    = $o['payment_amount'] !== null ? (float) $o['payment_amount'] : (float) $o['listing_price'];
                    $dispute   = ((int) $o['dispute_count'] > 0) ? $disputeModel->forOrder((int) $o['id']) : null;
                    $progress  = orderProgress($o, $role, $dispute);
                    $events    = $orderModel->events((int) $o['id']);
                    $oid       = (int) $o['id'];

                    // The one thing this person should do next (if anything)
                    $primary = '';
                    $post = function (string $action, string $label, string $class = 'btn-small') use ($oid) {
                        return '<form method="POST"><input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="' . $action . '"><button type="submit" class="' . $class . '">' . htmlspecialchars($label) . '</button></form>';
                    };
                    if (!$isBuyer && $status === 'pending') {
                        $primary = $post('accept', 'Accept request') . $post('decline', 'Decline', 'btn-small btn-ghost');
                    } elseif ($isBuyer && $isBuy && $status === 'accepted' && $payStatus === 'unpaid') {
                        $primary = StripeClient::enabled()
                            ? $post('pay', 'Pay ' . number_format($amount, 2) . ' DKK with card')
                            : '<form method="POST" class="oc-actions"><input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="pay"><select name="method"><option value="card">Card</option><option value="mobilepay">MobilePay</option></select><button type="submit" class="btn-small">Pay ' . number_format($amount, 2) . ' DKK (simulated)</button></form>';
                    } elseif ($isBuyer && $status === 'accepted' && $payStatus === 'held' && (int) $o['dispute_count'] === 0) {
                        $primary = '<form method="POST" onsubmit="return confirm(\'Is the item fine? This releases the money to the seller and cannot be undone. If something is wrong, press Cancel and report a problem instead.\');"><input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="confirm_received"><button type="submit" class="btn-small">I received the item and it is fine</button></form>';
                    } elseif (!$isBuyer && $status === 'accepted' && $payStatus !== 'held' && !$isBuy) {
                        $primary = $post('complete', 'Mark as completed');
                    }
                    $replyNeeded = (!$isBuyer && $dispute && $dispute['status'] === 'open' && empty($dispute['seller_replied_at']) && (int) $dispute['raised_by'] === (int) $o['buyer_id']);
                    $yourTurn = ($primary !== '' || $replyNeeded);
                    ?>
                    <div class="order-card<?= $yourTurn ? ' your-turn' : '' ?>">
                        <div class="oc-head">
                            <div>
                                <div class="oc-title">
                                    <a href="order.php?id=<?= $oid ?>"><?= htmlspecialchars($o['listing_title']) ?></a>
                                    <?php if ($yourTurn): ?><span class="oc-badge">Your turn</span><?php endif; ?>
                                </div>
                                <div class="oc-sub">
                                    <?= $isBuyer ? 'Buying from' : 'Selling to' ?> <?= htmlspecialchars($o['other_name']) ?>
                                    · <?= date('j M Y', strtotime($o['created_at'])) ?>
                                    · <?= $isBuy ? 'Purchase' : 'Swap' ?>
                                </div>
                                <?php if (!$isBuy && !empty($o['offer_text'])): ?>
                                    <div class="oc-sub">Offer: <?= htmlspecialchars($o['offer_text']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($isBuy): ?><div class="oc-price"><?= number_format($amount, 2) ?> DKK</div><?php endif; ?>
                                <div style="text-align:right;"><span class="pill pill-<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
                                <?php if ($isBuy && $status !== 'pending' && $status !== 'declined'): ?>
                                    <span class="pill pill-<?= htmlspecialchars($payStatus) ?>"><?= htmlspecialchars($payStatus) ?></span>
                                <?php endif; ?></div>
                            </div>
                        </div>

                        <ol class="tracker" aria-label="Order progress">
                            <?php foreach ($progress['steps'] as $i => $label): ?>
                                <?php
                                $cls = '';
                                if ($progress['terminal'] === null && $i < $progress['current']) { $cls = 'done'; }
                                if ($progress['terminal'] === null && $i === $progress['current']) { $cls = 'now'; }
                                if ($progress['terminal'] === null && $i === $progress['current'] && $progress['current'] === count($progress['steps']) - 1) { $cls = 'done'; }
                                ?>
                                <li class="<?= $cls ?>"><span class="dot"></span><?= htmlspecialchars($label) ?></li>
                            <?php endforeach; ?>
                            <?php if ($progress['terminal'] !== null): ?>
                                <li class="stop"><span class="dot"></span><?= htmlspecialchars($progress['terminal']) ?></li>
                            <?php endif; ?>
                        </ol>

                        <?php if ($progress['message'] !== '' || $primary !== ''): ?>
                            <div class="oc-next">
                                <?php if ($progress['message'] !== ''): ?><p><?= htmlspecialchars($progress['message']) ?></p><?php endif; ?>
                                <?php if ($primary !== ''): ?><div class="oc-actions"><?= $primary ?></div><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($dispute && !empty($dispute['seller_reply'])): ?>
                            <p class="muted"><strong>Seller's reply:</strong> <?= htmlspecialchars($dispute['seller_reply']) ?></p>
                        <?php endif; ?>
                        <?php if ($replyNeeded): ?>
                            <details class="report-box" open>
                                <summary>Reply to the problem report</summary>
                                <p class="muted">The buyer wrote: <?= htmlspecialchars($dispute['description']) ?></p>
                                <form method="POST" class="form-stack" style="margin-top:0.5rem;">
                                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                                    <input type="hidden" name="action" value="dispute_reply">
                                    <textarea name="reply" rows="3" placeholder="Explain your side. An administrator will read it."></textarea>
                                    <button type="submit" class="btn-small">Send reply</button>
                                </form>
                            </details>
                        <?php endif; ?>

                        <?php if ($status === 'accepted'): ?>
                            <?= deliveryBox($o, $me, $otherFirst) ?>
                        <?php elseif ($status === 'completed' && ($o['delivery_status'] ?? 'none') !== 'none'): ?>
                            <details class="oc-more"><summary class="muted">Delivery details</summary><?= deliveryBox($o, $me, $otherFirst) ?></details>
                        <?php endif; ?>

                        <div class="oc-foot">
                            <a class="btn-small" href="order.php?id=<?= $oid ?>"><?= $yourTurn ? 'Open and continue' : 'Open' ?></a>
                            <a class="btn-small btn-ghost" href="conversation.php?listing=<?= (int) $o['listing_id'] ?>&with=<?= $otherId ?>">Message <?= htmlspecialchars($otherFirst) ?></a>

                            <?php if ($isBuyer && (($status === 'pending') || ($status === 'accepted' && $payStatus === 'unpaid'))): ?>
                                <?= $post('cancel', 'Cancel request', 'btn-small btn-ghost') ?>
                            <?php endif; ?>

                            <?php if (in_array($status, ['accepted', 'completed'], true)): ?>
                                <?php if ((int) $o['dispute_count'] > 0): ?>
                                    <span class="muted">Problem reported</span>
                                <?php else: ?>
                                    <details class="report-box">
                                        <summary>Report a problem</summary>
                                        <form method="POST" enctype="multipart/form-data" class="form-stack" style="margin-top:0.5rem;">
                                            <input type="hidden" name="order_id" value="<?= $oid ?>">
                                            <input type="hidden" name="action" value="dispute">
                                            <textarea name="description" rows="3" required placeholder="What went wrong with this transaction?"></textarea>
                                            <label class="muted">Photo of the problem (required, JPG or PNG, max 5 MB)</label>
                                            <input type="file" name="evidence" accept="image/jpeg,image/png" required>
                                            <button type="submit" class="btn-small btn-danger">Send to administrators</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($events): ?>
                                <details class="timeline-box">
                                    <summary>History (<?= count($events) ?>)</summary>
                                    <ul class="timeline">
                                        <?php foreach ($events as $ev): ?>
                                            <li<?= !empty($ev['is_automatic']) ? ' class="auto"' : '' ?>>
                                                <span class="when"><?= date('j M H:i', strtotime($ev['created_at'])) ?></span>
                                                <?= htmlspecialchars($ev['event']) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </div>
                        <?php if ($isBuy && !empty($o['payment_method'])): ?>
                            <div class="pay-line">Paid by <?= htmlspecialchars($methodLabels[$o['payment_method']] ?? $o['payment_method']) ?> · reference <?= htmlspecialchars($o['payment_ref']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>
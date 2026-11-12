<?php
// One order on one screen: a step bar and only the step that matters now (like a web shop checkout).
// All buttons post to orders.php, which holds the rules, and return here.
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../includes/delivery_ui.php';
require_once __DIR__ . '/../includes/stripe.php';
require_once __DIR__ . '/../includes/order_status.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me           = (int) $_SESSION['user_id'];
// The navigation bar needs the signed-in user, or it shows Log in and Sign up
$currentUser  = (new User())->findById($me);
$orderModel   = new Order();
$disputeModel = new Dispute();
$listingModel = new Listing();

$oid = (int) ($_GET['id'] ?? 0);
$o   = $orderModel->findById($oid);
if ($o === null || ((int) $o['buyer_id'] !== $me && (int) $o['seller_id'] !== $me)) {
    $_SESSION['flash'] = ['error', 'That order was not found.'];
    header('Location: orders.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isBuyer    = ((int) $o['buyer_id'] === $me);
$role       = $isBuyer ? 'buyer' : 'seller';
$isBuy      = ($o['type'] === 'buy');
$status     = $o['status'];
$pay        = $o['payment_status'] ?? 'unpaid';
$ds         = $o['delivery_status'] ?? 'none';
$otherId    = $isBuyer ? (int) $o['seller_id'] : (int) $o['buyer_id'];
$otherName  = $isBuyer ? $o['seller_name'] : $o['buyer_name'];
$otherFirst = explode(' ', trim((string) $otherName))[0];
$listing    = $listingModel->findById((int) $o['listing_id']);
$amount     = $o['payment_amount'] !== null ? (float) $o['payment_amount'] : (float) ($listing['price'] ?? 0);
$dispute    = $disputeModel->existsForOrder($oid) ? $disputeModel->forOrder($oid) : null;
$events     = $orderModel->events($oid);
$back       = 'order.php?id=' . $oid;
$summary    = deliverySummary($o);
$planAgreed = in_array($ds, ['agreed', 'shipped'], true);
$forcePay   = (($_GET['step'] ?? '') === 'pay');
$h          = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// Steps and where we are
$steps = $isBuy ? ['Request', 'Accepted', 'Delivery', 'Payment', 'Received'] : ['Request', 'Accepted', 'Delivery', 'Done'];
$terminal = null;
if ($status === 'declined') { $terminal = 'Declined'; }
elseif ($pay === 'refunded') { $terminal = 'Refunded'; }
elseif ($status === 'cancelled') { $terminal = 'Cancelled'; }

$cur = 1;
if ($status === 'completed') {
    $cur = count($steps);   // everything done
} elseif ($status === 'accepted') {
    if ($isBuy && $pay === 'held') {
        $cur = 4;
    } elseif ($isBuy) {
        $cur = ($planAgreed || $forcePay) ? 3 : 2;
    } else {
        $cur = $planAgreed ? 3 : 2;
    }
}

$progress = orderProgress($o + ['dispute_count' => $dispute ? 1 : 0], $role, $dispute);

$post = function (string $action, string $label, string $class = 'btn-small', string $extra = '') use ($oid, $back, $h) {
    return '<form method="POST" action="orders.php"><input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="back" value="' . $h($back) . '">' . $extra . '<button type="submit" class="' . $class . '">' . $h($label) . '</button></form>';
};

// The report form. A photo is mandatory, so the administrator can see the problem.
$reportForm = function (string $id) use ($oid, $back, $h) {
    return '<form method="POST" action="orders.php" enctype="multipart/form-data" class="form-stack" id="' . $id . '" style="margin-top:0.75rem;display:flex;flex-direction:column;align-items:stretch;gap:0.5rem;width:100%;">'
         . '<input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="dispute"><input type="hidden" name="back" value="' . $h($back) . '">'
         . '<textarea name="description" rows="3" required placeholder="What went wrong with this transaction?"></textarea>'
         . '<label class="muted" for="' . $id . '_photo">Photo of the problem (required, JPG or PNG, max 5 MB)</label>'
         . '<input type="file" id="' . $id . '_photo" name="evidence" accept="image/jpeg,image/png" required>'
         . '<button type="submit" class="btn-small btn-danger">Send to administrators</button></form>';
};
$buyerAtReceive = false;

// The current step: a headline, one sentence, and the buttons for it
$headline = '';
$text     = $progress['message'];
$buttons  = '';
$extraHtml = '';

if ($terminal !== null) {
    $headline = $terminal;
} elseif ($status === 'completed') {
    $headline = 'All done';
} elseif ($status === 'pending') {
    if ($isBuyer) {
        $headline = 'Waiting for ' . $otherFirst . ' to accept';
    } else {
        $headline = $otherFirst . ' would like to ' . ($isBuy ? 'buy' : 'swap for') . ' this item';
        $buttons  = $post('accept', 'Accept request') . $post('decline', 'Decline', 'btn-small btn-ghost');
    }
} elseif ($dispute && $dispute['status'] === 'open') {
    $headline = 'A problem was reported';
} elseif ($status === 'accepted' && $cur === 2) {
    $headline = 'Agree how the item gets to the buyer';
    $text = 'Suggest a meet-up, a pick-up or a parcel below. When ' . $otherFirst . ' accepts, ' . ($isBuyer && $isBuy ? 'you can pay.' : 'the plan is set.');
    if ($isBuyer && $isBuy) {
        $extraHtml = '<p class="muted">Already agreed this in the chat? <a href="order.php?id=' . $oid . '&step=pay">Continue to payment</a></p>';
    }
} elseif ($status === 'accepted' && $isBuy && $cur === 3) {
    if ($isBuyer) {
        $headline = 'Pay for your item';
        $text = 'The money is held by TrustTrade and only reaches ' . $otherFirst . ' after you confirm that you received the item.';
        $payForm = StripeClient::enabled()
            ? $post('pay', 'Pay ' . number_format($amount, 2) . ' DKK with card')
            : $post('pay', 'Pay ' . number_format($amount, 2) . ' DKK (simulated)', 'btn-small', '<select name="method" style="margin-right:0.5rem;"><option value="card">Card</option><option value="mobilepay">MobilePay</option></select>');
        $buttons = $payForm;
        $extraHtml = '<table class="co-summary"><tr><td>Item</td><td>' . $h($o['listing_title']) . '</td></tr>'
                   . '<tr><td>Seller</td><td>' . $h($otherName) . '</td></tr>'
                   . '<tr><td>Hand-over</td><td>' . ($summary !== '' ? $h($summary) : 'Not agreed yet') . '</td></tr>'
                   . '<tr><td>Total</td><td><strong>' . number_format($amount, 2) . ' DKK</strong></td></tr></table>'
                   . (StripeClient::enabled() ? '<p class="muted">You pay on Stripe\'s secure page (test mode). Test card: 4242 4242 4242 4242, any future date, any 3 digits.</p>' : '');
    } else {
        $headline = 'Waiting for ' . $otherFirst . ' to pay';
    }
} elseif ($status === 'accepted' && $isBuy && $cur === 4) {
    if ($isBuyer) {
        $headline = 'Got your item? Check it first';
        $text     = 'Only press the button when you have checked the item and it is fine. Pressing it releases the money to the seller and it cannot be taken back. If something is wrong, use "Report a problem" below instead. The payment then stays frozen until an administrator decides.';
        $confirmForm = '<form method="POST" action="orders.php" onsubmit="return confirm(\'Is the item fine? This releases the money to the seller and cannot be undone. If something is wrong, press Cancel and use Report a problem instead.\');"><input type="hidden" name="order_id" value="' . $oid . '"><input type="hidden" name="action" value="confirm_received"><input type="hidden" name="back" value="' . $h($back) . '"><button type="submit" class="btn-small">I received the item and it is fine</button></form>';
        $buyerAtReceive = !$dispute;
        if ($buyerAtReceive) {
            $buttons   = '<div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:flex-start;">' . $confirmForm
                       . '<button type="button" class="btn-small btn-danger" onclick="document.getElementById(\'report_now\').style.display=\'block\';this.style.display=\'none\';">Report a problem</button></div>'
                       . '<div id="report_now" style="display:none;"><p class="muted">Add a photo that shows the problem. It is required.</p>' . $reportForm('report_now_form') . '</div>';
        } else {
            $buttons = $confirmForm;
        }
    } else {
        $headline = 'Hand over the item';
    }
} elseif ($status === 'accepted' && !$isBuy && $cur === 3) {
    if (!$isBuyer) {
        $headline = 'Swap done? Mark it completed';
        $buttons  = $post('complete', 'Mark as completed');
    } else {
        $headline = 'Waiting for ' . $otherFirst . ' to complete the swap';
    }
}
$replyNeeded = (!$isBuyer && $dispute && $dispute['status'] === 'open' && empty($dispute['seller_replied_at']) && (int) $dispute['raised_by'] === (int) $o['buyer_id']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your order - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .order-page { max-width: 720px; margin: 0 auto; }
        .op-back { display: inline-block; margin-bottom: 0.6rem; color: var(--muted); text-decoration: none; font-size: 0.9rem; }
        .op-head { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.4rem 1rem; align-items: baseline; }
        .op-head h2 { margin: 0; }
        .op-sub { color: var(--muted); font-size: 0.92rem; margin: 0.2rem 0 1rem; }
        .stepbar { list-style: none; display: flex; margin: 0 0 1.2rem; padding: 0; }
        .stepbar li { flex: 1; text-align: center; position: relative; font-size: 0.8rem; color: var(--muted); }
        .stepbar li::before { content: ""; position: absolute; top: 14px; left: -50%; width: 100%; height: 3px; background: var(--line); z-index: 0; }
        .stepbar li:first-child::before { display: none; }
        .stepbar .n { position: relative; z-index: 1; display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: #fff; border: 3px solid var(--line); font-weight: 700; margin-bottom: 0.25rem; }
        .stepbar li.done .n { background: var(--ok); border-color: var(--ok); color: #fff; }
        .stepbar li.done::before, .stepbar li.now::before { background: var(--ok); }
        .stepbar li.now .n { background: var(--hl); border-color: var(--ink); color: var(--ink-deep); }
        .stepbar li.now { color: var(--ink); font-weight: 700; }
        .stepbar li.stop .n { background: var(--danger); border-color: var(--danger); color: #fff; }
        .stepbar li.stop { color: #611a15; font-weight: 700; }
        .step-panel { background: #fff; border: 2px solid var(--ink); border-top: 6px solid var(--hl); border-radius: 14px; box-shadow: var(--shadow); padding: 1.2rem 1.3rem; margin-bottom: 1rem; }
        .step-panel h3 { margin: 0 0 0.4rem; color: var(--ink); font-size: 1.25rem; }
        .step-panel p { margin: 0 0 0.8rem; line-height: 1.5; }
        .step-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .step-actions form { margin: 0; display: flex; align-items: center; }
        .step-actions .btn-small { font-size: 1rem; padding: 0.7rem 1.4rem; }
        .co-summary { width: 100%; border-collapse: collapse; margin: 0.4rem 0 0.8rem; }
        .co-summary td { padding: 0.45rem 0; border-bottom: 1px solid var(--line-soft); }
        .co-summary td:first-child { color: var(--muted); width: 35%; }
        .op-foot { display: flex; flex-wrap: wrap; gap: 0.6rem 1rem; align-items: flex-start; margin-top: 1rem; padding-top: 0.8rem; border-top: 1px solid var(--line); }
        .op-foot details { font-size: 0.9rem; }
        .op-foot summary { cursor: pointer; color: var(--muted); }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
      <div class="order-page">
        <?php if ($flash): ?>
            <div class="flash flash-<?= $h($flash[0]) ?>"><?= $h($flash[1]) ?></div>
        <?php endif; ?>

        <a class="op-back" href="orders.php">&larr; All my orders</a>
        <div class="op-head">
            <h2><a href="view_listing.php?id=<?= (int) $o['listing_id'] ?>" style="color:inherit;text-decoration:none;"><?= $h($o['listing_title']) ?></a></h2>
            <?php if ($isBuy): ?><strong><?= number_format($amount, 2) ?> DKK</strong><?php endif; ?>
        </div>
        <div class="op-sub"><?= $isBuyer ? 'Buying from' : 'Selling to' ?> <?= $h($otherName) ?><?= (!$isBuy && !empty($o['offer_text'])) ? ' · Offer: ' . $h($o['offer_text']) : '' ?></div>

        <ol class="stepbar" aria-label="Order progress">
            <?php foreach ($steps as $i => $label): ?>
                <?php
                $cls = '';
                if ($terminal === null) {
                    if ($i < $cur) { $cls = 'done'; }
                    elseif ($i === $cur) { $cls = 'now'; }
                    // Paid before the hand-over was agreed: keep the Delivery step open
                    if ($i === 2 && $status === 'accepted' && !$planAgreed && $cur > 2) { $cls = 'now'; }
                } elseif ($i === 0) {
                    $cls = 'done';
                }
                ?>
                <li class="<?= $cls ?>"><span class="n"><?= $cls === 'done' ? '&#10003;' : $i + 1 ?></span><br><?= $h($label) ?></li>
            <?php endforeach; ?>
            <?php if ($terminal !== null): ?>
                <li class="stop"><span class="n">!</span><br><?= $h($terminal) ?></li>
            <?php endif; ?>
        </ol>

        <div class="step-panel">
            <h3><?= $h($headline) ?></h3>
            <?php if ($text !== ''): ?><p><?= $h($text) ?></p><?php endif; ?>
            <?= $extraHtml ?>
            <?php if ($buttons !== ''): ?><div class="step-actions"><?= $buttons ?></div><?php endif; ?>
        </div>

        <?php if ($dispute && !empty($dispute['seller_reply'])): ?>
            <p class="muted"><strong>Seller's reply:</strong> <?= $h($dispute['seller_reply']) ?></p>
        <?php endif; ?>
        <?php if ($replyNeeded): ?>
            <details class="report-box" open>
                <summary>Reply to the problem report</summary>
                <p class="muted">The buyer wrote: <?= $h($dispute['description']) ?></p>
                <form method="POST" action="orders.php" class="form-stack" style="margin-top:0.5rem;">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <input type="hidden" name="action" value="dispute_reply">
                    <input type="hidden" name="back" value="<?= $h($back) ?>">
                    <textarea name="reply" rows="3" placeholder="Explain your side. An administrator will read it."></textarea>
                    <button type="submit" class="btn-small">Send reply</button>
                </form>
            </details>
        <?php endif; ?>

        <?php if ($status === 'accepted'): ?>
            <?= deliveryBox($o, $me, $otherFirst, $back) ?>
        <?php elseif ($status === 'completed' && $ds !== 'none'): ?>
            <details><summary class="muted">Delivery details</summary><?= deliveryBox($o, $me, $otherFirst, $back) ?></details>
        <?php endif; ?>

        <div class="op-foot">
            <a class="btn-small btn-ghost" href="conversation.php?listing=<?= (int) $o['listing_id'] ?>&with=<?= $otherId ?>">Message <?= $h($otherFirst) ?></a>

            <?php if ($isBuyer && ($status === 'pending' || ($status === 'accepted' && $pay === 'unpaid'))): ?>
                <?= $post('cancel', 'Cancel request', 'btn-small btn-ghost') ?>
            <?php endif; ?>

            <?php if (in_array($status, ['accepted', 'completed'], true)): ?>
                <?php if ($dispute): ?>
                    <span class="muted">Problem reported</span>
                <?php elseif ($buyerAtReceive): ?>
                <?php else: ?>
                    <details class="report-box">
                        <summary>Report a problem</summary>
                        <?= $reportForm('report_foot') ?>
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
                                <?= $h($ev['event']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>
        </div>
      </div>
    </div>
</body>
</html>
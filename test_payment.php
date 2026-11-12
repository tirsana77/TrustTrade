<?php

session_start();

require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Dispute.php';

$userModel = new User();
$me = !empty($_SESSION['user_id']) ? $userModel->findById((int) $_SESSION['user_id']) : null;
if (!$me || empty($me['is_admin'])) {
    http_response_code(403);
    exit('Administrators only. Log in as an administrator and open this page again.');
}

$db           = (new Database())->getConnection();
$listingModel = new Listing();
$orderModel   = new Order();
$disputeModel = new Dispute();

$results = [];
function check(string $name, bool $ok): void
{
    global $results;
    $results[] = [$name, $ok];
}

function cleanupPayment(PDO $db): void
{
    $found = $db->query("SELECT id FROM users WHERE email LIKE 'autopay%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$found) {
        return;
    }
    $in = implode(',', array_map('intval', $found));
    $db->exec("DELETE FROM disputes WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE buyer_id IN ($in) OR seller_id IN ($in)) x)");
    $db->exec("DELETE FROM orders WHERE buyer_id IN ($in) OR seller_id IN ($in)");
    $db->exec("DELETE FROM listings WHERE user_id IN ($in)");
    $db->exec("DELETE FROM users WHERE id IN ($in)");
}

$crash = null;
cleanupPayment($db);

try {
    $tag    = bin2hex(random_bytes(3));
    $seller = $userModel->create('Pay Seller', "autopay_{$tag}_s@example.test", 'password123');
    $buyer  = $userModel->create('Pay Buyer', "autopay_{$tag}_b@example.test", 'password123');
    $other  = $userModel->create('Pay Other', "autopay_{$tag}_o@example.test", 'password123');

    $l1 = $listingModel->create($seller, "Autopay {$tag} laptop", 'Escrow test item', 100.00, 'Electronics');
    $l2 = $listingModel->create($seller, "Autopay {$tag} book", 'Escrow test item', 40.00, 'Textbooks');
    $l3 = $listingModel->create($seller, "Autopay {$tag} trade", 'Escrow test item', 10.00, 'Other');

    // ---- Paying ----
    $o1 = $orderModel->create($l1, $buyer, $seller, 'buy', null);
    $order = $orderModel->findById($o1);
    check('A new buy order starts unpaid', ($order['payment_status'] ?? '') === 'unpaid');
    check('An order that is still pending cannot be paid', $orderModel->pay($o1, $buyer, 'card') === false);

    $orderModel->updateStatus($o1, 'accepted');
    check('Only the buyer of the order can pay', $orderModel->pay($o1, $other, 'card') === false);
    check('An unknown payment method is rejected', $orderModel->pay($o1, $buyer, 'bitcoin') === false);
    check('The buyer can pay an accepted order (card)', $orderModel->pay($o1, $buyer, 'card') === true);

    $order = $orderModel->findById($o1);
    check('After paying the money is held', ($order['payment_status'] ?? '') === 'held');
    check('The amount is taken from the listing price (100.00)', abs((float) ($order['payment_amount'] ?? 0) - 100.00) < 0.001);
    check('The payment method and a reference are stored', ($order['payment_method'] ?? '') === 'card' && strpos((string) $order['payment_ref'], 'SIM-') === 0);
    check('An order cannot be paid twice', $orderModel->pay($o1, $buyer, 'mobilepay') === false);

    // ---- Releasing ----
    check('Another student cannot release the payment', $orderModel->releasePayment($o1, $other) === false);
    check('The seller cannot release their own payment', $orderModel->releasePayment($o1, $seller) === false);
    check('The buyer confirming receipt releases the payment', $orderModel->releasePayment($o1, $buyer) === true);
    $order = $orderModel->findById($o1);
    check('A released payment completes the order', ($order['payment_status'] ?? '') === 'released' && ($order['status'] ?? '') === 'completed');
    check('A released payment cannot be released again', $orderModel->releasePayment($o1, $buyer) === false);
    check('A released payment cannot be refunded', $orderModel->refundPayment($o1) === false);

    // ---- Refund after a dispute ----
    $o2 = $orderModel->create($l2, $buyer, $seller, 'buy', null);
    $orderModel->updateStatus($o2, 'accepted');
    $orderModel->pay($o2, $buyer, 'mobilepay');
    $did = $disputeModel->create($o2, $buyer, 'Item never handed over');
    $row = $disputeModel->findById($did);
    check('A dispute shows the order payment status', ($row['payment_status'] ?? '') === 'held');
    $open = $disputeModel->listByStatus('open');
    $found = null;
    foreach ($open as $d) {
        if ((int) $d['id'] === $did) {
            $found = $d;
        }
    }
    check('The admin dispute list includes payment details', $found !== null && ($found['payment_method'] ?? '') === 'mobilepay');

    check('The admin can refund a held payment', $orderModel->refundPayment($o2) === true);
    $order = $orderModel->findById($o2);
    check('A refund marks the payment refunded and the order cancelled', ($order['payment_status'] ?? '') === 'refunded' && ($order['status'] ?? '') === 'cancelled');
    check('A refunded payment cannot be released', $orderModel->releasePayment($o2) === false);
    check('Refunded orders no longer count as open requests', $orderModel->hasOpenRequest($l2, $buyer) === false);

    // ---- Trade orders ----
    $o3 = $orderModel->create($l3, $buyer, $seller, 'trade', 'My calculator');
    $orderModel->updateStatus($o3, 'accepted');
    check('A trade order cannot be paid', $orderModel->pay($o3, $buyer, 'card') === false);
    check('Buyer and seller can still see their orders', count($orderModel->forBuyer($buyer)) === 3 && count($orderModel->forSeller($seller)) === 3);
} catch (Throwable $e) {
    $crash = $e->getMessage() . ' (line ' . $e->getLine() . ')';
}

cleanupPayment($db);

$passed = count(array_filter($results, fn($r) => $r[1]));
$total  = count($results);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Payment tests - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h1>Payment tests (simulated escrow)</h1>
        <?php if ($crash !== null): ?>
            <div class="flash flash-error">The tests stopped with an error: <?= htmlspecialchars($crash) ?>.
            If it mentions an unknown column, run payment_columns.sql in phpMyAdmin first.</div>
        <?php endif; ?>
        <table class="data-table">
            <?php foreach ($results as [$name, $ok]): ?>
                <tr><td><?= htmlspecialchars($name) ?></td><td><?= $ok ? '<span class="pill pill-completed">PASS</span>' : '<span class="pill pill-sold">FAIL</span>' ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p><strong><?= $passed ?> of <?= $total ?> checks passed.</strong> Temporary test accounts were removed.</p>
        <p><a href="controllers/dashboard.php" class="back-link">← Back to the site</a></p>
    </div>
</body>
</html>
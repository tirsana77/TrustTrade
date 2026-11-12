<?php
// Tests for the automatic rules. Open as an administrator, or run: php test_automation.php
// The rules take the current time as a parameter, so deadlines are tested by passing a time
// 6 days 23 hours 59 minutes, exactly 7 days, or 8 days later. No waiting is needed.
// The rules are limited to the temporary test accounts, so real data is never touched.

if (PHP_SAPI !== 'cli') {
    session_start();
}
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Dispute.php';
require_once __DIR__ . '/models/Report.php';
require_once __DIR__ . '/models/Automation.php';

if (PHP_SAPI !== 'cli') {
    $me = !empty($_SESSION['user_id']) ? (new User())->findById((int) $_SESSION['user_id']) : null;
    if (!$me || empty($me['is_admin'])) {
        http_response_code(403);
        exit('Administrators only. Log in as an administrator and open this page again.');
    }
}

$db           = (new Database())->getConnection();
$userModel    = new User();
$listingModel = new Listing();
$orderModel   = new Order();
$disputeModel = new Dispute();
$reportModel  = new Report();

$results = [];
function check(string $name, bool $ok): void
{
    global $results;
    $results[] = [$name, $ok];
}

function cleanupAuto(PDO $db): void
{
    $ids = $db->query("SELECT id FROM users WHERE email LIKE 'autoauto%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM notifications WHERE user_id IN ($in)");
    $db->exec("DELETE FROM listings WHERE user_id IN ($in)");   // cascades orders, disputes, events, reports, views
    $db->exec("DELETE FROM users WHERE id IN ($in)");
}

function mkUser(User $m, PDO $db, string $name, string $tag, bool $old = false): int
{
    $id = $m->create($name, "autoauto_{$tag}_" . strtolower(substr($name, 0, 1)) . bin2hex(random_bytes(2)) . '@example.test', 'password123');
    $db->prepare('UPDATE users SET student_verified = 1' . ($old ? ", created_at = '2020-01-01 10:00:00'" : '') . ' WHERE id = :id')->execute(['id' => $id]);
    return $id;
}

function paidOrder(Order $om, int $listing, int $buyer, int $seller): int
{
    $o = $om->create($listing, $buyer, $seller, 'buy', null);
    $om->updateStatus($o, 'accepted');
    $om->pay($o, $buyer, 'card');
    return $o;
}

function f(DateTimeImmutable $t): string
{
    return $t->format('Y-m-d H:i:s');
}

function events(PDO $db, int $orderId, int $auto = 1): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM order_events WHERE order_id = :o AND is_automatic = :a');
    $st->execute(['o' => $orderId, 'a' => $auto]);
    return (int) $st->fetchColumn();
}

function notes(PDO $db, int $user, string $like): int
{
    $st = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND message LIKE :m');
    $st->execute(['u' => $user, 'm' => $like]);
    return (int) $st->fetchColumn();
}

$crash = null;
cleanupAuto($db);

try {
    $tag    = bin2hex(random_bytes(3));
    $seller = mkUser($userModel, $db, 'Auto Seller', $tag, true);
    $buyer  = mkUser($userModel, $db, 'Auto Buyer', $tag, true);
    $third  = mkUser($userModel, $db, 'Auto Third', $tag, true);
    $fourth = mkUser($userModel, $db, 'Auto Fourth', $tag, true);
    $newbie = mkUser($userModel, $db, 'Auto Newbie', $tag, false);
    $auto   = new Automation([$seller, $buyer, $third, $fourth, $newbie]);
    $now    = $orderModel->dbNow();

    // ---- Pure rules and boundaries ----
    check('Risk score 59 does not hold a listing', Automation::shouldHoldForRisk(59) === false);
    check('Risk score 60 holds a listing', Automation::shouldHoldForRisk(60) === true);
    check('2 different reporters do not hold a listing', Automation::shouldHoldForReports(2) === false);
    check('3 different reporters hold a listing', Automation::shouldHoldForReports(3) === true);

    // ---- 1. Automatic release after 7 days ----
    $l1 = $listingModel->create($seller, "Autoauto {$tag} calculator", 'Test item', 100.00, 'Calculators');
    $o1 = paidOrder($orderModel, $l1, $buyer, $seller);
    $row = $orderModel->findById($o1);
    $due = new DateTimeImmutable($row['auto_release_at']);
    $paid = new DateTimeImmutable($row['paid_at']);
    check('Paying sets the release deadline 7 days ahead', $due->getTimestamp() - $paid->getTimestamp() >= 7 * 86400 - 5 && $due->getTimestamp() - $paid->getTimestamp() <= 7 * 86400 + 5);

    $auto->autoRelease($due->modify('-1 second'));
    check('One second before the deadline the payment is still held', $orderModel->findById($o1)['payment_status'] === 'held');
    check('At the deadline the payment is released automatically', $auto->autoRelease($due) === 1);
    $row = $orderModel->findById($o1);
    check('Automatic release completes the order', $row['payment_status'] === 'released' && $row['status'] === 'completed');
    check('Automatic release marks the listing as sold', $listingModel->findById($l1)['status'] === 'sold');
    check('Both people are notified of the automatic release', notes($db, $buyer, 'Automatic:%released%') === 1 && notes($db, $seller, 'Automatic:%released%') === 1);
    check('The release is recorded in the timeline as automatic', events($db, $o1) === 1);
    check('Running the rule again changes nothing', $auto->autoRelease($due->modify('+1 day')) === 0 && events($db, $o1) === 1);

    // ---- 2. An open dispute freezes the payment ----
    $l2 = $listingModel->create($seller, "Autoauto {$tag} book", 'Test item', 50.00, 'Textbooks');
    $o2 = paidOrder($orderModel, $l2, $buyer, $seller);
    $deadline = $now->modify('+3 days');
    $d2 = $disputeModel->create($o2, $buyer, 'Not as described', f($deadline));
    $due2 = new DateTimeImmutable($orderModel->findById($o2)['auto_release_at']);
    $auto->autoRelease($due2->modify('+1 day'));
    check('A payment with an open dispute is never auto-released', $orderModel->findById($o2)['payment_status'] === 'held');

    // ---- 3. Seller must reply within 3 days, otherwise the buyer is refunded ----
    $auto->autoRefundSilentSellers($deadline->modify('-1 second'));
    check('One second before the reply deadline there is no refund', $orderModel->findById($o2)['payment_status'] === 'held');
    check('At the reply deadline the buyer is refunded automatically', $auto->autoRefundSilentSellers($deadline) === 1);
    $row = $orderModel->findById($o2);
    check('The automatic refund cancels the order', $row['payment_status'] === 'refunded' && $row['status'] === 'cancelled');
    $dr = $disputeModel->findById($d2);
    check('The dispute is closed with an automatic note', $dr['status'] === 'resolved' && strpos((string) $dr['resolution_note'], 'Automatic') === 0);
    check('Both people are notified of the automatic refund', notes($db, $buyer, 'Automatic:%refunded%') === 1 && notes($db, $seller, 'Automatic:%refunded%') === 1);
    check('A refunded order cannot be released afterwards', $orderModel->releasePayment($o2) === false);
    check('The refund rule does not run twice', $auto->autoRefundSilentSellers($deadline->modify('+1 day')) === 0);

    // ---- 4. A seller reply stops the automatic refund ----
    $l3 = $listingModel->create($seller, "Autoauto {$tag} lamp", 'Test item', 60.00, 'Other');
    $o3 = paidOrder($orderModel, $l3, $buyer, $seller);
    $d3 = $disputeModel->create($o3, $buyer, 'Broken', f($deadline));
    check('Another student cannot answer the dispute', $disputeModel->sellerReply($d3, $third, 'Not mine', f($now)) === false);
    check('The seller can answer the dispute', $disputeModel->sellerReply($d3, $seller, 'It worked when I sold it', f($now)) === true);
    check('A dispute can only be answered once', $disputeModel->sellerReply($d3, $seller, 'Again', f($now)) === false);
    $auto->autoRefundSilentSellers($deadline->modify('+2 days'));
    $row = $orderModel->findById($o3);
    check('After a seller reply the buyer is not refunded automatically', $row['payment_status'] === 'held' && $disputeModel->findById($d3)['status'] === 'open');
    check('An administrator can still decide (refund)', $orderModel->refundPayment($o3) === true);

    // ---- 5. Unpaid orders: reminder after 24 hours, cancel after 3 days ----
    $l4 = $listingModel->create($seller, "Autoauto {$tag} mouse", 'Test item', 30.00, 'Electronics');
    $o4 = $orderModel->create($l4, $buyer, $seller, 'buy', null);
    $orderModel->updateStatus($o4, 'accepted');
    $acc = $now;
    $db->prepare('UPDATE orders SET accepted_at = :t WHERE id = :id')->execute(['t' => f($acc), 'id' => $o4]);
    check('No reminder after 23 hours', $auto->remindUnpaid($acc->modify('+23 hours')) === 0);
    check('A reminder is sent after 24 hours', $auto->remindUnpaid($acc->modify('+24 hours')) === 1 && notes($db, $buyer, 'Reminder: pay for%') === 1);
    check('The reminder is sent only once', $auto->remindUnpaid($acc->modify('+30 hours')) === 0);
    check('Not cancelled after 2 days 23 hours', $auto->cancelUnpaid($acc->modify('+2 days 23 hours')) === 0 && $orderModel->findById($o4)['status'] === 'accepted');
    check('Cancelled after 3 days', $auto->cancelUnpaid($acc->modify('+3 days')) === 1 && $orderModel->findById($o4)['status'] === 'cancelled');
    check('The buyer and the seller are told about the cancellation', notes($db, $buyer, 'Automatic:%cancelled%') === 1 && notes($db, $seller, 'Automatic:%cancelled%') === 1);
    $o5 = paidOrder($orderModel, $listingModel->create($seller, "Autoauto {$tag} pen", 'Test item', 20.00, 'Stationery'), $third, $seller);
    $db->prepare('UPDATE orders SET accepted_at = :t WHERE id = :id')->execute(['t' => f($acc->modify('-10 days')), 'id' => $o5]);
    $auto->cancelUnpaid($acc->modify('+20 days'));
    check('A paid order is never cancelled for non-payment', $orderModel->findById($o5)['status'] === 'accepted');

    // ---- 6. Listings: AI risk score and reports ----
    $scam = $listingModel->create($newbie, "Autoauto {$tag} laptop", 'Pay first by western union or whatsapp, gift card accepted, urgent', 20.00, 'Electronics');
    $reason = $auto->checkListing($scam);
    check('A listing with scam wording from a brand-new account is held at once', $reason !== null && $listingModel->findById($scam)['status'] === 'held');
    check('The held listing is not shown in search', count(array_filter($listingModel->search("Autoauto {$tag} laptop"), fn($x) => (int) $x['id'] === $scam)) === 0);
    check('The seller is told why the listing was held', notes($db, $newbie, 'Automatic: your listing%under review%') === 1);
    $normal = $listingModel->create($third, "Autoauto {$tag} textbook", 'Clean copy, a few notes inside', 150.00, 'Textbooks');
    check('A normal listing from an established seller is not held', $auto->checkListing($normal) === null && $listingModel->findById($normal)['status'] === 'active');

    check('An administrator can restore a held listing', $auto->restoreListing($scam) === true && $listingModel->findById($scam)['status'] === 'active');
    $auto->holdListings($now);
    check('A restored listing is not held again by the rules', $listingModel->findById($scam)['status'] === 'active');
    check('Restoring a listing that is not on hold does nothing', $auto->restoreListing($normal) === false);

    $rep = $listingModel->create($third, "Autoauto {$tag} headphones", 'Good condition', 200.00, 'Electronics');
    $reportModel->create($buyer, $rep, $third, 'Looks fake');
    $reportModel->create($fourth, $rep, $third, 'Photos are from the internet');
    check('Two reports do not hold a listing', $auto->checkListing($rep) === null);
    $reportModel->create($seller, $rep, $third, 'Seller asked me to pay outside');
    check('Three different reporters hold the listing', $auto->checkListing($rep) !== null && $listingModel->findById($rep)['status'] === 'held');
    $rep2 = $listingModel->create($third, "Autoauto {$tag} keyboard", 'Good condition', 80.00, 'Electronics');
    for ($i = 0; $i < 3; $i++) {
        $reportModel->create($buyer, $rep2, $third, "Report {$i}");
    }
    check('Three reports from the same student count as one', $auto->checkListing($rep2) === null);

    // ---- 7. Seller record and review flag ----
    check('A seller with no sales is a new seller', $auto->sellerStats($fourth)['level'] === 'new');
    $s5 = mkUser($userModel, $db, 'Auto Star', $tag, true);
    $auto = new Automation([$seller, $buyer, $third, $fourth, $newbie, $s5]);
    for ($i = 1; $i <= 4; $i++) {
        $lo = $listingModel->create($s5, "Autoauto {$tag} item{$i}", 'Test item', 10.00 * $i, 'Other');
        $oo = $orderModel->create($lo, $buyer, $s5, 'buy', null);
        $orderModel->updateStatus($oo, 'completed');
    }
    check('4 completed sales are not enough for the trusted badge', $auto->sellerStats($s5)['level'] === 'standard');
    $lo = $listingModel->create($s5, "Autoauto {$tag} item5", 'Test item', 50.00, 'Other');
    $oo = $orderModel->create($lo, $buyer, $s5, 'buy', null);
    $orderModel->updateStatus($oo, 'completed');
    check('5 completed sales and no refunds give the trusted badge', $auto->sellerStats($s5)['level'] === 'trusted');

    // $seller has 2 refunded orders after disputes (o2 refunded automatically, o3 refunded by an admin)
    check('Two refunded orders flag the seller account for review', $auto->flagSellers() === 1 && $auto->sellerStats($seller)['level'] === 'review');
    check('The seller is told about the review', notes($db, $seller, 'Automatic: your account is under review%') === 1);
    check('The flag is set only once', $auto->flagSellers() === 0);

    // ---- 8. Many random sequences: money moves only in allowed ways ----
    $allowed = ['unpaid' => ['unpaid', 'held'], 'held' => ['held', 'released', 'refunded'], 'released' => ['released'], 'refunded' => ['refunded']];
    $bad = 0;
    $runs = 60;
    mt_srand(2026);
    $lr = $listingModel->create($third, "Autoauto {$tag} random", 'Test item', 40.00, 'Other');
    for ($r = 0; $r < $runs; $r++) {
        $oid = $orderModel->create($lr, $fourth, $third, 'buy', null);
        $orderModel->updateStatus($oid, 'accepted');
        $prev = 'unpaid';
        $did = null;
        for ($step = 0; $step < 8; $step++) {
            switch (mt_rand(0, 6)) {
                case 0: $orderModel->pay($oid, $fourth, 'card'); break;
                case 1: $orderModel->releasePayment($oid, $fourth); break;
                case 2: $orderModel->refundPayment($oid); break;
                case 3: if ($did === null) { $did = $disputeModel->create($oid, $fourth, 'x', f($now->modify('+3 days'))); } break;
                case 4: $auto->autoRelease($now->modify('+' . mt_rand(0, 10) . ' days')); break;
                case 5: $auto->autoRefundSilentSellers($now->modify('+' . mt_rand(0, 5) . ' days')); break;
                case 6: if ($did !== null) { $disputeModel->sellerReply($did, $third, 'reply', f($now)); } break;
            }
            $cur = $orderModel->findById($oid);
            if (!in_array($cur['payment_status'], $allowed[$prev], true)) {
                $bad++;
            }
            if ($cur['payment_status'] === 'released' && $cur['status'] !== 'completed') { $bad++; }
            if ($cur['payment_status'] === 'refunded' && $cur['status'] !== 'cancelled') { $bad++; }
            $prev = $cur['payment_status'];
        }
        $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id AND status = 'accepted'")->execute(['id' => $oid]);
    }
    check("{$runs} random action sequences: payment states only move unpaid, held, then released or refunded", $bad === 0);

    // ---- 9. Repeated action ----
    $l9 = $listingModel->create($seller, "Autoauto {$tag} double", 'Test item', 70.00, 'Other');
    $o9 = paidOrder($orderModel, $l9, $buyer, $seller);
    check('Pressing release twice releases the money only once', $orderModel->releasePayment($o9, $buyer) === true && $orderModel->releasePayment($o9, $buyer) === false);
} catch (Throwable $e) {
    $crash = $e->getMessage() . ' (line ' . $e->getLine() . ')';
}

cleanupAuto($db);

$passed = count(array_filter($results, fn($r) => $r[1]));
$total  = count($results);

if (PHP_SAPI === 'cli') {
    foreach ($results as [$name, $ok]) {
        echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n";
    }
    echo ($crash !== null ? "STOPPED: $crash\n" : '') . "$passed of $total checks passed.\n";
    exit($passed === $total && $crash === null ? 0 : 1);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Automation tests - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h1>Automation tests</h1>
        <?php if ($crash !== null): ?>
            <div class="flash flash-error">The tests stopped with an error: <?= htmlspecialchars($crash) ?>.
            If it mentions an unknown column, run the database changes (migration) in phpMyAdmin first.</div>
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
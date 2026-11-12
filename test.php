<?php
session_start();

require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Message.php';
require_once __DIR__ . '/models/Report.php';
require_once __DIR__ . '/models/Dispute.php';
require_once __DIR__ . '/models/Admin.php';

$userModel = new User();
$me = !empty($_SESSION['user_id']) ? $userModel->findById((int) $_SESSION['user_id']) : null;
if (!$me || empty($me['is_admin'])) {
    http_response_code(403);
    exit('Administrators only. Log in as an administrator and open this page again.');
}

$listingModel = new Listing();
$orderModel   = new Order();
$messageModel = new Message();
$reportModel  = new Report();
$disputeModel = new Dispute();
$adminModel   = new Admin();
$db = (new Database())->getConnection();

$results = [];

function check(string $name, bool $ok): void
{
    global $results;
    $results[] = [$name, $ok];
}

function ids(array $rows): array
{
    return array_map('intval', array_column($rows, 'id'));
}

/** Removes every temporary account (and its listings) created by this script. */
function cleanup(PDO $db): void
{
    $found = $db->query("SELECT id FROM users WHERE email LIKE 'autotest%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$found) {
        return;
    }
    $in = implode(',', array_map('intval', $found));
    $db->exec("DELETE FROM listings WHERE user_id IN ($in)");
    $db->exec("DELETE FROM users WHERE id IN ($in)");
}

$crash = null;
cleanup($db);

try {
    $tag    = bin2hex(random_bytes(3));
    $emailS = "autotest_{$tag}_s@example.test";
    $emailA = "autotest_{$tag}_a@example.test";
    $emailB = "autotest_{$tag}_b@example.test";
    $emailC = "autotest_{$tag}_c@example.test";

    // ---------- Accounts and passwords ----------
    $idS = $userModel->create('Auto Seller', $emailS, 'password123');
    $idA = $userModel->create('Auto A', $emailA, 'password123');
    $idB = $userModel->create('Auto B', $emailB, 'password123');
    $idC = $userModel->create('Auto C', $emailC, 'password123');

    $s = $userModel->findById($idS);
    check('Registration creates a user row', $s !== null && ($s['email'] ?? '') === $emailS);
    check('findByEmail finds the account', (int) ($userModel->findByEmail($emailS)['id'] ?? 0) === $idS);
    check('findByEmail returns nothing for an unknown email', $userModel->findByEmail("nobody_{$tag}@example.test") === null);
    check('Password is stored hashed, not in plain text', ($s['password_hash'] ?? '') !== 'password123' && strlen($s['password_hash'] ?? '') >= 60);
    check('Correct password is accepted', $userModel->verifyPassword('password123', $s['password_hash'] ?? ''));
    check('Wrong password is rejected', !$userModel->verifyPassword('wrongpass', $s['password_hash'] ?? ''));
    check('New accounts start unverified', (int) ($s['student_verified'] ?? 1) === 0);
    check('New accounts are not administrators', (int) ($s['is_admin'] ?? 1) === 0);

    $duplicate = false;
    try {
        $userModel->create('Duplicate', $emailS, 'password123');
    } catch (PDOException $e) {
        $duplicate = true;
    }
    check('A duplicate email is rejected by the database', $duplicate);

    // ---------- Student verification gate ----------
    $userModel->saveStudentIdPhoto($idB, 'fake_id.jpg');
    check('A submitted ID appears in the review queue', in_array($idB, ids($userModel->getPendingVerifications()), true));
    $userModel->approveStudent($idB);
    check('Approving sets student_verified to 1', (int) ($userModel->findById($idB)['student_verified'] ?? 0) === 1);
    check('An approved student leaves the review queue', !in_array($idB, ids($userModel->getPendingVerifications()), true));
    $adminModel->setVerified($idB, false);
    $b = $userModel->findById($idB);
    check('Removing verification resets the flag and clears the ID photo', (int) ($b['student_verified'] ?? 1) === 0 && empty($b['student_id_photo']));
    $userModel->saveStudentIdPhoto($idC, 'fake_id2.jpg');
    $userModel->rejectStudent($idC);
    check('Rejecting an ID clears the stored photo', empty($userModel->findById($idC)['student_id_photo']));
    check('An ID date in the past is refused', User::idExpiryError('2020-01-01') !== null);
    check('A missing ID date is refused', User::idExpiryError('') !== null);
    check('A nonsense ID date is refused', User::idExpiryError('2027-13-45') !== null);
    check('An ID date more than 10 years ahead is refused', User::idExpiryError(date('Y-m-d', strtotime('+11 years'))) !== null);
    check('A valid future ID date is accepted', User::idExpiryError(date('Y-m-d', strtotime('+1 year'))) === null);
    $userModel->saveStudentIdPhoto($idC, 'fake_id3.jpg', date('Y-m-d', strtotime('-1 day')));
    check('A stored ID whose date has passed is recognised as expired', User::idIsExpired($userModel->findById($idC)));
    $userModel->rejectStudent($idC);
    check('Rejecting an ID also clears its date', empty($userModel->findById($idC)['student_id_expiry']));

    // ---------- Listings and search ----------
    $l1 = $listingModel->create($idS, "Autotest {$tag} item 1", 'Barely used, no markings', 25.00, 'Textbooks');
    $l2 = $listingModel->create($idS, "Autotest {$tag} item 2", 'Works perfectly', 15.00, 'Electronics');
    $l3 = $listingModel->create($idS, "Autotest {$tag} item 3", 'Good condition', 10.00, 'Textbooks');
    $l4 = $listingModel->create($idS, "Autotest {$tag} item 4", 'Spare copy', 5.00, 'Other');

    $f = $listingModel->findById($l1);
    check('A listing can be created and read back with its seller name',
        $f !== null && ($f['title'] ?? '') === "Autotest {$tag} item 1" && (float) ($f['price'] ?? 0) === 25.0
        && ($f['status'] ?? '') === 'active' && ($f['seller_name'] ?? '') === 'Auto Seller');

    $found = ids($listingModel->search("Autotest {$tag}"));
    check('Keyword search finds all four test listings', count(array_intersect($found, [$l1, $l2, $l3, $l4])) === 4);
    $electronics = ids($listingModel->search("Autotest {$tag}", 'Electronics'));
    check('Category filter keeps only matching listings', in_array($l2, $electronics, true) && !in_array($l1, $electronics, true));

    $listingModel->setStatus($l2, 'sold');
    check('Sold listings are hidden from search', !in_array($l2, ids($listingModel->search("Autotest {$tag}")), true));

    $listingModel->update($l1, $idA, 'Hijacked title', 'Hijacked description', 1.00, 'Other');
    check('A non-owner cannot edit a listing', ($listingModel->findById($l1)['title'] ?? '') === "Autotest {$tag} item 1");
    $listingModel->update($l1, $idS, "Autotest {$tag} item 1", 'Barely used, no markings', 30.00, 'Textbooks');
    check('The owner can edit their own listing', (float) ($listingModel->findById($l1)['price'] ?? 0) === 30.0);

    check('Owner can delete a listing that has no requests', $listingModel->delete($l4, $idS) === true && $listingModel->findById($l4) === null);

    // ---------- Orders ----------
    $o = $orderModel->create($l1, $idB, $idS, 'buy', null);
    $order = $orderModel->findById($o);
    check('A buy request is created as pending', ($order['status'] ?? '') === 'pending' && ($order['type'] ?? '') === 'buy');
    check('An open request is detected for the same buyer', $orderModel->hasOpenRequest($l1, $idB));
    check('Another buyer has no open request', !$orderModel->hasOpenRequest($l1, $idC));
    $orderModel->updateStatus($o, 'accepted');
    check('A seller can accept a request', ($orderModel->findById($o)['status'] ?? '') === 'accepted');
    $orderModel->updateStatus($o, 'cancelled');
    check('A cancelled request no longer counts as open', !$orderModel->hasOpenRequest($l1, $idB));
    $o2 = $orderModel->create($l3, $idC, $idS, 'trade', 'My old calculator');
    check('A trade request stores the offer text', ($orderModel->findById($o2)['offer_text'] ?? '') === 'My old calculator');
    check('Buyer and seller can each see the order', count($orderModel->forBuyer($idB)) === 1 && count($orderModel->forSeller($idS)) === 2);
    check('Owner cannot delete a listing that already has requests', $listingModel->delete($l1, $idS) === false && $listingModel->findById($l1) !== null);

    // ---------- Messages ----------
    $messageModel->send($l1, $idB, $idS, 'Is this still available?');
    check('The receiver has one unread message', $messageModel->unreadCount($idS) === 1);
    check('The conversation list shows one conversation', count($messageModel->conversations($idS)) === 1);
    $messageModel->markThreadRead($l1, $idS, $idB);
    check('Opening the thread marks messages as read', $messageModel->unreadCount($idS) === 0);
    check('The thread returns the message', count($messageModel->thread($l1, $idS, $idB)) === 1);

    // ---------- Reports and disputes ----------
    $rid = $reportModel->create($idB, $l1, $idS, 'Looks like a scam');
    check('A new report appears in the open list', in_array($rid, ids($reportModel->listByStatus('open')), true));
    $reportModel->setStatus($rid, 'resolved');
    check('A resolved report leaves the open list', !in_array($rid, ids($reportModel->listByStatus('open')), true));

    $did = $disputeModel->create($o, $idB, 'Item was not as described');
    check('A dispute is recorded for the order', $disputeModel->existsForOrder($o));
    check('A new dispute appears in the open list', in_array($did, ids($disputeModel->listByStatus('open')), true));
    $disputeModel->resolve($did, 'resolved', 'Refund agreed');
    check('A resolved dispute leaves the open list', !in_array($did, ids($disputeModel->listByStatus('open')), true));

    // ---------- Recommendations (collaborative filtering) ----------
    $listingModel->recordView($idA, $l1);
    $listingModel->recordView($idB, $l1);
    $listingModel->recordView($idB, $l3);
    $recs = $listingModel->getRecommendations($idA, 4);
    $recIds = ids($recs);
    check('A listing viewed by similar users is recommended', in_array($l3, $recIds, true));
    check('Recommendations exclude listings the user already viewed', !in_array($l1, $recIds, true));
    $ownRecs = $listingModel->getRecommendations($idS, 4);
    $ownClean = true;
    foreach ($ownRecs as $r) {
        if ((int) $r['user_id'] === $idS) {
            $ownClean = false;
        }
    }
    check('Recommendations never include listings owned by the same user', $ownClean);
    check('A brand-new user (cold start) gets a list without errors', is_array($listingModel->getRecommendations($idC, 4)));

    // ---------- Admin statistics ----------
    $stats = $adminModel->stats();
    check('Admin statistics return counts for every figure',
        isset($stats['students'], $stats['verified'], $stats['pending'], $stats['open_reports'], $stats['open_disputes'], $stats['active_listings'], $stats['orders']));
} catch (Throwable $e) {
    $crash = $e->getMessage() . ' (line ' . $e->getLine() . ')';
} finally {
    cleanup($db);
}

$passed = count(array_filter($results, fn($r) => $r[1]));
$total  = count($results);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Automated tests - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h2>Automated tests: model layer</h2>
        <p class="muted">Creates temporary accounts named autotest_*@example.test, runs every check below, then deletes them. Run <?= date('j M Y, H:i') ?>.</p>

        <?php if ($crash): ?>
            <div class="flash flash-error">The run stopped early: <?= htmlspecialchars($crash) ?></div>
        <?php endif; ?>

        <div class="flash <?= ($passed === $total && !$crash) ? 'flash-success' : 'flash-error' ?>">
            <?= $passed ?> of <?= $total ?> checks passed
        </div>

        <table class="data-table">
            <tr><th>#</th><th>Check</th><th>Result</th></tr>
            <?php foreach ($results as $i => $r): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($r[0]) ?></td>
                    <td><span class="pill <?= $r[1] ? 'pill-completed' : 'pill-sold' ?>"><?= $r[1] ? 'PASS' : 'FAIL' ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <p><a href="test.php" class="btn-inline">Run again</a> <a href="controllers/admin.php" class="btn-outline">Back to admin</a></p>
    </div>
</body>
</html>
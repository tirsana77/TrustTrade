<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/Dispute.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Admin.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/AiInsights.php';
require_once __DIR__ . '/../models/Automation.php';
require_once __DIR__ . '/../includes/admin_charts.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$user->ensureExpiryColumn();
$currentUser = $user->findById($_SESSION['user_id']);

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    die('You do not have access to this page.');
}

// The details window of the charts asks for its data here (JSON only)
if (isset($_GET['detail'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $detail = adminDetail((new Database())->getConnection(), (string) ($_GET['type'] ?? ''), (string) ($_GET['key'] ?? ''));
    if ($detail === null) {
        http_response_code(404);
        echo json_encode(['error' => 'Nothing found.']);
    } else {
        echo json_encode($detail);
    }
    exit;
}

Automation::runIfDue();

$listingModel = new Listing();
$reportModel  = new Report();
$disputeModel = new Dispute();
$orderModel   = new Order();
$adminModel   = new Admin();
$notify       = new Notification();

/** Tell both parties of an order what the administrator decided. */
$notifyParties = function (int $orderId, string $text) use ($orderModel, $notify): void {
    $o = $orderModel->findById($orderId);
    if ($o) {
        $msg = 'Administrator decision on "' . $o['listing_title'] . '": ' . $text;
        $notify->add((int) $o['buyer_id'],  $msg, 'orders.php');
        $notify->add((int) $o['seller_id'], $msg, 'orders.php');
    }
};

$titles = [
    'overview'      => 'Overview',
    'verifications' => 'Verifications',
    'reports'       => 'Reports',
    'disputes'      => 'Disputes',
    'listings'      => 'Listings',
    'held'          => 'Held listings',
    'users'         => 'Users',
];
$tab = $_GET['tab'] ?? 'overview';
if (!isset($titles[$tab])) {
    $tab = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash  = ['error', 'Something went wrong. Please try again.'];

    if ($action === 'approve') {
        $applicantRow = $user->findById((int) ($_POST['user_id'] ?? 0));
        if ($applicantRow === null || empty($applicantRow['student_id_photo']) || empty($applicantRow['profile_photo'])) {
            // Both photos are needed to compare the face with the ID
            $flash = ['error', 'This student has not uploaded both a student ID and a face photo. Reject it so they upload both.'];
        } elseif (User::idIsExpired($applicantRow)) {
            $flash = ['error', 'This student ID has expired. Reject it and choose the reason "ID has expired".'];
        } else {
            $user->approveStudent((int) $applicantRow['id']);
            $notify->add((int) $applicantRow['id'], 'Your student ID was approved. You can now buy, sell and trade.', 'dashboard.php');
            $flash = ['success', 'Student approved.'];
        }
    } elseif ($action === 'reject') {
        $reasons = ['mismatch' => 'the face photo does not match the student ID', 'unclear' => 'a photo is unclear', 'expired' => 'the student ID has expired', 'notid' => 'the picture is not a student ID'];
        $why = $reasons[$_POST['reason'] ?? ''] ?? null;
        $user->rejectStudent((int) ($_POST['user_id'] ?? 0));
        $notify->add((int) ($_POST['user_id'] ?? 0), 'Your student ID was not accepted' . ($why ? ': ' . $why : '') . '. Please upload new photos.', 'dashboard.php');
        $flash = ['success', 'Submission rejected. The student can upload a new photo.'];
    } elseif ($action === 'revoke') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId === (int) $currentUser['id']) {
            $flash = ['error', 'You cannot change your own account here.'];
        } else {
            $adminModel->setVerified($targetId, false);
            $flash = ['success', 'Verification removed. The student must upload a new ID.'];
        }
    } elseif ($action === 'report_resolve') {
        $reportModel->setStatus((int) ($_POST['report_id'] ?? 0), 'resolved');
        $flash = ['success', 'Report marked as resolved.'];
    } elseif ($action === 'report_dismiss') {
        $reportModel->setStatus((int) ($_POST['report_id'] ?? 0), 'dismissed');
        $flash = ['success', 'Report dismissed.'];
    } elseif ($action === 'report_remove_listing') {
        $listingModel->delete((int) ($_POST['listing_id'] ?? 0));
        $reportModel->setStatus((int) ($_POST['report_id'] ?? 0), 'resolved');
        $flash = ['success', 'Listing removed and report resolved.'];
    } elseif ($action === 'dispute_resolve' || $action === 'dispute_dismiss') {
        $note   = trim($_POST['note'] ?? '');
        $status = ($action === 'dispute_resolve') ? 'resolved' : 'dismissed';
        $dId = (int) ($_POST['dispute_id'] ?? 0);
        $d   = $disputeModel->findById($dId);
        $disputeModel->resolve($dId, $status, $note);
        if ($d) {
            $notifyParties((int) $d['order_id'], ($status === 'resolved' ? 'the problem report was closed as resolved.' : 'the problem report was dismissed.') . ($note !== '' ? ' ' . $note : ''));
        }
        $flash = ['success', 'Dispute ' . $status . '.'];
    } elseif ($action === 'dispute_refund' || $action === 'dispute_release') {
        // Simulated escrow decision: money is held by the platform until the admin decides
        $note      = trim($_POST['note'] ?? '');
        $disputeId = (int) ($_POST['dispute_id'] ?? 0);
        $dispute   = $disputeModel->findById($disputeId);

        if ($dispute === null || $dispute['status'] !== 'open') {
            $flash = ['error', 'That dispute is no longer open.'];
        } else {
            $orderId = (int) $dispute['order_id'];
            if ($action === 'dispute_refund') {
                $done  = $orderModel->refundPayment($orderId);
                $label = 'Payment refunded to the buyer (simulated).';
            } else {
                $done = $orderModel->releasePayment($orderId);
                if ($done) {
                    $listingModel->setStatus((int) $dispute['listing_id'], 'sold');
                }
                $label = 'Payment released to the seller (simulated).';
            }

            if ($done) {
                $orderModel->addEvent($orderId, 'Administrator decision: ' . $label);
                $disputeModel->resolve($disputeId, 'resolved', $label . ($note !== '' ? ' ' . $note : ''));
                $notifyParties($orderId, $label . ($note !== '' ? ' ' . $note : ''));
                $flash = ['success', $label];
            } else {
                $flash = ['error', 'No payment is being held for this order.'];
            }
        }
    } elseif ($action === 'restore_listing') {
        $flash = (new Automation())->restoreListing((int) ($_POST['listing_id'] ?? 0))
            ? ['success', 'Listing restored. The automatic rules will not hold it again.']
            : ['error', 'That listing is not on hold.'];
    } elseif ($action === 'remove_listing') {
        $listingModel->delete((int) ($_POST['listing_id'] ?? 0));
        $flash = ['success', 'Listing removed.'];
    }

    $_SESSION['flash'] = $flash;
    header('Location: admin.php?tab=' . $tab);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pending      = $user->getPendingVerifications();
$openReports  = $reportModel->listByStatus('open');
$openDisputes = $disputeModel->listByStatus('open');
$heldListings = [];
try { $heldListings = $listingModel->findHeld(); } catch (Throwable $e) { $heldListings = []; }
$counts = [
    'verifications' => count($pending),
    'reports'       => count($openReports),
    'disputes'      => count($openDisputes),
    'held'          => count($heldListings),
];
$stats       = ($tab === 'overview') ? $adminModel->stats() : [];
$charts      = ($tab === 'overview') ? $adminModel->chartData() : [];
$allListings = ($tab === 'listings') ? $listingModel->search() : [];
$allUsers    = ($tab === 'users') ? $adminModel->allUsers() : [];

// AI scam-risk scores for the listings shown on the Listings and Reports tabs
$riskIds = [];
foreach ($allListings as $l) { $riskIds[] = (int) $l['id']; }
if ($tab === 'held') { foreach ($heldListings as $l) { $riskIds[] = (int) $l['id']; } }
if ($tab === 'reports') { foreach ($openReports as $r) { if (!empty($r['listing_id'])) { $riskIds[] = (int) $r['listing_id']; } } }
$risk = [];
if ($riskIds) { try { $risk = (new AiInsights())->riskFor($riskIds); } catch (Throwable $e) { $risk = []; } }
$riskPill = function (?array $r): string {
    if (!$r) { return '<span class="muted">n/a</span>'; }
    $tip = [];
    foreach ($r['factors'] as $f) { if ($f['points'] > 0) { $tip[] = $f['label']; } }
    return '<span class="risk risk-' . $r['level'] . '" title="' . htmlspecialchars($tip ? implode('; ', $tip) : 'No risk signals') . '">'
         . (int) $r['score'] . ' ' . $r['level'] . '</span>';
};
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="admin-body">
    <?php include __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-topbar">
            <h1><?= htmlspecialchars($titles[$tab]) ?></h1>
            <span class="muted"><?= date('l j F Y') ?></span>
        </div>

        <div class="admin-content">
            <?php if ($flash): ?>
                <div class="flash flash-<?= htmlspecialchars($flash[0]) ?>"><?= htmlspecialchars($flash[1]) ?></div>
            <?php endif; ?>

            <?php if ($tab === 'overview'): ?>
                <?= chartStyles() ?>
                <div class="ch-grid">
                    <div class="ch-card">
                        <h3>Students</h3>
                        <p class="ch-sub">Who is verified. Click a slice to see the students.</p>
                        <?= chartDoughnut('chStudents', $charts['students'], ['#2e9e6b', '#f0b400', '#b9b6dc'], ['Verified' => ['students', 'verified'], 'Waiting for review' => ['students', 'pending'], 'Not verified yet' => ['students', 'none']]) ?>
                    </div>
                    <div class="ch-card">
                        <h3>Requests by status</h3>
                        <p class="ch-sub">All buy and trade requests so far. Click a bar to see them.</p>
                        <?= chartHBars($charts['statuses'], '#23215b', 'orders') ?>
                    </div>
                    <div class="ch-card">
                        <h3>Listings by category</h3>
                        <p class="ch-sub">Click a bar to see the listings.</p>
                        <?= chartHBars($charts['categories'], '#f0b400', 'listings') ?>
                    </div>
                    <div class="ch-card">
                        <h3>New requests, last 14 days</h3>
                        <p class="ch-sub">Requests made each day (day of the month below). Click a column to see them.</p>
                        <?= chartColumns($charts['per_day'], '#34318f', 'orders_day') ?>
                    </div>
                </div>
                <?= chartScript() ?>

                <div class="panel">
                    <h3 style="margin-top:0;">Needs your attention</h3>
                    <?php if ($counts['verifications'] + $counts['reports'] + $counts['disputes'] + $counts['held'] === 0): ?>
                        <p class="muted" style="margin-bottom:0;">All clear. Nothing is waiting for review.</p>
                    <?php endif; ?>

                    <?php if ($counts['verifications'] > 0): ?>
                        <a class="attention-item" href="admin.php?tab=verifications">
                            <span>Student IDs waiting for review</span>
                            <span class="badge"><?= $counts['verifications'] ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($counts['reports'] > 0): ?>
                        <a class="attention-item" href="admin.php?tab=reports">
                            <span>Open reports on listings</span>
                            <span class="badge"><?= $counts['reports'] ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($counts['held'] > 0): ?>
                        <a class="attention-item" href="admin.php?tab=held">
                            <span>Listings held automatically for review</span>
                            <span class="badge"><?= $counts['held'] ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($counts['disputes'] > 0): ?>
                        <a class="attention-item" href="admin.php?tab=disputes">
                            <span>Open transaction disputes</span>
                            <span class="badge"><?= $counts['disputes'] ?></span>
                        </a>
                    <?php endif; ?>
                </div>

            <?php elseif ($tab === 'verifications'): ?>
                <?php if (empty($pending)): ?>
                    <p class="muted">No student IDs waiting for review.</p>
                <?php else: ?>
                    <div class="panel">
                        <?php foreach ($pending as $applicant): ?>
                            <div class="review-card">
                                <div style="display:flex; gap:0.8rem; flex-wrap:wrap;">
                                    <div style="text-align:center;">
                                        <a href="student_id_image.php?user=<?= (int) $applicant['id'] ?>" target="_blank">
                                            <img src="student_id_image.php?user=<?= (int) $applicant['id'] ?>" alt="Student ID" class="review-photo" style="width:150px;height:150px;">
                                        </a>
                                        <div class="muted" style="font-size:0.8rem;">Student ID</div>
                                    </div>
                                    <div style="text-align:center;">
                                        <?php if (!empty($applicant['profile_photo'])): ?>
                                            <a href="profile_photo.php?user=<?= (int) $applicant['id'] ?>" target="_blank">
                                                <img src="profile_photo.php?user=<?= (int) $applicant['id'] ?>" alt="Face photo" class="review-photo" style="width:150px;height:150px;">
                                            </a>
                                        <?php else: ?>
                                            <div class="review-photo" style="width:150px;height:150px;display:flex;align-items:center;justify-content:center;background:#fdecea;color:#611a15;font-size:0.8rem;padding:0.5rem;">No face photo. Reject so the student uploads both.</div>
                                        <?php endif; ?>
                                        <div class="muted" style="font-size:0.8rem;">Face photo</div>
                                    </div>
                                </div>
                                <div class="review-info">
                                    <strong><?= htmlspecialchars($applicant['full_name']) ?></strong>
                                    <span><?= htmlspecialchars($applicant['email']) ?></span>
                                    <?php if (User::idIsExpired($applicant)): ?>
                                        <span style="color:#a32d22;font-weight:600;">ID EXPIRED on <?= date('j M Y', strtotime($applicant['student_id_expiry'])) ?></span>
                                    <?php elseif (!empty($applicant['student_id_expiry'])): ?>
                                        <span style="color:#1b7a3d;">ID valid until <?= date('j M Y', strtotime($applicant['student_id_expiry'])) ?></span>
                                    <?php else: ?>
                                        <span class="muted">No expiry date given</span>
                                    <?php endif; ?>
                                    <span class="muted">Waiting since <?= date('j M Y', strtotime($applicant['created_at'])) ?></span>
                                </div>
                                <form method="POST" class="review-actions">
                                    <input type="hidden" name="user_id" value="<?= (int) $applicant['id'] ?>">
                                    <button type="submit" name="action" value="approve" class="btn-approve"<?= empty($applicant['profile_photo']) ? ' disabled title="A face photo is needed"' : (User::idIsExpired($applicant) ? ' disabled title="The ID has expired"' : '') ?>>Approve</button>
                                    <select name="reason" aria-label="Reason for rejecting">
                                        <option value="">Reason (optional)</option>
                                        <option value="mismatch">Photos do not match</option>
                                        <option value="unclear">Photo is unclear</option>
                                        <option value="expired"<?= User::idIsExpired($applicant) ? ' selected' : '' ?>>ID has expired</option>
                                        <option value="notid">Not a student ID</option>
                                    </select>
                                    <button type="submit" name="action" value="reject" class="btn-reject">Reject</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($tab === 'reports'): ?>
                <?php if (empty($openReports)): ?>
                    <p class="muted">No open reports.</p>
                <?php else: ?>
                    <table class="data-table">
                        <tr>
                            <th>Reported by</th>
                            <th>Listing</th>
                            <th>Seller</th>
                            <th>Reason</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($openReports as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['reporter_name']) ?></td>
                                <td>
                                    <?php if (!empty($r['listing_id'])): ?>
                                        <a href="view_listing.php?id=<?= (int) $r['listing_id'] ?>"><?= htmlspecialchars($r['listing_title']) ?></a>
                                    <?php else: ?>
                                        <span class="muted">Listing no longer exists</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['reported_name'] ?? 'Unknown') ?></td>
                                <td><?= nl2br(htmlspecialchars($r['reason'])) ?><?php if (!empty($r['listing_id'])): ?><br>AI risk: <?= $riskPill($risk[(int) $r['listing_id']] ?? null) ?><?php endif; ?></td>
                                <td><?= date('j M Y', strtotime($r['created_at'])) ?></td>
                                <td>
                                    <?php if (!empty($r['listing_id'])): ?>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Remove this listing permanently?');">
                                            <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
                                            <input type="hidden" name="listing_id" value="<?= (int) $r['listing_id'] ?>">
                                            <input type="hidden" name="action" value="report_remove_listing">
                                            <button type="submit" class="btn-small btn-danger">Remove listing</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="action" value="report_resolve">
                                        <button type="submit" class="btn-small">Mark resolved</button>
                                    </form>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
                                        <input type="hidden" name="action" value="report_dismiss">
                                        <button type="submit" class="btn-small btn-ghost">Dismiss</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

            <?php elseif ($tab === 'disputes'): ?>
                <?php if (empty($openDisputes)): ?>
                    <p class="muted">No open disputes.</p>
                <?php else: ?>
                    <?php foreach ($openDisputes as $d): ?>
                        <div class="panel">
                            <p style="margin-top:0;">
                                <strong><?= htmlspecialchars($d['listing_title']) ?></strong>
                                <span class="tag tag-muted"><?= htmlspecialchars($d['type']) ?></span>
                                <span class="pill pill-<?= htmlspecialchars($d['order_status']) ?>">order <?= htmlspecialchars($d['order_status']) ?></span>
                            </p>
                            <p class="muted">
                                Buyer: <?= htmlspecialchars($d['buyer_name']) ?> ·
                                Seller: <?= htmlspecialchars($d['seller_name']) ?> ·
                                Raised by <?= htmlspecialchars($d['raised_by_name']) ?> on <?= date('j M Y', strtotime($d['created_at'])) ?>
                            </p>
                            <p><?= nl2br(htmlspecialchars($d['description'])) ?></p>
                            <?php if (!empty($d['evidence_photo'])): ?>
                                <p><a href="dispute_image.php?id=<?= (int) $d['id'] ?>" target="_blank"><img src="dispute_image.php?id=<?= (int) $d['id'] ?>" alt="Evidence photo" style="max-width:260px;max-height:200px;border-radius:6px;border:1px solid #ddd;"></a><br><span class="muted">Evidence photo from the reporter (click to enlarge)</span></p>
                            <?php endif; ?>
                            <?php if (!empty($d['seller_reply'])): ?>
                                <p><strong>Seller's reply:</strong> <?= nl2br(htmlspecialchars($d['seller_reply'])) ?></p>
                            <?php elseif (!empty($d['seller_deadline'])): ?>
                                <p class="muted">Seller must reply by <?= date('j M Y H:i', strtotime($d['seller_deadline'])) ?>. If not, the buyer is refunded automatically.</p>
                            <?php endif; ?>

                            <?php if ($d['type'] === 'buy'): ?>
                                <p class="muted">
                                    Payment (simulated):
                                    <span class="pill pill-<?= htmlspecialchars($d['payment_status']) ?>"><?= htmlspecialchars($d['payment_status']) ?></span>
                                    <?php if ($d['payment_amount'] !== null): ?>
                                        <?= number_format((float) $d['payment_amount'], 2) ?> DKK via <?= htmlspecialchars($d['payment_method']) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($d['type'] === 'buy' && $d['payment_status'] === 'released'): ?>
                                <p class="muted"><strong>The payment was already released</strong> when the buyer confirmed receipt, so refund and release are not available. You can still resolve or dismiss the report and leave a note.</p>
                            <?php endif; ?>
                            <form method="POST" class="form-stack">
                                <input type="hidden" name="dispute_id" value="<?= (int) $d['id'] ?>">
                                <textarea name="note" rows="2" placeholder="Resolution note (what was decided and why)"></textarea>
                                <div>
                                    <?php if ($d['payment_status'] === 'held'): ?>
                                        <button type="submit" name="action" value="dispute_refund" class="btn-small btn-danger">Refund buyer</button>
                                        <button type="submit" name="action" value="dispute_release" class="btn-small">Release to seller</button>
                                    <?php endif; ?>
                                    <button type="submit" name="action" value="dispute_resolve" class="btn-small btn-ghost">Resolve (no payment change)</button>
                                    <button type="submit" name="action" value="dispute_dismiss" class="btn-small btn-ghost">Dismiss</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            <?php elseif ($tab === 'listings'): ?>
                <?php if (empty($allListings)): ?>
                    <p class="muted">No active listings.</p>
                <?php else: ?>
                    <table class="data-table">
                        <tr>
                            <th>Title</th>
                            <th>Seller</th>
                            <th>Price</th>
                            <th>Category</th>
                            <th>Posted</th>
                            <th>AI risk</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($allListings as $l): ?>
                            <tr>
                                <td><a href="view_listing.php?id=<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['title']) ?></a></td>
                                <td><?= htmlspecialchars($l['seller_name']) ?></td>
                                <td><?= number_format((float) $l['price'], 2) ?> DKK</td>
                                <td><?= htmlspecialchars($l['category']) ?></td>
                                <td><?= date('j M Y', strtotime($l['created_at'])) ?></td>
                                <td><?= $riskPill($risk[(int) $l['id']] ?? null) ?></td>
                                <td>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Remove this listing permanently?');">
                                        <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="action" value="remove_listing">
                                        <button type="submit" class="btn-small btn-danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

            <?php elseif ($tab === 'held'): ?>
                <p class="muted">These listings were hidden automatically (AI risk score of <?= Automation::RISK_HOLD_SCORE ?> or more, or reported by <?= Automation::REPORT_HOLD_COUNT ?> different students). Restoring a listing marks it as checked, so the rules do not hold it again.</p>
                <?php if (empty($heldListings)): ?>
                    <p class="muted">No listings are on hold.</p>
                <?php else: ?>
                    <table class="data-table">
                        <tr>
                            <th>Title</th>
                            <th>Seller</th>
                            <th>Price</th>
                            <th>Why it was held</th>
                            <th>AI risk</th>
                            <th>Held</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($heldListings as $l): ?>
                            <tr>
                                <td><a href="view_listing.php?id=<?= (int) $l['id'] ?>"><?= htmlspecialchars($l['title']) ?></a></td>
                                <td><?= htmlspecialchars($l['seller_name']) ?></td>
                                <td><?= number_format((float) $l['price'], 2) ?> DKK</td>
                                <td><?= htmlspecialchars($l['hold_reason'] ?? '') ?></td>
                                <td><?= $riskPill($risk[(int) $l['id']] ?? null) ?></td>
                                <td><?= !empty($l['held_at']) ? date('j M H:i', strtotime($l['held_at'])) : '' ?></td>
                                <td>
                                    <form method="POST" class="inline-form">
                                        <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="action" value="restore_listing">
                                        <button type="submit" class="btn-small">Restore</button>
                                    </form>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Remove this listing permanently?');">
                                        <input type="hidden" name="listing_id" value="<?= (int) $l['id'] ?>">
                                        <input type="hidden" name="action" value="remove_listing">
                                        <button type="submit" class="btn-small btn-danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

            <?php else: ?>
                <table class="data-table">
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Verification</th>
                        <th>Listings</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                    <?php foreach ($allUsers as $u): ?>
                        <?php
                        if (!empty($u['student_verified'])) {
                            $v = ['resolved', 'Verified'];
                        } elseif (!empty($u['student_id_photo'])) {
                            $v = ['open', 'Pending review'];
                        } else {
                            $v = ['declined', 'Not verified'];
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($u['full_name']) ?><?php if (!empty($u['needs_review'])): ?> <span class="tag tag-sold" title="<?= htmlspecialchars($u['review_reason'] ?? '') ?>">Under review</span><?php endif; ?></td>
                            <td><?= htmlspecialchars($u['email']) ?></td>
                            <td><?= !empty($u['is_admin']) ? '<span class="tag tag-you">Admin</span>' : 'Student' ?></td>
                            <td><span class="pill pill-<?= $v[0] ?>"><?= $v[1] ?></span></td>
                            <td><?= (int) $u['listing_count'] ?></td>
                            <td><?= date('j M Y', strtotime($u['created_at'])) ?></td>
                            <td>
                                <?php if (!empty($u['student_verified']) && empty($u['is_admin']) && (int) $u['id'] !== (int) $currentUser['id']): ?>
                                    <form method="POST" class="inline-form" onsubmit="return confirm('Remove this student\'s verification? They will have to upload a new ID.');">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <button type="submit" class="btn-small btn-danger">Remove verification</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
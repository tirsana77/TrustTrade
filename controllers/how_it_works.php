<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Automation.php';

// Guests can read this page. $currentUser stays null for them.
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = (new User())->findById((int) $_SESSION['user_id']);
    if (!$currentUser) {
        unset($_SESSION['user_id'], $_SESSION['user_name']);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>How it works - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <h1>How TrustTrade keeps you safe</h1>
        <p class="muted">Payments in this prototype are simulated. The rules below are what the system does automatically. An administrator can always step in.</p>

        <ol class="how-steps">
            <li><strong>Verified students only</strong><br>Everyone uploads a student ID photo that an administrator approves before they can buy, sell or message.</li>
            <li><strong>Request and agree</strong><br>You ask to buy. The seller accepts. Nothing is paid until then.</li>
            <li><strong>Pay into escrow</strong><br>Your money is held by TrustTrade. The seller is not paid yet.</li>
            <li><strong>Confirm receipt</strong><br>When you have the item, you confirm and the money is released. If something is wrong, you report a problem instead.</li>
        </ol>

        <h2 class="section-title">What happens automatically</h2>
        <table class="how-table">
            <tr><th>Situation</th><th>What the system does</th></tr>
            <tr><td>Seller accepted but you have not paid</td><td>Reminder after <?= Automation::REMINDER_HOURS ?> hours. The request is cancelled after <?= Automation::CANCEL_DAYS ?> days.</td></tr>
            <tr><td>You paid and received the item but did nothing</td><td>The payment is released to the seller <?= Automation::RELEASE_DAYS ?> days after you paid, if no problem was reported.</td></tr>
            <tr><td>You report a problem on a paid order</td><td>The payment is frozen at once and both people are notified. The seller has <?= Automation::DISPUTE_REPLY_DAYS ?> days to reply. If the seller does not reply, you are refunded automatically. An administrator can decide at any time.</td></tr>
            <tr><td>A listing gets a high AI risk score (<?= Automation::RISK_HOLD_SCORE ?> or more out of 100)</td><td>It is hidden and put under review. The seller is told why. An administrator checks it and can restore it.</td></tr>
            <tr><td>A listing is reported by <?= Automation::REPORT_HOLD_COUNT ?> different students</td><td>It is hidden and put under review in the same way.</td></tr>
            <tr><td>A seller has <?= Automation::REVIEW_REFUNDS ?> orders refunded after disputes</td><td>The seller account is flagged for administrator review.</td></tr>
            <tr><td>A seller completes <?= Automation::TRUSTED_SALES ?> sales with no refunds</td><td>The seller gets the "Trusted seller" badge automatically.</td></tr>
        </table>
        <p class="muted">You get a notification (the bell in the menu) every time an automatic action affects you, and each order has a History that marks automatic steps.</p>

        <h2 class="section-title">What the AI does</h2>
        <p>When a seller lists an item, the AI suggests a price range from similar listings and warns about prices far below normal. On every listing, buyers see a price check. The AI scam-risk score is only used to hold a listing for a person to check. It never deletes anything and it never decides a dispute.</p>

        <h2 class="section-title">Questions</h2>
        <div class="faq">
            <details><summary>What if the seller never sends the item?</summary><p>Report a problem on the order before you confirm. The payment is frozen. If the seller does not reply within <?= Automation::DISPUTE_REPLY_DAYS ?> days you are refunded automatically, otherwise an administrator decides.</p></details>
            <details><summary>Who sees my student ID?</summary><p>Only administrators. The photo is stored outside the public part of the site and is never shown to other students.</p></details>
            <details><summary>Why was my listing hidden?</summary><p>The AI flagged it, or several students reported it. Your notification says why. An administrator will check it and can put it back.</p></details>
            <details><summary>Is real money used?</summary><p>No. Payments are simulated. No card details are collected.</p></details>
        </div>
    </div>
</body>
</html>
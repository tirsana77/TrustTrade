<?php
// Stripe sends the buyer back here after paying. The payment is checked with Stripe before anything is held.
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../includes/stripe.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$me           = (int) $_SESSION['user_id'];
$orderModel   = new Order();
$listingModel = new Listing();
$notify       = new Notification();

$orderId   = (int) ($_GET['order_id'] ?? 0);
$sessionId = (string) ($_GET['session_id'] ?? '');
$order     = $orderModel->findById($orderId);
$flash     = ['error', 'The payment could not be confirmed. You have not been charged in TrustTrade.'];

if ($order !== null && (int) $order['buyer_id'] === $me && $sessionId !== '' && StripeClient::enabled()) {
    $listing = $listingModel->findById((int) $order['listing_id']);
    $s       = StripeClient::retrieve($sessionId);
    $meta    = $s['metadata'] ?? [];

    $valid = $listing !== null
        && ($s['payment_status'] ?? '') === 'paid'
        && ($s['currency'] ?? '') === 'dkk'
        && (int) ($s['amount_total'] ?? -1) === (int) round((float) $listing['price'] * 100)
        && (int) ($meta['order_id'] ?? 0) === $orderId
        && (int) ($meta['buyer_id'] ?? 0) === $me;

    if ($valid) {
        $ref = 'STRIPE-' . strtoupper(substr($sessionId, -10));
        if ($orderModel->pay($orderId, $me, 'card', $ref)) {
            $orderModel->addEvent($orderId, 'Buyer paid ' . number_format((float) $listing['price'], 2) . ' DKK through Stripe (test mode). The money is held in escrow');
            $notify->add((int) $order['seller_id'], 'The buyer has paid for "' . $order['listing_title'] . '". The money is held by TrustTrade: hand over the item.', 'orders.php');
            $flash = ['success', 'Payment received through Stripe (test mode) and held by TrustTrade. It is released to the seller when you confirm that you received the item.'];
        } else {
            $flash = ['error', 'This order was already paid or can no longer be paid.'];
        }
    }
}

$_SESSION['flash'] = $flash;
header('Location: ' . ($orderId > 0 ? 'order.php?id=' . $orderId : 'orders.php'));
exit;
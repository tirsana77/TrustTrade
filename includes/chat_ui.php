<?php
/**
 * Helpers for the chat pages (messages.php, conversation.php) and the live chat (chat_api.php).
 */
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Order.php';
require_once __DIR__ . '/delivery_ui.php';

function chatInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '?', 0, 1);
    $b = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($a . $b);
}

function chatAvatar(string $name, string $extra = ''): string
{
    return '<span class="avatar ' . $extra . '" aria-hidden="true">' . deliveryH(chatInitials($name)) . '</span>';
}

/** "14:05" for today, "5 Nov" for older messages. */
function chatShortTime(string $ts): string
{
    $t = strtotime($ts);
    return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : date('j M', $t);
}

/**
 * Checks that the logged-in person may open this conversation and returns everything the page needs.
 * One side of the conversation must be the seller of the listing. Answers with an error page, or with JSON when $json is true.
 */
function chatContext(bool $json): array
{
    $fail = function (int $code, string $msg) use ($json) {
        http_response_code($code);
        if ($json) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => $msg]);
        } else {
            $errorMessage = $msg;
            include __DIR__ . '/error_page.php';
        }
        exit;
    };

    if (empty($_SESSION['user_id'])) {
        if ($json) {
            $fail(401, 'Please log in again.');
        }
        header('Location: login.php');
        exit;
    }

    $users       = new User();
    $currentUser = $users->findById((int) $_SESSION['user_id']);
    if (!$currentUser) {
        $fail(401, 'Please log in again.');
    }
    $me        = (int) $currentUser['id'];
    $listingId = (int) ($_POST['listing'] ?? $_GET['listing'] ?? 0);
    $withId    = (int) ($_POST['with'] ?? $_GET['with'] ?? 0);
    $listing   = (new Listing())->findById($listingId);
    $other     = $users->findById($withId);

    if ($listing === null || $other === null || $withId === $me
        || ((int) $listing['user_id'] !== $me && (int) $listing['user_id'] !== $withId)) {
        $fail(404, 'Conversation not found.');
    }
    return [$currentUser, $me, $listing, $other, $listingId, $withId];
}

/** One message as the browser needs it. */
function chatMessageData(array $m, int $me): array
{
    return [
        'id'     => (int) $m['id'],
        'mine'   => ((int) $m['sender_id'] === $me),
        'system' => (($m['kind'] ?? 'text') === 'system'),
        'body'   => (string) $m['body'],
        'ts'     => (string) $m['created_at'],
    ];
}

/** Short text that changes whenever the order or its delivery plan changes (the live chat compares it). */
function chatOrderSignature(?array $order): string
{
    if ($order === null) {
        return 'none';
    }
    return md5(implode('|', [
        $order['id'], $order['status'], $order['payment_status'] ?? '', $order['delivery_status'] ?? '', $order['delivery_by'] ?? '',
        $order['delivery_method'] ?? '', $order['delivery_place'] ?? '', $order['delivery_time'] ?? '', $order['delivery_note'] ?? '', $order['tracking_ref'] ?? '',
    ]));
}

/** The order panel above the messages: where the order stands and the delivery step. */
function chatOrderCard(?array $order, int $me, string $otherName, int $listingId, int $withId): string
{
    if ($order === null) {
        return '<div class="chat-order-empty">No request for this item yet. <a href="view_listing.php?id=' . $listingId . '">Open the listing</a> to request it.</div>';
    }
    $first  = explode(' ', trim($otherName))[0];
    $back   = 'conversation.php?listing=' . $listingId . '&with=' . $withId;
    $isBuy  = ($order['type'] === 'buy');
    $pay    = $order['payment_status'] ?? 'unpaid';
    $amount = $order['payment_amount'] !== null ? (float) $order['payment_amount'] : (float) $order['listing_price'];
    $h  = '<div class="chat-order-head"><span class="pill pill-' . deliveryH($order['status']) . '">' . deliveryH($order['status']) . '</span> '
        . '<span class="tag tag-muted">' . deliveryH($order['type']) . '</span> ';
    if ($isBuy) {
        $h .= '<span class="muted">' . number_format($amount, 2) . ' DKK'
            . ($order['status'] !== 'pending' && $order['status'] !== 'declined' ? ' · payment ' . deliveryH($pay) : '') . '</span> ';
    }
    $h .= '<a class="chat-order-link" href="orders.php">Open in Orders</a></div>';
    return $h . deliveryBox($order, $me, $first, $back);
}

/** The list of conversations on the left (and the whole page on a phone before a chat is opened). */
function chatSidebar(array $conversations, int $me, int $activeListing = 0, int $activeWith = 0): string
{
    $h = '<div class="chat-side-head">Chats</div><div class="chat-list">';
    if (empty($conversations)) {
        return $h . '<p class="muted chat-list-empty">No conversations yet. Open a listing and use "Message the seller" to start one.</p></div>';
    }
    foreach ($conversations as $c) {
        $unread  = (int) ($c['unread_count'] ?? 0);
        $system  = (($c['kind'] ?? 'text') === 'system');
        $preview = $system ? (string) $c['body'] : (((int) $c['sender_id'] === $me ? 'You: ' : '') . $c['body']);
        $preview = preg_replace('/\s+/', ' ', $preview);
        if (mb_strlen($preview) > 60) {
            $preview = mb_substr($preview, 0, 60) . '…';
        }
        $active = ((int) $c['listing_id'] === $activeListing && (int) $c['other_id'] === $activeWith);
        $h .= '<a class="chat-row' . ($active ? ' active' : '') . ($unread > 0 ? ' unread' : '') . '" href="conversation.php?listing=' . (int) $c['listing_id'] . '&amp;with=' . (int) $c['other_id'] . '">'
            . chatAvatar((string) $c['other_name'])
            . '<span class="chat-row-text"><span class="chat-row-top"><strong>' . deliveryH((string) $c['other_name']) . '</strong>'
            . '<span class="chat-row-time">' . deliveryH(chatShortTime((string) $c['created_at'])) . '</span></span>'
            . '<span class="chat-row-item">' . deliveryH((string) $c['listing_title']) . '</span>'
            . '<span class="chat-row-preview">' . deliveryH($preview) . '</span></span>'
            . ($unread > 0 ? '<span class="chat-badge">' . $unread . '</span>' : '')
            . '</a>';
    }
    return $h . '</div>';
}
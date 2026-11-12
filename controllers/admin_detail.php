<?php
/**
 * The admin charts ask this page for the details behind a slice or a bar. It answers with JSON only, and only for administrators.
 *   GET admin_detail.php?type=students&key=verified|pending|none
 *   GET admin_detail.php?type=orders&key=pending|accepted|completed|declined|cancelled
 *   GET admin_detail.php?type=orders_day&key=YYYY-MM-DD
 *   GET admin_detail.php?type=listings&key=<category>
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$me = empty($_SESSION['user_id']) ? null : (new User())->findById((int) $_SESSION['user_id']);
if ($me === null || empty($me['is_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Administrators only.']);
    exit;
}

$db   = (new Database())->getConnection();
$type = $_GET['type'] ?? '';
$key  = (string) ($_GET['key'] ?? '');
$date = fn($t) => $t ? date('j M Y', strtotime($t)) : '';
$out  = null;

if ($type === 'students') {
    $where = [
        'verified' => 'student_verified = 1',
        'pending'  => "student_verified = 0 AND student_id_photo IS NOT NULL AND student_id_photo != ''",
        'none'     => "student_verified = 0 AND (student_id_photo IS NULL OR student_id_photo = '')",
    ];
    $names = ['verified' => 'Verified students', 'pending' => 'Students waiting for review', 'none' => 'Students not verified yet'];
    if (isset($where[$key])) {
        $rows = $db->query('SELECT full_name, email, created_at FROM users WHERE is_admin = 0 AND ' . $where[$key] . ' ORDER BY created_at DESC LIMIT 200')->fetchAll();
        $out = [
            'title'   => $names[$key],
            'columns' => ['Name', 'Email', 'Joined'],
            'rows'    => array_map(fn($r) => [$r['full_name'], $r['email'], $date($r['created_at'])], $rows),
            'link'    => ['admin.php?tab=' . ($key === 'pending' ? 'verifications' : 'users'), $key === 'pending' ? 'Open Verifications' : 'Open Users'],
        ];
    }
} elseif ($type === 'orders' || $type === 'orders_day') {
    $sql = 'SELECT l.title, b.full_name AS buyer, s.full_name AS seller, o.type, o.status, o.payment_status,
                   COALESCE(o.payment_amount, l.price) AS amount, o.created_at
            FROM orders o
            JOIN listings l ON l.id = o.listing_id
            JOIN users b ON b.id = o.buyer_id
            JOIN users s ON s.id = o.seller_id WHERE ';
    $params = [];
    $title  = '';
    if ($type === 'orders' && in_array($key, ['pending', 'accepted', 'completed', 'declined', 'cancelled'], true)) {
        $sql .= 'o.status = :k';
        $params = ['k' => $key];
        $title = 'Requests: ' . $key;
    } elseif ($type === 'orders_day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
        $sql .= 'DATE(o.created_at) = :k';
        $params = ['k' => $key];
        $title = 'Requests on ' . date('j M Y', strtotime($key));
    }
    if ($title !== '') {
        $stmt = $db->prepare($sql . ' ORDER BY o.created_at DESC LIMIT 200');
        $stmt->execute($params);
        $out = [
            'title'   => $title,
            'columns' => ['Item', 'Buyer', 'Seller', 'Type', 'Status', 'Amount', 'Date'],
            'rows'    => array_map(fn($r) => [$r['title'], $r['buyer'], $r['seller'], $r['type'],
                                              $r['status'] . ($r['type'] === 'buy' && $r['payment_status'] !== 'unpaid' ? ' / ' . $r['payment_status'] : ''),
                                              number_format((float) $r['amount'], 2) . ' DKK', $date($r['created_at'])], $stmt->fetchAll()),
            'link'    => null,
        ];
    }
} elseif ($type === 'listings' && $key !== '') {
    $stmt = $db->prepare(
        "SELECT l.title, u.full_name AS seller, l.price, l.status, l.created_at
         FROM listings l JOIN users u ON u.id = l.user_id
         WHERE COALESCE(NULLIF(l.category, ''), 'Other') = :k ORDER BY l.created_at DESC LIMIT 200"
    );
    $stmt->execute(['k' => $key]);
    $out = [
        'title'   => 'Listings in ' . $key,
        'columns' => ['Item', 'Seller', 'Price', 'Status', 'Listed'],
        'rows'    => array_map(fn($r) => [$r['title'], $r['seller'], number_format((float) $r['price'], 2) . ' DKK', $r['status'], $date($r['created_at'])], $stmt->fetchAll()),
        'link'    => ['admin.php?tab=listings', 'Open Listings'],
    ];
}

if ($out === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Nothing found.']);
    exit;
}
echo json_encode($out);
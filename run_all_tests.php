<?php
// ONE page that runs every automated test and shows one result table. Log in as an administrator, then open:
//   http://localhost/TrustTrade/run_all_tests.php
// Part 1 runs the four test scripts (test.php, test_payment.php, test_ai.php, test_automation.php).
// Part 2 is an end-to-end test: it logs in as temporary students through the real login page and clicks
//        through the real pages with HTTP requests (buy, accept, pay, report a problem, refund, held listing),
//        which replaces most of the manual test cases. It also tries a script injection, an SQL injection
//        and paying for someone else's order. All temporary accounts are removed at the end.
 
session_start();
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';
require_once __DIR__ . '/models/Order.php';
require_once __DIR__ . '/models/Dispute.php';
require_once __DIR__ . '/models/Automation.php';
 
$me = !empty($_SESSION['user_id']) ? (new User())->findById((int) $_SESSION['user_id']) : null;
if (!$me || empty($me['is_admin'])) {
    http_response_code(403);
    exit('Administrators only. Log in as an administrator and open this page again.');
}
$cookie = session_name() . '=' . session_id();
session_write_close();   // lets the pages we call use this login at the same time
 
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = preg_match('/^[A-Za-z0-9.\-:]+$/', $_GET['host'] ?? '') ? $_GET['host'] : $_SERVER['HTTP_HOST'];   // e.g. ?host=127.0.0.1
$root   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$app    = $root . '/controllers/';
 
$suites  = [];   // [name, passed, total, note]
$e2e     = [];   // [name, ok]
$crash   = null;
 
/**
 * One HTTP request. $jar holds one person's cookies (name => value), so each person keeps their own login.
 * Cookies and redirects are handled here, step by step, so the result does not depend on cURL settings.
 */
function http(string $method, string $url, array &$jar, array $data = [], ?string $cookieHeader = null): array
{
    $code = 0;
    $body = '';
    for ($hop = 0; $hop < 6; $hop++) {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'TrustTrade-tests',
            CURLOPT_HTTPHEADER => ['X-TT-Sim: ' . ($GLOBALS['simToken'] ?? '')],
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
                $headers[] = $line;
                return strlen($line);
            },
        ]);
        $pairs = [];
        foreach ($jar as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        if ($cookieHeader) {
            $pairs[] = $cookieHeader;
        }
        if ($pairs) {
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $pairs));
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $files = array_filter($data, fn($v) => is_array($v) && isset($v['content']));
            if ($files) {
                // multipart body built by hand (no CURLFile), so it works the same on every setup
                $boundary = '----TTBOUNDARY' . bin2hex(random_bytes(8));
                $body_out = '';
                foreach ($data as $k => $v) {
                    if (is_array($v) && isset($v['content'])) {
                        $body_out .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"; filename=\"{$v['name']}\"\r\nContent-Type: {$v['type']}\r\n\r\n" . $v['content'] . "\r\n";
                    } else {
                        $body_out .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$v}\r\n";
                    }
                }
                $body_out .= "--{$boundary}--\r\n";
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-TT-Sim: ' . ($GLOBALS['simToken'] ?? ''), 'Content-Type: multipart/form-data; boundary=' . $boundary, 'Expect:']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body_out);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
        }
        $body = (string) curl_exec($ch);
        if ($body === '' && curl_errno($ch)) { $GLOBALS['curlError'] = curl_error($ch); }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
 
        $location = null;
        foreach ($headers as $h) {
            if (stripos($h, 'Set-Cookie:') === 0 && preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $h, $m)) {
                if ($m[2] === 'deleted' || $m[2] === '') {
                    unset($jar[$m[1]]);
                } else {
                    $jar[$m[1]] = $m[2];
                }
            }
            if (stripos($h, 'Location:') === 0) {
                $location = trim(substr($h, 9));
            }
        }
        if ($code >= 300 && $code < 400 && $location !== null) {
            if (!preg_match('#^https?://#i', $location)) {
                $parts = parse_url($url);
                $base  = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $location = ($location[0] === '/') ? $base . $location : $base . rtrim(dirname($parts['path']), '/') . '/' . $location;
            }
            $url = $location;
            $method = 'GET';
            $data = [];
            continue;
        }
        break;
    }
    return [$code, $body, $url];
}
 
function text(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES)));
}
 
function ok(string $name, bool $cond): void
{
    global $e2e;
    $e2e[] = [$name, $cond];
}
 
// ---------------- Part 1: the four test scripts ----------------
foreach ([['test.php', 'Models, search, recommender'], ['test_payment.php', 'Escrow payments'], ['test_ai.php', 'AI models (price, category, risk)'], ['test_automation.php', 'Automatic rules']] as [$file, $label]) {
    $tmp = [];
    [$code, $body] = http('GET', $root . '/' . $file, $tmp, [], $cookie);
    if (preg_match('/(\d+) of (\d+) checks passed/', $body, $m)) {
        $suites[] = [$label, (int) $m[1], (int) $m[2], $file];
    } elseif (preg_match('/(\d+) passed, (\d+) failed/', $body, $m)) {
        $suites[] = [$label, (int) $m[1], (int) $m[1] + (int) $m[2], $file];
    } else {
        $suites[] = [$label, 0, 1, $file . ' did not return a result (HTTP ' . $code . ')'];
    }
}
 
// ---------------- Part 2: end-to-end test through the real pages ----------------
// The end-to-end test uses the simulated payment. Its requests carry a secret one-time token (see includes/stripe.php),
// so your Stripe key is never touched and real visitors are not affected.
$simToken     = bin2hex(random_bytes(16));
$simTokenFile = __DIR__ . '/storage/sim_payment.token';
file_put_contents($simTokenFile, $simToken);
register_shutdown_function(function () use ($simTokenFile) { @unlink($simTokenFile); });
 
$db = (new Database())->getConnection();
 
function cleanupE2E(PDO $db): void
{
    $ids = $db->query("SELECT id FROM users WHERE email LIKE 'autoe2e%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_merge($ids, $GLOBALS['extraE2E'] ?? []);   // accounts the deletion test has already renamed
    if (!$ids) {
        return;
    }
    $in  = implode(',', array_map('intval', $ids));
    // delete the evidence photos that the test uploaded
    foreach ($db->query("SELECT d.evidence_photo FROM disputes d JOIN orders o ON o.id = d.order_id WHERE o.buyer_id IN ($in) OR o.seller_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $ev) {
        if ($ev) { @unlink(__DIR__ . '/storage/dispute_evidence/' . basename($ev)); }
    }
    $lst = "SELECT id FROM listings WHERE user_id IN ($in)";
    $ord = "SELECT id FROM orders WHERE buyer_id IN ($in) OR seller_id IN ($in) OR listing_id IN ($lst)";
    foreach ([
        "DELETE FROM order_events WHERE order_id IN ($ord)",
        "DELETE FROM disputes WHERE order_id IN ($ord)",
        "DELETE FROM orders WHERE id IN ($ord)",
        "DELETE FROM reports WHERE listing_id IN ($lst) OR reporter_id IN ($in)",
        "DELETE FROM listing_views WHERE user_id IN ($in) OR listing_id IN ($lst)",
        "DELETE FROM messages WHERE sender_id IN ($in) OR receiver_id IN ($in)",
        "DELETE FROM notifications WHERE user_id IN ($in)",
        "DELETE FROM listings WHERE user_id IN ($in)",
        "DELETE FROM users WHERE id IN ($in)",
    ] as $sql) {
        $db->exec($sql);
    }
}
 
$jars = ['guest' => [], 'seller' => [], 'buyer' => [], 'other' => [], 'novice' => [], 'admin' => [], 'quitter' => []];
$diag = '';
 
cleanupE2E($db);
try {
    $um = new User();
    $lm = new Listing();
    $tag = bin2hex(random_bytes(3));
    $pw = 'password123';
    $mk = function (string $name, string $who, bool $verified, bool $admin = false) use ($um, $db, $tag, $pw): array {
        $email = "autoe2e_{$tag}_{$who}@example.test";
        $id = $um->create($name, $email, $pw);
        $db->exec('UPDATE users SET student_verified = ' . ($verified ? 1 : 0) . ', is_admin = ' . ($admin ? 1 : 0) . ", created_at = '2020-01-01 10:00:00' WHERE id = " . (int) $id);
        return [$id, $email];
    };
    [$sellerId, $sellerMail] = $mk('Sally Seller', 'seller', true);
    [$buyerId,  $buyerMail]  = $mk('Bob Buyer', 'buyer', true);
    [$otherId,  $otherMail]  = $mk('Olga Other', 'other', true);
    [$noviceId, $noviceMail] = $mk('Nina Novice', 'novice', false);
    [$adminId,  $adminMail]  = $mk('Adam Admin', 'admin', true, true);
    $login = function (string $who, string $mail) use (&$jars, &$diag, $app, $pw): void {
        http('GET', $app . 'login.php', $jars[$who]);   // gets the session cookie, like a browser
        [$c, $b, $u] = http('POST', $app . 'login.php', $jars[$who], ['email' => $mail, 'password' => $pw]);
        [$c2, $b2] = http('GET', $app . 'dashboard.php', $jars[$who]);
        if (strpos($b2, 'Log out') === false) {
            $diag .= "{$who}: login did not work (login page answered HTTP {$c}, ended at {$u}" . (strpos($b, 'Incorrect email') !== false ? ', said: Incorrect email or password' : '') . '). ';
        }
    };
    $login('seller', $sellerMail); $login('buyer', $buyerMail); $login('other', $otherMail);
    $login('novice', $noviceMail); $login('admin', $adminMail);
 
    ok('The temporary students could log in through the real login page', $diag === '');
    $title = "Autoe2e {$tag} Calculus textbook";
    $lid = $lm->create($sellerId, $title, 'Clean copy for first year', 150.00, 'Textbooks');
 
    // access control
    [$c, $b] = http('GET', $app . "view_listing.php?id={$lid}", $jars['guest']);
    ok('A guest can open a listing and sees only the seller\'s first name', $c === 200 && strpos($b, 'Sally') !== false && strpos($b, 'Sally Seller') === false);
    [$c, $b, $u] = http('GET', $app . 'orders.php', $jars['guest']);
    ok('A guest is sent to the login page for Orders', strpos($u, 'login.php') !== false);
    [$c] = http('GET', $app . 'admin.php', $jars['buyer']);
    ok('A student cannot open the admin console (403)', $c === 403);
    [$c, $b] = http('POST', $app . "view_listing.php?id={$lid}", $jars['novice'], ['action' => 'buy']);
    ok('An unverified student cannot buy', strpos(text($b), 'Only verified students can buy') !== false);
 
    // buy, accept, pay
    [$c, $b] = http('POST', $app . "view_listing.php?id={$lid}", $jars['buyer'], ['action' => 'buy']);
    ok('A verified student can request to buy', strpos(text($b), 'Request sent') !== false || strpos(text($b), 'Waiting for Sally to accept') !== false);
    $oid = (int) $db->query("SELECT id FROM orders WHERE listing_id = {$lid} AND buyer_id = {$buyerId}")->fetchColumn();
    ok('The order exists and is pending', $oid > 0 && $db->query("SELECT status FROM orders WHERE id = {$oid}")->fetchColumn() === 'pending');
    [$c, $b] = http('GET', $app . 'orders.php', $jars['seller']);
    ok('The seller sees the request with a plain explanation', strpos(text($b), 'Accept or decline this request') !== false);
    http('POST', $app . 'orders.php', $jars['seller'], ['order_id' => $oid, 'action' => 'accept']);
    [$c, $b] = http('GET', $app . 'orders.php', $jars['buyer']);
    ok('After accepting, the buyer is told to pay and when it is cancelled automatically', strpos(text($b), 'Pay to secure the item') !== false && strpos(text($b), 'cancelled automatically') !== false);
 
    // someone else cannot pay for this order
    http('POST', $app . 'orders.php', $jars['other'], ['order_id' => $oid, 'action' => 'pay', 'method' => 'card']);
    ok('A different student cannot pay for someone else\'s order', $db->query("SELECT payment_status FROM orders WHERE id = {$oid}")->fetchColumn() === 'unpaid');
 
    [$c, $b] = http('POST', $app . 'orders.php', $jars['buyer'], ['order_id' => $oid, 'action' => 'pay', 'method' => 'card']);
    ok('The buyer pays and is told the money is held' . (strpos(text($b), 'Payment received (simulated)') !== false ? '' : ' (HTTP ' . $c . ', end of page: ' . substr(text(preg_replace('#<style.*?</style>#si', '', $b)), 0, 400) . ', token file: ' . (is_file($simTokenFile) ? 'exists' : 'MISSING') . ')'), strpos(text($b), 'Payment received (simulated)') !== false);
    ok('The payment is held and the amount comes from the database (150.00)', (float) $db->query("SELECT payment_amount FROM orders WHERE id = {$oid}")->fetchColumn() === 150.0 && $db->query("SELECT payment_status FROM orders WHERE id = {$oid}")->fetchColumn() === 'held');
    [$c, $b] = http('GET', $app . 'orders.php', $jars['buyer']);
    ok('The order page explains escrow and the automatic release date', strpos(text($b), 'safe in escrow') !== false && strpos(text($b), 'released to the seller automatically on') !== false);
 
    // dispute freezes the payment
    // the evidence photo is mandatory
    // a real 1x1 PNG, sent straight from memory (no temporary files)
    $png  = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    $fake = 'this is not an image';
    $disputeCount = fn() => (int) $db->query("SELECT COUNT(*) FROM disputes WHERE order_id = {$oid}")->fetchColumn();
    [$c, $b] = http('POST', $app . 'orders.php', $jars['buyer'], ['order_id' => $oid, 'action' => 'dispute', 'description' => 'The book is missing pages']);
    ok('A problem report without a photo is refused', $disputeCount() === 0 && strpos(text($b), 'photo') !== false);
    [$c, $b] = http('POST', $app . 'orders.php', $jars['buyer'], ['order_id' => $oid, 'action' => 'dispute', 'description' => 'The book is missing pages', 'evidence' => ['content' => $fake, 'type' => 'image/png', 'name' => 'evidence.png']]);
    ok('A file that is not a real image is refused, even when it is named .png', $disputeCount() === 0);
    http('POST', $app . 'orders.php', $jars['seller'], ['order_id' => $oid, 'action' => 'dispute', 'description' => 'Seller tries without photo']);
    ok('A report from the seller without a photo is refused too', $disputeCount() === 0);
    [$c, $b] = http('POST', $app . 'orders.php', $jars['buyer'], ['order_id' => $oid, 'action' => 'dispute', 'description' => 'The book is missing pages', 'evidence' => ['content' => $png, 'type' => 'image/png', 'name' => 'evidence.png']]);
    $d = $db->query("SELECT * FROM disputes WHERE order_id = {$oid}")->fetch();
    preg_match('#<div class="flash[^>]*>(.*?)</div>#si', $b, $fm);
    $why = ($d && !empty($d['seller_deadline'])) ? '' : ' (HTTP ' . $c . ', the page said: ' . ($fm ? text($fm[1]) : 'no message') . '; folder writable: ' . (is_writable(__DIR__ . '/storage/dispute_evidence') ? 'yes' : 'NO') . ')';
    ok('Reporting a problem with a photo creates a dispute with a seller reply deadline' . $why, $d && !empty($d['seller_deadline']));
    $evFile = $d ? __DIR__ . '/storage/dispute_evidence/' . basename((string) $d['evidence_photo']) : '';
    ok('The photo is saved with a random name outside the public folder', $d && !empty($d['evidence_photo']) && is_file($evFile) && strpos($d['evidence_photo'], 'evidence.png') === false);
    [$c, $b] = http('GET', $app . 'dispute_image.php?id=' . (int) $d['id'], $jars['admin']);
    ok('The administrator can open the evidence photo', $c === 200 && substr($b, 1, 3) === 'PNG');
    [$c, $b] = http('GET', $app . 'dispute_image.php?id=' . (int) $d['id'], $jars['seller']);
    ok('The seller of that order can open the evidence photo', $c === 200 && substr($b, 1, 3) === 'PNG');
    [$c, $b] = http('GET', $app . 'dispute_image.php?id=' . (int) $d['id'], $jars['other']);
    ok('Another student cannot open the evidence photo (403)', $c === 403);
    [$c, $b, $u] = http('GET', $app . 'dispute_image.php?id=' . (int) $d['id'], $jars['guest']);
    ok('A guest cannot open the evidence photo (403)', $c === 403);
    [$c, $b] = http('POST', $app . 'orders.php', $jars['buyer'], ['order_id' => $oid, 'action' => 'confirm_received']);
    ok('The buyer cannot release the money while the dispute is open', strpos(text($b), 'The payment is frozen') !== false && $db->query("SELECT payment_status FROM orders WHERE id = {$oid}")->fetchColumn() === 'held');
    [$c, $b] = http('GET', $app . 'orders.php', $jars['seller']);
    ok('The seller sees the dispute and the reply deadline', strpos(text($b), 'Reply to the problem report') !== false && strpos(text($b), 'otherwise the buyer is refunded automatically') !== false);
    [$c, $b] = http('GET', $app . 'notifications.php', $jars['seller']);
    ok('The seller got a notification about the frozen payment', strpos(text($b), 'The payment is frozen') !== false);
    http('POST', $app . 'orders.php', $jars['seller'], ['order_id' => $oid, 'action' => 'dispute_reply', 'reply' => 'It was complete when I sent it']);
    [$c, $b] = http('GET', $app . 'orders.php', $jars['buyer']);
    ok('The buyer can read the seller\'s reply', strpos(text($b), 'It was complete when I sent it') !== false);
 
    // admin decides
    [$c, $b] = http('GET', $app . 'admin.php?tab=disputes', $jars['admin']);
    ok('The administrator sees the dispute with the seller\'s reply', strpos(text($b), 'It was complete when I sent it') !== false);
    ok('The administrator sees the evidence photo in the disputes tab', strpos($b, 'dispute_image.php?id=' . (int) $d['id']) !== false);
    http('POST', $app . 'admin.php?tab=disputes', $jars['admin'], ['action' => 'dispute_refund', 'dispute_id' => $d['id'], 'note' => 'Pages missing']);
    ok('The administrator can refund the buyer', $db->query("SELECT payment_status FROM orders WHERE id = {$oid}")->fetchColumn() === 'refunded');
    [$c, $b] = http('GET', $app . 'orders.php', $jars['buyer']);
    ok('The buyer\'s order page shows the refund and the timeline', strpos(text($b), 'was refunded') !== false && strpos(text($b), 'History') !== false);
 
    // held listing
    $scamTitle = "Autoe2e {$tag} Cheap laptop";
    $scam = $lm->create($noviceId, $scamTitle, 'Pay first by western union or whatsapp, gift card accepted, urgent', 20.00, 'Electronics');
    $db->exec("UPDATE users SET created_at = CURRENT_TIMESTAMP WHERE id = {$noviceId}");
    $held = (new Automation([$noviceId]))->checkListing($scam);
    ok('A listing with scam wording from a brand-new account is held automatically', $held !== null && $db->query("SELECT status FROM listings WHERE id = {$scam}")->fetchColumn() === 'held');
    [$c, $b] = http('GET', $app . 'dashboard.php?q=' . urlencode($scamTitle), $jars['buyer']);
    ok('The held listing is not shown in search', strpos($b, "view_listing.php?id={$scam}") === false);
    [$c, $b] = http('GET', $app . "view_listing.php?id={$scam}", $jars['buyer']);
    ok('A buyer who opens it is told it is under review and cannot buy', strpos(text($b), 'under review') !== false && strpos($b, 'Request to buy') === false);
    [$c, $b] = http('GET', $app . 'admin.php?tab=held', $jars['admin']);
    ok('The administrator sees it under Held listings with the reason', strpos(text($b), $scamTitle) !== false && strpos(text($b), 'AI risk score') !== false);
    http('POST', $app . 'admin.php?tab=held', $jars['admin'], ['action' => 'restore_listing', 'listing_id' => $scam]);
    [$c, $b] = http('GET', $app . 'dashboard.php?q=' . urlencode($scamTitle), $jars['buyer']);
    ok('After the administrator restores it, it is shown in search again', strpos($b, "view_listing.php?id={$scam}") !== false);
 
    // security
    $xss = "Autoe2e {$tag} <script>alert(1)</script>";
    $xl = $lm->create($sellerId, $xss, 'Test <script>alert(2)</script>', 50.00, 'Other');
    [$c, $b] = http('GET', $app . "view_listing.php?id={$xl}", $jars['buyer']);
    ok('A script in a listing title or description is shown as text, not run', strpos($b, '<script>alert(1)</script>') === false && strpos($b, '<script>alert(2)</script>') === false);
    [$c, $b] = http('GET', $app . 'dashboard.php?q=' . urlencode("' OR '1'='1' -- "), $jars['buyer']);
    ok('An SQL injection attempt in the search box causes no error', $c === 200 && stripos($b, 'SQLSTATE') === false && stripos($b, 'syntax error') === false);
    [$c, $b] = http('GET', $app . 'view_listing.php?id=' . urlencode("1 OR 1=1"), $jars['buyer']);
    ok('A non-numeric listing id is handled safely', stripos($b, 'SQLSTATE') === false);
    $mailPages = http('GET', $app . 'how_it_works.php', $jars['guest']);
    ok('The How it works page is open to guests and lists the automatic rules', $mailPages[0] === 200 && strpos(text($mailPages[1]), 'What happens automatically') !== false);
 
    // ---- new features: sign-up photo, profile edits, length limits, account deletion ----
    [$c, $b] = http('POST', $app . 'register.php', $jars['guest'], ['full_name' => 'Autoe2e Nophoto', 'email' => "autoe2e_{$tag}_nophoto@example.test", 'password' => 'password123']);
    ok('Sign-up without a face photo is refused', strpos(text($b), 'Please add a clear photo of your face') !== false && $um->findByEmail("autoe2e_{$tag}_nophoto@example.test") === null);
 
    http('POST', $app . 'profile.php', $jars['other'], ['action' => 'update_name', 'full_name' => 'Olga Renamed']);
    ok('A student can change their name on the profile page', ($um->findById($otherId)['full_name'] ?? '') === 'Olga Renamed');
    http('POST', $app . 'profile.php', $jars['other'], ['action' => 'change_password', 'current_password' => 'wrongpass1', 'new_password' => 'newpassword9', 'confirm_password' => 'newpassword9']);
    ok('Changing the password with a wrong current password is refused', $um->verifyPassword($pw, $um->findById($otherId)['password_hash']));
    http('POST', $app . 'profile.php', $jars['other'], ['action' => 'update_email', 'email' => $buyerMail, 'current_password' => $pw]);
    ok('An email that another account already uses cannot be taken', ($um->findById($otherId)['email'] ?? '') === $otherMail);
 
    [$c, $b] = http('POST', $app . "view_listing.php?id={$lid}", $jars['buyer'], ['action' => 'report', 'reason' => str_repeat('x', 1001)]);
    ok('A listing report longer than 1000 characters is refused', strpos(text($b), 'too long') !== false);
 
    // a student who wants to leave
    [$quitId, $quitMail] = $mk('Quinn Quitter', 'quitter', true);
    $db->exec("UPDATE users SET student_id_photo = 'autoe2e_none.jpg', profile_photo = 'autoe2e_none_face.jpg' WHERE id = " . (int) $quitId);
    $GLOBALS['extraE2E'] = [$quitId];
    $login('quitter', $quitMail);
    $orderM = new Order();
    $freeListing = $lm->create($quitId, "Autoe2e {$tag} Unordered lamp", 'Never ordered', 40.00, 'Other');
    $usedListing = $lm->create($quitId, "Autoe2e {$tag} Ordered desk", 'Had an order', 90.00, 'Other');
    $pendingOrder = $orderM->create($usedListing, $buyerId, $quitId, 'buy', null);
 
    $check = function (string $who, string $password) use ($app, &$jars): array {
        [$c, $b] = http('POST', $app . 'profile.php', $jars[$who], ['action' => 'check_password', 'password' => $password]);
        return json_decode($b, true) ?: [];
    };
    $r = $check('quitter', 'not-the-password');
    ok('Deleting an account: a wrong password is refused before the countdown', isset($r['ok']) && $r['ok'] === false);
    $r = $check('quitter', $pw);
    ok('Deleting an account is blocked while a request is still open', isset($r['ok']) && $r['ok'] === false && strpos($r['error'] ?? '', 'not finished') !== false);
    $r = $check('admin', $pw);
    ok('An administrator account cannot be deleted', isset($r['ok']) && $r['ok'] === false);
 
    $orderM->updateStatus($pendingOrder, 'completed');
    $r = $check('quitter', $pw);
    ok('With no open requests the password check passes', isset($r['ok']) && $r['ok'] === true);
 
    http('POST', $app . 'profile.php', $jars['quitter'], ['action' => 'delete_account', 'password' => 'wrong-password-1']);
    ok('Cancelling or a wrong password deletes nothing (account stays verified)', ($f = $um->findById($quitId)) && $f['email'] === $quitMail && (int) $f['student_verified'] === 1);
 
    [$c, $b] = http('POST', $app . 'profile.php', $jars['quitter'], ['action' => 'delete_account', 'password' => $pw]);
    $gone = $um->findById($quitId);
    ok('After the final step the goodbye page is shown', strpos(text($b), 'Your account has been deleted') !== false);
    ok('Personal data is erased (name, email, photos, verified status)', $gone && $gone['full_name'] === 'Deleted user' && $gone['email'] !== $quitMail
        && strpos($gone['email'], '@deleted.invalid') !== false && $gone['student_id_photo'] === null && $gone['profile_photo'] === null && (int) $gone['student_verified'] === 0);
    $tmp = [];
    http('GET', $app . 'login.php', $tmp);
    [$c, $b] = http('POST', $app . 'login.php', $tmp, ['email' => $quitMail, 'password' => $pw]);
    [$c2, $b2] = http('GET', $app . 'dashboard.php', $tmp);
    ok('The deleted account can no longer log in', strpos($b2, 'Log out') === false);
    $left = $db->prepare('SELECT (SELECT COUNT(*) FROM listings WHERE id = :a), (SELECT status FROM listings WHERE id = :b)');
    $left->execute(['a' => $freeListing, 'b' => $usedListing]);
    [$freeCount, $usedStatus] = $left->fetch(PDO::FETCH_NUM);
    ok('A listing nobody ordered is removed; a listing with an order stays hidden as sold', (int) $freeCount === 0 && $usedStatus === 'sold');
    [$c, $b] = http('GET', $app . 'admin.php?tab=users', $jars['admin']);
    ok('Deleted accounts are not shown in the administrator\'s Users list', strpos($b, 'Quinn Quitter') === false && strpos($b, 'deleted_' . $quitId . '@deleted.invalid') === false);
} catch (Throwable $e) {
    $crash = $e->getMessage() . ' (line ' . $e->getLine() . ')';
}
cleanupE2E($db);
@unlink($simTokenFile);
 
$p1 = array_sum(array_column($suites, 1));
$t1 = array_sum(array_column($suites, 2));
$p2 = count(array_filter($e2e, fn($r) => $r[1]));
$t2 = count($e2e);
$all = ($p1 + $p2 === $t1 + $t2) && $crash === null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>All tests - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h1>All automated tests</h1>
        <div class="flash <?= $all ? 'flash-success' : 'flash-error' ?>">
            <?= $p1 + $p2 ?> of <?= $t1 + $t2 ?> checks passed (<?= $p1 ?> of <?= $t1 ?> in the four test scripts, <?= $p2 ?> of <?= $t2 ?> in the end-to-end test)
        </div>
        <?php if ($diag !== ''): ?>
            <div class="flash flash-error">Problem found: <?= htmlspecialchars($diag) ?> Try opening this page as run_all_tests.php?host=127.0.0.1 and send me this message.</div>
        <?php endif; ?>
        <?php if ($crash !== null): ?>
            <div class="flash flash-error">The end-to-end test stopped with an error: <?= htmlspecialchars($crash) ?></div>
        <?php endif; ?>
 
        <h2>Part 1: test scripts</h2>
        <table class="data-table">
            <tr><th>Area</th><th>Script</th><th>Result</th></tr>
            <?php foreach ($suites as [$label, $p, $t, $note]): ?>
                <tr><td><?= htmlspecialchars($label) ?></td><td><?= htmlspecialchars($note) ?></td>
                    <td><span class="pill <?= $p === $t ? 'pill-completed' : 'pill-sold' ?>"><?= $p ?> of <?= $t ?></span></td></tr>
            <?php endforeach; ?>
        </table>
 
        <h2>Part 2: end-to-end test through the real pages</h2>
        <table class="data-table">
            <?php foreach ($e2e as $i => [$name, $ok]): ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($name) ?></td>
                    <td><?= $ok ? '<span class="pill pill-completed">PASS</span>' : '<span class="pill pill-sold">FAIL</span>' ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">Temporary test accounts and listings were removed. Open a script on its own (for example test_payment.php) to see each of its checks.</p>
        <p><a href="controllers/dashboard.php" class="back-link">← Back to the site</a></p>
    </div>
</body>
</html>
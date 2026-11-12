<?php
session_start();

require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';

$userModel = new User();
$me = !empty($_SESSION['user_id']) ? $userModel->findById((int) $_SESSION['user_id']) : null;
if (!$me || empty($me['is_admin'])) {
    http_response_code(403);
    exit('Administrators only. Log in as an administrator and open this page again.');
}

$db = (new Database())->getConnection();

function removeDemo(PDO $db): void
{
    $ids = $db->query("SELECT id FROM users WHERE email LIKE 'demo\\_%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM listing_views WHERE user_id IN ($in) OR listing_id IN (SELECT id FROM (SELECT id FROM listings WHERE user_id IN ($in)) x)");
    $db->exec("DELETE FROM listings WHERE user_id IN ($in)");
    $db->exec("DELETE FROM users WHERE id IN ($in)");
}

header('Content-Type: text/plain; charset=utf-8');

if (isset($_GET['reset'])) {
    removeDemo($db);
    exit("Demo data removed.\n");
}

removeDemo($db);   // running twice never duplicates data

$listingModel = new Listing();

function makeStudent(User $users, string $name, string $email): int
{
    $id = $users->create($name, $email, 'password123');
    $users->saveStudentIdPhoto($id, 'demo_placeholder.jpg');
    $users->approveStudent($id);
    return $id;
}

$maja = makeStudent($userModel, 'Maja Jensen', 'demo_maja@example.test');
$lars = makeStudent($userModel, 'Lars Nielsen', 'demo_lars@example.test');

$items = [
    1  => [$maja, 'Dell Latitude 14 inch laptop', 'Reliable laptop for lectures, charger included', 2500, 'Electronics'],
    2  => [$maja, 'MacBook Air 13 inch laptop', 'Light laptop with a good battery', 4500, 'Electronics'],
    3  => [$maja, 'Sony noise cancelling headphones', 'Great for studying in the library', 400, 'Electronics'],
    4  => [$maja, 'TI-84 Plus graphing calculator', 'Allowed in maths exams', 300, 'Calculators'],
    5  => [$maja, 'Casio scientific calculator fx-991', 'Solar powered scientific calculator', 120, 'Calculators'],
    6  => [$lars, 'Database Systems textbook 7th edition', 'Covers SQL and database design', 200, 'Textbooks'],
    7  => [$lars, 'Introduction to Java programming textbook', 'Object oriented programming, few notes inside', 180, 'Textbooks'],
    8  => [$lars, 'Calculus early transcendentals textbook', 'Clean copy for first year maths', 220, 'Textbooks'],
    9  => [$lars, 'Dell XPS 15 inch laptop', 'Fast laptop with charger, perfect for programming', 5200, 'Electronics'],
    10 => [$lars, 'Desk lamp LED adjustable', 'Bright lamp for night study', 60, 'Other'],
    11 => [$lars, 'Ring binder and notebook set', 'Six notebooks and two ring binders', 40, 'Stationery'],
    12 => [$maja, 'Logitech wireless mouse', 'Works with any laptop', 90, 'Electronics'],
];
$listingIds = [];
foreach ($items as $key => [$owner, $title, $desc, $price, $cat]) {
    $listingIds[$key] = $listingModel->create($owner, $title, $desc, (float) $price, $cat);
}

// Viewers and the listings they looked at (in order). These patterns are simulated.
$viewers = [
    'Anna Hansen'    => ['demo_anna@example.test',  [1, 3]],
    'Ben Olsen'      => ['demo_ben@example.test',   [1, 3, 2, 12]],
    'Clara Madsen'   => ['demo_clara@example.test', [2, 12, 1]],
    'David Poulsen'  => ['demo_david@example.test', [4, 5, 6, 8]],
    'Emma Sorensen'  => ['demo_emma@example.test',  [6, 7, 8, 4]],
];
foreach ($viewers as $name => [$email, $viewed]) {
    $uid = makeStudent($userModel, $name, $email);
    foreach ($viewed as $key) {
        $listingModel->recordView($uid, $listingIds[$key]);
    }
}

echo "Demo data added (SIMULATED).\n\n";
echo "Log in as demo_anna@example.test  (password: password123)\n";
echo "-> 'Recommended for you' should show the MacBook and the mouse (students with the same views also viewed them)\n";
echo "   and the Dell XPS laptop (similar wording to the Dell Latitude she viewed).\n\n";
echo "Log in as demo_david@example.test to see textbook and calculator recommendations.\n";
echo "Open recommender_report.php as admin to see the checks and the evaluation.\n";
echo "Remove everything again with seed_demo.php?reset=1\n";
<?php
session_start();

require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Listing.php';

$me = !empty($_SESSION['user_id']) ? (new User())->findById((int) $_SESSION['user_id']) : null;
if (!$me || empty($me['is_admin'])) {
    http_response_code(403);
    exit('Administrators only. Log in as an administrator and open this page again.');
}

/* ---------- 1. Unit checks on the recommender (synthetic data, no database) ---------- */
function mk(int $id, int $owner, string $title, string $desc, string $cat, string $status, string $created): array
{
    return ['id' => $id, 'user_id' => $owner, 'title' => $title, 'description' => $desc,
            'category' => $cat, 'status' => $status, 'created_at' => $created];
}

$L = [
    1 => mk(1, 100, 'Dell Latitude 14 inch laptop', 'Works well, charger included', 'Electronics', 'active', '2026-10-01 10:00:00'),
    2 => mk(2, 100, 'MacBook Air 13 inch laptop', 'Battery in good shape', 'Electronics', 'active', '2026-10-02 10:00:00'),
    3 => mk(3, 100, 'Sony noise cancelling headphones', 'Barely used', 'Electronics', 'active', '2026-10-03 10:00:00'),
    4 => mk(4, 101, 'Database Systems textbook', '7th edition', 'Textbooks', 'active', '2026-10-04 10:00:00'),
    5 => mk(5, 101, 'Casio calculator', 'Scientific', 'Calculators', 'sold', '2026-10-05 10:00:00'),
    6 => mk(6, 101, 'Dell XPS 15 inch laptop', 'Fast laptop with charger', 'Electronics', 'active', '2026-10-09 10:00:00'),
    7 => mk(7, 1, 'Desk lamp', 'LED lamp owned by user 1', 'Other', 'active', '2026-10-06 10:00:00'),
];
$V = [1 => [1, 3], 2 => [1, 3, 2], 3 => [4]];

$unit = [];
$collab = Recommender::recommend(1, $V, $L, 4, 'collab', false);
$unit[] = ['Collaborative: a listing viewed by similar students is recommended', in_array(2, array_column($collab, 'id'), true)];
$unit[] = ['Recommendations exclude listings the user already viewed',
    !array_intersect([1, 3], array_column(Recommender::recommend(1, $V, $L, 6), 'id'))];
$unit[] = ['Recommendations exclude the user\'s own listings',
    !in_array(7, array_column(Recommender::recommend(1, $V, $L, 6), 'id'), true)];
$unit[] = ['Recommendations exclude sold listings',
    !in_array(5, array_column(Recommender::recommend(1, $V, $L, 6), 'id'), true)];
$content = Recommender::recommend(1, $V, $L, 4, 'content', false);
$unit[] = ['Content-based: a similar listing nobody has viewed (Dell XPS) is recommended',
    in_array(6, array_column($content, 'id'), true)];
$unit[] = ['Counting method (baseline) does not recommend a listing nobody viewed together with mine',
    !in_array(6, array_column($collab, 'id'), true)];
$learned = Recommender::recommend(1, $V, $L, 4, 'learned', false);
$unit[] = ['Learned taste: a listing viewed by students with similar views is recommended',
    in_array(2, array_column($learned, 'id'), true)];
$unit[] = ['Learned taste: training twice on the same views gives the same result',
    Recommender::recommend(1, $V, $L, 4, 'learned', false) === $learned];
$model = Recommender::learnTastes($V, array_keys($L));
$unit[] = ['Learned taste: the model gives every student and every viewed listing taste numbers',
    isset($model['users'][1], $model['items'][2]) && count($model['users'][1]) === count($model['items'][2])];
$hybrid = Recommender::recommend(1, $V, $L, 4, 'hybrid', false);
$unit[] = ['Hybrid combines both signals (learned taste and wording results both appear)',
    in_array(2, array_column($hybrid, 'id'), true) && in_array(6, array_column($hybrid, 'id'), true)];
$cold = Recommender::recommend(50, $V, $L, 3);
$unit[] = ['Cold start: a new user gets the newest listings', count($cold) === 3 && $cold[0]['id'] === 6 && $cold[0]['type'] === 'new'];
$unit[] = ['Tokenizer drops stop words and one-letter tokens', Recommender::tokenize('The Dell and a laptop') === ['dell', 'laptop']];

/* ---------- 2. Offline evaluation: leave-one-out on the real view data ---------- */
[$views, $listings] = (new Listing())->recommenderData();
$k = 4;
$methods = ['popular' => 'Most viewed (baseline)', 'collab' => 'Counting method (old baseline)', 'learned' => 'Learned taste only', 'content' => 'Wording only (TF-IDF)', 'hybrid' => 'Hybrid (learned taste + wording)'];
$hits = array_fill_keys(array_keys($methods), 0);
$tested = 0;

foreach ($views as $uid => $seen) {
    if (count($seen) < 2) {
        continue;
    }
    $hidden = $seen[count($seen) - 1];
    if (!isset($listings[$hidden]) || $listings[$hidden]['status'] !== 'active' || (int) $listings[$hidden]['user_id'] === $uid) {
        continue;
    }
    $train = $views;
    $train[$uid] = array_slice($seen, 0, -1);
    $tested++;

    foreach (['collab', 'learned', 'content', 'hybrid'] as $mode) {
        $rec = Recommender::recommend($uid, $train, $listings, $k, $mode, false);
        if (in_array($hidden, array_column($rec, 'id'), true)) {
            $hits[$mode]++;
        }
    }

    // Baseline: the most viewed listings the user has not seen
    $count = [];
    foreach ($train as $u => $list) {
        if ($u === $uid) {
            continue;
        }
        foreach ($list as $lid) {
            $count[$lid] = ($count[$lid] ?? 0) + 1;
        }
    }
    arsort($count);
    $pop = [];
    foreach (array_keys($count) as $lid) {
        if (in_array($lid, $train[$uid], true) || !isset($listings[$lid]) || $listings[$lid]['status'] !== 'active' || (int) $listings[$lid]['user_id'] === $uid) {
            continue;
        }
        $pop[] = $lid;
        if (count($pop) >= $k) {
            break;
        }
    }
    if (in_array($hidden, $pop, true)) {
        $hits['popular']++;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recommender report - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h1>Recommender report</h1>

        <h2 class="section-title">1. Unit checks</h2>
        <table class="data-table">
            <?php $passed = 0; foreach ($unit as [$name, $ok]): $passed += $ok ? 1 : 0; ?>
                <tr><td><?= htmlspecialchars($name) ?></td><td><?= $ok ? '<span class="pill pill-completed">PASS</span>' : '<span class="pill pill-sold">FAIL</span>' ?></td></tr>
            <?php endforeach; ?>
        </table>
        <p><strong><?= $passed ?> of <?= count($unit) ?> checks passed.</strong></p>

        <h2 class="section-title">2. Offline evaluation (leave-one-out, hit rate at <?= $k ?>)</h2>
        <p class="muted">
            For every student with at least two viewed listings, the last viewed listing is hidden.
            The recommender sees the remaining views and gets <?= $k ?> recommendation slots.
            A "hit" means the hidden listing was among them. Students tested: <?= $tested ?>.
        </p>
        <?php if ($tested === 0): ?>
            <p class="muted">Not enough view data yet. Run seed_demo.php or browse some listings with several accounts.</p>
        <?php else: ?>
            <table class="data-table">
                <tr><th>Method</th><th>Hits</th><th>Hit rate</th></tr>
                <?php foreach ($methods as $key => $label): ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td><?= $hits[$key] ?> / <?= $tested ?></td>
                        <td><?= number_format(100 * $hits[$key] / $tested, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <p class="muted">With few students the numbers are only indicative. They improve as more views are recorded.</p>
        <?php endif; ?>

        <p><a href="controllers/dashboard.php" class="back-link">← Back to the site</a></p>
    </div>
</body>
</html>
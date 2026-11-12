<?php
/**
 * Offline evaluation of the recommender on a SIMULATED marketplace.
 * 60 listings in 6 topics. Every simulated student likes one or two topics and views listings
 * mostly from those topics (80%) and sometimes from any topic (20%).
 * The last view of each student is hidden and the recommender gets 4 slots to find it.
 * The data is invented, so the result shows that the methods work, not how good they are for real students.
 */
if (PHP_SAPI !== 'cli') {
    session_start();
    require_once __DIR__ . '/models/User.php';
    $me = !empty($_SESSION['user_id']) ? (new User())->findById((int) $_SESSION['user_id']) : null;
    if (!$me || empty($me['is_admin'])) {
        http_response_code(403);
        exit('Administrators only. Log in as an administrator and open this page again.');
    }
}
set_time_limit(180);
require_once __DIR__ . '/models/Recommender.php';

$topics = [
 'Electronics' => ['laptop','charger','screen','battery','headphones','tablet','keyboard','mouse'],
 'Textbooks'   => ['textbook','edition','chapter','exam','notes','calculus','physics','statistics'],
 'Bikes'       => ['bike','tyres','gears','lights','helmet','frame','brakes','basket'],
 'Furniture'   => ['desk','chair','lamp','shelf','sofa','bed','table','drawer'],
 'Kitchen'     => ['pan','kettle','plates','knife','toaster','blender','cups','pot'],
 'Sports'      => ['racket','football','yoga','weights','shoes','ball','gloves','mat'],
];
function run(int $seed, int $nStudents, int $views): array {
  global $topics;
  mt_srand($seed);
  $names = array_keys($topics);
  $L = []; $id = 0;
  foreach ($names as $ti => $cat) for ($k=0;$k<10;$k++) { $id++;
    $w = $topics[$cat]; shuffle($w);
    // 3 topic words + 1 word from another topic (noise), so wording is helpful but not perfect
    $other = $topics[$names[($ti+1+mt_rand(0,4))%6]]; 
    $L[$id] = ['id'=>$id,'user_id'=>1000+$k,'title'=>$w[0].' '.$w[1].' '.$cat.' item '.$id,'description'=>$w[2].' '.$w[3].' '.$other[mt_rand(0,7)],'category'=>$cat,'status'=>'active','created_at'=>sprintf('2026-10-%02d 10:00:00',1+($id%28))];
  }
  $byTopic = []; foreach ($L as $l) $byTopic[$l['category']][] = $l['id'];
  $V = [];
  for ($u=1;$u<=$nStudents;$u++) {
    $likes = [$names[mt_rand(0,5)], $names[mt_rand(0,5)]];
    $seen = [];
    while (count($seen) < $views) {
      $cat = mt_rand(1,100) <= 80 ? $likes[mt_rand(0,1)] : $names[mt_rand(0,5)];
      $lid = $byTopic[$cat][mt_rand(0,9)];
      if (!in_array($lid,$seen,true)) $seen[] = $lid;
    }
    $V[$u] = $seen;
  }
  return [$V,$L];
}
function evaluate(array $V, array $L, int $k=4): array {
  $hits = ['popular'=>0,'collab'=>0,'learned'=>0,'content'=>0,'hybrid'=>0]; $tested=0;
  foreach ($V as $uid=>$seen) {
    $hidden = $seen[count($seen)-1];
    $train = $V; $train[$uid] = array_slice($seen,0,-1); $tested++;
    foreach (['collab','learned','content','hybrid'] as $m) {
      $rec = Recommender::recommend($uid,$train,$L,$k,$m,false);
      if (in_array($hidden,array_column($rec,'id'),true)) $hits[$m]++;
    }
    $cnt=[]; foreach ($train as $u=>$list) { if ($u===$uid) continue; foreach ($list as $lid) $cnt[$lid]=($cnt[$lid]??0)+1; }
    arsort($cnt); $pop=[]; foreach (array_keys($cnt) as $lid) { if (in_array($lid,$train[$uid],true)) continue; $pop[]=$lid; if (count($pop)>=$k) break; }
    if (in_array($hidden,$pop,true)) $hits['popular']++;
  }
  return [$hits,$tested];
}

$students = 30; $viewsEach = 8; $seeds = [1, 2, 3];
$tot = array_fill_keys(['popular', 'collab', 'learned', 'content', 'hybrid'], 0); $tested = 0;
foreach ($seeds as $seed) {
    [$V, $L] = run($seed, $students, $viewsEach);
    [$h, $t] = evaluate($V, $L);
    foreach ($h as $m => $x) { $tot[$m] += $x; }
    $tested += $t;
}
$labels = ['popular' => 'Most viewed (baseline)', 'collab' => 'Counting method (old baseline)', 'learned' => 'Learned taste only', 'content' => 'Wording only (TF-IDF)', 'hybrid' => 'Hybrid (learned taste + wording)'];
if (PHP_SAPI === 'cli') {
    foreach ($tot as $m => $x) { printf("%-36s %d / %d  %.1f%%\n", $labels[$m], $x, $tested, 100 * $x / $tested); }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recommender simulation - TrustTrade</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content">
        <h1>Recommender simulation</h1>
        <p class="muted">
            A simulated marketplace with 60 listings in 6 topics and <?= count($seeds) ?> runs of <?= $students ?> students
            who each view <?= $viewsEach ?> listings. The last view of every student is hidden and the recommender gets 4 slots to find it.
            Students tested: <?= $tested ?>. A random guess would hit about 8%.
        </p>
        <table class="data-table">
            <tr><th>Method</th><th>Hits</th><th>Hit rate</th></tr>
            <?php foreach ($labels as $key => $label): ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><?= $tot[$key] ?> / <?= $tested ?></td>
                    <td><?= number_format(100 * $tot[$key] / $tested, 1) ?>%</td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">The data is simulated, so this shows that the methods work, not how good they are for real students.</p>
        <p><a href="controllers/dashboard.php" class="back-link">&larr; Back to the site</a></p>
    </div>
</body>
</html>
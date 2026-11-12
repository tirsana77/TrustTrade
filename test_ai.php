<?php
// Open in the browser or run: php test_ai.php
require_once __DIR__ . '/models/PriceModel.php';
require_once __DIR__ . '/models/RiskModel.php';
$pass = 0; $fail = 0;
function check(string $name, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? 'PASS  ' : 'FAIL  ') . $name . "\n"; }

$L = [
 1 => ['title'=>'Calculus textbook Stewart','description'=>'Calculus early transcendentals, good condition','category'=>'Books','price'=>300],
 2 => ['title'=>'Calculus textbook 8th edition','description'=>'Stewart calculus book, some notes in margins','category'=>'Books','price'=>260],
 3 => ['title'=>'Calculus book Stewart','description'=>'Used calculus textbook for first year','category'=>'Books','price'=>340],
 4 => ['title'=>'Statistics textbook','description'=>'Introductory statistics book, like new','category'=>'Books','price'=>280],
 5 => ['title'=>'Mountain bike Trek','description'=>'Trek mountain bike 26 inch, new tyres','category'=>'Bikes','price'=>1800],
 6 => ['title'=>'City bike','description'=>'Dutch city bike with basket and lights','category'=>'Bikes','price'=>1200],
 7 => ['title'=>'Racing bike','description'=>'Light racing bike, carbon fork','category'=>'Bikes','price'=>3000],
];
$q = ['title'=>'Calculus textbook','description'=>'Stewart calculus, good shape','category'=>'Books'];
$h = PriceModel::hint($q, $L);
check('price hint found for similar books', $h !== null);
check('hint median inside the book price range', $h && $h['mid'] >= 250 && $h['mid'] <= 350);
check('hint ordered low <= mid <= high', $h && $h['low'] <= $h['mid'] && $h['mid'] <= $h['high']);
check('bike prices do not leak into a book hint', $h && $h['high'] < 1000);
check('no hint for unrelated text', PriceModel::hint(['title'=>'xyzzy plugh','description'=>'','category'=>''], $L) === null);
check('assess: 50 kr is underpriced', PriceModel::assess(50, $h) === 'underpriced');
check('assess: 290 kr is fair', PriceModel::assess(290, $h) === 'fair');
check('assess: 900 kr is overpriced', PriceModel::assess(900, $h) === 'overpriced');
check('assess: unknown without a hint', PriceModel::assess(100, null) === 'unknown');
$c = PriceModel::suggestCategory(['title'=>'Trek bike','description'=>'mountain bike with lights'], $L);
check('category suggestion is Bikes', $c && $c['name'] === 'Bikes');
$c = PriceModel::suggestCategory(['title'=>'Stewart calculus','description'=>'textbook'], $L);
check('category suggestion is Books', $c && $c['name'] === 'Books');

// risk model
$safe = RiskModel::score(RiskModel::features(['price_ratio'=>1.0,'account_age_days'=>200,'completed'=>5,'text'=>'Nice desk lamp']));
check('established seller, fair price: low risk', $safe['level'] === 'low' && $safe['score'] < 10);
$bad = RiskModel::score(RiskModel::features(['price_ratio'=>0.2,'account_age_days'=>1,'reports'=>2,'text'=>'Pay first by western union, contact me on whatsapp']));
check('cheap, new, reported, scam words: high risk', $bad['level'] === 'high' && $bad['score'] >= 90);
check('factors explain the score', count($bad['factors']) >= 3 && $bad['factors'][0]['points'] >= $bad['factors'][1]['points']);
$mid = RiskModel::score(RiskModel::features(['price_ratio'=>0.45,'account_age_days'=>3,'completed'=>0,'text'=>'bike']));
check('underpriced new seller: medium or high', in_array($mid['level'], ['medium','high'], true));
check('track record lowers risk', RiskModel::score(RiskModel::features(['price_ratio'=>0.4,'account_age_days'=>200,'completed'=>5]))['score']
      < RiskModel::score(RiskModel::features(['price_ratio'=>0.4,'account_age_days'=>200,'completed'=>0]))['score']);
check('scam wording detected', count(RiskModel::suspiciousTerms('Send money by Western Union')) >= 1);
check('plain wording not flagged', RiskModel::suspiciousTerms('Blue desk lamp, works great') === []);
check('missing inputs never crash', RiskModel::score(RiskModel::features([]))['score'] <= 10);

// training on synthetic labelled data
mt_srand(7);
$ex = [];
for ($i = 0; $i < 300; $i++) {
    $under = mt_rand(0,1); $new = mt_rand(0,1); $words = mt_rand(0,1); $rep = mt_rand(0,1); $hist = mt_rand(0,1);
    $risky = ($under + $words + $rep) >= 2 || ($under && $new);
    $ex[] = [[ 'underpriced'=>$under*0.9,'new_account'=>$new,'reported'=>$rep,'seller_refunds'=>0,'suspicious_text'=>$words,'seller_disputes'=>0,'track_record'=>$hist ], $risky ? 1 : 0];
}
$m = RiskModel::train($ex);
$right = 0;
foreach ($ex as [$x, $y]) { $s = RiskModel::score($x, $m['weights'], $m['bias'])['score']; if (($s >= 50) === ($y === 1)) $right++; }
check('train(): fits labelled data (accuracy >= 90%)', $right / count($ex) >= 0.9);
check('train(): learns positive weight for underpriced', $m['weights']['underpriced'] > 0.5);
printf("\n%d passed, %d failed\n", $pass, $fail);
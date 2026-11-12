<?php
/**
 * Hybrid recommender (pure PHP, no database calls, so it can be tested offline).
 *
 *  - Learned taste model: matrix factorisation. From everybody's views the model learns a small
 *    list of numbers ("taste factors") for every student and every listing, by gradient descent.
 *    A listing scores high for a student when their taste numbers fit together.
 *    This is the part that learns from data.
 *  - Content-based filtering: each listing (title x3 + description + category) becomes a
 *    TF-IDF vector; candidates are scored by cosine similarity to the listings I viewed.
 *    This covers new listings that nobody has viewed yet.
 *  - Hybrid score = tasteWeight * learned taste + (1 - tasteWeight) * content.
 *  - Cold start: a user with no views gets the newest listings.
 *  - The old counting method (cosine similarity of view sets) is kept as mode 'collab',
 *    so it can be compared with the learned model in recommender_report.php.
 */
class Recommender
{
    /** Content matches weaker than this are treated as noise (shared filler words). */
    private const MIN_CONTENT_SIMILARITY = 0.05;

    /** A learned taste score must be at least this many times the student's average to count. */
    private const MIN_TASTE_LIFT = 1.5;

    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'from', 'are', 'was', 'has', 'have', 'not',
        'but', 'you', 'your', 'its', 'used', 'good', 'condition', 'very', 'new',
        'og', 'er', 'det', 'der', 'til', 'med', 'en', 'et', 'af', 'som', 'jeg', 'den',
    ];

    public static function tokenize(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') < 2 || in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            $tokens[] = $part;
        }
        return $tokens;
    }

    /** TF-IDF vectors (L2-normalised) for every listing: [listingId => [term => weight]]. */
    public static function buildVectors(array $listings): array
    {
        $termCounts = [];
        $docFreq    = [];

        foreach ($listings as $id => $l) {
            $titleTokens = self::tokenize((string) ($l['title'] ?? ''));
            $tokens = array_merge(
                $titleTokens, $titleTokens, $titleTokens,
                self::tokenize((string) ($l['description'] ?? '')),
                self::tokenize((string) ($l['category'] ?? ''))
            );
            $counts = array_count_values($tokens);
            $termCounts[$id] = $counts;
            foreach (array_keys($counts) as $term) {
                $docFreq[$term] = ($docFreq[$term] ?? 0) + 1;
            }
        }

        $n = count($termCounts);
        $vectors = [];
        foreach ($termCounts as $id => $counts) {
            $vec  = [];
            $norm = 0.0;
            foreach ($counts as $term => $count) {
                $w = (1 + log($count)) * (log(($n + 1) / ($docFreq[$term] + 1)) + 1);
                $vec[$term] = $w;
                $norm += $w * $w;
            }
            $norm = sqrt($norm);
            if ($norm > 0) {
                foreach ($vec as $term => $w) {
                    $vec[$term] = $w / $norm;
                }
            }
            $vectors[$id] = $vec;
        }
        return $vectors;
    }

    public static function cosine(array $a, array $b): float
    {
        if (count($a) > count($b)) {
            [$a, $b] = [$b, $a];
        }
        $dot = 0.0;
        foreach ($a as $term => $w) {
            if (isset($b[$term])) {
                $dot += $w * $b[$term];
            }
        }
        return $dot;   // vectors are already normalised
    }

    /** Small deterministic random generator, so training gives the same result every time. */
    private static function rand01(int &$state): float
    {
        $state = ($state * 1103515245 + 12345) & 0x7fffffff;
        return $state / 0x7fffffff;
    }

    private static function dot(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $f => $v) {
            $s += $v * $b[$f];
        }
        return $s;
    }

    /**
     * Learn taste factors by matrix factorisation (stochastic gradient descent).
     * Every view is a positive example. For each one, a few listings the student did not view
     * are used as negative examples. The model changes its numbers to score views high and
     * non-views low.
     *
     * @param array $viewsByUser [userId => [listingId, ...]]
     * @param array $listingIds  ids of the listings the model may learn about
     * @return array ['users' => [userId => float[]], 'items' => [listingId => float[]]]
     */
    public static function learnTastes(
        array $viewsByUser,
        array $listingIds,
        int $factors = 6,
        int $epochs = 120,
        float $rate = 0.08,
        float $reg = 0.02,
        int $seed = 42
    ): array {
        $items = [];
        foreach ($listingIds as $id) {
            $items[(int) $id] = true;
        }
        $itemList = array_keys($items);
        if (count($itemList) < 2) {
            return ['users' => [], 'items' => []];
        }

        $state = $seed;
        $seen  = [];
        $pairs = [];
        foreach ($viewsByUser as $uid => $views) {
            foreach ($views as $lid) {
                $lid = (int) $lid;
                if (!isset($items[$lid])) {
                    continue;
                }
                $seen[(int) $uid][$lid] = true;
            }
            foreach (array_keys($seen[(int) $uid] ?? []) as $lid) {
                $pairs[] = [(int) $uid, $lid];
            }
        }
        if (!$pairs) {
            return ['users' => [], 'items' => []];
        }

        $initVec = function () use ($factors, &$state): array {
            $v = [];
            for ($f = 0; $f < $factors; $f++) {
                $v[$f] = (self::rand01($state) - 0.5) * 0.2;
            }
            return $v;
        };
        $P = [];
        $Q = [];
        foreach (array_keys($seen) as $uid) {
            $P[$uid] = $initVec();
        }
        foreach ($itemList as $lid) {
            $Q[$lid] = $initVec();
        }

        $step = function (int $u, int $i, float $y) use (&$P, &$Q, $factors, $rate, $reg): void {
            $e = $y - 1.0 / (1.0 + exp(-self::dot($P[$u], $Q[$i])));
            for ($f = 0; $f < $factors; $f++) {
                $pf = $P[$u][$f];
                $qf = $Q[$i][$f];
                $P[$u][$f] += $rate * ($e * $qf - $reg * $pf);
                $Q[$i][$f] += $rate * ($e * $pf - $reg * $qf);
            }
        };

        $n = count($pairs);
        for ($epoch = 0; $epoch < $epochs; $epoch++) {
            // shuffle the examples (Fisher-Yates, deterministic)
            for ($k = $n - 1; $k > 0; $k--) {
                $j = (int) floor(self::rand01($state) * ($k + 1));
                [$pairs[$k], $pairs[$j]] = [$pairs[$j], $pairs[$k]];
            }
            foreach ($pairs as [$u, $i]) {
                $step($u, $i, 1.0);
                for ($t = 0; $t < 2; $t++) {
                    for ($try = 0; $try < 5; $try++) {
                        $neg = $itemList[(int) floor(self::rand01($state) * count($itemList))];
                        if (!isset($seen[$u][$neg])) {
                            $step($u, $neg, 0.0);
                            break;
                        }
                    }
                }
            }
        }
        return ['users' => $P, 'items' => $Q];
    }

    /**
     * Taste score between 0 and 1 for each candidate listing the model knows.
     * Listings nobody has viewed have no taste numbers and are left out.
     *
     * @return array [listingId => score]
     */
    public static function tasteScores(int $userId, array $model, array $candidateIds): array
    {
        $p = $model['users'][$userId] ?? null;
        if ($p === null) {
            return [];
        }
        $out = [];
        foreach ($candidateIds as $cid) {
            $cid = (int) $cid;
            if (!isset($model['items'][$cid])) {
                continue;
            }
            $out[$cid] = 1.0 / (1.0 + exp(-self::dot($p, $model['items'][$cid])));
        }
        return $out;
    }

    /**
     * @param array  $viewsByUser [userId => [listingId, ...]] distinct views
     * @param array  $listings    [listingId => row with user_id, title, description, category, status, created_at]
     * @param string $mode        'hybrid' (learned taste + wording) | 'learned' | 'collab' (old counting method) | 'content'
     * @param bool   $fill        fill empty slots with the newest listings
     * @return array [['id' => int, 'score' => float, 'type' => 'taste'|'viewers'|'similar'|'new', 'via' => ?int], ...]
     */
    public static function recommend(
        int $userId,
        array $viewsByUser,
        array $listings,
        int $limit = 4,
        string $mode = 'hybrid',
        bool $fill = true,
        float $collabWeight = 0.6
    ): array {
        $mine = [];
        foreach ($viewsByUser[$userId] ?? [] as $lid) {
            $mine[(int) $lid] = true;
        }

        $candidates = [];
        foreach ($listings as $id => $l) {
            $id = (int) $id;
            if (($l['status'] ?? 'active') !== 'active') {
                continue;
            }
            if ((int) $l['user_id'] === $userId || isset($mine[$id])) {
                continue;
            }
            $candidates[$id] = $l;
        }
        if (!$candidates) {
            return [];
        }

        $newest = function (array $skip) use ($candidates): array {
            $ids = array_keys(array_diff_key($candidates, $skip));
            usort($ids, function ($x, $y) use ($candidates) {
                $cmp = strcmp((string) ($candidates[$y]['created_at'] ?? ''), (string) ($candidates[$x]['created_at'] ?? ''));
                return $cmp !== 0 ? $cmp : $y <=> $x;
            });
            return $ids;
        };

        // Cold start: no views yet
        if (!$mine) {
            $out = [];
            foreach (array_slice($newest([]), 0, $limit) as $id) {
                $out[] = ['id' => $id, 'score' => 0.0, 'type' => 'new', 'via' => null];
            }
            return $out;
        }

        // Old counting method (kept as the baseline 'collab')
        $collab = [];
        $collabVia = [];
        $myCount = count($mine);
        foreach ($mode === 'collab' ? $viewsByUser : [] as $uid => $views) {
            if ((int) $uid === $userId) {
                continue;
            }
            $theirs = [];
            foreach ($views as $lid) {
                $theirs[(int) $lid] = true;
            }
            $shared = array_intersect_key($mine, $theirs);
            if (!$shared) {
                continue;
            }
            $sim = count($shared) / sqrt($myCount * count($theirs));
            $via = array_key_first($shared);
            foreach ($theirs as $lid => $_) {
                if (!isset($candidates[$lid])) {
                    continue;
                }
                $collab[$lid] = ($collab[$lid] ?? 0.0) + $sim;
                if (!isset($collabVia[$lid])) {
                    $collabVia[$lid] = $via;
                }
            }
        }
        $maxCollab = $collab ? max($collab) : 0.0;

        // Learned taste (matrix factorisation on everybody's views)
        $taste = [];
        if ($mode === 'hybrid' || $mode === 'learned') {
            $model  = self::learnTastes($viewsByUser, array_keys($listings));
            $scores = self::tasteScores($userId, $model, array_keys($candidates));
            if ($scores) {
                // A listing counts only when the model scores it clearly above this student's average
                // for listings they have not seen (1.5 times or more). Below that it is noise.
                $avg = array_sum($scores) / count($scores);
                foreach ($scores as $cid => $sig) {
                    if ($avg > 0 && $sig / $avg >= self::MIN_TASTE_LIFT) {
                        $taste[$cid] = $sig;
                    }
                }
            }
        }
        $maxTaste = $taste ? max($taste) : 0.0;

        // Content-based filtering
        $content = [];
        $contentVia = [];
        if ($mode !== 'collab' && $mode !== 'learned') {
            $vectors = self::buildVectors($listings);
            foreach ($candidates as $cid => $_) {
                $best = 0.0;
                $bestVia = null;
                foreach ($mine as $mid => $__) {
                    if (!isset($vectors[$cid], $vectors[$mid])) {
                        continue;
                    }
                    $s = self::cosine($vectors[$cid], $vectors[$mid]);
                    if ($s > $best) {
                        $best = $s;
                        $bestVia = $mid;
                    }
                }
                if ($best >= self::MIN_CONTENT_SIMILARITY) {
                    $content[$cid] = $best;
                    $contentVia[$cid] = $bestVia;
                }
            }
        }

        // Combine
        $scored = [];
        foreach ($candidates as $cid => $_) {
            $c = ($maxCollab > 0 && isset($collab[$cid])) ? $collab[$cid] / $maxCollab : 0.0;
            $l = ($maxTaste > 0 && isset($taste[$cid])) ? $taste[$cid] / $maxTaste : 0.0;
            $t = $content[$cid] ?? 0.0;

            if ($mode === 'collab') {
                $score = $c;
            } elseif ($mode === 'learned') {
                $score = $l;
            } elseif ($mode === 'content') {
                $score = $t;
            } else {
                $score = $collabWeight * $l + (1 - $collabWeight) * $t;
            }
            if ($score <= 0) {
                continue;
            }

            if ($mode === 'collab') {
                $type = 'viewers';
            } elseif ($mode === 'learned') {
                $type = 'taste';
            } elseif ($mode === 'content') {
                $type = 'similar';
            } else {
                $type = ($collabWeight * $l >= (1 - $collabWeight) * $t) ? 'taste' : 'similar';
            }
            $scored[$cid] = [
                'id'    => $cid,
                'score' => $score,
                'type'  => $type,
                'via'   => $type === 'viewers' ? ($collabVia[$cid] ?? null) : ($type === 'similar' ? ($contentVia[$cid] ?? null) : null),
            ];
        }
        uasort($scored, function ($a, $b) use ($candidates) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp((string) ($candidates[$b['id']]['created_at'] ?? ''), (string) ($candidates[$a['id']]['created_at'] ?? ''));
        });

        $out = array_slice(array_values($scored), 0, $limit);

        if ($fill && count($out) < $limit) {
            $skip = array_flip(array_column($out, 'id'));
            foreach ($newest($skip) as $id) {
                if (count($out) >= $limit) {
                    break;
                }
                $out[] = ['id' => $id, 'score' => 0.0, 'type' => 'new', 'via' => null];
            }
        }
        return $out;
    }
}
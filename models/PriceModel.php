<?php
require_once __DIR__ . '/Recommender.php';

/**
 * Price and category hints from similar listings (pure PHP, no database calls).
 *
 * Each listing is a TF-IDF vector (the same representation the recommender uses).
 * For a new listing we find the most similar existing listings (cosine similarity) and
 *  - price:    take the similarity-weighted 25th / 50th / 75th percentile of their prices
 *              (a k-nearest-neighbours regression),
 *  - category: take a similarity-weighted vote of their categories (k-nearest-neighbours classification).
 */
class PriceModel
{
    public const MIN_SIM = 0.12;   // neighbours less similar than this are ignored
    public const K       = 7;      // number of neighbours used

    /** Query doc id. Real listing ids start at 1, so 0 is free. */
    private const Q = 0;

    /**
     * @param array $query    ['title' =>, 'description' =>, 'category' =>]
     * @param array $listings [id => row with title, description, category, price]
     * @return array|null     null when there are fewer than two similar listings
     */
    public static function hint(array $query, array $listings, ?int $excludeId = null): ?array
    {
        $docs = self::docs($query, $listings, $excludeId, true);
        $vectors = Recommender::buildVectors($docs);
        return self::fromVectors($vectors, $vectors[self::Q] ?? [], $docs, self::Q);
    }

    /** Same, but with vectors that were built once for many lookups (used by the admin risk view). */
    public static function fromVectors(array $vectors, array $q, array $listings, int $skipId): ?array
    {
        $sims = [];
        foreach ($listings as $id => $l) {
            $id = (int) $id;
            if ($id === $skipId || !isset($vectors[$id]) || (float) ($l['price'] ?? 0) <= 0) {
                continue;
            }
            $s = Recommender::cosine($q, $vectors[$id]);
            if ($s >= self::MIN_SIM) {
                $sims[$id] = $s;
            }
        }
        if (count($sims) < 2) {
            return null;
        }
        arsort($sims);
        $sims = array_slice($sims, 0, self::K, true);

        $pts = [];
        foreach ($sims as $id => $s) {
            $pts[] = [(float) $listings[$id]['price'], $s];
        }
        usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
        $total = array_sum(array_column($pts, 1));

        $pct = function (float $p) use ($pts, $total): float {
            $acc = 0.0;
            foreach ($pts as [$price, $w]) {
                $acc += $w;
                if ($acc / $total >= $p) {
                    return $price;
                }
            }
            return end($pts)[0];
        };
        $low = $pct(0.25); $mid = $pct(0.5); $high = $pct(0.75);
        if ($high - $low < 0.1 * $mid) {            // all neighbours cost about the same: widen a little
            $low = $mid * 0.9; $high = $mid * 1.1;
        }

        $n = count($sims);
        $avg = array_sum($sims) / $n;
        return [
            'low'        => floor($low / 10) * 10,
            'mid'        => round($mid / 10) * 10,
            'high'       => ceil($high / 10) * 10,
            'n'          => $n,
            'avg_sim'    => round($avg, 3),
            'confidence' => ($n >= 5 && $avg >= 0.3) ? 'high' : ($n >= 3 ? 'medium' : 'low'),
            'ids'        => array_keys($sims),
        ];
    }

    /** 'underpriced' | 'overpriced' | 'fair' | 'unknown' */
    public static function assess(float $price, ?array $hint): string
    {
        if ($hint === null || $hint['n'] < 3 || $hint['mid'] <= 0) {
            return 'unknown';
        }
        if ($price < 0.5 * $hint['mid']) {
            return 'underpriced';
        }
        if ($price > 1.8 * $hint['mid']) {
            return 'overpriced';
        }
        return 'fair';
    }

    /** price / typical price, or null when there is no reliable hint. */
    public static function ratio(float $price, ?array $hint): ?float
    {
        if ($hint === null || $hint['n'] < 3 || $hint['mid'] <= 0) {
            return null;
        }
        return $price / $hint['mid'];
    }

    /**
     * Category suggestion by weighted vote of the most similar listings.
     * @return array|null ['name' => string, 'confidence' => 0..1, 'n' => int]
     */
    public static function suggestCategory(array $query, array $listings): ?array
    {
        $query['category'] = '';                      // the category is what we are predicting
        $docs = self::docs($query, $listings, null, false);
        $vectors = Recommender::buildVectors($docs);
        $q = $vectors[self::Q] ?? [];

        $sims = [];
        foreach ($listings as $id => $l) {
            $id = (int) $id;
            if (!isset($vectors[$id]) || trim((string) ($l['category'] ?? '')) === '') {
                continue;
            }
            $s = Recommender::cosine($q, $vectors[$id]);
            if ($s >= self::MIN_SIM) {
                $sims[$id] = $s;
            }
        }
        if (count($sims) < 2) {
            return null;
        }
        arsort($sims);
        $sims = array_slice($sims, 0, self::K, true);

        $votes = [];
        foreach ($sims as $id => $s) {
            $cat = $listings[$id]['category'];
            $votes[$cat] = ($votes[$cat] ?? 0) + $s;
        }
        arsort($votes);
        $best = array_key_first($votes);
        $share = $votes[$best] / array_sum($votes);
        if ($share < 0.5 || max($sims) < 0.15) {
            return null;
        }
        return ['name' => $best, 'confidence' => round($share, 2), 'n' => count($sims)];
    }

    private static function docs(array $query, array $listings, ?int $excludeId, bool $needPrice): array
    {
        $docs = [];
        foreach ($listings as $id => $l) {
            if ($excludeId !== null && (int) $id === $excludeId) {
                continue;
            }
            $docs[(int) $id] = $l;
        }
        $docs[self::Q] = [
            'title' => (string) ($query['title'] ?? ''),
            'description' => (string) ($query['description'] ?? ''),
            'category' => (string) ($query['category'] ?? ''),
            'price' => 0,
        ];
        return $docs;
    }
}
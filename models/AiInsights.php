<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Recommender.php';
require_once __DIR__ . '/PriceModel.php';
require_once __DIR__ . '/RiskModel.php';


class AiInsights
{
    private PDO $db;
    private ?array $cache = null;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /** id => row (title, description, category, price, user_id, status, created_at) */
    public function listings(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            try {
                $rows = $this->db->query('SELECT id, user_id, title, description, category, price, status, created_at FROM listings')->fetchAll();
                foreach ($rows as $r) {
                    $this->cache[(int) $r['id']] = $r;
                }
            } catch (Throwable $e) {
                $this->cache = [];
            }
        }
        return $this->cache;
    }

    /** Price range and category hint for text typed on the create-listing form. */
    public function assist(string $title, string $description, string $category, ?float $price): array
    {
        $q = ['title' => $title, 'description' => $description, 'category' => $category];
        $all = $this->listings();
        $hint = PriceModel::hint($q, $all);

        $out = ['price' => null, 'assessment' => 'unknown', 'category' => null, 'warnings' => []];
        if ($hint !== null) {
            $out['price'] = [
                'low' => $hint['low'], 'mid' => $hint['mid'], 'high' => $hint['high'],
                'n' => $hint['n'], 'confidence' => $hint['confidence'],
            ];
            if ($price !== null && $price > 0) {
                $out['assessment'] = PriceModel::assess($price, $hint);
            }
        }
        $cat = PriceModel::suggestCategory($q, $all);
        if ($cat !== null && strcasecmp($cat['name'], $category) !== 0) {
            $out['category'] = $cat;
        }
        $terms = RiskModel::suspiciousTerms($title . ' ' . $description);
        if ($terms) {
            $out['warnings'][] = 'Wording like "' . $terms[0] . '" is often used in scams. TrustTrade keeps payment and chat on the site, so you do not need it.';
        }
        return $out;
    }

    /** Price check of one listing against similar listings (excluding itself), for the buyer's view. */
    public function priceNote(int $id): ?array
    {
        $all = $this->listings();
        if (!isset($all[$id])) {
            return null;
        }
        $l = $all[$id];
        $hint = PriceModel::hint(['title' => $l['title'], 'description' => $l['description'], 'category' => $l['category']], $all, $id);
        if ($hint === null) {
            return null;
        }
        return [
            'assessment' => PriceModel::assess((float) $l['price'], $hint),
            'low' => $hint['low'], 'high' => $hint['high'], 'n' => $hint['n'],
        ];
    }

    /**
     * Risk for many listings at once (admin views). Returns listingId => ['score','level','factors'].
     * @param int[] $ids
     */
    public function riskFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $all = $this->listings();
        $vectors = Recommender::buildVectors($all);

        $sellerIds = [];
        foreach ($ids as $id) {
            if (isset($all[$id])) {
                $sellerIds[(int) $all[$id]['user_id']] = true;
            }
        }
        $sellerIds = array_keys($sellerIds);
        $age = $this->sellerAges($sellerIds);
        $refunds = $this->sellerCounts("SELECT seller_id AS k, COUNT(*) AS c FROM orders WHERE payment_status = 'refunded' GROUP BY seller_id");
        $disputes = $this->sellerCounts('SELECT o.seller_id AS k, COUNT(*) AS c FROM disputes d JOIN orders o ON o.id = d.order_id GROUP BY o.seller_id');
        $done = $this->sellerCounts("SELECT seller_id AS k, COUNT(*) AS c FROM orders WHERE status = 'completed' GROUP BY seller_id");
        $reports = $this->sellerCounts('SELECT listing_id AS k, COUNT(*) AS c FROM reports WHERE listing_id IS NOT NULL GROUP BY listing_id');

        $out = [];
        foreach ($ids as $id) {
            if (!isset($all[$id])) {
                continue;
            }
            $l = $all[$id];
            $seller = (int) $l['user_id'];
            $hint = PriceModel::fromVectors($vectors, $vectors[$id] ?? [], $all, $id);
            $x = RiskModel::features([
                'price_ratio'      => PriceModel::ratio((float) $l['price'], $hint),
                'account_age_days' => $age[$seller] ?? 999,
                'reports'          => $reports[$id] ?? 0,
                'refunds'          => $refunds[$seller] ?? 0,
                'disputes'         => $disputes[$seller] ?? 0,
                'completed'        => $done[$seller] ?? 0,
                'text'             => $l['title'] . ' ' . $l['description'],
            ]);
            $out[$id] = RiskModel::score($x);
        }
        return $out;
    }

    private function sellerAges(array $ids): array
    {
        $ages = [];
        if (!$ids) {
            return $ages;
        }
        try {
            $in = implode(',', array_map('intval', $ids));
            $rows = $this->db->query("SELECT id, DATEDIFF(NOW(), created_at) AS d FROM users WHERE id IN ($in)")->fetchAll();
            foreach ($rows as $r) {
                if ($r['d'] !== null) {
                    $ages[(int) $r['id']] = max(0, (int) $r['d']);
                }
            }
        } catch (Throwable $e) {
            // no created_at on users: the account-age signal is simply off
        }
        return $ages;
    }

    private function sellerCounts(string $sql): array
    {
        $out = [];
        try {
            foreach ($this->db->query($sql)->fetchAll() as $r) {
                $out[(int) $r['k']] = (int) $r['c'];
            }
        } catch (Throwable $e) {
            // table or column missing: this signal is off
        }
        return $out;
    }
}
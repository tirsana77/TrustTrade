<?php
/**
 * Scam-risk score for a listing (pure PHP, no database calls).
 *
 * A logistic-regression model: risk = sigmoid(bias + sum(weight_i * feature_i)), each feature scaled to 0..1.
 * The starting weights are expert priors chosen for a student marketplace; train() can re-fit them by
 * gradient descent when labelled outcomes (for example listings that administrators removed) are available.
 * Every score comes with the factors that pushed it up, so an administrator can see why.
 */
class RiskModel
{
    public const FEATURES = ['underpriced', 'new_account', 'reported', 'seller_refunds', 'suspicious_text', 'seller_disputes', 'track_record'];

    public const BIAS = -3.0;
    public const WEIGHTS = [
        'underpriced'     => 3.2,
        'new_account'     => 1.6,
        'reported'        => 2.4,
        'seller_refunds'  => 2.2,
        'suspicious_text' => 2.6,
        'seller_disputes' => 1.4,
        'track_record'    => -2.0,
    ];
    public const LABELS = [
        'underpriced'     => 'Priced far below similar listings',
        'new_account'     => 'Seller account is new',
        'reported'        => 'Listing has been reported',
        'seller_refunds'  => 'Seller has had payments refunded',
        'suspicious_text' => 'Wording often used in scams',
        'seller_disputes' => 'Seller has had disputes',
        'track_record'    => 'Seller has completed sales',
    ];

    private const SCAM_TERMS = [
        'western union', 'moneygram', 'wire transfer', 'gift card', 'giftcard', 'paypal friends',
        'whatsapp', 'telegram', 'pay first', 'send money', 'advance payment', 'pay in advance',
        'outside the site', 'outside trusttrade', 'bank transfer only', 'deposit first', 'urgent', 'mobilepay me',
    ];

    public static function suspiciousTerms(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $found = [];
        foreach (self::SCAM_TERMS as $term) {
            if (mb_strpos($text, $term) !== false) {
                $found[] = $term;
            }
        }
        return $found;
    }

    /**
     * Raw inputs to features on a 0..1 scale.
     * @param array $in price_ratio (?float), account_age_days, reports, refunds, disputes, completed, text
     */
    public static function features(array $in): array
    {
        $ratio = $in['price_ratio'] ?? null;
        return [
            'underpriced'     => ($ratio !== null && $ratio < 0.6) ? min(1.0, (0.6 - $ratio) / 0.6) : 0.0,
            'new_account'     => max(0.0, 1.0 - ((float) ($in['account_age_days'] ?? 999)) / 14.0),
            'reported'        => min((int) ($in['reports'] ?? 0), 3) / 3,
            'seller_refunds'  => min((int) ($in['refunds'] ?? 0), 2) / 2,
            'suspicious_text' => min(count(self::suspiciousTerms((string) ($in['text'] ?? ''))), 3) / 3,
            'seller_disputes' => min((int) ($in['disputes'] ?? 0), 3) / 3,
            'track_record'    => min((int) ($in['completed'] ?? 0), 5) / 5,
        ];
    }

    /** @return array ['score' => 0..100, 'level' => low|medium|high, 'factors' => [['feature','label','points']...]] */
    public static function score(array $x, ?array $weights = null, ?float $bias = null): array
    {
        $w = $weights ?? self::WEIGHTS;
        $z = $bias ?? self::BIAS;
        $factors = [];
        foreach (self::FEATURES as $f) {
            $c = ($w[$f] ?? 0.0) * ($x[$f] ?? 0.0);
            $z += $c;
            if (abs($c) >= 0.15) {
                $factors[] = ['feature' => $f, 'label' => self::LABELS[$f], 'points' => round($c, 2)];
            }
        }
        usort($factors, fn($a, $b) => $b['points'] <=> $a['points']);
        $score = (int) round(100 / (1 + exp(-$z)));
        return [
            'score'   => $score,
            'level'   => $score >= 60 ? 'high' : ($score >= 25 ? 'medium' : 'low'),
            'factors' => $factors,
        ];
    }

    /**
     * Fit the weights by gradient descent on labelled examples.
     * @param array $examples [[feature array, label 0|1], ...]
     * @return array ['weights' => [...], 'bias' => float]
     */
    public static function train(array $examples, int $epochs = 600, float $lr = 0.5, float $l2 = 0.01): array
    {
        $w = array_fill_keys(self::FEATURES, 0.0);
        $b = 0.0;
        $n = max(1, count($examples));
        for ($e = 0; $e < $epochs; $e++) {
            $gw = array_fill_keys(self::FEATURES, 0.0);
            $gb = 0.0;
            foreach ($examples as [$x, $y]) {
                $z = $b;
                foreach (self::FEATURES as $f) {
                    $z += $w[$f] * ($x[$f] ?? 0.0);
                }
                $err = 1 / (1 + exp(-$z)) - $y;
                foreach (self::FEATURES as $f) {
                    $gw[$f] += $err * ($x[$f] ?? 0.0);
                }
                $gb += $err;
            }
            foreach (self::FEATURES as $f) {
                $w[$f] -= $lr * ($gw[$f] / $n + $l2 * $w[$f]);
            }
            $b -= $lr * $gb / $n;
        }
        return ['weights' => $w, 'bias' => $b];
    }
}
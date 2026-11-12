<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Notification.php';
require_once __DIR__ . '/AiInsights.php';


class Automation
{
    public const RELEASE_DAYS       = 7;   
    public const DISPUTE_REPLY_DAYS = 3;   
    public const REMINDER_HOURS     = 24;  
    public const CANCEL_DAYS        = 3;   
    public const RISK_HOLD_SCORE    = 60;  
    public const REPORT_HOLD_COUNT  = 3;   
    public const TRUSTED_SALES      = 5;   
    public const REVIEW_REFUNDS     = 2;   

    private PDO $db;
    private Notification $notify;
    
    private array $scope;

    public function __construct(array $scopeUserIds = [])
    {
        $this->db = (new Database())->getConnection();
        $this->notify = new Notification();
        $this->scope = array_map('intval', $scopeUserIds);
    }

    private function inList(): string
    {
        return implode(',', $this->scope);
    }

    /** SQL to limit an orders alias to the scope. */
    private function orderScope(string $a): string
    {
        return $this->scope ? " AND ($a.buyer_id IN ({$this->inList()}) OR $a.seller_id IN ({$this->inList()}))" : '';
    }

    /** SQL to limit a listings alias to the scope. */
    private function listingScope(string $a): string
    {
        return $this->scope ? " AND $a.user_id IN ({$this->inList()})" : '';
    }

    /** The database clock, so deadlines and checks use one time base. */
    public function dbNow(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->db->query('SELECT NOW()')->fetchColumn());
    }

    private static function f(DateTimeImmutable $t): string
    {
        return $t->format('Y-m-d H:i:s');
    }

    /** Pure rule: should a listing with this AI risk score be held? */
    public static function shouldHoldForRisk(int $score): bool
    {
        return $score >= self::RISK_HOLD_SCORE;
    }

    /** Pure rule: should a listing with this many different reporters be held? */
    public static function shouldHoldForReports(int $reporters): bool
    {
        return $reporters >= self::REPORT_HOLD_COUNT;
    }

    /** Run all rules once. Returns how many rows each rule changed. */
    public function run(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? $this->dbNow();
        $out = [];
        foreach ([
            'released'  => fn() => $this->autoRelease($now),
            'refunded'  => fn() => $this->autoRefundSilentSellers($now),
            'reminded'  => fn() => $this->remindUnpaid($now),
            'cancelled' => fn() => $this->cancelUnpaid($now),
            'held'      => fn() => $this->holdListings($now),
            'flagged'   => fn() => $this->flagSellers(),
        ] as $name => $rule) {
            try {
                $out[$name] = $rule();
            } catch (Throwable $e) {
                $out[$name] = 0;   // one failing rule never stops the others or the page
            }
        }
        return $out;
    }

    /** Run at most once per minute, triggered by page views (XAMPP has no scheduler). */
    public static function runIfDue(int $seconds = 60): void
    {
        $file = __DIR__ . '/../storage/automation.last';
        $last = is_file($file) ? (int) @filemtime($file) : 0;
        if (time() - $last < $seconds) {
            return;
        }
        @touch($file);
        try {
            (new self())->run();
        } catch (Throwable $e) {
        }
    }

    private function event(int $orderId, string $text): void
    {
        try {
            $this->db->prepare('INSERT INTO order_events (order_id, event, is_automatic) VALUES (:o, :e, 1)')
                ->execute(['o' => $orderId, 'e' => mb_substr($text, 0, 255)]);
        } catch (Throwable $e) {
        }
    }

    // ---- 1. Payment held for 7 days with no dispute: release to the seller ----
    public function autoRelease(DateTimeImmutable $now): int
    {
        $sel = $this->db->prepare(
            "SELECT o.id, o.buyer_id, o.seller_id, o.listing_id, l.title
             FROM orders o JOIN listings l ON l.id = o.listing_id
             WHERE o.payment_status = 'held' AND o.status = 'accepted'
               AND o.auto_release_at IS NOT NULL AND o.auto_release_at <= :now
               AND NOT EXISTS (SELECT 1 FROM disputes d WHERE d.order_id = o.id AND d.status = 'open')"
            . $this->orderScope('o')
        );
        $sel->execute(['now' => self::f($now)]);
        $n = 0;
        foreach ($sel->fetchAll() as $o) {
            $upd = $this->db->prepare(
                "UPDATE orders SET payment_status = 'released', status = 'completed'
                 WHERE id = :id AND payment_status = 'held' AND status = 'accepted'
                   AND auto_release_at IS NOT NULL AND auto_release_at <= :now
                   AND NOT EXISTS (SELECT 1 FROM disputes d WHERE d.order_id = orders.id AND d.status = 'open')"
            );
            $upd->execute(['id' => $o['id'], 'now' => self::f($now)]);
            if ($upd->rowCount() > 0) {
                $this->db->prepare("UPDATE listings SET status = 'sold' WHERE id = :id AND status = 'active'")
                    ->execute(['id' => $o['listing_id']]);
                $t = '"' . $o['title'] . '"';
                $this->notify->add((int) $o['buyer_id'], 'Automatic: you did not confirm or report a problem within ' . self::RELEASE_DAYS . ' days, so the payment for ' . $t . ' was released to the seller.', 'orders.php');
                $this->notify->add((int) $o['seller_id'], 'Automatic: the payment for ' . $t . ' was released to you after ' . self::RELEASE_DAYS . ' days without a problem report.', 'orders.php');
                $this->event((int) $o['id'], 'Automatic: payment released to the seller after ' . self::RELEASE_DAYS . ' days with no confirmation or dispute');
                $n++;
            }
        }
        return $n;
    }

    // ---- 2. Seller did not answer a buyer's dispute in time: refund the buyer ----
    public function autoRefundSilentSellers(DateTimeImmutable $now): int
    {
        $sel = $this->db->prepare(
            "SELECT d.id AS dispute_id, o.id AS order_id, o.buyer_id, o.seller_id, l.title
             FROM disputes d
             JOIN orders o ON o.id = d.order_id
             JOIN listings l ON l.id = o.listing_id
             WHERE d.status = 'open' AND d.seller_deadline IS NOT NULL AND d.seller_deadline <= :now
               AND d.seller_replied_at IS NULL AND o.payment_status = 'held'"
            . $this->orderScope('o')
        );
        $sel->execute(['now' => self::f($now)]);
        $n = 0;
        foreach ($sel->fetchAll() as $d) {
            $upd = $this->db->prepare(
                "UPDATE orders SET payment_status = 'refunded', status = 'cancelled'
                 WHERE id = :id AND payment_status = 'held'
                   AND EXISTS (SELECT 1 FROM disputes d WHERE d.id = :did AND d.status = 'open' AND d.seller_replied_at IS NULL)"
            );
            $upd->execute(['id' => $d['order_id'], 'did' => $d['dispute_id']]);
            if ($upd->rowCount() > 0) {
                $note = 'Automatic: the seller did not reply within ' . self::DISPUTE_REPLY_DAYS . ' days. Payment refunded to the buyer (simulated).';
                $this->db->prepare("UPDATE disputes SET status = 'resolved', resolution_note = :n WHERE id = :id AND status = 'open'")
                    ->execute(['n' => $note, 'id' => $d['dispute_id']]);
                $t = '"' . $d['title'] . '"';
                $this->notify->add((int) $d['buyer_id'], 'Automatic: the seller did not reply to your problem report on ' . $t . ' in time, so your payment was refunded.', 'orders.php');
                $this->notify->add((int) $d['seller_id'], 'Automatic: you did not reply to the problem report on ' . $t . ' in time, so the buyer was refunded.', 'orders.php');
                $this->event((int) $d['order_id'], $note);
                $n++;
            }
        }
        return $n;
    }

    // ---- 3. Accepted but unpaid: reminder after 24 hours ----
    public function remindUnpaid(DateTimeImmutable $now): int
    {
        $limit = $now->modify('-' . self::REMINDER_HOURS . ' hours');
        $sel = $this->db->prepare(
            "SELECT o.id, o.buyer_id, o.accepted_at, l.title
             FROM orders o JOIN listings l ON l.id = o.listing_id
             WHERE o.type = 'buy' AND o.status = 'accepted' AND o.payment_status = 'unpaid'
               AND o.accepted_at IS NOT NULL AND o.accepted_at <= :limit AND o.reminder_sent_at IS NULL"
            . $this->orderScope('o')
        );
        $sel->execute(['limit' => self::f($limit)]);
        $n = 0;
        foreach ($sel->fetchAll() as $o) {
            $upd = $this->db->prepare(
                "UPDATE orders SET reminder_sent_at = :now
                 WHERE id = :id AND reminder_sent_at IS NULL AND status = 'accepted' AND payment_status = 'unpaid'"
            );
            $upd->execute(['now' => self::f($now), 'id' => $o['id']]);
            if ($upd->rowCount() > 0) {
                $cancelAt = (new DateTimeImmutable($o['accepted_at']))->modify('+' . self::CANCEL_DAYS . ' days');
                $this->notify->add((int) $o['buyer_id'], 'Reminder: pay for "' . $o['title'] . '" by ' . $cancelAt->format('j M H:i') . ', or the request is cancelled automatically.', 'orders.php');
                $this->event((int) $o['id'], 'Automatic: reminder sent to the buyer to pay');
                $n++;
            }
        }
        return $n;
    }

    // ---- 4. Accepted but unpaid for 3 days: cancel ----
    public function cancelUnpaid(DateTimeImmutable $now): int
    {
        $limit = $now->modify('-' . self::CANCEL_DAYS . ' days');
        $sel = $this->db->prepare(
            "SELECT o.id, o.buyer_id, o.seller_id, l.title
             FROM orders o JOIN listings l ON l.id = o.listing_id
             WHERE o.type = 'buy' AND o.status = 'accepted' AND o.payment_status = 'unpaid'
               AND o.accepted_at IS NOT NULL AND o.accepted_at <= :limit"
            . $this->orderScope('o')
        );
        $sel->execute(['limit' => self::f($limit)]);
        $n = 0;
        foreach ($sel->fetchAll() as $o) {
            $upd = $this->db->prepare(
                "UPDATE orders SET status = 'cancelled'
                 WHERE id = :id AND status = 'accepted' AND payment_status = 'unpaid' AND accepted_at <= :limit"
            );
            $upd->execute(['id' => $o['id'], 'limit' => self::f($limit)]);
            if ($upd->rowCount() > 0) {
                $t = '"' . $o['title'] . '"';
                $this->notify->add((int) $o['buyer_id'], 'Automatic: the request for ' . $t . ' was cancelled because it was not paid within ' . self::CANCEL_DAYS . ' days.', 'orders.php');
                $this->notify->add((int) $o['seller_id'], 'Automatic: the request for ' . $t . ' was cancelled because the buyer did not pay in time. Your listing is available again.', 'orders.php');
                $this->event((int) $o['id'], 'Automatic: cancelled because it was not paid within ' . self::CANCEL_DAYS . ' days');
                $n++;
            }
        }
        return $n;
    }

    // ---- 5. Hold listings: high AI risk score, or reported by 3 different students ----
    public function holdListings(DateTimeImmutable $now): int
    {
        $n = 0;
        $ids = $this->db->query("SELECT id FROM listings l WHERE l.status = 'active' AND l.reviewed_ok = 0" . $this->listingScope('l'))->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $risk = (new AiInsights())->riskFor(array_map('intval', $ids));
            foreach ($risk as $id => $r) {
                if (self::shouldHoldForRisk((int) $r['score']) && $this->hold((int) $id, $this->riskReason($r), $now)) {
                    $n++;
                }
            }
        }
        $rows = $this->db->query(
            "SELECT r.listing_id, COUNT(DISTINCT r.reporter_id) AS c
             FROM reports r JOIN listings l ON l.id = r.listing_id
             WHERE r.status = 'open' AND r.listing_id IS NOT NULL AND l.status = 'active' AND l.reviewed_ok = 0"
            . $this->listingScope('l') . "
             GROUP BY r.listing_id"
        )->fetchAll();
        foreach ($rows as $r) {
            if (self::shouldHoldForReports((int) $r['c']) && $this->hold((int) $r['listing_id'], (int) $r['c'] . ' different students reported this listing', $now)) {
                $n++;
            }
        }
        return $n;
    }

    /** Check one listing straight after it is created or reported. Returns the hold reason, or null. */
    public function checkListing(int $listingId): ?string
    {
        $now = $this->dbNow();
        $st = $this->db->prepare("SELECT status, reviewed_ok FROM listings WHERE id = :id");
        $st->execute(['id' => $listingId]);
        $l = $st->fetch();
        if (!$l || $l['status'] !== 'active' || (int) $l['reviewed_ok'] === 1) {
            return null;
        }
        $risk = (new AiInsights())->riskFor([$listingId])[$listingId] ?? null;
        if ($risk && self::shouldHoldForRisk((int) $risk['score'])) {
            $reason = $this->riskReason($risk);
            return $this->hold($listingId, $reason, $now) ? $reason : null;
        }
        $st = $this->db->prepare("SELECT COUNT(DISTINCT reporter_id) FROM reports WHERE listing_id = :id AND status = 'open'");
        $st->execute(['id' => $listingId]);
        $c = (int) $st->fetchColumn();
        if (self::shouldHoldForReports($c)) {
            $reason = $c . ' different students reported this listing';
            return $this->hold($listingId, $reason, $now) ? $reason : null;
        }
        return null;
    }

    private function riskReason(array $r): string
    {
        $labels = [];
        foreach ($r['factors'] as $f) {
            if ($f['points'] > 0) {
                $labels[] = $f['label'];
            }
        }
        return 'AI risk score ' . (int) $r['score'] . ($labels ? ': ' . implode('; ', array_slice($labels, 0, 3)) : '');
    }

    private function hold(int $listingId, string $reason, DateTimeImmutable $now): bool
    {
        $upd = $this->db->prepare(
            "UPDATE listings SET status = 'held', hold_reason = :r, held_at = :t
             WHERE id = :id AND status = 'active' AND reviewed_ok = 0"
        );
        $upd->execute(['r' => mb_substr($reason, 0, 255), 't' => self::f($now), 'id' => $listingId]);
        if ($upd->rowCount() === 0) {
            return false;
        }
        $st = $this->db->prepare('SELECT user_id, title FROM listings WHERE id = :id');
        $st->execute(['id' => $listingId]);
        if ($l = $st->fetch()) {
            $this->notify->add((int) $l['user_id'], 'Automatic: your listing "' . $l['title'] . '" was put under review (' . $reason . '). It is hidden until an administrator checks it.', 'my_listings.php');
        }
        return true;
    }

    /** Administrator decision: put a held listing back. It is marked as reviewed so the rules do not hold it again. */
    public function restoreListing(int $listingId): bool
    {
        $upd = $this->db->prepare(
            "UPDATE listings SET status = 'active', hold_reason = NULL, held_at = NULL, reviewed_ok = 1
             WHERE id = :id AND status = 'held'"
        );
        $upd->execute(['id' => $listingId]);
        if ($upd->rowCount() === 0) {
            return false;
        }
        $st = $this->db->prepare('SELECT user_id, title FROM listings WHERE id = :id');
        $st->execute(['id' => $listingId]);
        if ($l = $st->fetch()) {
            $this->notify->add((int) $l['user_id'], 'An administrator checked your listing "' . $l['title'] . '" and it is visible again.', 'my_listings.php');
        }
        return true;
    }

    // ---- 6. Seller with 2 refunded orders: flag the account for review ----
    public function flagSellers(): int
    {
        $rows = $this->db->query(
            "SELECT o.seller_id, COUNT(*) AS c
             FROM orders o JOIN users u ON u.id = o.seller_id
             WHERE o.payment_status = 'refunded' AND u.needs_review = 0
               AND EXISTS (SELECT 1 FROM disputes d WHERE d.order_id = o.id)"
            . $this->orderScope('o') . "
             GROUP BY o.seller_id"
        )->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            if ((int) $r['c'] < self::REVIEW_REFUNDS) {
                continue;
            }
            $upd = $this->db->prepare(
                'UPDATE users SET needs_review = 1, review_reason = :r WHERE id = :id AND needs_review = 0'
            );
            $upd->execute(['r' => (int) $r['c'] . ' orders refunded after disputes', 'id' => $r['seller_id']]);
            if ($upd->rowCount() > 0) {
                $this->notify->add((int) $r['seller_id'], 'Automatic: your account is under review because ' . (int) $r['c'] . ' of your orders were refunded after disputes.', 'profile.php');
                $n++;
            }
        }
        return $n;
    }

    /** Public seller record shown on listings. level: trusted | standard | new | review */
    public function sellerStats(int $userId): array
    {
        $st = $this->db->prepare(
            "SELECT
               (SELECT COUNT(*) FROM orders WHERE seller_id = :a AND status = 'completed') AS completed,
               (SELECT COUNT(*) FROM orders o WHERE o.seller_id = :b AND o.payment_status = 'refunded'
                  AND EXISTS (SELECT 1 FROM disputes d WHERE d.order_id = o.id)) AS refunded"
        );
        $st->execute(['a' => $userId, 'b' => $userId]);
        $s = $st->fetch() ?: ['completed' => 0, 'refunded' => 0];
        $u = $this->db->prepare('SELECT created_at, needs_review FROM users WHERE id = :id');
        $u->execute(['id' => $userId]);
        $user = $u->fetch() ?: ['created_at' => null, 'needs_review' => 0];

        $completed = (int) $s['completed'];
        $refunded  = (int) $s['refunded'];
        if ((int) $user['needs_review'] === 1) {
            $level = 'review';
        } elseif ($completed >= self::TRUSTED_SALES && $refunded === 0) {
            $level = 'trusted';
        } elseif ($completed === 0 && $refunded === 0) {
            $level = 'new';
        } else {
            $level = 'standard';
        }
        $labels = ['trusted' => 'Trusted seller', 'standard' => 'Seller', 'new' => 'New seller', 'review' => 'Under review'];
        return [
            'completed' => $completed,
            'refunded'  => $refunded,
            'since'     => $user['created_at'],
            'level'     => $level,
            'label'     => $labels[$level],
        ];
    }
}
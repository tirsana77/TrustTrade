<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Automation.php';

class Order
{
    /** Payment methods offered in the (simulated) checkout. */
    public const PAYMENT_METHODS = ['card', 'mobilepay'];

    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function create(int $listingId, int $buyerId, int $sellerId, string $type, ?string $offerText): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (listing_id, buyer_id, seller_id, type, offer_text)
             VALUES (:listing_id, :buyer_id, :seller_id, :type, :offer_text)'
        );
        $stmt->execute([
            'listing_id' => $listingId,
            'buyer_id'   => $buyerId,
            'seller_id'  => $sellerId,
            'type'       => $type,
            'offer_text' => $offerText,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** True if this buyer already has a pending or accepted request on this listing. */
    public function hasOpenRequest(int $listingId, int $buyerId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE listing_id = :listing_id AND buyer_id = :buyer_id AND status IN ('pending','accepted')"
        );
        $stmt->execute(['listing_id' => $listingId, 'buyer_id' => $buyerId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, l.title AS listing_title, b.full_name AS buyer_name, s.full_name AS seller_name
             FROM orders o
             JOIN listings l ON l.id = o.listing_id
             JOIN users b ON b.id = o.buyer_id
             JOIN users s ON s.id = o.seller_id
             WHERE o.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    public function forBuyer(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, l.title AS listing_title, l.price AS listing_price, s.full_name AS other_name,
                    (SELECT COUNT(*) FROM disputes d WHERE d.order_id = o.id) AS dispute_count
             FROM orders o
             JOIN listings l ON l.id = o.listing_id
             JOIN users s ON s.id = o.seller_id
             WHERE o.buyer_id = :user_id
             ORDER BY o.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function forSeller(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, l.title AS listing_title, l.price AS listing_price, b.full_name AS other_name,
                    (SELECT COUNT(*) FROM disputes d WHERE d.order_id = o.id) AS dispute_count
             FROM orders o
             JOIN listings l ON l.id = o.listing_id
             JOIN users b ON b.id = o.buyer_id
             WHERE o.seller_id = :user_id
             ORDER BY o.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function updateStatus(int $id, string $status): void
    {
        $allowed = ['pending', 'accepted', 'declined', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return;
        }
        if ($status === 'accepted') {
            // the acceptance time starts the automatic payment reminder and cancellation
            $stmt = $this->db->prepare('UPDATE orders SET status = :status, accepted_at = :t WHERE id = :id');
            $stmt->execute(['status' => $status, 't' => $this->dbNow()->format('Y-m-d H:i:s'), 'id' => $id]);
            return;
        }
        $stmt = $this->db->prepare('UPDATE orders SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /** The database clock, so every deadline uses the same time base. */
    public function dbNow(): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $this->db->query('SELECT NOW()')->fetchColumn());
    }

    /** Add a line to the order timeline. Never breaks the action that called it. */
    public function addEvent(int $orderId, string $text, bool $automatic = false): void
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO order_events (order_id, event, is_automatic) VALUES (:o, :e, :a)');
            $stmt->execute(['o' => $orderId, 'e' => mb_substr($text, 0, 255), 'a' => $automatic ? 1 : 0]);
        } catch (PDOException $e) {
        }
    }

    public function events(int $orderId): array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM order_events WHERE order_id = :o ORDER BY created_at ASC, id ASC');
            $stmt->execute(['o' => $orderId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    
    public function pay(int $orderId, int $buyerId, string $method, ?string $ref = null): bool
    {
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT l.price
             FROM orders o JOIN listings l ON l.id = o.listing_id
             WHERE o.id = :id AND o.buyer_id = :buyer AND o.type = 'buy'
               AND o.status = 'accepted' AND o.payment_status = 'unpaid'
             LIMIT 1"
        );
        $stmt->execute(['id' => $orderId, 'buyer' => $buyerId]);
        $price = $stmt->fetchColumn();
        if ($price === false) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE orders
             SET payment_status = 'held', payment_method = :method, payment_amount = :amount,
                 payment_ref = :ref, paid_at = CURRENT_TIMESTAMP, auto_release_at = :release
             WHERE id = :id AND buyer_id = :buyer AND type = 'buy'
               AND status = 'accepted' AND payment_status = 'unpaid'"
        );
        $stmt->execute([
            'method' => $method,
            'amount' => $price,
            'ref'    => $ref ?? ('SIM-' . strtoupper(bin2hex(random_bytes(4)))),
            'release' => $this->dbNow()->modify('+' . Automation::RELEASE_DAYS . ' days')->format('Y-m-d H:i:s'),
            'id'     => $orderId,
            'buyer'  => $buyerId,
        ]);
        return $stmt->rowCount() > 0;
    }

    
    public function releasePayment(int $orderId, ?int $buyerId = null): bool
    {
        $sql = "UPDATE orders SET payment_status = 'released', status = 'completed'
                WHERE id = :id AND payment_status = 'held'";
        $params = ['id' => $orderId];
        if ($buyerId !== null) {
            $sql .= ' AND buyer_id = :buyer';
            $params['buyer'] = $buyerId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Administrator refunds held money to the buyer and cancels the order. */
    public function refundPayment(int $orderId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET payment_status = 'refunded', status = 'cancelled'
             WHERE id = :id AND payment_status = 'held'"
        );
        $stmt->execute(['id' => $orderId]);
        return $stmt->rowCount() > 0;
    }

    /** The newest order for one listing between two people (either way round). Used by the chat. */
    public function pairOrder(int $listingId, int $userA, int $userB): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, l.title AS listing_title, l.price AS listing_price
             FROM orders o JOIN listings l ON l.id = o.listing_id
             WHERE o.listing_id = :l
               AND ((o.buyer_id = :a1 AND o.seller_id = :b1) OR (o.buyer_id = :b2 AND o.seller_id = :a2))
             ORDER BY o.id DESC LIMIT 1'
        );
        $stmt->execute(['l' => $listingId, 'a1' => $userA, 'b1' => $userB, 'a2' => $userA, 'b2' => $userB]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Save a delivery suggestion. The other person must accept it before it counts as agreed. */
    public function proposeDelivery(int $id, string $method, string $place, ?string $time, string $note, int $byUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET delivery_method = :m, delivery_place = :p, delivery_time = :t, delivery_note = :n,
                    delivery_status = 'proposed', delivery_by = :by, tracking_ref = NULL
             WHERE id = :id"
        );
        $stmt->execute(['m' => $method, 'p' => $place, 't' => $time, 'n' => $note, 'by' => $byUserId, 'id' => $id]);
    }

    public function agreeDelivery(int $id): void
    {
        $this->db->prepare("UPDATE orders SET delivery_status = 'agreed' WHERE id = :id AND delivery_status = 'proposed'")
                 ->execute(['id' => $id]);
    }

    public function markShipped(int $id, string $tracking): void
    {
        $this->db->prepare("UPDATE orders SET delivery_status = 'shipped', tracking_ref = :t WHERE id = :id AND delivery_status = 'agreed'")
                 ->execute(['t' => $tracking !== '' ? $tracking : null, 'id' => $id]);
    }
}
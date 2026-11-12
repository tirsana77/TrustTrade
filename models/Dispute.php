<?php
require_once __DIR__ . '/../config/database.php';

class Dispute
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /** $sellerDeadline is set when a buyer raises a dispute on a held payment: the seller must reply by then. */
    public function create(int $orderId, int $raisedBy, string $description, ?string $sellerDeadline = null, ?string $evidencePhoto = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO disputes (order_id, raised_by, description, seller_deadline, evidence_photo)
             VALUES (:order_id, :raised_by, :description, :deadline, :photo)'
        );
        $stmt->execute([
            'order_id' => $orderId, 'raised_by' => $raisedBy, 'description' => $description, 'deadline' => $sellerDeadline, 'photo' => $evidencePhoto,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** The first dispute of an order, or null. */
    public function forOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM disputes WHERE order_id = :o ORDER BY id ASC LIMIT 1');
        $stmt->execute(['o' => $orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** The seller answers an open dispute on their own order. Stops the automatic refund. */
    public function sellerReply(int $disputeId, int $sellerId, string $text, string $now): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE disputes SET seller_reply = :t, seller_replied_at = :now
             WHERE id = :id AND status = 'open' AND seller_replied_at IS NULL
               AND order_id IN (SELECT id FROM orders WHERE seller_id = :s)"
        );
        $stmt->execute(['t' => $text, 'now' => $now, 'id' => $disputeId, 's' => $sellerId]);
        return $stmt->rowCount() > 0;
    }

    public function existsForOrder(int $orderId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM disputes WHERE order_id = :order_id');
        $stmt->execute(['order_id' => $orderId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** One dispute with the order it belongs to (used by the admin payment decisions). */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, o.listing_id, o.payment_status
             FROM disputes d
             JOIN orders o ON o.id = d.order_id
             WHERE d.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listByStatus(string $status = 'open'): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, o.type, o.status AS order_status, o.payment_status, o.payment_method, o.payment_amount,
                    l.title AS listing_title,
                    rb.full_name AS raised_by_name, b.full_name AS buyer_name, s.full_name AS seller_name
             FROM disputes d
             JOIN orders o ON o.id = d.order_id
             JOIN listings l ON l.id = o.listing_id
             JOIN users rb ON rb.id = d.raised_by
             JOIN users b ON b.id = o.buyer_id
             JOIN users s ON s.id = o.seller_id
             WHERE d.status = :status
             ORDER BY d.created_at ASC'
        );
        $stmt->execute(['status' => $status]);
        return $stmt->fetchAll();
    }

    public function resolve(int $id, string $status, string $note): void
    {
        if (!in_array($status, ['resolved', 'dismissed'], true)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE disputes SET status = :status, resolution_note = :note WHERE id = :id');
        $stmt->execute(['status' => $status, 'note' => $note, 'id' => $id]);
    }
}
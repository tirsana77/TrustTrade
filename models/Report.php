<?php
require_once __DIR__ . '/../config/database.php';

class Report
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function create(int $reporterId, ?int $listingId, ?int $reportedUserId, string $reason): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO reports (reporter_id, listing_id, reported_user_id, reason)
             VALUES (:reporter_id, :listing_id, :reported_user_id, :reason)'
        );
        $stmt->execute([
            'reporter_id'      => $reporterId,
            'listing_id'       => $listingId,
            'reported_user_id' => $reportedUserId,
            'reason'           => $reason,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listByStatus(string $status = 'open'): array
    {
        $stmt = $this->db->prepare(
            'SELECT r.*, rep.full_name AS reporter_name, l.title AS listing_title, ru.full_name AS reported_name
             FROM reports r
             JOIN users rep ON rep.id = r.reporter_id
             LEFT JOIN listings l ON l.id = r.listing_id
             LEFT JOIN users ru ON ru.id = r.reported_user_id
             WHERE r.status = :status
             ORDER BY r.created_at ASC'
        );
        $stmt->execute(['status' => $status]);
        return $stmt->fetchAll();
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['open', 'resolved', 'dismissed'], true)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE reports SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }
}
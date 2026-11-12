<?php
require_once __DIR__ . '/../config/database.php';

/** In-app notifications. Failures never break the page that triggered them. */
class Notification
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function add(int $userId, string $message, ?string $link = null): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO notifications (user_id, message, link) VALUES (:u, :m, :l)'
            );
            $stmt->execute(['u' => $userId, 'm' => mb_substr($message, 0, 255), 'l' => $link]);
        } catch (PDOException $e) {
            // table missing or similar: do not break the main action
        }
    }

    public function unreadCount(int $userId): int
    {
        try {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u AND is_read = 0');
            $stmt->execute(['u' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT * FROM notifications WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
            );
            $stmt->execute(['u' => $userId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function markAllRead(int $userId): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :u AND is_read = 0');
            $stmt->execute(['u' => $userId]);
        } catch (PDOException $e) {
        }
    }
}
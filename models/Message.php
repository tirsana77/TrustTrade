<?php
require_once __DIR__ . '/../config/database.php';

class Message
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function send(int $listingId, int $senderId, int $receiverId, string $body): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO messages (listing_id, sender_id, receiver_id, body)
             VALUES (:listing_id, :sender_id, :receiver_id, :body)'
        );
        $stmt->execute([
            'listing_id'  => $listingId,
            'sender_id'   => $senderId,
            'receiver_id' => $receiverId,
            'body'        => $body,
        ]);
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = :user_id AND is_read = 0');
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** One row per conversation (listing + other person), showing the latest message. */
    public function conversations(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, l.title AS listing_title, u.full_name AS other_name,
                    CASE WHEN m.sender_id = :uid1 THEN m.receiver_id ELSE m.sender_id END AS other_id,
                    (SELECT COUNT(*) FROM messages x
                      WHERE x.listing_id = m.listing_id AND x.receiver_id = :uid5
                        AND x.sender_id = (CASE WHEN m.sender_id = :uid6 THEN m.receiver_id ELSE m.sender_id END)
                        AND x.is_read = 0) AS unread_count
             FROM messages m
             JOIN listings l ON l.id = m.listing_id
             JOIN users u ON u.id = CASE WHEN m.sender_id = :uid2 THEN m.receiver_id ELSE m.sender_id END
             WHERE m.id IN (
                 SELECT MAX(id) FROM messages
                 WHERE sender_id = :uid3 OR receiver_id = :uid4
                 GROUP BY listing_id, LEAST(sender_id, receiver_id), GREATEST(sender_id, receiver_id)
             )
             ORDER BY m.created_at DESC'
        );
        $stmt->execute(['uid1' => $userId, 'uid2' => $userId, 'uid3' => $userId, 'uid4' => $userId, 'uid5' => $userId, 'uid6' => $userId]);
        return $stmt->fetchAll();
    }

    public function thread(int $listingId, int $userA, int $userB): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, u.full_name AS sender_name
             FROM messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.listing_id = :listing_id
               AND ((m.sender_id = :a1 AND m.receiver_id = :b1) OR (m.sender_id = :b2 AND m.receiver_id = :a2))
             ORDER BY m.created_at ASC'
        );
        $stmt->execute(['listing_id' => $listingId, 'a1' => $userA, 'b1' => $userB, 'a2' => $userA, 'b2' => $userB]);
        return $stmt->fetchAll();
    }

    public function markThreadRead(int $listingId, int $receiverId, int $senderId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE messages SET is_read = 1
             WHERE listing_id = :listing_id AND receiver_id = :receiver_id AND sender_id = :sender_id'
        );
        $stmt->execute(['listing_id' => $listingId, 'receiver_id' => $receiverId, 'sender_id' => $senderId]);
    }

    /** A line written by the system into the chat, for example when a delivery plan changes. Always counts as read. */
    public function sendSystem(int $listingId, int $actorId, int $otherId, string $body): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO messages (listing_id, sender_id, receiver_id, body, is_read, kind)
             VALUES (:listing_id, :sender_id, :receiver_id, :body, 1, 'system')"
        );
        $stmt->execute([
            'listing_id'  => $listingId,
            'sender_id'   => $actorId,
            'receiver_id' => $otherId,
            'body'        => mb_substr($body, 0, 500),
        ]);
    }

    /** Messages of one conversation that are newer than $afterId (used by the live chat). */
    public function since(int $listingId, int $userA, int $userB, int $afterId): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.* FROM messages m
             WHERE m.listing_id = :listing_id AND m.id > :after
               AND ((m.sender_id = :a1 AND m.receiver_id = :b1) OR (m.sender_id = :b2 AND m.receiver_id = :a2))
             ORDER BY m.id ASC'
        );
        $stmt->execute(['listing_id' => $listingId, 'after' => $afterId, 'a1' => $userA, 'b1' => $userB, 'a2' => $userA, 'b2' => $userB]);
        return $stmt->fetchAll();
    }

    /** The newest message of mine that the other person has read (for the "Seen" mark). */
    public function seenUpTo(int $listingId, int $me, int $other): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(id), 0) FROM messages
             WHERE listing_id = :listing_id AND sender_id = :me AND receiver_id = :other AND is_read = 1 AND kind = 'text'"
        );
        $stmt->execute(['listing_id' => $listingId, 'me' => $me, 'other' => $other]);
        return (int) $stmt->fetchColumn();
    }
}
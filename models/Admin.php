<?php
require_once __DIR__ . '/../config/database.php';

class Admin
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    private function count(string $sql): int
    {
        return (int) $this->db->query($sql)->fetchColumn();
    }

    public function stats(): array
    {
        return [
            'students'        => $this->count("SELECT COUNT(*) FROM users WHERE is_admin = 0 AND email NOT LIKE '%@deleted.invalid'"),
            'verified'        => $this->count('SELECT COUNT(*) FROM users WHERE is_admin = 0 AND student_verified = 1'),
            'pending'         => $this->count("SELECT COUNT(*) FROM users WHERE student_verified = 0 AND student_id_photo IS NOT NULL AND student_id_photo != ''"),
            'open_reports'    => $this->count("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
            'open_disputes'   => $this->count("SELECT COUNT(*) FROM disputes WHERE status = 'open'"),
            'active_listings' => $this->count("SELECT COUNT(*) FROM listings WHERE status = 'active'"),
            'sold_listings'   => $this->count("SELECT COUNT(*) FROM listings WHERE status = 'sold'"),
            'orders'          => $this->count('SELECT COUNT(*) FROM orders'),
        ];
    }

    /** Numbers for the charts on the admin overview. */
    public function chartData(): array
    {
        $students = $this->stats();
        $notVerified = max(0, $students['students'] - $students['verified'] - $students['pending']);

        $statuses = ['pending' => 0, 'accepted' => 0, 'completed' => 0, 'declined' => 0, 'cancelled' => 0];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status')->fetchAll() as $r) {
            $statuses[$r['status']] = (int) $r['n'];
        }

        $categories = [];
        foreach ($this->db->query("SELECT COALESCE(NULLIF(category, ''), 'Other') AS c, COUNT(*) AS n FROM listings GROUP BY c ORDER BY n DESC LIMIT 6")->fetchAll() as $r) {
            $categories[$r['c']] = (int) $r['n'];
        }

        // New requests per day, last 14 days (days without requests count as 0)
        $perDay = [];
        for ($i = 13; $i >= 0; $i--) {
            $perDay[date('Y-m-d', strtotime("-$i day"))] = 0;
        }
        $stmt = $this->db->query('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM orders WHERE created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)');
        foreach ($stmt->fetchAll() as $r) {
            if (isset($perDay[$r['d']])) {
                $perDay[$r['d']] = (int) $r['n'];
            }
        }

        return [
            'students'   => ['Verified' => $students['verified'], 'Waiting for review' => $students['pending'], 'Not verified yet' => $notVerified],
            'statuses'   => $statuses,
            'categories' => $categories,
            'per_day'    => $perDay,
        ];
    }

    /**
     * How active each student is: listings posted, requests made or received, messages sent, and the date of the last action.
     * Returns user id => row. "total" is the sum of the three counts.
     */
    public function activity(): array
    {
        $sql = "SELECT u.id,
                    (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id) AS listings,
                    (SELECT COUNT(*) FROM orders o WHERE o.buyer_id = u.id OR o.seller_id = u.id) AS requests,
                    (SELECT COUNT(*) FROM messages m WHERE m.sender_id = u.id AND m.kind = 'text') AS messages,
                    (SELECT MAX(l.created_at) FROM listings l WHERE l.user_id = u.id) AS last_listing,
                    (SELECT MAX(o.created_at) FROM orders o WHERE o.buyer_id = u.id OR o.seller_id = u.id) AS last_request,
                    (SELECT MAX(m.created_at) FROM messages m WHERE m.sender_id = u.id AND m.kind = 'text') AS last_message
                FROM users u WHERE u.is_admin = 0";
        $out = [];
        foreach ($this->db->query($sql)->fetchAll() as $r) {
            $dates = array_filter([$r['last_listing'], $r['last_request'], $r['last_message']]);
            $out[(int) $r['id']] = [
                'listings' => (int) $r['listings'],
                'requests' => (int) $r['requests'],
                'messages' => (int) $r['messages'],
                'total'    => (int) $r['listings'] + (int) $r['requests'] + (int) $r['messages'],
                'last'     => $dates ? max($dates) : null,
            ];
        }
        return $out;
    }

    public function allUsers(): array
    {
        $stmt = $this->db->query(
            'SELECT u.*, (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id) AS listing_count
             FROM users u
             WHERE u.email NOT LIKE \'%@deleted.invalid\'
             ORDER BY u.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    /** Removing verification also clears the ID photo, so the student has to upload a new one. */
    public function setVerified(int $userId, bool $verified): void
    {
        if ($verified) {
            $stmt = $this->db->prepare('UPDATE users SET student_verified = 1 WHERE id = :id');
        } else {
            $stmt = $this->db->prepare('UPDATE users SET student_verified = 0, student_id_photo = NULL WHERE id = :id');
        }
        $stmt->execute(['id' => $userId]);
    }
}
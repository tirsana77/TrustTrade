<?php
require_once __DIR__ . '/../config/database.php';

class User
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    /** Adds the "ID valid until" column on first use, so no manual SQL is needed. */
    public function ensureExpiryColumn(): void
    {
        try {
            if (!$this->db->query("SHOW COLUMNS FROM users LIKE 'student_id_expiry'")->fetch()) {
                $this->db->exec('ALTER TABLE users ADD COLUMN student_id_expiry DATE NULL');
            }
        } catch (PDOException $e) {
        }
    }

    /** Checks the "valid until" date typed from the student ID. Returns an error text, or null when the date is fine. */
    public static function idExpiryError(?string $date, ?string $today = null): ?string
    {
        $today = $today ?? date('Y-m-d');
        $date  = trim((string) $date);
        if ($date === '') {
            return 'Please enter the date your student ID is valid until.';
        }
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            return 'The "valid until" date is not a valid date.';
        }
        if ($date <= $today) {
            return 'This student ID has expired. Please use an ID that is still valid.';
        }
        if ($date > date('Y-m-d', strtotime($today . ' +10 years'))) {
            return 'The "valid until" date is too far ahead. Please check the card.';
        }
        return null;
    }

    /** True when the stored "valid until" date has passed. */
    public static function idIsExpired(array $row, ?string $today = null): bool
    {
        return !empty($row['student_id_expiry']) && $row['student_id_expiry'] <= ($today ?? date('Y-m-d'));
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function create(string $fullName, string $email, string $plainPassword): int
    {
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

        $stmt = $this->db->prepare(
            'INSERT INTO users (full_name, email, password_hash)
             VALUES (:full_name, :email, :password_hash)'
        );
        $stmt->execute([
            'full_name'     => $fullName,
            'email'         => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function verifyPassword(string $plainPassword, string $storedHash): bool
    {
        return password_verify($plainPassword, $storedHash);
    }

    public function updateName(int $id, string $fullName): void
    {
        $stmt = $this->db->prepare('UPDATE users SET full_name = :full_name WHERE id = :id');
        $stmt->execute(['full_name' => $fullName, 'id' => $id]);
    }

    public function updateEmail(int $id, string $email): void
    {
        $stmt = $this->db->prepare('UPDATE users SET email = :email WHERE id = :id');
        $stmt->execute(['email' => $email, 'id' => $id]);
    }

    /** Ids of all administrators (they are told when a verified student changes their face photo). */
    public function adminIds(): array
    {
        return array_map('intval', $this->db->query('SELECT id FROM users WHERE is_admin = 1')->fetchAll(PDO::FETCH_COLUMN));
    }

    public function updatePassword(int $id, string $plainPassword): void
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => password_hash($plainPassword, PASSWORD_DEFAULT), 'id' => $id]);
    }

    public function saveStudentIdPhoto(int $userId, string $filename, ?string $expiry = null): void
    {
        $stmt = $this->db->prepare('UPDATE users SET student_id_photo = :photo WHERE id = :id');
        $stmt->execute(['photo' => $filename, 'id' => $userId]);
        if ($expiry !== null) {
            $this->ensureExpiryColumn();
            try {
                $this->db->prepare('UPDATE users SET student_id_expiry = :e WHERE id = :id')->execute(['e' => $expiry, 'id' => $userId]);
            } catch (PDOException $e) {
            }
        }
    }

    public function getPendingVerifications(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM users
             WHERE student_id_photo IS NOT NULL AND student_id_photo != '' AND student_verified = 0
             ORDER BY created_at ASC"
        );
        return $stmt->fetchAll();
    }

    public function approveStudent(int $userId): void
    {
        $stmt = $this->db->prepare('UPDATE users SET student_verified = 1 WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    public function markWelcomeSeen(int $userId): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE users SET welcome_seen = 1 WHERE id = :id');
            $stmt->execute(['id' => $userId]);
        } catch (PDOException $e) {
        }
    }

    /** Used when an account was created but its photo could not be saved. Verified accounts are never deleted here. */
    public function deleteUnverified(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id AND student_verified = 0');
        $stmt->execute(['id' => $id]);
    }

    public function saveProfilePhoto(int $userId, string $filename): void
    {
        $stmt = $this->db->prepare('UPDATE users SET profile_photo = :photo WHERE id = :id');
        $stmt->execute(['photo' => $filename, 'id' => $userId]);
    }

    /**
     * Deletes a person's account in a way that keeps other people's order history correct.
     *  - Personal data is erased: name, email, password, student ID photo, face photo, notifications, viewing history.
     *  - Listings that nobody ever ordered are deleted. Listings with orders stay hidden as sold (the orders belong to other people too).
     *  - The row itself stays as "Deleted user", so old orders and chats still make sense for the other side.
     * Returns the files to remove: ['storage' => [...student_ids files], 'listings' => [...listing image files]].
     * Callers must first check that the person has no open orders.
     */
    public function deleteAccount(int $id): array
    {
        $files = ['storage' => [], 'listings' => []];
        $me = $this->findById($id);
        if ($me === null || !empty($me['is_admin'])) {
            return $files;
        }
        foreach (['student_id_photo', 'profile_photo'] as $col) {
            if (!empty($me[$col])) {
                $files['storage'][] = basename($me[$col]);
            }
        }

        $this->db->beginTransaction();
        try {
            $ids = $this->db->prepare('SELECT id FROM listings WHERE user_id = :id');
            $ids->execute(['id' => $id]);
            foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $listingId) {
                $has = $this->db->prepare('SELECT COUNT(*) FROM orders WHERE listing_id = :l');
                $has->execute(['l' => $listingId]);
                if ((int) $has->fetchColumn() > 0) {
                    $this->db->prepare("UPDATE listings SET status = 'sold' WHERE id = :l")->execute(['l' => $listingId]);
                } else {
                    $imgs = $this->db->prepare('SELECT image FROM listing_images WHERE listing_id = :l');
                    $imgs->execute(['l' => $listingId]);
                    foreach ($imgs->fetchAll(PDO::FETCH_COLUMN) as $img) {
                        $files['listings'][] = basename($img);
                    }
                    $this->db->prepare('DELETE FROM listings WHERE id = :l')->execute(['l' => $listingId]);
                }
            }
            foreach (['notifications', 'listing_views'] as $table) {
                try {
                    $this->db->prepare('DELETE FROM ' . $table . ' WHERE user_id = :id')->execute(['id' => $id]);
                } catch (PDOException $e) {
                }
            }
            $anon = $this->db->prepare(
                "UPDATE users SET full_name = 'Deleted user', email = :email, password_hash = :hash,
                        student_verified = 0, student_id_photo = NULL, profile_photo = NULL
                 WHERE id = :id AND is_admin = 0"
            );
            $anon->execute([
                'email' => 'deleted_' . $id . '@deleted.invalid',
                'hash'  => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'id'    => $id,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['storage' => [], 'listings' => [], 'failed' => true];
        }
        return $files;
    }

    public function rejectStudent(int $userId): void
    {
        // Both photos are cleared: the student uploads a new student ID photo and a new face photo
        $stmt = $this->db->prepare('UPDATE users SET student_id_photo = NULL, profile_photo = NULL WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        try {
            $this->db->prepare('UPDATE users SET student_id_expiry = NULL WHERE id = :id')->execute(['id' => $userId]);
        } catch (PDOException $e) {
        }
    }
}
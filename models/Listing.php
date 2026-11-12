<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Recommender.php';

class Listing
{
    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->getConnection();
    }

    public function create(int $userId, string $title, string $description, float $price, string $category): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO listings (user_id, title, description, price, category)
             VALUES (:user_id, :title, :description, :price, :category)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'title'       => $title,
            'description' => $description,
            'price'       => $price,
            'category'    => $category,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $userId, string $title, string $description, float $price, string $category): void
    {
        $stmt = $this->db->prepare(
            'UPDATE listings SET title = :title, description = :description, price = :price, category = :category
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            'title'       => $title,
            'description' => $description,
            'price'       => $price,
            'category'    => $category,
            'id'          => $id,
            'user_id'     => $userId,
        ]);
    }

    /**
     * Owners can only delete a listing that has no orders (use "mark as sold" instead).
     * Pass $userId = null for an admin removal, which skips that check.
     */
    public function delete(int $id, ?int $userId = null): bool
    {
        if ($userId !== null) {
            $check = $this->db->prepare('SELECT COUNT(*) FROM orders WHERE listing_id = :id');
            $check->execute(['id' => $id]);
            if ((int) $check->fetchColumn() > 0) {
                return false;
            }
        }

        $images = $this->getImages($id);

        if ($userId === null) {
            $stmt = $this->db->prepare('DELETE FROM listings WHERE id = :id');
            $stmt->execute(['id' => $id]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM listings WHERE id = :id AND user_id = :user_id');
            $stmt->execute(['id' => $id, 'user_id' => $userId]);
        }

        if ($stmt->rowCount() > 0) {
            foreach ($images as $img) {
                $path = __DIR__ . '/../public/uploads/listings/' . $img;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            return true;
        }
        return false;
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'sold'], true)) {
            return;
        }
        $stmt = $this->db->prepare('UPDATE listings SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function addImage(int $listingId, string $filename, int $order): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO listing_images (listing_id, image, display_order) VALUES (:listing_id, :image, :display_order)'
        );
        $stmt->execute([
            'listing_id'    => $listingId,
            'image'         => $filename,
            'display_order' => $order,
        ]);
    }

    public function getImages(int $listingId): array
    {
        $stmt = $this->db->prepare('SELECT image FROM listing_images WHERE listing_id = :id ORDER BY display_order ASC');
        $stmt->execute(['id' => $listingId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function likePattern(string $word): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word) . '%';
    }

    /**
     * Active listings, optionally filtered by keywords and/or category.
     * With keywords, results are ranked by a relevance score:
     *   title match +3, description match +1 (per word),
     *   +2 if the listing is in a category the user views most,
     *   +0.2 per view by other students (capped at 5 views).
     */
    public function search(string $query = '', string $category = '', ?int $userId = null): array
    {
        $params     = [];
        $scoreParts = [];
        $where      = ["listings.status = 'active'"];

        $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_slice($words, 0, 5);

        foreach ($words as $i => $word) {
            $like = $this->likePattern($word);

            $params["w_t{$i}"] = $like;
            $params["w_d{$i}"] = $like;
            $params["w_c{$i}"] = $like;
            $where[] = "(listings.title LIKE :w_t{$i} OR listings.description LIKE :w_d{$i} OR listings.category LIKE :w_c{$i})";

            $params["s_t{$i}"] = $like;
            $params["s_d{$i}"] = $like;
            $scoreParts[] = "(CASE WHEN listings.title LIKE :s_t{$i} THEN 3 ELSE 0 END)";
            $scoreParts[] = "(CASE WHEN listings.description LIKE :s_d{$i} THEN 1 ELSE 0 END)";
        }

        if ($category !== '') {
            $where[] = 'listings.category = :category';
            $params['category'] = $category;
        }

        if (!empty($words)) {
            if ($userId !== null) {
                $params['u_cat'] = $userId;
                $scoreParts[] = "(CASE WHEN listings.category IN (
                                    SELECT category FROM (
                                        SELECT l2.category AS category
                                        FROM listing_views v2
                                        JOIN listings l2 ON l2.id = v2.listing_id
                                        WHERE v2.user_id = :u_cat
                                        GROUP BY l2.category
                                        ORDER BY COUNT(*) DESC
                                        LIMIT 2
                                    ) top_categories
                                 ) THEN 2 ELSE 0 END)";
            }
            $scoreParts[] = '(LEAST((SELECT COUNT(*) FROM listing_views pv WHERE pv.listing_id = listings.id), 5) * 0.2)';
        }

        $sql = 'SELECT listings.*, users.full_name AS seller_name';
        if (!empty($scoreParts)) {
            $sql .= ', (' . implode(' + ', $scoreParts) . ') AS relevance';
        }
        $sql .= ' FROM listings JOIN users ON listings.user_id = users.id WHERE ' . implode(' AND ', $where);
        $sql .= !empty($scoreParts)
            ? ' ORDER BY relevance DESC, listings.created_at DESC'
            : ' ORDER BY listings.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->attachImages($stmt->fetchAll());
    }

    /** Titles that start with or contain the typed text, for the search suggestions. */
    public function suggestTitles(string $term, int $limit = 6): array
    {
        $limit = max(1, min(10, $limit));
        $stmt = $this->db->prepare(
            "SELECT id, title, category
             FROM listings
             WHERE status = 'active' AND title LIKE :contains
             ORDER BY (title LIKE :prefix) DESC, created_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'contains' => $this->likePattern($term),
            'prefix'   => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%',
        ]);
        return $stmt->fetchAll();
    }

    public function findAll(): array
    {
        return $this->search();
    }

    /** Listings the automatic rules put under review, newest first (admin Held tab). */
    public function findHeld(): array
    {
        $stmt = $this->db->query(
            "SELECT listings.*, users.full_name AS seller_name
             FROM listings JOIN users ON listings.user_id = users.id
             WHERE listings.status = 'held'
             ORDER BY listings.held_at DESC, listings.id DESC"
        );
        return $this->attachImages($stmt->fetchAll());
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT listings.*, users.full_name AS seller_name
             FROM listings
             JOIN users ON listings.user_id = users.id
             WHERE listings.user_id = :user_id
             ORDER BY listings.created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        return $this->attachImages($stmt->fetchAll());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT listings.*, users.full_name AS seller_name
             FROM listings
             JOIN users ON listings.user_id = users.id
             WHERE listings.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $listing = $stmt->fetch();
        if (!$listing) {
            return null;
        }

        $listing['images'] = $this->getImages((int) $listing['id']);
        return $listing;
    }

    public function recordView(int $userId, int $listingId): void
    {
        $stmt = $this->db->prepare('INSERT INTO listing_views (user_id, listing_id) VALUES (:user_id, :listing_id)');
        $stmt->execute(['user_id' => $userId, 'listing_id' => $listingId]);
    }

    /**
     * All listings and each user's distinct viewed listings (in the order viewed).
     * Used by the recommender and by recommender_report.php.
     * @return array [$viewsByUser, $listings]
     */
    public function recommenderData(): array
    {
        $listings = [];
        $rows = $this->db->query(
            'SELECT id, user_id, title, description, category, status, created_at FROM listings'
        )->fetchAll();
        foreach ($rows as $row) {
            $listings[(int) $row['id']] = $row;
        }

        $views = [];
        $rows = $this->db->query('SELECT user_id, listing_id FROM listing_views ORDER BY id ASC')->fetchAll();
        foreach ($rows as $row) {
            $u = (int) $row['user_id'];
            $l = (int) $row['listing_id'];
            if (!isset($listings[$l])) {
                continue;
            }
            if (!isset($views[$u])) {
                $views[$u] = [];
            }
            if (!in_array($l, $views[$u], true)) {
                $views[$u][] = $l;
            }
        }
        return [$views, $listings];
    }

    /**
     * Hybrid recommender (see models/Recommender.php):
     *   a taste model learned from listing views (matrix factorisation) + TF-IDF content similarity,
     *   with a cold-start fallback to the newest listings.
     * Each returned listing carries a 'reason' text such as "Similar to X, which you viewed".
     */
    public function getRecommendations(int $userId, int $limit = 4): array
    {
        [$views, $listings] = $this->recommenderData();
        $picked = Recommender::recommend($userId, $views, $listings, max(1, $limit));

        $meta = [];
        foreach ($picked as $p) {
            $meta[(int) $p['id']] = $p;
        }

        $rows = $this->loadByIds(array_keys($meta));
        foreach ($rows as $i => $row) {
            $rows[$i]['reason'] = $this->reasonText($meta[(int) $row['id']], $listings);
        }
        return $rows;
    }

    private function reasonText(array $pick, array $listings): string
    {
        $via = '';
        if ($pick['via'] !== null && isset($listings[$pick['via']])) {
            $via = mb_strimwidth((string) $listings[$pick['via']]['title'], 0, 40, '...');
        }
        if ($pick['type'] === 'taste') {
            return 'Students with a similar taste also viewed this';
        }
        if ($pick['type'] === 'viewers' && $via !== '') {
            return 'Students who viewed "' . $via . '" also viewed this';
        }
        if ($pick['type'] === 'similar' && $via !== '') {
            return 'Similar to "' . $via . '", which you viewed';
        }
        return 'New on TrustTrade';
    }

    private function loadByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT listings.*, users.full_name AS seller_name
             FROM listings
             JOIN users ON listings.user_id = users.id
             WHERE listings.id IN ($placeholders)"
        );
        $stmt->execute(array_values($ids));

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $this->attachImages($ordered);
    }

    private function attachImages(array $listings): array
    {
        foreach ($listings as $i => $listing) {
            $listings[$i]['images'] = $this->getImages((int) $listing['id']);
        }
        return $listings;
    }
}
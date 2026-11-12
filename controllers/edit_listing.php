<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];

$listingModel = new Listing();
$id = (int) ($_GET['id'] ?? 0);
$listing = $listingModel->findById($id);

if ($listing === null || (int) $listing['user_id'] !== $me) {
    http_response_code(404);
       $errorMessage = 'Listing not found.'; include __DIR__ . '/../includes/error_page.php'; exit;
}

$categories = ['Textbooks', 'Electronics', 'Calculators', 'Stationery', 'Other'];
$errors = [];

$title       = $listing['title'];
$description = $listing['description'];
$price       = $listing['price'];
$category    = $listing['category'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = $_POST['price'] ?? '';
    $category    = $_POST['category'] ?? '';

    if ($title === '') {
        $errors[] = 'Please enter a title.';
    }
    if ($description === '') {
        $errors[] = 'Please enter a description.';
    }
    if (mb_strlen($title) > 150) {
        $errors[] = 'Title must be 150 characters or fewer.';
    }
    if (mb_strlen($description) > 5000) {
        $errors[] = 'Description must be 5000 characters or fewer.';
    }
    if (!is_numeric($price) || (float) $price < 0 || (float) $price > 99999) {
        $errors[] = 'Please enter a valid price (0 to 99999).';
    }
    if (!in_array($category, $categories, true)) {
        $errors[] = 'Please choose a valid category.';
    }

    if (empty($errors)) {
        $listingModel->update($id, $me, $title, $description, (float) $price, $category);
        $_SESSION['flash'] = ['success', 'Listing updated.'];
        header('Location: my_listings.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit listing - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <p><a href="my_listings.php" class="back-link">← Back to my listings</a></p>

        <div class="detail-card">
            <h1>Edit listing</h1>

            <?php foreach ($errors as $error): ?>
                <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>

            <form method="POST" class="form-stack">
                <label>Title
                    <input type="text" name="title" value="<?= htmlspecialchars($title) ?>">
                </label>
                <label>Description
                    <textarea name="description" rows="4"><?= htmlspecialchars($description) ?></textarea>
                </label>
                <label>Price (DKK)
                    <input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars((string) $price) ?>">
                </label>
                <label>Category
                    <select name="category">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= $category === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn-inline">Save changes</button>
            </form>

            <?php if (!empty($listing['images'])): ?>
                <h3>Photos</h3>
                <div class="photo-grid">
                    <?php foreach ($listing['images'] as $img): ?>
                        <img src="../public/uploads/listings/<?= htmlspecialchars($img) ?>" alt="">
                    <?php endforeach; ?>
                </div>
                <p class="muted">Photos can't be changed after posting, so the 4-photo rule stays enforced. To use different photos, delete this listing and create a new one.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
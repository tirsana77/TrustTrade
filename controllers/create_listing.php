<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Automation.php';


/*
 * Finds photos that are the same picture, even when the file name differs or the picture was resized, recoloured or mirrored.
 * Two steps: an exact check of the file content, and a "visual fingerprint" (9x8 grey squares, 64 bits) that is compared bit by bit.
 */
/** Fingerprint of an image file as a 64-character string of 0 and 1, or null when it cannot be read. */
function photoFingerprint(string $path, string $mime, bool $mirror = false): ?string
{
    if (!function_exists('imagecreatefromjpeg')) {
        return null;
    }
    $img = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
    if (!$img) {
        return null;
    }
    $small = imagecreatetruecolor(9, 8);
    $white = imagecolorallocate($small, 255, 255, 255);
    imagefill($small, 0, 0, $white);   // transparent PNG parts count as white
    imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
    if ($mirror) {
        imageflip($small, IMG_FLIP_HORIZONTAL);
    }
    $grey = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 9; $x++) {
            $c = imagecolorat($small, $x, $y);
            $grey[$y][$x] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
        }
    }
    imagedestroy($img);
    imagedestroy($small);
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $bits .= $grey[$y][$x] > $grey[$y][$x + 1] ? '1' : '0';
        }
    }
    return $bits;
}

/** Number of different bits (0 = identical fingerprint, 64 = completely different). */
function photoDistance(string $a, string $b): int
{
    $d = 0;
    for ($i = 0; $i < 64; $i++) {
        if ($a[$i] !== $b[$i]) {
            $d++;
        }
    }
    return $d;
}

/**
 * Compares photos of one listing with each other.
 * @param array $photos key => ['tmp' => path, 'mime' => mime type]
 * @return array list of [keyA, keyB] pairs that show the same picture
 */
function findSamePhotos(array $photos, int $maxDistance = 5): array
{
    $keys = array_keys($photos);
    $hash = $fp = $fpMirror = [];
    foreach ($keys as $k) {
        $hash[$k]     = sha1_file($photos[$k]['tmp']);
        $fp[$k]       = photoFingerprint($photos[$k]['tmp'], $photos[$k]['mime']);
        $fpMirror[$k] = photoFingerprint($photos[$k]['tmp'], $photos[$k]['mime'], true);
    }
    $same = [];
    for ($i = 0; $i < count($keys); $i++) {
        for ($j = $i + 1; $j < count($keys); $j++) {
            $a = $keys[$i];
            $b = $keys[$j];
            $isSame = ($hash[$a] === $hash[$b]);
            if (!$isSame && $fp[$a] !== null && $fp[$b] !== null) {
                $isSame = photoDistance($fp[$a], $fp[$b]) <= $maxDistance
                       || photoDistance($fp[$a], $fpMirror[$b]) <= $maxDistance;
            }
            if ($isSame) {
                $same[] = [$a, $b];
            }
        }
    }
    return $same;
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);

if (empty($currentUser['student_verified'])) {
    header('Location: dashboard.php');
    exit;
}

$listingModel = new Listing();
$errors = [];
$success = false;
$heldReason = null;

$categories   = ['Textbooks', 'Electronics', 'Calculators', 'Stationery', 'Other'];
$allowedTypes = ['image/jpeg', 'image/png'];

// Each slot: [label, required]
$slots = [
    'front'  => ['First photo', true],
    'back'   => ['Second photo', true],
    'side'   => ['Photo 3', false],
    'detail' => ['Photo 4', false],
    'extra1' => ['Photo 5', false],
    'extra2' => ['Photo 6', false],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If the files were bigger than the server allows, PHP throws away the whole form.
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        $errors[] = 'Your photos were too large to upload. Please use smaller photos and try again.';
    } else {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price       = $_POST['price'] ?? '';
        $category    = $_POST['category'] ?? '';

        if ($title === '') {
            $errors[] = 'Please enter a title.';
        }
        if (mb_strlen($title) > 150) {
            $errors[] = 'Title must be 150 characters or fewer.';
        }
        if ($description === '') {
            $errors[] = 'Please enter a description.';
        } elseif (mb_strlen($description) > 5000) {
            $errors[] = 'Description must be 5000 characters or fewer.';
        }
        if (!is_numeric($price) || (float) $price < 0 || (float) $price > 99999) {
            $errors[] = 'Please enter a valid price (0 to 99999).';
        }
        if (!in_array($category, $categories, true)) {
            $errors[] = 'Please choose a valid category.';
        }

        $validPhotos = [];
        foreach ($slots as $key => $slot) {
            $label    = $slot[0];
            $required = $slot[1];
            $err      = $_FILES['photos']['error'][$key] ?? UPLOAD_ERR_NO_FILE;

            if ($err === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors[] = "Please add the {$label}.";
                }
                continue;
            }
            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = "The {$label} failed to upload. It may be too large.";
                continue;
            }

            $tmp  = $_FILES['photos']['tmp_name'][$key];
            $size = $_FILES['photos']['size'][$key];
            $mime = mime_content_type($tmp);

            if (!in_array($mime, $allowedTypes, true)) {
                $errors[] = "The {$label} must be a JPG or PNG image.";
            } elseif ($size > 5 * 1024 * 1024) {
                $errors[] = "The {$label} must be under 5MB.";
            } else {
                $validPhotos[$key] = ['tmp' => $tmp, 'mime' => $mime];
            }
        }

        // The photos of one listing must be different pictures (not the same photo under another file name)
        if (count($validPhotos) > 1) {
            foreach (findSamePhotos($validPhotos) as [$a, $b]) {
                $errors[] = "The {$slots[$a][0]} and the {$slots[$b][0]} show the same picture. Please add a different photo, for example another side of the item.";
            }
        }

        if (empty($errors)) {
            $listingId = $listingModel->create((int) $_SESSION['user_id'], $title, $description, (float) $price, $category);

            $order = 0;
            foreach ($validPhotos as $photo) {
                $extension   = $photo['mime'] === 'image/png' ? 'png' : 'jpg';
                $newFilename = 'listing_' . $listingId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
                $destination = __DIR__ . '/../public/uploads/listings/' . $newFilename;

                if (move_uploaded_file($photo['tmp'], $destination)) {
                    $listingModel->addImage($listingId, $newFilename, $order);
                    $order++;
                }
            }

            // A listing with a high AI risk score is put under review straight away
            try {
                $heldReason = (new Automation())->checkListing($listingId);
            } catch (Throwable $e) {
            }

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>List an item - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <p><a href="dashboard.php" class="back-link">← Back to home</a></p>

        <div class="detail-card" style="max-width:620px; margin:0 auto;">
            <h1>List an item</h1>

            <?php if ($success): ?>
                <div class="flash flash-success">Listing created!</div>
                <?php if ($heldReason): ?>
                    <div class="flash flash-error">Your listing was put under review automatically (<?= htmlspecialchars($heldReason) ?>). It stays hidden until an administrator checks it. You will get a notification.</div>
                <?php endif; ?>
                <p>
                    <a href="my_listings.php" class="btn-inline">View my listings</a>
                    <a href="create_listing.php" class="btn-outline">List another item</a>
                </p>
            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>

                <form method="POST" enctype="multipart/form-data" class="form-stack">
                    <label>Title
                        <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
                    </label>
                    <label>Description
                        <textarea name="description" rows="4"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </label>
                    <label>Price (DKK)
                        <input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($_POST['price'] ?? '') ?>">
                    </label>
                    <label>Category
                        <select name="category">
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat ?>" <?= (($_POST['category'] ?? '') === $cat) ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div>
                        <strong style="font-size:0.85rem; color:#606770;">Photos (JPG or PNG)</strong>
                        <p class="muted" style="margin:0.2rem 0 0.6rem;">Add at least 2 photos (the boxes with a red outline), so buyers can check the item matches your description. Up to 4 more are optional.</p>
                        <div class="photo-slots">
                            <?php foreach ($slots as $key => $slot): ?>
                                <div class="photo-slot<?= $slot[1] ? ' photo-required' : '' ?>">
                                    <?= $slot[1] ? 'Required photo *' : 'Optional photo' ?>
                                    <img alt="" class="slot-preview">
                                    <input type="file" name="photos[<?= $key ?>]" accept="image/jpeg,image/png">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="muted" style="margin-top:0.5rem;">Red outline = required. If the form shows an error you'll need to choose the photos again.</p>
                    </div>

                    <button type="submit" class="btn-inline">Create listing</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    document.querySelectorAll('.photo-slot').forEach(function (slot) {
        var input = slot.querySelector('input[type="file"]');
        var img = slot.querySelector('.slot-preview');
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                img.src = URL.createObjectURL(input.files[0]);
                img.classList.add('show');
            } else {
                img.classList.remove('show');
            }
        });
    });
    </script>
</body>
</html>
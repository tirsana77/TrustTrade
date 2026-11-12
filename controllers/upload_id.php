<?php
session_start();

require_once __DIR__ . '/../models/User.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$user->ensureExpiryColumn();
$currentUser = $user->findById($_SESSION['user_id']);
$isVerified = !empty($currentUser['student_verified']);
$hasPending = !$isVerified && !empty($currentUser['student_id_photo']);
$needFace   = empty($currentUser['profile_photo']);   // new accounts give it when they sign up

$errors = [];
$success = false;
$storageDir = __DIR__ . '/../storage/student_ids/';

/** Checks one uploaded image. Returns [extension, null] when it is fine, or [null, error text]. */
function checkImage(?array $file, string $label): array
{
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Please choose a file for the ' . $label . '.'];
    }
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return [null, 'The ' . $label . ' must be a JPG or PNG image.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'The ' . $label . ' is too large (max 5MB).'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return [null, 'The ' . $label . ' is not a valid image.'];
    }
    return [$mimeType === 'image/png' ? 'png' : 'jpg', null];
}

if (!$isVerified && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $idFile   = $_FILES['student_id'] ?? null;
    $faceFile = $_FILES['face_photo'] ?? null;

    [$idExt, $idErr] = checkImage($idFile, 'student ID photo');
    if ($idErr !== null) { $errors[] = $idErr; }
    $idExpiry = trim($_POST['id_expiry'] ?? '');
    $expiryErr = User::idExpiryError($idExpiry);
    if ($expiryErr !== null) { $errors[] = $expiryErr; }
    if ($needFace) {
        [$faceExt, $faceErr] = checkImage($faceFile, 'photo of your face');
        if ($faceErr !== null) { $errors[] = $faceErr; }
    }

    if (!$errors) {
        $uid    = (int) $_SESSION['user_id'];
        $idName = 'student_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $idExt;
        $saved  = move_uploaded_file($idFile['tmp_name'], $storageDir . $idName);
        $faceName = null;
        if ($saved && $needFace) {
            $faceName = 'face_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $faceExt;
            $saved    = move_uploaded_file($faceFile['tmp_name'], $storageDir . $faceName);
        }

        if ($saved) {
            // Replacing an earlier submission: delete the old photo(s).
            $oldCols = $needFace ? ['student_id_photo', 'profile_photo'] : ['student_id_photo'];
            foreach ($oldCols as $col) {
                if (!empty($currentUser[$col])) {
                    $old = $storageDir . basename($currentUser[$col]);
                    if (is_file($old)) {
                        unlink($old);
                    }
                }
            }
            $user->saveStudentIdPhoto($uid, $idName, $idExpiry);
            if ($faceName !== null) {
                $user->saveProfilePhoto($uid, $faceName);
            }
            $success = true;
        } else {
            $errors[] = 'Something went wrong saving the files. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify your identity - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <div class="detail-card" style="max-width:520px; margin:0 auto;">
            <h1>Verify your student ID</h1>
            <p class="muted">Upload a clear photo of your student ID. An administrator compares it with the face photo you gave when you signed up, to check that it is the same person. Only you and the administrators can see these photos.</p>

            <?php if ($isVerified): ?>
                <div class="flash flash-success">Your account is already verified.</div>
                <p><a href="dashboard.php" class="btn-inline">Back to home</a></p>
            <?php elseif ($success): ?>
                <div class="flash flash-success">Uploaded! An administrator will now compare your student ID with your face photo. You will get a notification when you are verified.</div>
                <p><a href="dashboard.php" class="btn-inline">Back to home</a></p>
            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <div class="flash flash-error"><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>

                <?php if ($hasPending): ?>
                    <div class="gate-notice gate-info" style="margin-top:0; margin-bottom:1rem;">
                        You have already submitted a photo. Uploading a new one replaces it.
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="form-stack">
                    <label>Student ID photo (JPG or PNG, max 5MB)
                        <input type="file" name="student_id" accept="image/jpeg,image/png" required>
                    </label>
                    <label>Valid until (the date printed on your student ID)
                        <input type="date" name="id_expiry" value="<?= htmlspecialchars($_POST['id_expiry'] ?? '') ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                    </label>
                    <?php if ($needFace): ?>
                        <label>A clear photo of your face, no sunglasses or hat (JPG or PNG, max 5MB)
                            <input type="file" name="face_photo" accept="image/jpeg,image/png" capture="user" required>
                        </label>
                    <?php endif; ?>
                    <button type="submit" class="btn-inline">Upload</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
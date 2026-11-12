<?php
session_start();

require_once __DIR__ . '/../models/User.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$user = new User();
$errors = [];
$success = false;

/** Checks the face photo. Returns [extension, null] when it is fine, or [null, error text]. */
function checkFacePhoto(?array $file): array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please add a clear photo of your face. It is required.'];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'The photo is too large (max 5MB).'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'The photo could not be uploaded. Please try again.'];
    }
    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return [null, 'The photo must be a JPG or PNG image.'];
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return [null, 'The photo is too large (max 5MB).'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return [null, 'The photo is not a valid image.'];
    }
    return [$mimeType === 'image/png' ? 'png' : 'jpg', null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($fullName === '') {
        $errors[] = 'Please enter your full name.';
    }
    if (mb_strlen($fullName) > 100) {
        $errors[] = 'Name must be 100 characters or fewer.';
    }
    $email = strtolower($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $errors[] = 'Please enter a valid email (max 150 characters).';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) {
        $errors[] = 'Password must be 72 characters or fewer.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one letter and one number.';
    }
    [$faceExt, $faceErr] = checkFacePhoto($_FILES['face_photo'] ?? null);
    if ($faceErr !== null) {
        $errors[] = $faceErr;
    }
    if (empty($errors) && $user->findByEmail($email) !== null) {
        $errors[] = 'An account with that email already exists.';
    }

    if (empty($errors)) {
        $newId    = $user->create($fullName, $email, $password);
        $faceName = 'face_' . $newId . '_' . bin2hex(random_bytes(8)) . '.' . $faceExt;
        if (move_uploaded_file($_FILES['face_photo']['tmp_name'], __DIR__ . '/../storage/student_ids/' . $faceName)) {
            $user->saveProfilePhoto($newId, $faceName);
            $success = true;
        } else {
            // No account without a photo: undo and ask again
            $user->deleteUnverified($newId);
            $errors[] = 'Something went wrong saving your photo. Please try again.';
        }
    }
}

$panelTagline = 'Join a marketplace built only for verified students at your university.';
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="split-body">
    <?php include __DIR__ . '/../includes/auth_panel.php'; ?>

    <div class="split-right">
        <div class="auth-card">
            <h1>Create a new account</h1>

            <?php if ($success): ?>
                <p class="success-message">Account created successfully! Next, log in and upload your student ID so an administrator can verify you.</p>
                <p style="text-align:center;"><a href="login.php" class="btn-secondary">Log in now</a></p>
            <?php else: ?>
                <?php foreach ($errors as $error): ?>
                    <p class="error-message"><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>

                <form method="POST" enctype="multipart/form-data">
                    <label>Full name
                        <input type="text" name="full_name" value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                    </label>
                    <label>Email
                        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </label>
                    <label>Password
                        <input type="password" name="password">
                    </label>
                    <label>Photo of your face (JPG or PNG, max 5MB)
                        <input type="file" name="face_photo" accept="image/jpeg,image/png" capture="user" required>
                    </label>
                    <p class="muted" style="font-size:0.82rem;margin:0;">A clear photo, no sunglasses or hat. An administrator compares it with your student ID. Only you and the administrators can see it.</p>
                    <button type="submit">Sign Up</button>
                </form>

                <div class="auth-divider">or</div>

                <a href="login.php" class="btn-secondary" style="background:#f0f2f5; color:#1c1e21;">Already have an account? Log in</a>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
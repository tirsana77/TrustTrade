<?php
session_start();

require_once __DIR__ . '/../models/User.php';

// Only allow returning to a listing page. Anything else is ignored.
function safeNext($value): string
{
    return (is_string($value) && preg_match('/^view_listing\.php\?id=\d+$/', $value)) ? $value : '';
}
$next = safeNext($_POST['next'] ?? $_GET['next'] ?? null);

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . ($next !== '' ? $next : 'dashboard.php'));
    exit;
}

$user = new User();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $found = $user->findByEmail($email);

    if ($found === null || !$user->verifyPassword($password, $found['password_hash'])) {
        $errors[] = 'Incorrect email or password.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $found['id'];
        $_SESSION['user_name'] = $found['full_name'];
        if (!empty($found['is_admin'])) {
            header('Location: admin.php');
        } else {
            header('Location: ' . ($next !== '' ? $next : 'dashboard.php'));
        }
        exit;
    }
}

$panelTagline = 'Buy, sell and trade study materials safely, within your verified student community.';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Log In - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="split-body">
    <?php include __DIR__ . '/../includes/auth_panel.php'; ?>

    <div class="split-right">
        <div class="auth-card">
            <h1>Log in to your account</h1>

            <?php foreach ($errors as $error): ?>
                <p class="error-message"><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>

            <form method="POST">
                <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
                <label>Email
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </label>
                <label>Password
                    <input type="password" name="password">
                </label>
                <button type="submit">Log In</button>
            </form>

            <div class="auth-divider">or</div>

            <a href="register.php" class="btn-secondary">Create new account</a>
            <a href="dashboard.php" class="guest-link">Browse without an account</a>
        </div>
    </div>
</body>
</html>
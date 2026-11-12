<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';
require_once __DIR__ . '/../models/Order.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$currentUser = $user->findById($_SESSION['user_id']);
$me = (int) $currentUser['id'];
$storageDir = __DIR__ . '/../storage/student_ids/';

/** Checks an uploaded photo. Returns [extension, null] when it is fine, or [null, error text]. */
function checkPhoto(?array $file): array
{
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, 'Please choose a photo.'];
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


/** Things that stop an account from being deleted right now (other people are waiting on them). */
function deleteBlockers(PDO $db, int $me): array
{
    $stop = [];
    $s = $db->prepare("SELECT COUNT(*) FROM orders WHERE (buyer_id = :a OR seller_id = :b) AND status IN ('pending','accepted')");
    $s->execute(['a' => $me, 'b' => $me]);
    if ((int) $s->fetchColumn() > 0) {
        $stop[] = 'You still have requests that are not finished. Finish or cancel them first (see Orders).';
    }
    $s = $db->prepare("SELECT COUNT(*) FROM disputes d JOIN orders o ON o.id = d.order_id WHERE d.status = 'open' AND (o.buyer_id = :a OR o.seller_id = :b)");
    $s->execute(['a' => $me, 'b' => $me]);
    if ((int) $s->fetchColumn() > 0) {
        $stop[] = 'You have an open dispute. It has to be resolved first.';
    }
    return $stop;
}
$delDb = (new Database())->getConnection();
$isAdminUser = !empty($currentUser['is_admin']);
$deleteBlockers = $isAdminUser ? ['Administrator accounts cannot be deleted here.'] : deleteBlockers($delDb, $me);

// The delete pop-up asks this first, so a wrong password is caught before the countdown starts.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_password') {
    header('Content-Type: application/json; charset=utf-8');
    $ok = $deleteBlockers === [] && $user->verifyPassword((string) ($_POST['password'] ?? ''), $currentUser['password_hash']);
    echo json_encode(['ok' => $ok, 'error' => $ok ? '' : ($deleteBlockers ? $deleteBlockers[0] : 'That password is not correct.')]);
    exit;
}

// Final step: only reached after both confirmations and the 10 second countdown.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_account') {
    if ($deleteBlockers === [] && $user->verifyPassword((string) ($_POST['password'] ?? ''), $currentUser['password_hash'])) {
        $gone = $user->deleteAccount($me);
        if (empty($gone['failed'])) {
            foreach ($gone['storage'] as $f) { @unlink($storageDir . $f); }
            foreach ($gone['listings'] as $f) { @unlink(__DIR__ . '/../public/uploads/listings/' . $f); }
            $_SESSION = [];
            session_destroy();
            ?><!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Account deleted - TrustTrade</title>
            <link rel="stylesheet" href="../public/css/style.css"></head><body class="app-body">
            <div class="card" style="max-width:480px;margin:4rem auto;text-align:center;padding:2rem">
                <h2>Your account has been deleted</h2>
                <p>Your name, email, photos and password have been erased. Thank you for using TrustTrade.</p>
                <p><a class="btn" href="login.php">Back to the start</a></p>
            </div></body></html><?php
            exit;
        }
    }
    $_SESSION['flash'] = ['error', 'Your account could not be deleted. Please check your password and try again.'];
    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $flash  = ['error', 'Something went wrong. Please try again.'];
    $editSection = null;   // when an edit in the pop-up fails, the pop-up opens again

    if ($action === 'update_name') {
        $name = trim($_POST['full_name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            $flash = ['error', 'Please enter a name (up to 100 characters).'];
            $editSection = 'name';
        } else {
            $user->updateName($me, $name);
            $_SESSION['user_name'] = $name;
            $flash = ['success', 'Your name has been updated.'];
        }

    } elseif ($action === 'update_email') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if (!$user->verifyPassword($_POST['current_password'] ?? '', $currentUser['password_hash'])) {
            $flash = ['error', 'Your password is incorrect.'];
            $editSection = 'email';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $flash = ['error', 'Please enter a valid email (max 150 characters).'];
            $editSection = 'email';
        } elseif ($email === strtolower((string) $currentUser['email'])) {
            $flash = ['error', 'That is already your email.'];
            $editSection = 'email';
        } elseif ($user->findByEmail($email) !== null) {
            $flash = ['error', 'An account with that email already exists.'];
            $editSection = 'email';
        } else {
            $user->updateEmail($me, $email);
            $flash = ['success', 'Your email has been updated. Use it the next time you log in.'];
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $editSection = 'password';

        if (!$user->verifyPassword($current, $currentUser['password_hash'])) {
            $flash = ['error', 'Your current password is incorrect.'];
        } elseif (strlen($new) < 8) {
            $flash = ['error', 'The new password must be at least 8 characters.'];
        } elseif (strlen($new) > 72) {
            $flash = ['error', 'The new password must be 72 characters or fewer.'];
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $flash = ['error', 'The new password must contain at least one letter and one number.'];
        } elseif ($new !== $confirm) {
            $flash = ['error', 'The new passwords do not match.'];
        } else {
            $user->updatePassword($me, $new);
            $flash = ['success', 'Your password has been changed.'];
            $editSection = null;
        }

    } elseif ($action === 'change_photo') {
        [$ext, $err] = checkPhoto($_FILES['photo'] ?? null);
        if ($err !== null) {
            $flash = ['error', $err];
        } else {
            $newName = 'face_' . $me . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $storageDir . $newName)) {
                if (!empty($currentUser['profile_photo'])) {
                    $old = $storageDir . basename($currentUser['profile_photo']);
                    if (is_file($old)) {
                        unlink($old);
                    }
                }
                $user->saveProfilePhoto($me, $newName);
                $flash = ['success', 'Your profile photo has been updated.'];
            } else {
                $flash = ['error', 'Something went wrong saving the photo. Please try again.'];
            }
        }
    }

    $_SESSION['flash'] = $flash;
    if ($editSection !== null && $flash[0] === 'error') {
        $_SESSION['edit_section'] = $editSection;
    }
    header('Location: profile.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$openSection = $_SESSION['edit_section'] ?? null;
unset($_SESSION['edit_section']);

$listingCount  = count((new Listing())->findByUser($me));
$orderModel    = new Order();
$requestsMade  = count($orderModel->forBuyer($me));
$requestsGot   = count($orderModel->forSeller($me));

if (!empty($currentUser['student_verified'])) {
    $verification = ['verified', 'Verified student'];
} elseif (!empty($currentUser['student_id_photo'])) {
    $verification = ['pending', 'Waiting for admin review'];
} else {
    $verification = ['open', 'Not verified yet'];
}
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$initial = mb_strtoupper(mb_substr(trim((string) $currentUser['full_name']), 0, 1));
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .profile-head { text-align: center; padding: 1.6rem 1rem 1.2rem; }
        .pp-wrap { position: relative; width: 150px; height: 150px; margin: 0 auto 0.9rem; }
        .pp-circle { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 4px solid var(--hl); background: var(--tint); color: var(--ink); display: flex; align-items: center; justify-content: center; font-size: 3.4rem; font-weight: 800; box-shadow: var(--shadow); }
        .pp-edit { position: absolute; right: 2px; bottom: 6px; width: 38px; height: 38px; border-radius: 50%; border: 3px solid #fff; background: var(--ink); color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; }
        .pp-edit:hover { background: var(--ink-hover); }
        .pp-edit svg { width: 18px; height: 18px; }
        .profile-head h3 { margin: 0 0 0.2rem; font-size: 1.4rem; }
        .btn-edit-profile { display: inline-flex; align-items: center; gap: 0.4rem; margin-top: 0.8rem; background: #fff; color: var(--ink); border: 2px solid var(--ink); border-radius: 999px; padding: 0.55rem 1.2rem; font: inherit; font-weight: 700; cursor: pointer; }
        .btn-edit-profile:hover { background: var(--tint); }
        dialog.edit-dialog { border: 0; border-radius: 16px; padding: 0; width: min(480px, calc(100vw - 2rem)); box-shadow: 0 20px 50px rgba(21,19,58,.4); }
        dialog.edit-dialog::backdrop { background: rgba(21,19,58,.45); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
        .ed-head { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.2rem; border-bottom: 1px solid var(--line); }
        .ed-head h3 { margin: 0; }
        .ed-close { border: 0; background: none; font-size: 1.6rem; cursor: pointer; color: var(--muted); line-height: 1; }
        .ed-body { padding: 0.4rem 1.2rem 1.2rem; max-height: 75vh; overflow-y: auto; }
        .ed-body details { border-bottom: 1px solid var(--line-soft); padding: 0.7rem 0; }
        .ed-body summary { cursor: pointer; font-weight: 700; list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .ed-body summary::-webkit-details-marker { display: none; }
        .ed-body summary::after { content: "\203A"; font-size: 1.4rem; color: var(--muted); transition: transform .15s; }
        .ed-body details[open] summary::after { transform: rotate(90deg); }
        .ed-body summary small { display: block; font-weight: 400; color: var(--muted); font-size: 0.82rem; }
        .ed-body form { margin-top: 0.7rem; }
        .ed-error { background: #fdecea; color: #611a15; border-radius: 10px; padding: 0.6rem 0.8rem; margin: 0.8rem 0 0; font-size: 0.9rem; font-weight: 600; }
        .danger-zone { margin: 1.4rem auto 0; max-width: 560px; text-align: center; }
        .btn-delete { background: none; border: 0; color: #a4262c; font: inherit; font-weight: 700; text-decoration: underline; cursor: pointer; }
        .del-step { display: none; } .del-step.on { display: block; }
        .del-warn { background: #fdecea; color: #611a15; border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.92rem; }
        .del-warn ul { margin: 0.4rem 0 0 1.1rem; padding: 0; }
        .del-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 1rem; }
        .btn-danger { background: #a4262c; color: #fff; border: 0; border-radius: 999px; padding: 0.6rem 1.2rem; font: inherit; font-weight: 700; cursor: pointer; }
        .btn-danger:disabled { opacity: .45; cursor: not-allowed; }
        .btn-keep { background: var(--ink); color: #fff; border: 0; border-radius: 999px; padding: 0.6rem 1.2rem; font: inherit; font-weight: 700; cursor: pointer; }
        .del-count { font-size: 4rem; font-weight: 800; text-align: center; color: #a4262c; margin: 0.4rem 0; }
        .del-bar { height: 8px; background: #eee; border-radius: 99px; overflow: hidden; } .del-bar i { display: block; height: 100%; background: #a4262c; width: 100%; transition: width 1s linear; }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <?php if ($flash && !($openSection !== null && $flash[0] === 'error')): ?>
            <div class="flash flash-<?= $h($flash[0]) ?>"><?= $h($flash[1]) ?></div>
        <?php endif; ?>

        <h2>Profile</h2>

        <div class="panel profile-head">
            <form method="POST" action="profile.php" enctype="multipart/form-data" id="photoForm">
                <input type="hidden" name="action" value="change_photo">
                <input type="file" name="photo" id="photoInput" accept="image/jpeg,image/png" hidden>
            </form>
            <div class="pp-wrap">
                <?php if (!empty($currentUser['profile_photo'])): ?>
                    <img class="pp-circle" src="profile_photo.php?user=<?= $me ?>&v=<?= substr(md5((string) $currentUser['profile_photo']), 0, 8) ?>" alt="Your profile photo">
                <?php else: ?>
                    <div class="pp-circle" aria-hidden="true"><?= $h($initial) ?></div>
                <?php endif; ?>
                <button type="button" class="pp-edit" id="photoBtn" aria-label="Change profile photo" title="Change profile photo">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                </button>
            </div>

            <h3><?= $h($currentUser['full_name']) ?></h3>
            <p class="muted" style="margin:0;"><?= $h($currentUser['email']) ?> · Member since <?= date('j M Y', strtotime($currentUser['created_at'])) ?></p>
            <p style="margin:0.6rem 0 0;">
                Verification:
                <span class="pill pill-<?= $verification[0] ?>"><?= $verification[1] ?></span>
                <?php if ($verification[0] === 'open'): ?>
                    <a href="upload_id.php">Verify now</a>
                <?php endif; ?>
            </p>
            <button type="button" class="btn-edit-profile" id="editBtn">Edit profile details <span aria-hidden="true">&#8250;</span></button>
        </div>

        <div class="panel">
            <div class="stat-row">
                <div class="stat"><strong><?= $listingCount ?></strong>Listings</div>
                <div class="stat"><strong><?= $requestsMade ?></strong>Requests made</div>
                <div class="stat"><strong><?= $requestsGot ?></strong>Requests received</div>
            </div>
        </div>
    </div>

    <dialog class="edit-dialog" id="editDialog">
        <div class="ed-head">
            <h3>Edit profile details</h3>
            <button type="button" class="ed-close" id="editClose" aria-label="Close">&times;</button>
        </div>
        <div class="ed-body">
            <?php if ($openSection !== null && $flash && $flash[0] === 'error'): ?>
                <div class="ed-error"><?= $h($flash[1]) ?></div>
            <?php endif; ?>

            <details<?= $openSection === 'name' ? ' open' : '' ?>>
                <summary><span>Name<small><?= $h($currentUser['full_name']) ?></small></span></summary>
                <form method="POST" action="profile.php" class="form-stack">
                    <input type="hidden" name="action" value="update_name">
                    <input type="text" name="full_name" maxlength="100" required value="<?= $h($currentUser['full_name']) ?>">
                    <button type="submit" class="btn-inline">Save name</button>
                </form>
            </details>

            <details<?= $openSection === 'email' ? ' open' : '' ?>>
                <summary><span>Email<small><?= $h($currentUser['email']) ?></small></span></summary>
                <form method="POST" action="profile.php" class="form-stack">
                    <input type="hidden" name="action" value="update_email">
                    <label>New email
                        <input type="email" name="email" maxlength="150" required>
                    </label>
                    <label>Your password (to confirm it is you)
                        <input type="password" name="current_password" required>
                    </label>
                    <button type="submit" class="btn-inline">Save email</button>
                </form>
            </details>

            <details<?= $openSection === 'password' ? ' open' : '' ?>>
                <summary><span>Password<small>Change your password</small></span></summary>
                <form method="POST" action="profile.php" class="form-stack">
                    <input type="hidden" name="action" value="change_password">
                    <label>Current password
                        <input type="password" name="current_password" required>
                    </label>
                    <label>New password (at least 8 characters, with a letter and a number)
                        <input type="password" name="new_password" required>
                    </label>
                    <label>Confirm new password
                        <input type="password" name="confirm_password" required>
                    </label>
                    <button type="submit" class="btn-inline">Change password</button>
                </form>
            </details>
        </div>
    </dialog>

    <div class="danger-zone"><button type="button" class="btn-delete" id="delBtn">Delete my account</button></div>

    <dialog class="edit-dialog" id="delDialog">
        <div class="ed-head"><h3>Delete account</h3><button type="button" class="ed-close" id="delClose" aria-label="Close">&times;</button></div>
        <div class="ed-body">
            <div class="del-step on" id="ds1">
                <div class="del-warn"><strong>Warning.</strong> Deleting your account cannot be undone. Your name, email, password, student ID photo and face photo are erased, and your verified student status is lost. Listings nobody has ordered are removed; listings with past orders stay hidden.</div>
                <?php if ($deleteBlockers): ?>
                    <div class="del-warn" style="margin-top:.7rem"><strong>You cannot delete your account yet:</strong><ul><?php foreach ($deleteBlockers as $b): ?><li><?= $h($b) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <div class="del-actions">
                    <button type="button" class="btn-keep" data-del-cancel>Keep my account</button>
                    <button type="button" class="btn-danger" id="ds1Next" <?= $deleteBlockers ? 'disabled' : '' ?>>I understand, continue</button>
                </div>
            </div>
            <div class="del-step" id="ds2">
                <div class="del-warn"><strong>Second warning.</strong> Are you absolutely sure? After this you get 10 seconds to change your mind. Type your password to confirm it is you.</div>
                <label style="display:block;margin-top:.8rem">Your password
                    <input type="password" id="delPass" autocomplete="current-password">
                </label>
                <div class="ed-error" id="delErr" style="display:none"></div>
                <div class="del-actions">
                    <button type="button" class="btn-keep" data-del-cancel>Keep my account</button>
                    <button type="button" class="btn-danger" id="ds2Next">Yes, delete my account</button>
                </div>
            </div>
            <div class="del-step" id="ds3">
                <p style="text-align:center;margin:.2rem 0"><strong>Deleting in&hellip;</strong></p>
                <div class="del-count" id="delCount">10</div>
                <div class="del-bar"><i id="delBar"></i></div>
                <p style="text-align:center;color:var(--muted);font-size:.92rem">Nothing has been deleted yet. Press the button below within 10 seconds and your account stays exactly as it is, including your verified status.</p>
                <div class="del-actions" style="justify-content:center"><button type="button" class="btn-keep" data-del-cancel>Cancel &ndash; keep my account</button></div>
                <form method="POST" action="profile.php" id="delForm">
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="password" id="delPassField">
                </form>
            </div>
        </div>
    </dialog>

    <script>
    (function () {
        var dlg = document.getElementById('editDialog');
        document.getElementById('editBtn').addEventListener('click', function () { dlg.showModal(); });
        document.getElementById('editClose').addEventListener('click', function () { dlg.close(); });
        dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });   // click on the blurred background
        var input = document.getElementById('photoInput');
        document.getElementById('photoBtn').addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () { if (input.files.length) { document.getElementById('photoForm').submit(); } });
        <?php if ($openSection !== null): ?>dlg.showModal();<?php endif; ?>
    })();
    </script>
    <script>
    (function () {
        var d = document.getElementById('delDialog'), timer = null;
        function show(n) { [1, 2, 3].forEach(function (i) { document.getElementById('ds' + i).classList.toggle('on', i === n); }); }
        function reset() {
            clearInterval(timer); timer = null; show(1);
            document.getElementById('delPass').value = '';
            document.getElementById('delErr').style.display = 'none';
            document.getElementById('ds2Next').disabled = false;
        }
        document.getElementById('delBtn').addEventListener('click', function () { reset(); d.showModal(); });
        document.getElementById('delClose').addEventListener('click', function () { d.close(); });
        d.addEventListener('close', reset);                       // closing the box at any time cancels everything
        d.addEventListener('cancel', function () { reset(); });   // Esc key
        Array.prototype.forEach.call(document.querySelectorAll('[data-del-cancel]'), function (b) { b.addEventListener('click', function () { d.close(); }); });
        document.getElementById('ds1Next').addEventListener('click', function () { show(2); document.getElementById('delPass').focus(); });
        document.getElementById('ds2Next').addEventListener('click', function () {
            var pass = document.getElementById('delPass').value, err = document.getElementById('delErr'), btn = this;
            if (pass === '') { err.textContent = 'Please type your password.'; err.style.display = 'block'; return; }
            btn.disabled = true;
            var fd = new FormData(); fd.append('action', 'check_password'); fd.append('password', pass);
            fetch('profile.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { err.textContent = j.error || 'That password is not correct.'; err.style.display = 'block'; btn.disabled = false; return; }
                    document.getElementById('delPassField').value = pass;
                    show(3);
                    var left = 10, num = document.getElementById('delCount'), bar = document.getElementById('delBar');
                    num.textContent = left; bar.style.transition = 'none'; bar.style.width = '100%';
                    setTimeout(function () { bar.style.transition = 'width 10s linear'; bar.style.width = '0%'; }, 30);
                    timer = setInterval(function () {
                        left--; num.textContent = left;
                        if (left <= 0) { clearInterval(timer); timer = null; document.getElementById('delForm').submit(); }
                    }, 1000);
                })
                .catch(function () { err.textContent = 'Could not check your password. Please try again.'; err.style.display = 'block'; btn.disabled = false; });
        });
    })();
    </script>
</body>
</html>
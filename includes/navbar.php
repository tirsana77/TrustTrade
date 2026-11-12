<?php
require_once __DIR__ . '/../models/Message.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../models/Automation.php';

// Automatic rules (payment timeouts, listing holds) run when pages load, at most once a minute
Automation::runIfDue();

// Guests have no $currentUser. Logged-in users get the slide-out menu.
$currentUser = $currentUser ?? null;
$isGuestNav  = empty($currentUser);
$unreadCount = $isGuestNav ? 0 : (new Message())->unreadCount((int) $currentUser['id']);
$notifCount  = $isGuestNav ? 0 : (new Notification())->unreadCount((int) $currentUser['id']);
$navUnreadTotal = $unreadCount + $notifCount;
$firstName   = $isGuestNav ? '' : explode(' ', trim((string) ($currentUser['full_name'] ?? '')))[0];

// Pages without their own "back" link get an automatic one under the navbar.
$navBackMap = [
    // file => [fallback page, name, mode]. "home": a main page, always "Home". "smart": "Back" when the person came from another page of the site, otherwise "Home".
    'my_listings.php'    => ['dashboard.php', 'Home', 'home'],
    'orders.php'         => ['dashboard.php', 'Home', 'home'],
    'messages.php'       => ['dashboard.php', 'Home', 'home'],
    'notifications.php'  => ['dashboard.php', 'Home', 'home'],
    'profile.php'        => ['dashboard.php', 'Home', 'home'],
    'upload_id.php'      => ['dashboard.php', 'Home', 'smart'],
    'how_it_works.php'   => ['dashboard.php', 'Home', 'smart'],
    'conversation.php'   => ['messages.php', 'Messages', 'smart'],
];
$navPage = basename($_SERVER['SCRIPT_NAME']);

// On the dashboard, a search or category filter gets a way back to the full list.
if ($navPage === 'dashboard.php' && (!empty($_GET['q']) || !empty($_GET['category']))) {
    $navBackMap['dashboard.php'] = ['dashboard.php', 'all listings', 'home'];
}

// Menu entries: [file, label, badge count]
$menuItems = [
    ['dashboard.php',     'Home',          0],
    ['my_listings.php',   'My listings',   0],
    ['orders.php',        'Orders',        0],
    ['messages.php',      'Messages',      $unreadCount],
    ['notifications.php', 'Notifications', $notifCount],
    ['profile.php',       'Profile',       0],
    ['how_it_works.php',  'How it works',  0],
];
if (!$isGuestNav && !empty($currentUser['is_admin'])) {
    $menuItems[] = ['admin.php', 'Admin', 0];
}

// Once per login: a short pop-up that says what is waiting (unread messages and notifications)
$loginToast = null;
$freshLogin = false;
if (!$isGuestNav && empty($_SESSION['login_toast_done'])) {
    $_SESSION['login_toast_done'] = true;
    $freshLogin = true;
    if ($navUnreadTotal > 0) {
        $latest = [];
        foreach ((new Notification())->forUser((int) $currentUser['id'], 10) as $n) {
            if (empty($n['is_read'])) { $latest[] = $n; }
            if (count($latest) === 3) { break; }
        }
        $loginToast = ['messages' => $unreadCount, 'notifs' => $notifCount, 'latest' => $latest];
    }
}
?>
<div class="top-wrap">
    <?php if (!$isGuestNav && !empty($currentUser['is_admin'])): ?>
        <div class="admin-strip">
            <span>You are viewing the student site as an administrator.</span>
            <a href="admin.php">← Back to admin console</a>
        </div>
    <?php endif; ?>

    <div class="navbar">
        <a href="dashboard.php"><img src="../public/images/logo.svg" alt="TrustTrade" class="navbar-logo"></a>

        <form action="dashboard.php" method="GET" class="navbar-search">
            <input type="text" name="q" placeholder="Search listings" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
        </form>

        <?php if ($isGuestNav): ?>
            <nav class="guest-nav">
                <a href="dashboard.php">Home</a>
                <a href="how_it_works.php">How it works</a>
                <a href="login.php">Log in</a>
                <a href="register.php" class="btn-small">Sign up</a>
            </nav>
        <?php else: ?>
            <button type="button" class="nav-toggle" id="navToggle" aria-label="Open menu" aria-expanded="false" aria-controls="navDrawer">
                <span class="bar"></span><span class="bar"></span><span class="bar"></span>
                <?php if ($navUnreadTotal > 0): ?><span class="nav-dot" aria-hidden="true"></span><?php endif; ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$isGuestNav): ?>
        <div class="drawer-overlay" id="navOverlay"></div>
        <aside class="drawer" id="navDrawer" aria-label="Main menu">
            <div class="drawer-head">
                <span class="drawer-hello" style="display:flex;align-items:center;gap:0.6rem;">
                    <?php if (!empty($currentUser['profile_photo'])): ?>
                        <img src="profile_photo.php?user=<?= (int) $currentUser['id'] ?>&v=<?= substr(md5((string) $currentUser['profile_photo']), 0, 8) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--hl);">
                    <?php else: ?>
                        <span aria-hidden="true" style="width:40px;height:40px;border-radius:50%;background:var(--tint);color:var(--ink);display:inline-flex;align-items:center;justify-content:center;font-weight:700;border:2px solid var(--hl);"><?= htmlspecialchars(mb_strtoupper(mb_substr($firstName !== '' ? $firstName : '?', 0, 1))) ?></span>
                    <?php endif; ?>
                    Hi, <?= htmlspecialchars($firstName !== '' ? $firstName : 'there') ?>
                </span>
                <button type="button" class="drawer-close" aria-label="Close menu">&times;</button>
            </div>
            <nav class="drawer-nav">
                <?php foreach ($menuItems as [$file, $label, $count]): ?>
                    <a href="<?= $file ?>"<?= $navPage === $file ? ' class="active" aria-current="page"' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                        <?php if ($count > 0): ?><span class="badge"><?= (int) $count ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <a href="logout.php" class="drawer-logout">Log out</a>
        </aside>
        <script>
        (function () {
            var toggle = document.getElementById('navToggle');
            var drawer = document.getElementById('navDrawer');
            var overlay = document.getElementById('navOverlay');
            if (!toggle || !drawer || !overlay) { return; }
            function setOpen(open) {
                drawer.classList.toggle('open', open);
                overlay.classList.toggle('show', open);
                document.body.classList.toggle('drawer-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
                if (open) { drawer.querySelector('.drawer-close').focus(); } else { toggle.focus(); }
            }
            toggle.addEventListener('click', function () { setOpen(!drawer.classList.contains('open')); });
            overlay.addEventListener('click', function () { setOpen(false); });
            drawer.querySelector('.drawer-close').addEventListener('click', function () { setOpen(false); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && drawer.classList.contains('open')) { setOpen(false); }
            });
        })();
        </script>
    <?php endif; ?>
</div>

<?php if ($loginToast): ?>
    <style>
        .login-toast { position: fixed; top: 78px; right: 1rem; z-index: 60; width: min(340px, calc(100vw - 2rem)); background: #fff; border: 2px solid var(--ink); border-top: 6px solid var(--hl); border-radius: 14px; box-shadow: 0 10px 30px rgba(35,33,91,.25); padding: 0.9rem 1rem 0.7rem; animation: ltIn .3s ease-out; }
        .login-toast.hide { opacity: 0; transform: translateY(-8px); transition: opacity .4s, transform .4s; }
        @keyframes ltIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: none; } }
        .login-toast h4 { margin: 0 1.6rem 0.4rem 0; color: var(--ink); font-size: 1rem; }
        .login-toast a.lt-row { display: block; padding: 0.35rem 0; color: var(--text); text-decoration: none; font-size: 0.9rem; border-top: 1px solid var(--line-soft); }
        .login-toast a.lt-row:hover { color: var(--ink); text-decoration: underline; }
        .login-toast .lt-close { position: absolute; top: 0.4rem; right: 0.6rem; border: 0; background: none; font-size: 1.3rem; cursor: pointer; color: var(--muted); }
        .login-toast .lt-bar { height: 4px; background: var(--tint); border-radius: 4px; overflow: hidden; margin-top: 0.5rem; }
        .login-toast .lt-bar i { display: block; height: 100%; background: var(--ink); transform-origin: left; animation: ltBar 5s linear forwards; }
        @keyframes ltBar { to { transform: scaleX(0); } }
    </style>
    <div class="login-toast" id="loginToast" role="status">
        <button type="button" class="lt-close" aria-label="Close">&times;</button>
        <h4>Welcome back<?= $firstName !== '' ? ', ' . htmlspecialchars($firstName) : '' ?>. You have news:</h4>
        <?php if ($loginToast['messages'] > 0): ?>
            <a class="lt-row" href="messages.php"><strong><?= (int) $loginToast['messages'] ?></strong> unread message<?= $loginToast['messages'] === 1 ? '' : 's' ?></a>
        <?php endif; ?>
        <?php if ($loginToast['notifs'] > 0): ?>
            <?php foreach ($loginToast['latest'] as $n): ?>
                <a class="lt-row" href="<?= htmlspecialchars(!empty($n['link']) ? $n['link'] : 'notifications.php') ?>"><?= htmlspecialchars(mb_strimwidth((string) $n['message'], 0, 90, '...')) ?></a>
            <?php endforeach; ?>
            <?php if ($loginToast['notifs'] > count($loginToast['latest'])): ?>
                <a class="lt-row" href="notifications.php">and <?= (int) ($loginToast['notifs'] - count($loginToast['latest'])) ?> more notification<?= ($loginToast['notifs'] - count($loginToast['latest'])) === 1 ? '' : 's' ?></a>
            <?php endif; ?>
        <?php endif; ?>
        <div class="lt-bar"><i></i></div>
    </div>
    <script>
    (function () {
        var t = document.getElementById('loginToast');
        if (!t) { return; }
        function gone() { t.classList.add('hide'); setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 450); }
        t.querySelector('.lt-close').addEventListener('click', gone);
        var timer = setTimeout(gone, 5000);
        t.addEventListener('mouseenter', function () { clearTimeout(timer); t.querySelector('.lt-bar').style.display = 'none'; });
    })();
    </script>
<?php endif; ?>

<?php if (!$isGuestNav && $navPage !== 'conversation.php'): ?>
    <script src="../public/js/chatdock.js" data-me="<?= (int) $currentUser['id'] ?>"<?= $freshLogin ? ' data-fresh="1"' : '' ?>></script>
<?php endif; ?>

<?php if (isset($navBackMap[$navPage])): ?>
    <div class="backbar">
        <a href="<?= $navBackMap[$navPage][0] ?>" class="back-link" data-mode="<?= $navBackMap[$navPage][2] ?? 'home' ?>" data-fallback="<?= htmlspecialchars($navBackMap[$navPage][1]) ?>" title="Back to <?= htmlspecialchars($navBackMap[$navPage][1]) ?>">&larr; <?= ($navBackMap[$navPage][2] ?? 'home') === 'smart' ? 'Back' : ($navBackMap[$navPage][1] === 'Home' ? 'Home' : 'Back to ' . htmlspecialchars($navBackMap[$navPage][1])) ?></a>
    </div>
<?php endif; ?>

<script>
// Two kinds of links:
//  - main pages (Orders, Messages, Profile ...) have a "Home" link that always goes to the home page.
//  - detail and flow pages (listing, order, chat, create/edit listing) have a "Back" link that goes back to the page the person came from,
//    so search results and earlier choices are still there. If there is no earlier page of this site, or a form was just submitted on this page,
//    the link's own address is used.
(function () {
    var links = document.querySelectorAll('a.back-link, a.op-back');
    if (!links.length) { return; }
    var ref = document.referrer, sameSite = false, refPath = '';
    try {
        var r = new URL(ref);
        sameSite = (r.origin === location.origin);
        refPath = r.pathname + r.search;
    } catch (e) { }
    var skip = /\/(login|register|logout|pay_return)\.php/.test(refPath) || refPath === location.pathname + location.search;
    var useHistory = sameSite && !skip && history.length > 1;
    Array.prototype.forEach.call(links, function (a) {
        var mode = a.getAttribute('data-mode');
        if (!a.getAttribute('title')) { a.setAttribute('title', a.textContent.trim()); }
        if (mode === 'home') { return; }                      // always "Home", plain link
        if (mode === 'smart' && !useHistory) { a.innerHTML = '&larr; ' + (a.getAttribute('data-fallback') || 'Home'); return; }
        a.innerHTML = '&larr; Back';
        if (useHistory) {
            a.addEventListener('click', function (e) { e.preventDefault(); history.back(); });
        }
    });
})();
</script>
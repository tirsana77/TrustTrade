<?php
session_start();

require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Listing.php';

// Guests (not logged in) can browse. $currentUser stays null for them.
$currentUser = null;
if (!empty($_SESSION['user_id'])) {
    $currentUser = (new User())->findById((int) $_SESSION['user_id']);
    if (!$currentUser) {
        unset($_SESSION['user_id'], $_SESSION['user_name']);
    }
}
$isGuest = ($currentUser === null);
$isAdmin = !$isGuest && !empty($currentUser['is_admin']);

// Dismiss the one-time welcome card
if (!$isGuest && ($_GET['welcome'] ?? '') === 'done') {
    (new User())->markWelcomeSeen((int) $currentUser['id']);
    header('Location: dashboard.php');
    exit;
}

if ($isGuest) {
    $verificationStatus = 'guest';
} elseif ($currentUser['student_verified'] == 1) {
    $verificationStatus = 'verified';
} elseif (!empty($currentUser['student_id_photo'])) {
    $verificationStatus = 'pending';
} else {
    $verificationStatus = 'not_started';
}

$categories = ['Textbooks', 'Electronics', 'Calculators', 'Stationery', 'Other'];
$q = trim($_GET['q'] ?? '');
$category = $_GET['category'] ?? '';
if (!in_array($category, $categories, true)) {
    $category = '';
}

$userId = $isGuest ? null : (int) $currentUser['id'];
$listingModel = new Listing();
$listings = $listingModel->search($q, $category, $userId);
$isFiltering = ($q !== '' || $category !== '');
$recommended = ($isFiltering || $isGuest) ? [] : $listingModel->getRecommendations($userId, 4);

$heroPhotos = ['s1.jpg', 's2.jpg', 's3.jpg', 's4.jpg'];

function chipUrl(string $q, string $category): string
{
    $params = [];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($category !== '') {
        $params['category'] = $category;
    }
    return 'dashboard.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Home - TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        button.info-btn { font: inherit; text-align: left; cursor: pointer; width: 100%; }
        button.info-btn:hover, button.info-btn:focus-visible { background: var(--tint); outline: 2px solid var(--ink); }
        .info-overlay { position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(21,19,58,.35); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); animation: infoFade .2s ease-out; }
        @keyframes infoFade { from { opacity: 0; } to { opacity: 1; } }
        .info-pop { position: relative; width: min(460px, 100%); background: #fff; border: 2px solid var(--ink); border-top: 6px solid var(--hl); border-radius: 14px; box-shadow: 0 16px 40px rgba(35,33,91,.35); padding: 1.2rem 1.3rem 1rem; }
        .info-pop h4 { margin: 0 1.8rem 0.5rem 0; font-size: 1.15rem; color: var(--ink); }
        .info-pop p { margin: 0 0 0.8rem; font-size: 1rem; line-height: 1.45; }
        .info-pop .info-close { position: absolute; top: 0.5rem; right: 0.6rem; border: 0; background: none; font-size: 1.4rem; line-height: 1; cursor: pointer; color: var(--muted); }
        .info-pop .info-bar { height: 4px; background: var(--tint); border-radius: 4px; overflow: hidden; margin-top: 0.5rem; }
        .info-pop .info-bar i { display: block; height: 100%; width: 100%; background: var(--ink); transform-origin: left; animation: infoBar 18s linear forwards; }
        @keyframes infoBar { to { transform: scaleX(0); } }
    </style>
</head>
<body class="app-body">
    <?php include __DIR__ . '/../includes/navbar.php'; ?>

    <div class="page-content">
        <?php if ($verificationStatus === 'not_started' && !$isAdmin): ?>
            <div class="banner banner-action">
                <span>Verify your student ID to start buying, selling and trading.</span>
                <a href="upload_id.php">Verify now</a>
            </div>
        <?php elseif ($verificationStatus === 'pending' && !$isAdmin): ?>
            <div class="banner banner-pending">
                Your student ID has been submitted and is waiting for admin review.
            </div>
        <?php endif; ?>

        <?php if (!$isFiltering): ?>
            <div class="hero" id="heroSlides">
                <?php foreach ($heroPhotos as $i => $file): ?>
                    <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>"
                         style="background-image:url('../public/images/students/<?= htmlspecialchars($file) ?>')"></div>
                <?php endforeach; ?>
                <div class="hero-shade"></div>
                <div class="hero-content">
                    <h1>Buy, sell and trade study gear with verified students</h1>
                    <p>Laptops, textbooks, calculators and more, from people on your campus.</p>
                    <div class="hero-actions">
                        <a href="#listings" class="btn-hero">Browse listings</a>
                        <?php if ($isGuest): ?>
                            <a href="register.php" class="btn-hero-ghost">Join with your student ID</a>
                        <?php elseif ($verificationStatus === 'verified'): ?>
                            <a href="create_listing.php" class="btn-hero-ghost">Sell an item</a>
                        <?php elseif ($verificationStatus === 'not_started' && !$isAdmin): ?>
                            <a href="upload_id.php" class="btn-hero-ghost">Verify now</a>
                        <?php endif; ?>
                    </div>
                    <div class="slide-dots" id="heroDots">
                        <?php foreach ($heroPhotos as $i => $file): ?>
                            <button type="button" class="dot<?= $i === 0 ? ' active' : '' ?>"
                                    aria-label="Show photo <?= $i + 1 ?>" data-i="<?= $i ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isGuest && !$isAdmin && $verificationStatus === 'verified' && empty($currentUser['welcome_seen']) && !$isFiltering): ?>
            <div class="welcome-card">
                <h3>Welcome to TrustTrade, <?= htmlspecialchars(explode(' ', trim($currentUser['full_name']))[0]) ?>. You are verified.</h3>
                <ol>
                    <li><strong>Buy safely:</strong> request an item, and pay only through TrustTrade. Your money is held in escrow until you confirm you received it.</li>
                    <li><strong>Sell with help:</strong> the AI price hint shows what similar items sell for.</li>
                    <li><strong>If something goes wrong:</strong> use "Report a problem" on the order. The payment is frozen and an administrator decides.</li>
                </ol>
                <a href="how_it_works.php" class="btn-small">How it works</a>
                <a href="dashboard.php?welcome=done" class="btn-small btn-ghost">Got it</a>
            </div>
        <?php endif; ?>

        <?php if (!$isGuest && !$isFiltering): ?>
            <div class="safe-strip">
                <button type="button" class="why-item info-btn" data-info-title="Verified students" data-info="Every seller on TrustTrade has uploaded a student ID that an administrator has checked and approved. Only approved students can list items, request items or send messages, so you always deal with a real student."><strong>Verified students</strong><span>Every seller has an approved student ID.</span></button>
                <button type="button" class="why-item info-btn" data-info-title="Escrow payments" data-info="When your request is accepted you pay through TrustTrade, not to the seller. The money is held safely until you press &quot;I received the item&quot;. If you do nothing, it is released automatically after 7 days. If something is wrong, report a problem and the payment is frozen until an administrator decides."><strong>Escrow payments</strong><span>Money is held until you confirm receipt.</span></button>
                <button type="button" class="why-item info-btn" data-info-title="AI checks" data-info="When a seller writes a listing, the AI suggests a fair price based on similar items and gives a scam-risk score with the reasons. The AI only advises: people and administrators make the decisions."><strong>AI checks</strong><span>Price hints and scam-risk checks on listings.</span></button>
                <button type="button" class="why-item info-btn" data-info-title="How it works" data-info="1. Request an item. 2. The seller accepts. 3. Agree the hand-over (meet-up, pick-up or post). 4. Pay through TrustTrade. 5. Confirm that you received it, and the seller is paid. Automatic deadlines protect both sides if someone stops answering." data-info-link="how_it_works.php" data-info-link-text="Read all the rules"><strong>How it works</strong><span>Rules, deadlines and what happens in a dispute.</span></button>
            </div>
        <?php endif; ?>

        <?php if ($isGuest && !$isFiltering): ?>
            <div class="why-strip">
                <button type="button" class="why-item info-btn" data-info-title="Verified students only" data-info="Every seller has uploaded a student ID that an administrator has approved. Create an account and upload your ID to join."><strong>Verified students only</strong><span>Every seller has an approved student ID.</span></button>
                <button type="button" class="why-item info-btn" data-info-title="Chat before you buy" data-info="Once you are verified you can message any seller inside TrustTrade, ask questions and agree how to hand over the item. You never need to share a phone number."><strong>Chat before you buy</strong><span>Message sellers inside TrustTrade.</span></button>
                <button type="button" class="why-item info-btn" data-info-title="Report and dispute" data-info="If something goes wrong you can report a problem on the order. The payment is frozen, the seller can reply, and an administrator reads both sides and decides." data-info-link="how_it_works.php" data-info-link-text="Read all the rules"><strong>Report and dispute</strong><span>Admins review reports and settle disputes.</span></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($recommended)): ?>
            <h2 class="section-title">Recommended for you</h2>
            <div class="listing-grid">
                <?php foreach ($recommended as $listing): ?>
                    <?php include __DIR__ . '/../includes/listing_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="page-header-row" id="listings">
            <h2>
                <?php if ($q !== ''): ?>
                    Results for "<?= htmlspecialchars($q) ?>" (<?= count($listings) ?>)
                <?php else: ?>
                    All listings
                <?php endif; ?>
                <?php if ($verificationStatus === 'verified' && !$isAdmin): ?>
                    <span class="pill pill-completed" style="vertical-align:middle; margin-left:0.4rem;">✓ Verified</span>
                <?php endif; ?>
            </h2>
            <?php if ($verificationStatus === 'verified'): ?>
                <a href="create_listing.php" class="btn-inline">+ List an item</a>
            <?php endif; ?>
        </div>

        <?php if ($q !== ''): ?>
            <p class="muted" style="margin-top:-0.5rem;">Sorted by relevance, popularity and the categories you browse most.</p>
        <?php endif; ?>

        <div class="chips">
            <a href="<?= htmlspecialchars(chipUrl($q, '')) ?>" class="chip <?= $category === '' ? 'chip-active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="<?= htmlspecialchars(chipUrl($q, $cat)) ?>" class="chip <?= $category === $cat ? 'chip-active' : '' ?>"><?= $cat ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($listings)): ?>
            <p class="muted">No listings found.</p>
        <?php else: ?>
            <div class="listing-grid">
                <?php foreach ($listings as $listing): ?>
                    <?php include __DIR__ . '/../includes/listing_card.php'; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        var slides = document.querySelectorAll('#heroSlides .hero-slide');
        var dots   = document.querySelectorAll('#heroDots .dot');
        if (slides.length < 2) { return; }
        var current = 0, timer = null;

        function show(n) {
            slides[current].classList.remove('active');
            dots[current].classList.remove('active');
            current = (n + slides.length) % slides.length;
            slides[current].classList.add('active');
            dots[current].classList.add('active');
        }
        function start() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
            timer = setInterval(function () { show(current + 1); }, 5000);
        }
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                clearInterval(timer);
                show(parseInt(dot.getAttribute('data-i'), 10));
                start();
            });
        });
        start();
    })();
    </script>
    <script>
    // Info boxes: clicking a box on the home page shows a small card on the same page for 18 seconds
    (function () {
        var overlay = null, pop = null, timer = null;
        function close() { if (overlay) { overlay.remove(); overlay = null; pop = null; } clearTimeout(timer); }
        function open(btn) {
            close();
            overlay = document.createElement('div');
            overlay.className = 'info-overlay';
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
            pop = document.createElement('div');
            pop.className = 'info-pop';
            pop.setAttribute('role', 'status');
            var h = document.createElement('h4'); h.textContent = btn.getAttribute('data-info-title');
            var p = document.createElement('p');  p.textContent = btn.getAttribute('data-info');
            var x = document.createElement('button'); x.type = 'button'; x.className = 'info-close'; x.setAttribute('aria-label', 'Close'); x.textContent = '\u00d7';
            x.addEventListener('click', close);
            pop.appendChild(x); pop.appendChild(h); pop.appendChild(p);
            var link = btn.getAttribute('data-info-link');
            if (link) {
                var a = document.createElement('a'); a.href = link; a.className = 'btn-small'; a.textContent = btn.getAttribute('data-info-link-text') || 'Read more';
                pop.appendChild(a);
            }
            var bar = document.createElement('div'); bar.className = 'info-bar'; bar.appendChild(document.createElement('i'));
            pop.appendChild(bar);
            overlay.appendChild(pop);
            document.body.appendChild(overlay);
            timer = setTimeout(close, 18000);
        }
        document.querySelectorAll('.info-btn').forEach(function (b) {
            b.addEventListener('click', function () { open(b); });
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    })();
    </script>
</body>
</html>
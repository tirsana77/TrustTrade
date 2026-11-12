<aside class="admin-sidebar">
    <div class="admin-brand">
        <div class="brand-chip"><img src="../public/images/logo.svg" alt="TrustTrade"></div>
        <div class="admin-label">ADMIN CONSOLE</div>
    </div>

    <nav class="admin-nav">
        <?php if ($tab !== 'overview'): ?>
            <a href="admin.php?tab=overview"><span>← Back to overview</span></a>
            <div class="nav-sep"></div>
        <?php endif; ?>

        <?php foreach ($titles as $key => $label): ?>
            <a href="admin.php?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>">
                <span><?= $label ?></span>
                <?php if (!empty($counts[$key])): ?><span class="badge"><?= $counts[$key] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>

        <div class="nav-sep"></div>
        <a href="dashboard.php"><span>View student site</span></a>
        <a href="logout.php"><span>Log out</span></a>
    </nav>

    <div class="admin-user">
        Signed in as<br>
        <strong style="color:#e4e6eb;"><?= htmlspecialchars($currentUser['full_name']) ?></strong>
    </div>
</aside>
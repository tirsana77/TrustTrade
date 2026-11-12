<?php
// Works for logged-in students and for guests ($currentUser is null for guests).
$cardDesc = $listing['description'];
if (strlen($cardDesc) > 80) {
    $cardDesc = substr($cardDesc, 0, 80) . '...';
}
$cardViewerId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;
$cardIsGuest  = ($cardViewerId === null);
$cardSeller   = (string) $listing['seller_name'];
if ($cardIsGuest) {
    $cardSeller = strtok($cardSeller, ' ') ?: $cardSeller;   // guests only see the first name
}
?>
<a href="view_listing.php?id=<?= (int) $listing['id'] ?>" class="listing-link">
    <div class="listing-card">
        <?php if (!empty($listing['images'])): ?>
            <img class="listing-cover" src="../public/uploads/listings/<?= htmlspecialchars($listing['images'][0]) ?>" alt="">
        <?php else: ?>
            <div class="listing-cover listing-cover-empty">No photo</div>
        <?php endif; ?>

        <?php if (!empty($listing['reason'])): ?>
            <p class="listing-reason"><?= htmlspecialchars($listing['reason']) ?></p>
        <?php endif; ?>

        <h3><?= htmlspecialchars($listing['title']) ?></h3>
        <p class="listing-price"><?= number_format((float) $listing['price'], 2) ?> DKK</p>
        <p class="listing-category"><?= htmlspecialchars($listing['category']) ?></p>
        <p class="listing-desc"><?= htmlspecialchars($cardDesc) ?></p>
        <p class="listing-seller">
            <?php if ($listing['status'] === 'sold'): ?><span class="tag tag-sold">Sold</span><?php endif; ?>
            <?php if ($cardViewerId !== null && (int) $listing['user_id'] === $cardViewerId): ?><span class="tag tag-you">Your listing</span><?php endif; ?>
            <span class="tag tag-muted"><?= count($listing['images']) ?> photos</span>
            <?= htmlspecialchars($cardSeller) ?>
        </p>
    </div>
</a>
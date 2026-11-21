<?php
require_once __DIR__ . '/../config/session.php';
startAppSession();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/coupons.php';

$currentCustomerId = isset($_SESSION["user_id"])
    ? (int) $_SESSION["user_id"]
    : null;
$availableCoupons = getAvailableCoupons($pdo, $currentCustomerId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Explore current VELOURA beauty offers and redeem eligible coupon codes at checkout.">
    <title>Offers | VELOURA</title>
    <link rel="stylesheet" href="assets/css/style.css?v=2">
    <link rel="stylesheet" href="assets/css/offers.css">
</head>
<body>
    <div class="announcement-bar">A little more beauty in every order</div>

    <?php require __DIR__ . '/partials/store-header.php'; ?>

    <main class="offers-page">
        <section class="offers-intro">
            <p class="subtitle">A LITTLE SOMETHING EXTRA</p>
            <h1>Offers, chosen for you.</h1>
            <p>Browse current offers available for your account and apply a code at checkout.</p>
        </section>

        <?php if ($availableCoupons): ?>
            <section class="offers-grid" aria-label="Available offers">
                <?php foreach ($availableCoupons as $offer): ?>
                    <?php
                    $expiresAt = $offer["expires_at"] ?? null;
                    $expiryLabel = couponOfferExpiry($expiresAt);
                    ?>
                    <article class="offer-card" data-offer-card>
                        <div class="offer-card-top">
                            <span class="offer-kicker">VELOURA OFFER</span>
                            <span class="offer-discount">
                                <?= htmlspecialchars(couponOfferDiscountLabel($offer), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>

                        <p class="offer-description">
                            <?= htmlspecialchars(couponOfferDescription($offer), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <?php if ($expiresAt && $expiryLabel !== ""): ?>
                            <p class="offer-expiry">
                                Ends <time datetime="<?= htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($expiryLabel, ENT_QUOTES, 'UTF-8') ?></time>
                            </p>
                        <?php else: ?>
                            <p class="offer-expiry">Available while this offer remains active.</p>
                        <?php endif; ?>

                        <div class="offer-code-panel">
                            <span>Coupon code</span>
                            <strong class="offer-code"><?= htmlspecialchars($offer["code"], ENT_QUOTES, 'UTF-8') ?></strong>
                            <button
                                type="button"
                                class="offer-copy-button"
                                data-copy-coupon
                                data-coupon-code="<?= htmlspecialchars($offer["code"], ENT_QUOTES, 'UTF-8') ?>"
                            >Copy code</button>
                        </div>
                        <p class="offer-copy-feedback" aria-live="polite" data-copy-feedback></p>
                        <a class="offer-shop-button" href="products.php">Shop now</a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php else: ?>
            <section class="offers-empty">
                <span class="offers-empty-mark" aria-hidden="true">V</span>
                <p class="subtitle">THOUGHTFULLY MADE FOR YOU</p>
                <h2>No offers just now</h2>
                <p>New eligible offers will appear here. In the meantime, discover the VELOURA collection.</p>
                <a class="primary-btn" href="products.php">Explore the collection</a>
            </section>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <a href="index.php" class="logo">VELOURA<span>.</span></a>
        <p>Discover Your Natural Glow.</p>
        <div class="footer-links">
            <a href="index.php">Home</a>
            <a href="products.php">Shop</a>
            <a href="offers.php">Offers</a>
        </div>
        <p class="copyright">&copy; 2026 VELOURA. All rights reserved.</p>
    </footer>

    <script src="assets/js/offers.js"></script>
</body>
</html>

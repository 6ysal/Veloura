
<?php
require_once __DIR__ . '/../config/session.php';
startAppSession();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/coupons.php';

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$contactError = "";
$contactSuccess = $_SESSION["contact_success"] ?? "";
unset($_SESSION["contact_success"]);
$newsletterError = "";
$newsletterSuccessData = $_SESSION["newsletter_success"] ?? "";
unset($_SESSION["newsletter_success"]);
$newsletterSuccess = is_array($newsletterSuccessData)
    ? (string) ($newsletterSuccessData["message"] ?? "")
    : (is_string($newsletterSuccessData) ? $newsletterSuccessData : "");
$newsletterCouponCode = is_array($newsletterSuccessData)
    ? (string) ($newsletterSuccessData["coupon_code"] ?? "")
    : "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["newsletter_submit"])) {
    $emailInput = $_POST["newsletter_email"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";
    $newsletterEmail = is_string($emailInput) ? trim($emailInput) : "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $newsletterError = "Your session expired. Please refresh the page and try again.";
    } elseif (
        strlen($newsletterEmail) > 254 ||
        !filter_var($newsletterEmail, FILTER_VALIDATE_EMAIL)
    ) {
        $newsletterError = "Enter a valid email address.";
    } else {
        try {
            $pdo->beginTransaction();

            $subscriberStmt = $pdo->prepare("
                SELECT
                    ns.coupon_id,
                    c.code,
                    c.status AS coupon_status,
                    c.max_uses,
                    c.used_count,
                    (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP()) AS coupon_is_current
                FROM newsletter_subscribers ns
                LEFT JOIN coupons c ON c.id = ns.coupon_id
                WHERE ns.email = :email
                LIMIT 1
                FOR UPDATE
            ");
            $subscriberStmt->execute(["email" => $newsletterEmail]);
            $subscriber = $subscriberStmt->fetch();
            $couponCode = null;
            $newSubscriber = false;

            if ($subscriber) {
                if (
                    $subscriber["coupon_id"] !== null &&
                    $subscriber["coupon_status"] === "active" &&
                    (bool) $subscriber["coupon_is_current"] &&
                    (
                        $subscriber["max_uses"] === null ||
                        (int) $subscriber["used_count"] < (int) $subscriber["max_uses"]
                    )
                ) {
                    $couponCode = (string) $subscriber["code"];
                }

                $updateSubscriber = $pdo->prepare("
                    UPDATE newsletter_subscribers
                    SET status = 'subscribed'
                    WHERE email = :email
                ");
                $updateSubscriber->execute(["email" => $newsletterEmail]);
            } else {
                $couponCode = "VELO10-" . strtoupper(bin2hex(random_bytes(12)));
                $couponStmt = $pdo->prepare("
                    INSERT INTO coupons (
                        code,
                        discount_type,
                        discount_value,
                        min_order_amount,
                        max_uses,
                        used_count,
                        assigned_email,
                        expires_at,
                        status
                    ) VALUES (
                        :code,
                        'percentage',
                        10,
                        0,
                        1,
                        0,
                        :email,
                        DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 DAY),
                        'active'
                    )
                ");
                $couponStmt->execute([
                    "code" => $couponCode,
                    "email" => $newsletterEmail
                ]);
                $couponId = (int) $pdo->lastInsertId();

                $insertSubscriber = $pdo->prepare("
                    INSERT INTO newsletter_subscribers (email, coupon_id, status)
                    VALUES (:email, :coupon_id, 'subscribed')
                ");
                $insertSubscriber->execute([
                    "email" => $newsletterEmail,
                    "coupon_id" => $couponId
                ]);
                $newSubscriber = true;
            }

            $pdo->commit();

            if ($couponCode !== null) {
                require_once __DIR__ . '/../config/mailer.php';
                $emailSent = false;
                $mailConfigured = isAppMailConfigured();
                if ($mailConfigured) {
                    $safeCode = htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8');
                    try {
                        sendAppEmail(
                            $newsletterEmail,
                            "Your VELOURA 10% welcome coupon",
                            "<p>Thank you for subscribing. Use this code at checkout for 10% off:</p><p><strong>{$safeCode}</strong></p><p>This coupon is valid for 30 days and is assigned to this email address.</p>",
                            "Thank you for subscribing. Use {$couponCode} at checkout for 10% off. This coupon is valid for 30 days and is assigned to this email address."
                        );
                        $emailSent = true;
                    } catch (RuntimeException $e) {
                        error_log("Newsletter coupon email failed: " . $e->getMessage());
                    }
                }

                if ($emailSent) {
                    $successMessage = $newSubscriber
                        ? "You're subscribed. Your welcome coupon is below and was also sent to your email."
                        : "You're already subscribed. Your unused coupon is below and was sent again.";
                } elseif ($mailConfigured) {
                    $successMessage = "Your subscription is saved, but email delivery failed. Use the coupon below at checkout.";
                } else {
                    $successMessage = "You're subscribed. Email delivery isn't configured, so use the coupon below at checkout.";
                }

                $_SESSION["newsletter_success"] = [
                    "message" => $successMessage,
                    "coupon_code" => $couponCode
                ];
            } else {
                $_SESSION["newsletter_success"] = [
                    "message" => "You're already subscribed. Your previous coupon is no longer available.",
                    "coupon_code" => ""
                ];
            }

            header("Location: index.php#newsletter");
            exit;
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $newsletterError = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Newsletter subscription failed: " . $e->getMessage());
            $newsletterError = "We couldn't complete your subscription right now. Please try again later.";
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Newsletter coupon email failed: " . $e->getMessage());
            $newsletterError = "Your subscription is saved, but we couldn't send the coupon email. Submit again to retry, or check the SMTP configuration.";
        }
    }
}

$isLoggedIn = isset($_SESSION["user_id"]);
$userName = $isLoggedIn ? $_SESSION["user_name"] : "";
$cartCount = 0;
$contactValues = [
    "name" => $isLoggedIn ? $userName : "",
    "email" => $isLoggedIn ? ($_SESSION["user_email"] ?? "") : "",
    "phone" => "",
    "message" => ""
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["contact_submit"])) {
    foreach ($contactValues as $field => $value) {
        $submittedValue = $_POST["contact_" . $field] ?? "";
        $contactValues[$field] = is_string($submittedValue)
            ? trim($submittedValue)
            : "";
    }

    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $contactError = "Your session expired. Please refresh the page and try again.";
    } elseif (
        preg_match('/\A.{1,150}\z/us', $contactValues["name"]) !== 1 ||
        strlen($contactValues["email"]) > 254 ||
        !filter_var($contactValues["email"], FILTER_VALIDATE_EMAIL) ||
        !preg_match('/\A[0-9+\-\s()]{7,20}\z/D', $contactValues["phone"]) ||
        $contactValues["message"] === "" ||
        strlen($contactValues["message"]) > 5000
    ) {
        $contactError = "Please enter a valid name, email, phone number, and message.";
    } else {
        try {
            $contactStmt = $pdo->prepare("
                INSERT INTO contact_messages (name, email, phone, message)
                VALUES (:name, :email, :phone, :message)
            ");
            $contactStmt->execute($contactValues);

            $_SESSION["contact_success"] = "Thanks for contacting VELOURA. Your message has been received.";
            header("Location: index.php#contact");
            exit;
        } catch (PDOException $e) {
            error_log("Contact form submission failed: " . $e->getMessage());
            $contactError = "We couldn't send your message right now. Please try again later.";
        }
    }
}

// Get logged-in user's cart count
if ($isLoggedIn) {
    $cartStmt = $pdo->prepare("
        SELECT COALESCE(SUM(ci.quantity), 0)
        FROM carts c
        LEFT JOIN cart_items ci ON ci.cart_id = c.id
        WHERE c.user_id = :user_id
    ");

    $cartStmt->execute([
        "user_id" => (int) $_SESSION["user_id"]
    ]);

    $cartCount = (int) $cartStmt->fetchColumn();
}

// Get active categories
$categoryStmt = $pdo->query("
    SELECT id, name
    FROM categories
    ORDER BY id ASC
");

$categories = $categoryStmt->fetchAll();

// Map category names to their database IDs
$categoryLinks = [];

foreach ($categories as $category) {
    $categoryLinks[strtolower(trim($category["name"]))] = (int) $category["id"];
}

// Get featured products from database
$productStmt = $pdo->query("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.status = 'active'
    ORDER BY p.created_at DESC
    LIMIT 3
");

$featuredProducts = $productStmt->fetchAll();
$currentCustomerId = $isLoggedIn ? (int) $_SESSION["user_id"] : null;
$availableCoupons = getAvailableCoupons($pdo, $currentCustomerId);
$homepageCoupon = $availableCoupons[0] ?? null;

// Helper for product images
function getProductImageUrl($image) {
    $image = trim($image ?? "");

    if ($image === "") {
        return "";
    }

    if (filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }

    if (strpos($image, "assets/") === 0) {
        return $image;
    }

    return "assets/images/" . basename($image);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="description" content="Discover your natural glow with VELOURA luxury cosmetics.">

    <title>VELOURA | Discover Your Natural Glow</title>

    <link rel="stylesheet" href="assets/css/style.css?v=2">
    <link rel="stylesheet" href="assets/css/offers.css">
</head>

<body>

    <!-- Announcement Bar -->
    <div class="announcement-bar">
        Free shipping on orders over 1000 EGP
    </div>

    <!-- Header -->
    <?php require __DIR__ . '/partials/store-header.php'; ?>

    <main>

        <!-- Hero Section -->
        <section class="hero" id="home">

            <div class="hero-content">

                <p class="subtitle">THE ART OF BEAUTY</p>

                <h1>
                    Discover Your<br>
                    Natural Glow.
                </h1>

                <p class="hero-description">
                    Enhance your beauty with carefully selected
                    cosmetics made to celebrate you.
                </p>

                <a href="products.php" class="primary-btn">
                    Explore Collection
                </a>

            </div>

            <div class="hero-image">

                <img
                    src="assets/images/hero.jpg"
                    alt="VELOURA Beauty Collection"
                >

                <div class="image-overlay">
                    <span>YOUR BEAUTY</span>
                    <span>YOUR RULES</span>
                </div>

            </div>

        </section>

        <?php if ($homepageCoupon): ?>
            <section class="promo-banner" aria-labelledby="promo-title">
                <div class="promo-banner-copy">
                    <p class="promo-eyebrow">BEAUTY SPECIAL OFFER</p>
                    <h2 id="promo-title"><?= htmlspecialchars(couponOfferDiscountLabel($homepageCoupon), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p><?= htmlspecialchars(couponOfferDescription($homepageCoupon), ENT_QUOTES, 'UTF-8') ?></p>
                    <div class="promo-code-line">
                        <span>Coupon code</span>
                        <strong><?= htmlspecialchars($homepageCoupon["code"], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <?php if ($homepageCoupon["expires_at"]): ?>
                        <p class="promo-expiry">Available until <?= htmlspecialchars(couponOfferExpiry($homepageCoupon["expires_at"]), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
                <a class="promo-shop-link" href="products.php">Shop now</a>
            </section>
        <?php endif; ?>

        <!-- Categories -->
        <section class="categories" id="shop">

            <div class="section-heading">

                <p class="subtitle">EXPLORE OUR WORLD</p>

                <h2>Shop by Category</h2>

                <p>
                    Discover the essentials for your beauty routine.
                </p>

            </div>

            <div class="category-grid">

                <!-- Skincare Category -->
                <a
                    href="products.php?category=<?= $categoryLinks['skincare'] ?? '' ?>"
                    class="category-card skincare"
                    style="
                        text-decoration: none;
                        color: inherit;
                        background-image:
                            linear-gradient(rgba(255, 249, 249, 0.15), rgba(172, 172, 172, 0.35)),
                            url('assets/images/serum.jpg');
                        background-size: cover;
                        background-position: center;
                    "
                >

                    <div class="category-content">
                        <p>01 / SKIN</p>
                        <h3>Skincare</h3>
                        <span>Care that makes you glow.</span>
                    </div>

                </a>

                <!-- Makeup Category -->
                <a
                    href="products.php?category=<?= $categoryLinks['makeup'] ?? '' ?>"
                    class="category-card makeup"
                    style="
                        text-decoration: none;
                        color: inherit;
                        background-image:
                            linear-gradient(rgba(0,0,0,0.15), rgba(172,172,172,0.35)),
                            url('assets/images/lipstick.jpg');
                        background-size: cover;
                        background-position: center;
                    "
                >

                    <div class="category-content">
                        <p>02 / COLOR</p>
                        <h3>Makeup</h3>
                        <span>Express your beauty.</span>
                    </div>

                </a>

                <!-- Fragrance Category -->
                <a
                    href="products.php?category=<?= $categoryLinks['fragrance'] ?? '' ?>"
                    class="category-card fragrance"
                    style="
                        text-decoration: none;
                        color: inherit;
                        background-image:
                            linear-gradient(rgba(0,0,0,0.15), rgba(172,172,172,0.35)),
                            url('assets/images/perfume.jpg');
                        background-size: cover;
                        background-position: center;
                    "
                >

                    <div class="category-content">
                        <p>03 / SCENT</p>
                        <h3>Fragrance</h3>
                        <span>Leave a lasting impression.</span>
                    </div>

                </a>

            </div>

            <div style="text-align: center; margin-top: 30px;">
                <a href="products.php" class="secondary-btn">
                    View All Categories
                </a>
            </div>

        </section>

        <!-- Featured Products -->
        <section class="featured-products">

            <div class="section-heading">

                <p class="subtitle">THE VELOURA EDIT</p>

                <h2>Our Best Sellers</h2>

                <p>
                    Beauty favorites selected just for you.
                </p>

            </div>

            <div class="product-grid">

                <?php if (!empty($featuredProducts)): ?>

                    <?php foreach ($featuredProducts as $product): ?>

                        <?php
                        $productImage = getProductImageUrl($product["image"]);
                        ?>

                        <div class="product-card">

                            <a
                                href="product-details.php?id=<?= (int)$product['id'] ?>"
                                class="featured-product-link"
                                style="display: block; color: inherit; text-decoration: none;"
                            >

                                <div class="product-image">

                                    <?php if ($productImage !== ""): ?>

                                        <img
                                            src="<?= htmlspecialchars($productImage, ENT_QUOTES, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>"
                                            style="width: 100%; height: 100%; object-fit: cover;"
                                            onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                        >

                                    <?php endif; ?>

                                    <div
                                        style="display: <?= $productImage !== '' ? 'none' : 'block' ?>; text-align: center; padding: 40px; color: #8b5e62;"
                                    >
                                        VELOURA
                                    </div>

                                </div>

                                <div class="product-info">

                                    <p>
                                        <?= htmlspecialchars($product['category_name'] ?? 'BEAUTY', ENT_QUOTES, 'UTF-8') ?>
                                    </p>

                                    <h3>
                                        <?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </h3>

                                    <span>
                                        <?= number_format((float)$product['price'], 2) ?> EGP
                                    </span>

                                </div>

                            </a>

                            <div style="padding: 0 15px 15px;">

                                <form method="POST" action="cart.php">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?= (int)$product['id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="quantity"
                                        value="1"
                                    >

                                    <button
                                        type="submit"
                                        class="add-to-cart"
                                        style="width: 100%;"
                                    >
                                        Add to Cart
                                    </button>

                                </form>

                                <a
                                    href="product-details.php?id=<?= (int)$product['id'] ?>"
                                    style="display: block; text-align: center; margin-top: 10px; color: #8b5e62; text-decoration: none;"
                                >
                                    View Details
                                </a>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <p>No products available at the moment.</p>

                <?php endif; ?>

            </div>

            <div style="text-align: center; margin-top: 35px;">
                <a href="products.php" class="primary-btn">
                    Shop All Products
                </a>
            </div>

        </section>

        <!-- About -->
        <section class="about" id="about">

            <div class="about-image">
                <img
                    src="assets/images/hero.jpg"
                    alt="VELOURA skincare and beauty collection"
                    loading="lazy"
                >
            </div>

            <div class="about-content">

                <p class="subtitle">ABOUT VELOURA</p>

                <h2>Beauty begins with you.</h2>

                <p>
                    We believe beauty is personal.
                    VELOURA brings together carefully selected
                    beauty essentials to help you feel confident
                    in your own skin.
                </p>

                <a href="products.php" class="secondary-btn">
                    Discover More
                </a>

            </div>

        </section>

        <!-- Contact -->
        <section class="contact-section" id="contact">

            <div class="contact-heading">
                <p class="subtitle">WE'RE HERE TO HELP</p>
                <h2>Get in Touch</h2>
                <p>Send us a message and our team will get back to you.</p>
            </div>

            <div class="contact-layout">
                <aside class="contact-visual" aria-label="VELOURA fragrance">
                    <div class="contact-visual-copy">
                        <p>VELOURA CARE</p>
                        <h3>A more personal touch.</h3>
                        <span>We're here to help you find the right beauty essentials for your routine.</span>
                    </div>
                    <span class="contact-visual-caption">YOUR BEAUTY, YOUR WAY</span>
                </aside>

                <div class="contact-form-card">
                    <?php if ($contactSuccess !== ""): ?>
                        <p class="contact-feedback" role="status">
                            <?= htmlspecialchars($contactSuccess, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($contactError !== ""): ?>
                        <p class="contact-feedback contact-error" role="alert">
                            <?= htmlspecialchars($contactError, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                    <?php endif; ?>

                    <form class="contact-form" method="POST" action="index.php#contact">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <div class="contact-form-group">
                            <label for="contact-name">Name</label>
                            <input
                                type="text"
                                id="contact-name"
                                name="contact_name"
                                maxlength="150"
                                autocomplete="name"
                                placeholder="Your full name"
                                value="<?= htmlspecialchars($contactValues["name"], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>

                        <div class="contact-form-group">
                            <label for="contact-email">Email</label>
                            <input
                                type="email"
                                id="contact-email"
                                name="contact_email"
                                maxlength="254"
                                autocomplete="email"
                                placeholder="you@example.com"
                                value="<?= htmlspecialchars($contactValues["email"], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>

                        <div class="contact-form-group">
                            <label for="contact-phone">Phone</label>
                            <input
                                type="tel"
                                id="contact-phone"
                                name="contact_phone"
                                maxlength="20"
                                autocomplete="tel"
                                placeholder="Your phone number"
                                value="<?= htmlspecialchars($contactValues["phone"], ENT_QUOTES, 'UTF-8') ?>"
                                required
                            >
                        </div>

                        <div class="contact-form-group contact-message-group">
                            <label for="contact-message">Message</label>
                            <textarea
                                id="contact-message"
                                name="contact_message"
                                maxlength="5000"
                                placeholder="Tell us how we can help..."
                                required
                            ><?= htmlspecialchars($contactValues["message"], ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>

                        <button type="submit" name="contact_submit" value="1">
                            Send Message
                        </button>

                    </form>
                </div>
            </div>

        </section>

        <!-- Newsletter -->
        <section class="newsletter" id="newsletter">

            <p class="subtitle">STAY IN THE GLOW</p>

            <h2>A little beauty in your inbox.</h2>

            <p>
                Subscribe to receive updates and exclusive offers.
            </p>

            <?php if ($newsletterError !== ""): ?>
                <p class="contact-feedback contact-error"><?= htmlspecialchars($newsletterError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($newsletterSuccess !== ""): ?>
                <p class="contact-feedback"><?= htmlspecialchars($newsletterSuccess, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($newsletterCouponCode !== ""): ?>
                <div class="newsletter-coupon" role="status">
                    <span>Your welcome coupon</span>
                    <strong><?= htmlspecialchars($newsletterCouponCode, ENT_QUOTES, 'UTF-8') ?></strong>
                    <a href="checkout.php">Use it at checkout</a>
                </div>
            <?php endif; ?>

            <form class="newsletter-form" method="POST" action="index.php#newsletter">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>">

                <input
                    type="email"
                    name="newsletter_email"
                    maxlength="254"
                    placeholder="Enter your email address"
                    required
                >

                <button type="submit" name="newsletter_submit" value="1">
                    Subscribe
                </button>

            </form>

        </section>

    </main>

    <!-- Footer -->
    <footer class="footer">

        <a href="index.php" class="logo">
            VELOURA<span>.</span>
        </a>

        <p>Discover Your Natural Glow.</p>

        <div class="footer-links">

            <a href="#home">Home</a>
            <a href="products.php">Shop</a>
            <a href="offers.php">Offers</a>
            <a href="#about">About</a>
            <a href="#contact">Contact</a>

        </div>

        <p class="copyright">
            &copy; 2026 VELOURA. All rights reserved.
        </p>

    </footer>

    <script src="assets/js/main.js"></script>
    <script src="assets/js/offers.js"></script>

</body>
</html>

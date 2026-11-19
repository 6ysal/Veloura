<?php
require_once __DIR__ . '/../../app/coupons.php';

$headerIsLoggedIn = isset($_SESSION["user_id"]);
$headerIsAdmin = $headerIsLoggedIn
    && is_string($_SESSION["user_role"] ?? null)
    && $_SESSION["user_role"] === "admin";

if ($headerIsAdmin) {
    $headerRoleStmt = $pdo->prepare("
        SELECT role, status
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $headerRoleStmt->execute(["user_id" => (int) $_SESSION["user_id"]]);
    $headerUser = $headerRoleStmt->fetch(PDO::FETCH_ASSOC);

    if ($headerUser) {
        $_SESSION["user_role"] = (string) $headerUser["role"];
        $headerIsAdmin = $_SESSION["user_role"] === "admin"
            && $headerUser["status"] === "active";
    } else {
        unset($_SESSION["user_role"]);
        $headerIsAdmin = false;
    }
}

$headerUserId = $headerIsLoggedIn ? (int) $_SESSION["user_id"] : null;
$headerAvailableCoupons = $availableCoupons
    ?? getAvailableCoupons($pdo, $headerUserId);
$newCouponCount = newCouponNotificationCount($headerAvailableCoupons, $headerUserId);

if (!isset($cartCount)) {
    $cartCount = 0;

    if ($headerIsLoggedIn) {
        $headerCartStmt = $pdo->prepare("
            SELECT COALESCE(SUM(ci.quantity), 0)
            FROM carts c
            LEFT JOIN cart_items ci ON ci.cart_id = c.id
            WHERE c.user_id = :user_id
        ");
        $headerCartStmt->execute([
            "user_id" => (int) $_SESSION["user_id"]
        ]);
        $cartCount = (int) $headerCartStmt->fetchColumn();
    }
}
?>

<nav class="header-actions" aria-label="Account and shopping">
    <a
        href="<?= $headerIsLoggedIn ? 'profile.php' : '../app/auth/login.php' ?>"
        class="header-action-link"
    >
        <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="8" r="3.5"></circle>
            <path d="M5 21c0-3.8 3.1-6 7-6s7 2.2 7 6"></path>
        </svg>
        <span>Account</span>
    </a>

    <?php if ($headerIsAdmin): ?>
        <a href="admin.php" class="header-action-link">
            <span>Admin</span>
        </a>
    <?php endif; ?>

    <?php if ($headerIsLoggedIn): ?>
        <a href="../app/auth/logout.php" class="header-action-link">
            <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H3"></path>
                <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path>
            </svg>
            <span>Logout</span>
        </a>
    <?php else: ?>
        <a href="../app/auth/register.php" class="header-action-link">
            <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="9" cy="8" r="3.5"></circle>
                <path d="M2 21c0-3.8 3.1-6 7-6"></path>
                <path d="M18 8v8M14 12h8"></path>
            </svg>
            <span>Sign up</span>
        </a>
    <?php endif; ?>

    <a
        href="offers.php"
        class="header-action-link"
        aria-label="<?= $newCouponCount > 0
            ? 'Offers, ' . $newCouponCount . ' new'
            : 'Offers' ?>"
    >
        <span class="header-action-cart-icon">
            <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M20 13 11 22 2 13V4h9z"></path>
                <circle cx="7.5" cy="8.5" r="1"></circle>
            </svg>
            <?php if ($newCouponCount > 0): ?>
                <span class="header-action-badge"><?= $newCouponCount > 99 ? "99+" : $newCouponCount ?></span>
            <?php endif; ?>
        </span>
        <span>Offers</span>
    </a>

    <a href="cart.php" class="header-action-link">
        <span class="header-action-cart-icon">
            <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 2-1.6L21 8H6"></path>
                <circle cx="10" cy="20" r="1"></circle>
                <circle cx="18" cy="20" r="1"></circle>
            </svg>
            <span class="header-action-badge">
                <?= $cartCount > 99 ? "99+" : (int) $cartCount ?>
            </span>
        </span>
        <span>Cart</span>
    </a>
</nav>

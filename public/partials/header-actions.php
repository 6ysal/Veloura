<?php
$headerIsLoggedIn = isset($_SESSION["user_id"]);
$headerIsAdmin = false;

if ($headerIsLoggedIn) {
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
    <details class="account-menu">
        <summary class="header-action-link">
            <svg class="header-action-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="8" r="3.5"></circle>
                <path d="M5 21c0-3.8 3.1-6 7-6s7 2.2 7 6"></path>
            </svg>
            <span>Account</span>
        </summary>
        <div class="account-dropdown">
            <?php if ($headerIsLoggedIn): ?>
                <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>profile.php">My Account</a>
                <?php if ($headerIsAdmin): ?>
                    <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>admin.php">Admin dashboard</a>
                <?php endif; ?>
                <form class="header-action-form" method="POST" action="<?= htmlspecialchars($headerAuthPrefix, ENT_QUOTES, 'UTF-8') ?>logout.php">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <button type="submit">Logout</button>
                </form>
            <?php else: ?>
                <a href="<?= htmlspecialchars($headerAuthPrefix, ENT_QUOTES, 'UTF-8') ?>login.php">Sign in</a>
                <a href="<?= htmlspecialchars($headerAuthPrefix, ENT_QUOTES, 'UTF-8') ?>register.php">Create account</a>
            <?php endif; ?>
        </div>
    </details>

    <a
        href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>cart.php"
        class="header-action-link"
        aria-label="Cart, <?= (int) $cartCount ?> items"
    >
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

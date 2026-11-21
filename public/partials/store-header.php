<?php
$headerPublicPrefix = $headerPublicPrefix ?? "";
$headerAuthPrefix = $headerAuthPrefix ?? "../app/auth/";
$headerCurrentPage = basename((string) ($_SERVER["SCRIPT_NAME"] ?? ""));
?>
<header class="header">
    <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>index.php" class="logo" aria-label="VELOURA home">VELOURA<span>.</span></a>

    <button
        class="menu-toggle"
        type="button"
        aria-label="Open navigation"
        aria-expanded="false"
        aria-controls="site-navigation"
        data-menu-toggle
    >
        <span></span>
        <span></span>
        <span></span>
    </button>

    <nav class="navbar" id="site-navigation" aria-label="Main navigation">
        <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>index.php#home" <?= $headerCurrentPage === "index.php" ? 'aria-current="page"' : "" ?>>Home</a>
        <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>products.php" <?= $headerCurrentPage === "products.php" ? 'aria-current="page"' : "" ?>>Shop</a>
        <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>offers.php" <?= $headerCurrentPage === "offers.php" ? 'aria-current="page"' : "" ?>>Offers</a>
        <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>index.php#about">About</a>
        <a href="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>index.php#contact">Contact</a>
    </nav>

    <?php require __DIR__ . '/header-actions.php'; ?>
</header>
<script src="<?= htmlspecialchars($headerPublicPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/navigation.js" defer></script>

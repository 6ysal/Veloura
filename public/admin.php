<?php

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/admin/helpers.php';

$admin = adminRequireAccess($pdo);

require_once __DIR__ . '/../app/admin/actions.php';
require_once __DIR__ . '/../app/admin/data.php';

adminHandlePost($pdo, (int) $admin["id"]);

$allowedSections = [
    "overview",
    "products",
    "categories",
    "orders",
    "customers",
    "coupons",
    "newsletter",
    "credits",
    "settings"
];
$requestedSection = $_GET["section"] ?? "overview";
$section = is_string($requestedSection) && in_array($requestedSection, $allowedSections, true)
    ? $requestedSection
    : "overview";
$searchInput = $_GET["q"] ?? "";
$search = is_string($searchInput) ? trim(substr($searchInput, 0, 120)) : "";
$pageInput = filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT);
$page = $pageInput && $pageInput > 0 ? $pageInput : 1;
$viewInput = $_GET["view"] ?? null;
$editInput = $_GET["edit"] ?? null;
$viewIdValue = $viewInput ?? ($section === "products" ? $editInput : null);
$viewId = filter_var($viewIdValue, FILTER_VALIDATE_INT);
$viewId = $viewId && $viewId > 0 ? (int) $viewId : null;
$filters = [
    "status" => is_string($_GET["status"] ?? null) ? $_GET["status"] : "",
    "payment" => is_string($_GET["payment"] ?? null) ? $_GET["payment"] : "",
    "category" => is_string($_GET["category"] ?? null) ? $_GET["category"] : ""
];

$data = adminSectionData(
    $pdo,
    $section,
    $search,
    $page,
    $filters,
    $viewId,
    (int) $admin["id"]
);
$settings = adminStoreSettings($pdo);
$pendingOrders = (int) $pdo->query("
    SELECT COUNT(*) FROM orders WHERE order_status = 'pending'
")->fetchColumn();
$lowStockStmt = $pdo->prepare("
    SELECT COUNT(*) FROM products
    WHERE status = 'active' AND stock <= :threshold
");
$lowStockStmt->execute(["threshold" => (int) $settings["low_stock_threshold"]]);
$lowStockCount = (int) $lowStockStmt->fetchColumn();
$notificationCount = $pendingOrders + $lowStockCount;
$flash = $_SESSION["admin_flash"] ?? null;
unset($_SESSION["admin_flash"]);

$titles = [
    "overview" => ["Overview", "A clear view of your store's performance."],
    "products" => ["Products", "Manage your catalog, pricing, and stock."],
    "categories" => ["Categories", "Organize products into clear collections."],
    "orders" => ["Orders", "Review fulfilment, payment, and customer details."],
    "customers" => ["Customers", "Manage customer accounts and order history."],
    "coupons" => ["Coupons", "Create offers and monitor their usage."],
    "newsletter" => ["Newsletter Subscribers", "Review subscriptions and export the list."],
    "credits" => ["Credit Transactions", "A read-only ledger of store-credit activity."],
    "settings" => ["Settings", "Adjust shipping and inventory preferences."]
];
$title = $titles[$section][0];
$subtitle = $titles[$section][1];
$navigation = [
    "overview" => "Overview",
    "products" => "Products",
    "categories" => "Categories",
    "orders" => "Orders",
    "customers" => "Customers",
    "coupons" => "Coupons",
    "newsletter" => "Newsletter Subscribers",
    "credits" => "Credit Transactions",
    "settings" => "Settings"
];
$allowedOrderTransitions = [
    "pending" => ["processing", "cancelled"],
    "processing" => ["shipped"],
    "shipped" => ["delivered"],
    "delivered" => [],
    "cancelled" => []
];

function adminStatusBadge(string $status): string
{
    return '<span class="status-pill status-' . adminEscape(strtolower($status)) . '">' .
        adminEscape(ucfirst(str_replace("_", " ", $status))) . '</span>';
}

function adminFormatDate(?string $date): string
{
    if (!$date) {
        return "—";
    }

    $timestamp = strtotime($date);
    return $timestamp === false ? "—" : date("M j, Y · g:i A", $timestamp);
}

function adminImageUrl(?string $image): string
{
    if (!$image) {
        return "";
    }

    $filename = basename($image);
    if ($filename !== $image || !preg_match('/\A[a-zA-Z0-9._-]+\.(?:jpe?g|png|webp|gif)\z/i', $filename)) {
        return "";
    }

    return "assets/images/" . rawurlencode($filename);
}

function adminPagination(array $list, string $section, string $search, array $filters = []): void
{
    if (($list["pages"] ?? 1) <= 1) {
        return;
    }

    echo '<nav class="pagination" aria-label="Pagination">';
    for ($number = 1; $number <= $list["pages"]; $number++) {
        $params = array_merge(
            ["section" => $section, "page" => $number],
            $search === "" ? [] : ["q" => $search],
            array_filter($filters, static function ($value): bool {
                return $value !== "";
            })
        );
        $class = $number === $list["page"] ? "page-link is-active" : "page-link";
        echo '<a class="' . $class . '" href="admin.php?' .
            adminEscape(http_build_query($params)) . '">' . $number . '</a>';
    }
    echo '</nav>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= adminEscape($title) ?> | VELOURA Admin</title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <script src="assets/js/admin.js" defer></script>
</head>
<body class="admin-body">
<div class="admin-shell">
    <aside class="admin-sidebar" id="adminSidebar">
        <a class="admin-brand" href="admin.php" aria-label="VELOURA Admin home">
            <span class="brand-wordmark">VELOURA<span>.</span></span>
            <span class="brand-caption">ADMIN STUDIO</span>
        </a>

        <div class="sidebar-label">WORKSPACE</div>
        <nav class="sidebar-nav" aria-label="Admin navigation">
            <?php foreach ($navigation as $key => $label): ?>
                <a class="sidebar-link <?= $section === $key ? "is-active" : "" ?>"
                   href="admin.php?section=<?= adminEscape($key) ?>"
                   <?= $section === $key ? 'aria-current="page"' : "" ?>>
                    <span class="sidebar-mark" aria-hidden="true"></span>
                    <span><?= adminEscape($label) ?></span>
                    <?php if ($key === "orders" && $pendingOrders > 0): ?>
                        <span class="sidebar-count"><?= $pendingOrders > 99 ? "99+" : $pendingOrders ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <a class="sidebar-link" href="index.php"><span class="sidebar-mark" aria-hidden="true"></span>Back to Store</a>
            <a class="sidebar-link" href="../app/auth/logout.php"><span class="sidebar-mark" aria-hidden="true"></span>Logout</a>
            <div class="sidebar-admin">
                <span class="admin-avatar"><?= adminEscape(strtoupper(substr($admin["full_name"], 0, 1))) ?></span>
                <span><strong><?= adminEscape($admin["full_name"]) ?></strong><small>Administrator</small></span>
            </div>
        </div>
    </aside>

    <button class="sidebar-scrim" type="button" data-sidebar-close aria-label="Close navigation"></button>

    <div class="admin-main">
        <header class="admin-topbar">
            <div class="topbar-title">
                <button class="mobile-menu-button" type="button" data-sidebar-open aria-label="Open navigation">
                    <span></span><span></span><span></span>
                </button>
                <div>
                    <p class="topbar-kicker">VELOURA / ADMIN</p>
                </div>
            </div>

            <div class="topbar-tools">
                <form class="global-search" method="GET" action="admin.php" role="search">
                    <input type="hidden" name="section" value="<?= adminEscape($section) ?>">
                    <label class="sr-only" for="adminSearch">Search this section</label>
                    <input id="adminSearch" type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search <?= adminEscape(strtolower($title)) ?>">
                    <button type="submit" aria-label="Search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.5"></circle><path d="m16 16 5 5"></path></svg>
                    </button>
                </form>
                <a class="notification-link" href="admin.php?section=orders&status=pending" aria-label="<?= $notificationCount ?> store notifications">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    <?php if ($notificationCount > 0): ?><span><?= $notificationCount > 99 ? "99+" : $notificationCount ?></span><?php endif; ?>
                </a>
                <div class="topbar-profile">
                    <span class="admin-avatar"><?= adminEscape(strtoupper(substr($admin["full_name"], 0, 1))) ?></span>
                    <span><strong><?= adminEscape($admin["full_name"]) ?></strong><small>Administrator</small></span>
                </div>
                <time class="topbar-date" datetime="<?= adminEscape(date("Y-m-d")) ?>"><?= adminEscape(date("D, M j")) ?></time>
            </div>
        </header>

        <main class="admin-content">
            <div class="page-intro">
                <div>
                    <p class="page-eyebrow">STORE MANAGEMENT</p>
                    <h1><?= adminEscape($title) ?></h1>
                    <p><?= adminEscape($subtitle) ?></p>
                </div>
                <?php if ($section === "products"): ?>
                    <a class="button button-primary" href="#product-editor">Add product</a>
                <?php elseif ($section === "categories"): ?>
                    <a class="button button-primary" href="#category-editor">Add category</a>
                <?php elseif ($section === "coupons"): ?>
                    <a class="button button-primary" href="#coupon-editor">Create coupon</a>
                <?php endif; ?>
            </div>

            <?php if ($flash && is_array($flash)): ?>
                <div class="flash-message <?= ($flash["type"] ?? "") === "error" ? "flash-error" : "flash-success" ?>" role="<?= ($flash["type"] ?? "") === "error" ? "alert" : "status" ?>">
                    <span><?= adminEscape($flash["message"] ?? "") ?></span>
                    <button type="button" data-dismiss aria-label="Dismiss message">×</button>
                </div>
            <?php endif; ?>

            <?php if ($section === "overview"): ?>
                <?php $dashboard = $data["dashboard"]; ?>
                <?php $stats = $dashboard["stats"]; ?>
                <?php
                    $maxRevenue = max(1.0, ...array_column($dashboard["months"], "revenue"));
                    $chartPoints = [];
                    foreach ($dashboard["months"] as $index => $month) {
                        $x = 48 + ($index * 122);
                        $y = 160 - ((float) $month["revenue"] / $maxRevenue * 118);
                        $chartPoints[] = number_format($x, 1, ".", "") . "," . number_format($y, 1, ".", "");
                    }
                ?>
                <section class="stat-grid" aria-label="Store statistics">
                    <article class="stat-card">
                        <span class="stat-icon stat-icon-rose">EGP</span>
                        <div><p>Total revenue</p><strong><?= number_format($stats["revenue"], 2) ?> <small>EGP</small></strong><span class="stat-note">Paid, non-cancelled orders</span></div>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon-sand">01</span>
                        <div><p>Total orders</p><strong><?= number_format($stats["orders"]) ?></strong><span class="stat-note">All order statuses</span></div>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon-mauve">02</span>
                        <div><p>Total customers</p><strong><?= number_format($stats["customers"]) ?></strong><span class="stat-note">Registered customer accounts</span></div>
                    </article>
                    <article class="stat-card">
                        <span class="stat-icon stat-icon-olive">03</span>
                        <div><p>Total products</p><strong><?= number_format($stats["products"]) ?></strong><span class="stat-note">Active and archived products</span></div>
                    </article>
                    <article class="stat-card stat-card-alert">
                        <span class="stat-icon stat-icon-sand">04</span>
                        <div><p>Pending orders</p><strong><?= number_format($stats["pending_orders"]) ?></strong><span class="stat-note">Awaiting fulfilment</span></div>
                    </article>
                    <article class="stat-card stat-card-alert">
                        <span class="stat-icon stat-icon-rose">05</span>
                        <div><p>Low stock</p><strong><?= number_format($stats["low_stock"]) ?></strong><span class="stat-note">At or below <?= (int) $settings["low_stock_threshold"] ?> units</span></div>
                    </article>
                </section>

                <div class="dashboard-grid">
                    <section class="panel revenue-panel">
                        <div class="panel-heading">
                            <div><p class="panel-eyebrow">REVENUE</p><h3>Paid sales · last 6 months</h3></div>
                            <span class="panel-tag">EGP</span>
                        </div>
                        <div class="chart-wrap">
                            <svg class="revenue-chart" viewBox="0 0 700 215" role="img" aria-label="Monthly paid revenue for the last six months">
                                <line x1="42" y1="42" x2="670" y2="42"></line>
                                <line x1="42" y1="101" x2="670" y2="101"></line>
                                <line x1="42" y1="160" x2="670" y2="160"></line>
                                <polyline points="<?= adminEscape(implode(" ", $chartPoints)) ?>"></polyline>
                                <?php foreach ($dashboard["months"] as $index => $month): ?>
                                    <?php $x = 48 + ($index * 122); $y = 160 - ((float) $month["revenue"] / $maxRevenue * 118); ?>
                                    <circle cx="<?= number_format($x, 1, ".", "") ?>" cy="<?= number_format($y, 1, ".", "") ?>" r="4">
                                        <title><?= adminEscape($month["label"] . ": " . number_format((float) $month["revenue"], 2) . " EGP") ?></title>
                                    </circle>
                                    <text x="<?= number_format($x, 1, ".", "") ?>" y="194"><?= adminEscape($month["label"]) ?></text>
                                <?php endforeach; ?>
                            </svg>
                        </div>
                    </section>

                    <section class="panel low-stock-panel">
                        <div class="panel-heading"><div><p class="panel-eyebrow">INVENTORY</p><h3>Low-stock alerts</h3></div><a class="text-link" href="admin.php?section=products&status=active">View products</a></div>
                        <?php if ($dashboard["low_stock_products"]): ?>
                            <ul class="compact-list">
                                <?php foreach ($dashboard["low_stock_products"] as $product): ?>
                                    <li><span class="compact-avatar"><?= adminEscape(strtoupper(substr($product["name"], 0, 1))) ?></span><span class="compact-main"><strong><?= adminEscape($product["name"]) ?></strong><small>Product #<?= (int) $product["id"] ?></small></span><span class="stock-count"><?= (int) $product["stock"] ?> left</span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state compact-empty"><strong>Stock looks healthy</strong><span>No products are below the alert threshold.</span></div>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="dashboard-grid dashboard-grid-lower">
                    <section class="panel recent-orders-panel">
                        <div class="panel-heading"><div><p class="panel-eyebrow">LATEST ACTIVITY</p><h3>Recent orders</h3></div><a class="text-link" href="admin.php?section=orders">All orders</a></div>
                        <?php if ($dashboard["recent_orders"]): ?>
                            <div class="table-wrap">
                                <table class="data-table">
                                    <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($dashboard["recent_orders"] as $order): ?>
                                        <tr>
                                            <td><a class="table-link" href="admin.php?section=orders&view=<?= (int) $order["id"] ?>">#<?= (int) $order["id"] ?></a></td>
                                            <td><?= adminEscape($order["customer_name"]) ?></td>
                                            <td><?= adminEscape(adminFormatDate($order["created_at"])) ?></td>
                                            <td><?= number_format((float) $order["final_amount"], 2) ?> EGP</td>
                                            <td><?= adminStatusBadge($order["order_status"]) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state"><strong>No orders yet</strong><span>New orders will appear here.</span></div>
                        <?php endif; ?>
                    </section>

                    <section class="panel top-products-panel">
                        <div class="panel-heading"><div><p class="panel-eyebrow">BEST SELLERS</p><h3>Top products</h3></div><a class="text-link" href="admin.php?section=products">Catalog</a></div>
                        <?php if ($dashboard["top_products"]): ?>
                            <ol class="ranked-list">
                                <?php foreach ($dashboard["top_products"] as $index => $product): ?>
                                    <li><span class="rank-number"><?= str_pad((string) ($index + 1), 2, "0", STR_PAD_LEFT) ?></span><span class="rank-main"><strong><?= adminEscape($product["product_name"]) ?></strong><small><?= number_format((int) $product["units_sold"]) ?> units sold</small></span><span class="rank-total"><?= number_format((float) $product["sales_amount"], 0) ?> EGP</span></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <div class="empty-state"><strong>Sales data will appear here</strong><span>Completed orders populate this list.</span></div>
                        <?php endif; ?>
                    </section>

                    <section class="panel latest-customers-panel">
                        <div class="panel-heading"><div><p class="panel-eyebrow">COMMUNITY</p><h3>Latest customers</h3></div><a class="text-link" href="admin.php?section=customers">View customers</a></div>
                        <?php if ($dashboard["latest_customers"]): ?>
                            <ul class="compact-list">
                                <?php foreach ($dashboard["latest_customers"] as $customer): ?>
                                    <li><span class="compact-avatar"><?= adminEscape(strtoupper(substr($customer["full_name"], 0, 1))) ?></span><span class="compact-main"><strong><a class="table-link" href="admin.php?section=customers&view=<?= (int) $customer["id"] ?>"><?= adminEscape($customer["full_name"]) ?></a></strong><small><?= adminEscape($customer["email"]) ?></small></span><span class="compact-date"><?= adminEscape(date("M j", strtotime($customer["created_at"]))) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="empty-state"><strong>No customers yet</strong><span>New registrations will appear here.</span></div>
                        <?php endif; ?>
                    </section>
                </div>

            <?php elseif ($section === "products"): ?>
                <section class="panel editor-panel" id="product-editor">
                    <div class="panel-heading"><div><p class="panel-eyebrow"><?= !empty($data["edit_product"]) ? "EDIT CATALOG" : "NEW CATALOG ITEM" ?></p><h3><?= !empty($data["edit_product"]) ? "Edit product" : "Add a product" ?></h3></div>
                        <?php if (!empty($data["edit_product"])): ?><a class="text-link" href="admin.php?section=products">Cancel edit</a><?php endif; ?>
                    </div>
                    <?php $editProduct = $data["edit_product"] ?? null; ?>
                    <form class="admin-form product-form" method="POST" enctype="multipart/form-data">
                        <?= adminCsrfField() ?>
                        <input type="hidden" name="action" value="<?= $editProduct ? "update_product" : "create_product" ?>">
                        <input type="hidden" name="return_section" value="products">
                        <?php if ($editProduct): ?><input type="hidden" name="product_id" value="<?= (int) $editProduct["id"] ?>"><?php endif; ?>
                        <label>Product name<input name="name" maxlength="150" value="<?= adminEscape($editProduct["name"] ?? "") ?>" required></label>
                        <label>Category<select name="category_id"><option value="">Uncategorized</option>
                            <?php foreach ($data["categories"] as $category): ?><option value="<?= (int) $category["id"] ?>" <?= (int) ($editProduct["category_id"] ?? 0) === (int) $category["id"] ? "selected" : "" ?>><?= adminEscape($category["name"]) ?></option><?php endforeach; ?>
                        </select></label>
                        <label>Price (EGP)<input name="price" type="number" min="0.01" step="0.01" value="<?= adminEscape($editProduct["price"] ?? "") ?>" required></label>
                        <label>Stock quantity<input name="stock" type="number" min="0" step="1" value="<?= adminEscape($editProduct["stock"] ?? "0") ?>" required></label>
                        <label>Status<select name="status"><option value="active" <?= ($editProduct["status"] ?? "active") === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= ($editProduct["status"] ?? "") === "inactive" ? "selected" : "" ?>>Inactive</option></select></label>
                        <label class="wide-field">Description<textarea name="description" rows="3" maxlength="20000"><?= adminEscape($editProduct["description"] ?? "") ?></textarea></label>
                        <label class="upload-field wide-field">Product image<input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-image-input><small>JPEG, PNG or WebP · up to 3 MB. A generated filename is used.</small>
                            <?php if ($editProduct && ($imageUrl = adminImageUrl($editProduct["image"])) !== ""): ?><span class="current-image"><img src="<?= adminEscape($imageUrl) ?>" alt="Current product image"><span>Current image</span></span><?php endif; ?>
                            <img class="upload-preview" alt="Selected image preview" data-image-preview hidden>
                        </label>
                        <div class="form-actions wide-field"><button class="button button-primary" type="submit"><?= $editProduct ? "Save product" : "Create product" ?></button></div>
                    </form>
                </section>

                <form class="filter-bar" method="GET" action="admin.php">
                    <input type="hidden" name="section" value="products">
                    <input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search products">
                    <select name="status"><option value="">All statuses</option><option value="active" <?= $filters["status"] === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= $filters["status"] === "inactive" ? "selected" : "" ?>>Inactive</option></select>
                    <select name="category"><option value="">All categories</option><?php foreach ($data["categories"] as $category): ?><option value="<?= (int) $category["id"] ?>" <?= $filters["category"] === (string) $category["id"] ? "selected" : "" ?>><?= adminEscape($category["name"]) ?></option><?php endforeach; ?></select>
                    <button class="button button-outline" type="submit">Filter</button>
                </form>

                <section class="panel table-panel">
                    <div class="panel-heading"><div><p class="panel-eyebrow">PRODUCT CATALOG</p><h3><?= number_format($data["list"]["total"]) ?> products</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?>
                        <div class="table-wrap"><table class="data-table">
                            <thead><tr><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($data["list"]["rows"] as $product): ?>
                                <?php $imageUrl = adminImageUrl($product["image"]); ?>
                                <tr>
                                    <td><div class="product-cell"><?php if ($imageUrl !== ""): ?><img src="<?= adminEscape($imageUrl) ?>" alt="" loading="lazy"><?php else: ?><span class="product-placeholder"><?= adminEscape(strtoupper(substr($product["name"], 0, 1))) ?></span><?php endif; ?><span><strong><?= adminEscape($product["name"]) ?></strong><small>#<?= (int) $product["id"] ?></small></span></div></td>
                                    <td><?= adminEscape($product["category_name"] ?? "Uncategorized") ?></td>
                                    <td><?= number_format((float) $product["price"], 2) ?> EGP</td>
                                    <td><span class="<?= (int) $product["stock"] <= (int) $settings["low_stock_threshold"] ? "stock-low" : "" ?>"><?= (int) $product["stock"] ?></span></td>
                                    <td><?= adminStatusBadge($product["status"]) ?></td>
                                    <td class="action-cell"><a class="button button-small button-outline" href="admin.php?section=products&edit=<?= (int) $product["id"] ?>">Edit</a><form method="POST" data-confirm="Delete this product? Order item snapshots will remain."><?= adminCsrfField() ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="product_id" value="<?= (int) $product["id"] ?>"><input type="hidden" name="return_section" value="products"><button class="button button-small button-danger" type="submit">Delete</button></form></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table></div>
                        <?php adminPagination($data["list"], $section, $search, ["status" => $filters["status"], "category" => $filters["category"]]); ?>
                    <?php else: ?><div class="empty-state"><strong>No products found</strong><span>Try changing your search or add your first product.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "categories"): ?>
                <section class="panel editor-panel" id="category-editor">
                    <div class="panel-heading"><div><p class="panel-eyebrow">COLLECTIONS</p><h3>Add category</h3></div></div>
                    <form class="admin-form" method="POST">
                        <?= adminCsrfField() ?><input type="hidden" name="action" value="create_category"><input type="hidden" name="return_section" value="categories">
                        <label>Category name<input name="name" maxlength="100" required></label>
                        <label class="wide-field">Description<textarea name="description" rows="3" maxlength="10000"></textarea></label>
                        <div class="form-actions wide-field"><button class="button button-primary" type="submit">Add category</button></div>
                    </form>
                </section>
                <form class="filter-bar" method="GET" action="admin.php"><input type="hidden" name="section" value="categories"><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search categories"><button class="button button-outline" type="submit">Search</button></form>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">PRODUCT ORGANIZATION</p><h3><?= number_format($data["list"]["total"]) ?> categories</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Category</th><th>Description</th><th>Products</th><th>Actions</th></tr></thead><tbody>
                    <?php foreach ($data["list"]["rows"] as $category): ?><tr><td colspan="4"><div class="category-edit-row"><form class="inline-edit-form" method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_category"><input type="hidden" name="category_id" value="<?= (int) $category["id"] ?>"><input type="hidden" name="return_section" value="categories"><label><span class="sr-only">Category name</span><input name="name" maxlength="100" value="<?= adminEscape($category["name"]) ?>" required></label><label><span class="sr-only">Category description</span><textarea name="description" rows="2" maxlength="10000"><?= adminEscape($category["description"] ?? "") ?></textarea></label><span class="category-count"><?= (int) $category["product_count"] ?> products</span><button class="button button-small button-outline" type="submit">Save</button></form>
                    <form method="POST" data-confirm="Delete this category? It must have no assigned products."><?= adminCsrfField() ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?= (int) $category["id"] ?>"><input type="hidden" name="return_section" value="categories"><button class="button button-small button-danger" type="submit">Delete</button></form></div></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php adminPagination($data["list"], $section, $search); ?><?php else: ?><div class="empty-state"><strong>No categories found</strong><span>Create a category to organize your products.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "orders"): ?>
                <?php if (array_key_exists("order", $data)): ?>
                    <?php if ($data["order"]): $order = $data["order"]; ?>
                        <section class="panel order-detail-panel">
                            <div class="panel-heading"><div><p class="panel-eyebrow">ORDER #<?= (int) $order["id"] ?></p><h3>Order details</h3></div><a class="text-link" href="admin.php?section=orders">Back to all orders</a></div>
                            <div class="detail-grid">
                                <div class="detail-card"><h4>Customer</h4><p><strong><?= adminEscape($order["customer_name"]) ?></strong></p><p><?= adminEscape($order["customer_email"]) ?></p><p><?= adminEscape($order["customer_phone"] ?? "No phone on file") ?></p><a class="text-link" href="admin.php?section=customers&view=<?= (int) $order["user_id"] ?>">View customer profile</a></div>
                                <div class="detail-card"><h4>Shipping</h4><p><?= adminEscape($order["shipping_name"]) ?></p><p><?= adminEscape($order["shipping_phone"]) ?></p><p><?= nl2br(adminEscape($order["shipping_address"])) ?></p></div>
                                <div class="detail-card"><h4>Payment</h4><p><?= adminEscape(ucwords(str_replace("_", " ", $order["payment_method"]))) ?></p><p><?= adminStatusBadge($order["payment_status"]) ?></p><p><?= adminFormatDate($order["created_at"]) ?></p></div>
                            </div>
                            <div class="table-wrap detail-items"><table class="data-table"><thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th>Subtotal</th></tr></thead><tbody><?php foreach ($data["order_items"] as $item): ?><tr><td><?= adminEscape($item["product_name"]) ?></td><td><?= number_format((float) $item["unit_price"], 2) ?> EGP</td><td><?= (int) $item["quantity"] ?></td><td><?= number_format((float) $item["subtotal"], 2) ?> EGP</td></tr><?php endforeach; ?></tbody><tfoot><tr><th colspan="3">Subtotal</th><td><?= number_format((float) $order["total_amount"], 2) ?> EGP</td></tr><tr><th colspan="3">Discount</th><td>−<?= number_format((float) $order["discount_amount"], 2) ?> EGP</td></tr><tr><th colspan="3">Shipping</th><td><?= number_format((float) $order["shipping_amount"], 2) ?> EGP</td></tr><tr><th colspan="3">Total</th><td><strong><?= number_format((float) $order["final_amount"], 2) ?> EGP</strong></td></tr></tfoot></table></div>
                            <?php $transitions = $allowedOrderTransitions[$order["order_status"]] ?? []; ?>
                            <?php if ($transitions): ?><form class="order-status-form" method="POST" data-confirm="<?= $order["order_status"] === "pending" ? "Changing to cancelled restores reserved stock. Continue?" : "Update this order's fulfilment status?" ?>"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_order_status"><input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>"><input type="hidden" name="return_section" value="orders"><input type="hidden" name="return_id" value="<?= (int) $order["id"] ?>"><label>Update status<select name="order_status"><?php foreach ($transitions as $transition): ?><option value="<?= adminEscape($transition) ?>"><?= adminEscape(ucfirst($transition)) ?></option><?php endforeach; ?></select></label><button class="button button-primary" type="submit">Save status</button></form><?php else: ?><p class="read-only-note">This order is in a final status and cannot be changed.</p><?php endif; ?>
                        </section>
                    <?php else: ?><div class="flash-message flash-error">Order not found.</div><?php endif; ?>
                <?php endif; ?>

                <form class="filter-bar" method="GET" action="admin.php"><input type="hidden" name="section" value="orders"><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Order ID, customer, or email"><select name="status"><option value="">All order statuses</option><?php foreach (["pending","processing","shipped","delivered","cancelled"] as $status): ?><option value="<?= $status ?>" <?= $filters["status"] === $status ? "selected" : "" ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select><select name="payment"><option value="">All payments</option><?php foreach (["pending","paid","failed","refunded"] as $status): ?><option value="<?= $status ?>" <?= $filters["payment"] === $status ? "selected" : "" ?>><?= ucfirst($status) ?></option><?php endforeach; ?></select><button class="button button-outline" type="submit">Filter</button></form>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">FULFILMENT</p><h3><?= number_format($data["list"]["total"]) ?> orders</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th><th>Update status</th></tr></thead><tbody>
                    <?php foreach ($data["list"]["rows"] as $order): ?><tr><td><a class="table-link" href="admin.php?section=orders&view=<?= (int) $order["id"] ?>">#<?= (int) $order["id"] ?></a></td><td><?= adminEscape($order["shipping_name"]) ?><small class="table-subtext"><?= adminEscape($order["customer_email"]) ?></small></td><td><?= adminEscape(adminFormatDate($order["created_at"])) ?></td><td><?= number_format((float) $order["final_amount"], 2) ?> EGP</td><td><?= adminStatusBadge($order["payment_status"]) ?></td><td><?= adminStatusBadge($order["order_status"]) ?></td><td><?php $rowTransitions = $allowedOrderTransitions[$order["order_status"]] ?? []; ?><?php if ($rowTransitions): ?><form class="compact-status-form" method="POST" data-confirm="Update this order status?"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_order_status"><input type="hidden" name="order_id" value="<?= (int) $order["id"] ?>"><input type="hidden" name="return_section" value="orders"><select name="order_status"><?php foreach ($rowTransitions as $transition): ?><option value="<?= adminEscape($transition) ?>"><?= adminEscape(ucfirst($transition)) ?></option><?php endforeach; ?></select><button type="submit" aria-label="Update order #<?= (int) $order["id"] ?>">Save</button></form><?php else: ?><span class="muted">Final</span><?php endif; ?></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php adminPagination($data["list"], $section, $search, ["status" => $filters["status"], "payment" => $filters["payment"]]); ?><?php else: ?><div class="empty-state"><strong>No orders found</strong><span>Try another search or filter.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "customers"): ?>
                <?php if (array_key_exists("customer", $data)): ?>
                    <?php if ($data["customer"]): $customer = $data["customer"]; ?>
                        <section class="panel customer-detail-panel"><div class="panel-heading"><div><p class="panel-eyebrow">ACCOUNT #<?= (int) $customer["id"] ?></p><h3><?= adminEscape($customer["full_name"]) ?></h3></div><a class="text-link" href="admin.php?section=customers">Back to customers</a></div>
                            <div class="detail-grid"><div class="detail-card"><h4>Contact</h4><p><?= adminEscape($customer["email"]) ?></p><p><?= adminEscape($customer["phone"] ?? "No phone on file") ?></p><p>Email <?= $customer["email_verified_at"] ? "verified" : "not verified" ?></p><p>Joined <?= adminEscape(adminFormatDate($customer["created_at"])) ?></p></div><div class="detail-card"><h4>Account</h4><p><?= adminStatusBadge($customer["status"]) ?> <?= adminStatusBadge($customer["role"]) ?></p><p>Store credit <strong><?= number_format((float) $customer["store_credit"], 2) ?> EGP</strong></p></div></div>
                            <form class="customer-edit-form" method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_customer"><input type="hidden" name="customer_id" value="<?= (int) $customer["id"] ?>"><input type="hidden" name="return_section" value="customers"><input type="hidden" name="return_id" value="<?= (int) $customer["id"] ?>"><label>Role<select name="role"><option value="customer" <?= $customer["role"] === "customer" ? "selected" : "" ?>>Customer</option><option value="admin" <?= $customer["role"] === "admin" ? "selected" : "" ?>>Administrator</option></select></label><label>Account status<select name="status"><option value="active" <?= $customer["status"] === "active" ? "selected" : "" ?>>Active</option><option value="blocked" <?= $customer["status"] === "blocked" ? "selected" : "" ?>>Blocked</option></select></label><label>Store credit (EGP)<input name="store_credit" type="number" min="0" step="0.01" value="<?= adminEscape($customer["store_credit"]) ?>" required></label><button class="button button-primary" type="submit">Save account</button></form>
                            <div class="panel-heading subpanel-heading"><div><p class="panel-eyebrow">ACCOUNT HISTORY</p><h3>Recent orders</h3></div></div>
                            <?php if ($data["customer_orders"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Order</th><th>Date</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead><tbody><?php foreach ($data["customer_orders"] as $order): ?><tr><td><a class="table-link" href="admin.php?section=orders&view=<?= (int) $order["id"] ?>">#<?= (int) $order["id"] ?></a></td><td><?= adminEscape(adminFormatDate($order["created_at"])) ?></td><td><?= number_format((float) $order["final_amount"], 2) ?> EGP</td><td><?= adminStatusBadge($order["payment_status"]) ?></td><td><?= adminStatusBadge($order["order_status"]) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><strong>No orders yet</strong><span>Customer orders will appear here.</span></div><?php endif; ?>
                        </section>
                    <?php else: ?><div class="flash-message flash-error">Customer not found.</div><?php endif; ?>
                <?php endif; ?>
                <form class="filter-bar" method="GET" action="admin.php"><input type="hidden" name="section" value="customers"><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search by name or email"><button class="button button-outline" type="submit">Search</button></form>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">CUSTOMER ACCOUNTS</p><h3><?= number_format($data["list"]["total"]) ?> accounts</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Customer</th><th>Joined</th><th>Orders</th><th>Credit</th><th>Role / status</th><th>Actions</th></tr></thead><tbody>
                    <?php foreach ($data["list"]["rows"] as $customer): ?><tr><td><a class="table-link" href="admin.php?section=customers&view=<?= (int) $customer["id"] ?>"><?= adminEscape($customer["full_name"]) ?></a><small class="table-subtext"><?= adminEscape($customer["email"]) ?></small></td><td><?= adminEscape(adminFormatDate($customer["created_at"])) ?></td><td><?= (int) $customer["order_count"] ?></td><td><?= number_format((float) $customer["store_credit"], 2) ?> EGP</td><td><?= adminStatusBadge($customer["role"]) ?> <?= adminStatusBadge($customer["status"]) ?></td><td><a class="button button-small button-outline" href="admin.php?section=customers&view=<?= (int) $customer["id"] ?>">View profile</a></td></tr><?php endforeach; ?>
                    </tbody></table></div><?php adminPagination($data["list"], $section, $search); ?><?php else: ?><div class="empty-state"><strong>No accounts found</strong><span>Try a different search.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "coupons"): ?>
                <section class="panel editor-panel" id="coupon-editor"><div class="panel-heading"><div><p class="panel-eyebrow">PROMOTIONS</p><h3>Create coupon</h3></div></div>
                    <form class="admin-form coupon-form" method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="create_coupon"><input type="hidden" name="return_section" value="coupons">
                        <label>Coupon code<input name="code" maxlength="50" pattern="[A-Za-z0-9_-]{3,50}" required></label><label>Discount type<select name="discount_type"><option value="percentage">Percentage</option><option value="fixed">Fixed amount</option></select></label><label>Discount value<input name="discount_value" type="number" min="0.01" step="0.01" required></label><label>Minimum order (EGP)<input name="min_order_amount" type="number" min="0" step="0.01" value="0" required></label><label>Maximum uses<input name="max_uses" type="number" min="1" step="1" placeholder="Unlimited"></label><label>Assigned email (optional)<input name="assigned_email" type="email" maxlength="254"></label><label>Starts at (UTC)<input name="starts_at" type="datetime-local"></label><label>Expires at (UTC)<input name="expires_at" type="datetime-local"></label><div class="form-actions wide-field"><button class="button button-primary" type="submit">Create coupon</button></div>
                    </form>
                </section>
                <form class="filter-bar" method="GET" action="admin.php"><input type="hidden" name="section" value="coupons"><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search code or assigned email"><select name="status"><option value="">All statuses</option><option value="active" <?= $filters["status"] === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= $filters["status"] === "inactive" ? "selected" : "" ?>>Inactive</option></select><button class="button button-outline" type="submit">Filter</button></form>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">OFFER MANAGEMENT</p><h3><?= number_format($data["list"]["total"]) ?> coupons</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?><div class="coupon-list">
                    <?php foreach ($data["list"]["rows"] as $coupon): ?><details class="coupon-card"><summary><span class="coupon-code"><?= adminEscape($coupon["code"]) ?></span><span><?= adminEscape($coupon["discount_value"]) ?><?= $coupon["discount_type"] === "percentage" ? "%" : " EGP" ?> off</span><span><?= (int) $coupon["used_count"] ?> / <?= $coupon["max_uses"] === null ? "∞" : (int) $coupon["max_uses"] ?> uses</span><?= adminStatusBadge($coupon["status"]) ?><span class="coupon-expiry">Expires <?= adminEscape(adminFormatDate($coupon["expires_at"])) ?></span></summary>
                        <form class="admin-form coupon-edit-form" method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_coupon"><input type="hidden" name="coupon_id" value="<?= (int) $coupon["id"] ?>"><input type="hidden" name="return_section" value="coupons"><label>Code<input name="code" maxlength="50" pattern="[A-Za-z0-9_-]{3,50}" value="<?= adminEscape($coupon["code"]) ?>" required></label><label>Discount type<select name="discount_type"><option value="percentage" <?= $coupon["discount_type"] === "percentage" ? "selected" : "" ?>>Percentage</option><option value="fixed" <?= $coupon["discount_type"] === "fixed" ? "selected" : "" ?>>Fixed amount</option></select></label><label>Value<input name="discount_value" type="number" min="0.01" step="0.01" value="<?= adminEscape($coupon["discount_value"]) ?>" required></label><label>Minimum order<input name="min_order_amount" type="number" min="0" step="0.01" value="<?= adminEscape($coupon["min_order_amount"] ?? "0") ?>" required></label><label>Usage limit<input name="max_uses" type="number" min="1" step="1" value="<?= adminEscape($coupon["max_uses"] ?? "") ?>" placeholder="Unlimited"></label><label>Assigned email<input name="assigned_email" type="email" maxlength="254" value="<?= adminEscape($coupon["assigned_email"] ?? "") ?>"></label><label>Starts at (UTC)<input name="starts_at" type="datetime-local" value="<?= adminEscape($coupon["starts_at"] ? substr(str_replace(" ", "T", $coupon["starts_at"]), 0, 16) : "") ?>"></label><label>Expires at (UTC)<input name="expires_at" type="datetime-local" value="<?= adminEscape($coupon["expires_at"] ? substr(str_replace(" ", "T", $coupon["expires_at"]), 0, 16) : "") ?>"></label><label>Status<select name="status"><option value="active" <?= $coupon["status"] === "active" ? "selected" : "" ?>>Active</option><option value="inactive" <?= $coupon["status"] === "inactive" ? "selected" : "" ?>>Inactive</option></select></label><div class="form-actions wide-field"><button class="button button-primary" type="submit">Save coupon</button><span class="muted"><?= (int) $coupon["recorded_redemptions"] ?> recorded redemptions</span></div></form>
                        <?php if ($coupon["status"] === "active"): ?><form class="coupon-archive-form" method="POST" data-confirm="Archive this coupon? Existing orders and subscriber records will remain."><?= adminCsrfField() ?><input type="hidden" name="action" value="delete_coupon"><input type="hidden" name="coupon_id" value="<?= (int) $coupon["id"] ?>"><input type="hidden" name="return_section" value="coupons"><button class="button button-danger" type="submit">Archive coupon</button></form><?php endif; ?>
                    </details><?php endforeach; ?>
                    </div><?php adminPagination($data["list"], $section, $search, ["status" => $filters["status"]]); ?><?php else: ?><div class="empty-state"><strong>No coupons found</strong><span>Create a coupon to launch an offer.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "newsletter"): ?>
                <section class="subscriber-summary"><div><span class="stat-icon stat-icon-rose">✉</span><span><small>Total subscribers</small><strong><?= number_format($data["total_subscribers"]) ?></strong></span></div>
                    <form method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="export_newsletter_csv"><input type="hidden" name="return_section" value="newsletter"><button class="button button-primary" type="submit">Export CSV</button></form>
                </section>
                <form class="filter-bar" method="GET" action="admin.php"><input type="hidden" name="section" value="newsletter"><input type="search" name="q" value="<?= adminEscape($search) ?>" placeholder="Search subscriber email"><button class="button button-outline" type="submit">Search</button></form>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">EMAIL COMMUNITY</p><h3><?= number_format($data["list"]["total"]) ?> subscribers</h3></div></div>
                    <?php if ($data["list"]["rows"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Email</th><th>Status</th><th>Subscribed at</th></tr></thead><tbody><?php foreach ($data["list"]["rows"] as $subscriber): ?><tr><td><?= adminEscape($subscriber["email"]) ?></td><td><?= adminStatusBadge($subscriber["status"]) ?></td><td><?= adminEscape(adminFormatDate($subscriber["created_at"])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php adminPagination($data["list"], $section, $search); ?><?php else: ?><div class="empty-state"><strong>No subscribers found</strong><span>Newsletter signups will appear here.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "credits"): ?>
                <section class="panel table-panel"><div class="panel-heading"><div><p class="panel-eyebrow">FINANCIAL HISTORY</p><h3><?= number_format($data["list"]["total"]) ?> transactions</h3></div><span class="read-only-note">Read only · historical records cannot be edited</span></div>
                    <?php if ($data["list"]["rows"]): ?><div class="table-wrap"><table class="data-table"><thead><tr><th>Transaction</th><th>Customer</th><th>Order</th><th>Type</th><th>Amount</th><th>Description</th><th>Date</th></tr></thead><tbody><?php foreach ($data["list"]["rows"] as $transaction): ?><tr><td>#<?= (int) $transaction["id"] ?></td><td><a class="table-link" href="admin.php?section=customers&view=<?= (int) $transaction["user_id"] ?>"><?= adminEscape($transaction["full_name"]) ?></a><small class="table-subtext"><?= adminEscape($transaction["email"]) ?></small></td><td><?= $transaction["order_id"] ? '<a class="table-link" href="admin.php?section=orders&view=' . (int) $transaction["order_id"] . '">#' . (int) $transaction["order_id"] . '</a>' : "—" ?></td><td><?= adminStatusBadge($transaction["transaction_type"]) ?></td><td><?= number_format((float) $transaction["amount"], 2) ?> EGP</td><td><?= adminEscape($transaction["description"] ?? "") ?></td><td><?= adminEscape(adminFormatDate($transaction["created_at"])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php adminPagination($data["list"], $section, $search); ?><?php else: ?><div class="empty-state"><strong>No credit activity found</strong><span>Transactions are recorded when store credit changes.</span></div><?php endif; ?>
                </section>

            <?php elseif ($section === "settings"): ?>
                <section class="panel settings-panel"><div class="panel-heading"><div><p class="panel-eyebrow">STORE EXPERIENCE</p><h3>Shipping & inventory</h3></div></div>
                    <p class="settings-intro">These values are used by checkout and the dashboard. Shipping settings retain the existing checkout defaults until changed here.</p>
                    <form class="admin-form settings-form" method="POST"><?= adminCsrfField() ?><input type="hidden" name="action" value="update_settings"><input type="hidden" name="return_section" value="settings">
                        <label>Shipping fee (EGP)<input name="shipping_fee" type="number" min="0" step="0.01" value="<?= adminEscape($settings["shipping_fee"]) ?>" required><small>Applied when an order is below the free-shipping threshold.</small></label>
                        <label>Free-shipping threshold (EGP)<input name="free_shipping_threshold" type="number" min="0" step="0.01" value="<?= adminEscape($settings["free_shipping_threshold"]) ?>" required><small>Orders at or above this subtotal qualify for free shipping.</small></label>
                        <label>Low-stock alert threshold<input name="low_stock_threshold" type="number" min="0" max="100000" step="1" value="<?= adminEscape($settings["low_stock_threshold"]) ?>" required><small>Active products at or below this stock count are highlighted.</small></label>
                        <div class="form-actions wide-field"><button class="button button-primary" type="submit">Save settings</button></div>
                    </form>
                </section>
                <section class="panel settings-panel"><div class="panel-heading"><div><p class="panel-eyebrow">SYSTEM STATUS</p><h3>Operational configuration</h3></div></div>
                    <div class="system-status-grid"><article><span>Database</span><strong><?= adminEscape($pdo->query("SELECT DATABASE()")->fetchColumn()) ?></strong><small>PDO connection is active.</small></article><article><span>Email delivery</span><strong class="<?= $data["mail_configured"] ? "text-success" : "text-warning" ?>"><?= $data["mail_configured"] ? "Configured" : "Needs setup" ?></strong><small><?php if ($data["mail_configured"]): ?>SMTP values are present; secrets are never displayed.<?php else: ?>Set environment variables: <?= adminEscape(implode(", ", $data["missing_mail_settings"])) ?>.<?php endif; ?></small></article><article><span>Server time</span><strong><?= adminEscape(date("T")) ?></strong><small><?= adminEscape(date("Y-m-d H:i:s")) ?></small></article></div>
                    <p class="settings-intro">Email credentials are managed in the server environment, not stored in the dashboard. See <a class="text-link" href="../docs/setup.md">setup instructions</a>.</p>
                </section>
            <?php endif; ?>
        </main>
        <footer class="admin-footer"><span>VELOURA Admin Studio</span><span>Store operations · <?= adminEscape(date("Y")) ?></span></footer>
    </div>
</div>
</body>
</html>

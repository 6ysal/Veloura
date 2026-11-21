<?php

function adminStoreSettings(PDO $pdo): array
{
    $settings = [
        "shipping_fee" => "50",
        "free_shipping_threshold" => "1000",
        "low_stock_threshold" => "5"
    ];
    $stmt = $pdo->query("
        SELECT setting_key, setting_value
        FROM store_settings
        WHERE setting_key IN ('shipping_fee', 'free_shipping_threshold', 'low_stock_threshold')
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row["setting_key"]] = $row["setting_value"];
    }

    return $settings;
}

function adminDashboardSearchData(PDO $pdo, string $search): array
{
    $pattern = "%" . addcslashes($search, "\\%_") . "%";

    $productsStmt = $pdo->prepare("
        SELECT id, name, price, stock, status
        FROM products
        WHERE name LIKE :name_search
           OR description LIKE :description_search
        ORDER BY name
        LIMIT 5
    ");
    $productsStmt->execute([
        "name_search" => $pattern,
        "description_search" => $pattern
    ]);

    $customersStmt = $pdo->prepare("
        SELECT id, full_name, email, status
        FROM users
        WHERE role = 'customer'
          AND (full_name LIKE :name_search OR email LIKE :email_search)
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ");
    $customersStmt->execute([
        "name_search" => $pattern,
        "email_search" => $pattern
    ]);

    $ordersStmt = $pdo->prepare("
        SELECT o.id, o.final_amount, o.order_status, o.created_at,
               o.shipping_name, u.full_name AS customer_name, u.email AS customer_email
        FROM orders o
        JOIN users u ON u.id = o.user_id
        WHERE CAST(o.id AS CHAR) = :order_id
           OR o.shipping_name LIKE :shipping_search
           OR u.full_name LIKE :customer_search
           OR u.email LIKE :email_search
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 5
    ");
    $ordersStmt->execute([
        "order_id" => $search,
        "shipping_search" => $pattern,
        "customer_search" => $pattern,
        "email_search" => $pattern
    ]);

    return [
        "products" => $productsStmt->fetchAll(PDO::FETCH_ASSOC),
        "customers" => $customersStmt->fetchAll(PDO::FETCH_ASSOC),
        "orders" => $ordersStmt->fetchAll(PDO::FETCH_ASSOC)
    ];
}

function adminDashboardData(PDO $pdo, int $lowStockThreshold): array
{
    $stats = [
        "revenue" => (float) $pdo->query("
            SELECT COALESCE(SUM(final_amount), 0)
            FROM orders
            WHERE payment_status = 'paid' AND order_status <> 'cancelled'
        ")->fetchColumn(),
        "orders" => (int) $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
        "customers" => (int) $pdo->query("
            SELECT COUNT(*) FROM users WHERE role = 'customer'
        ")->fetchColumn(),
        "products" => (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
        "pending_orders" => (int) $pdo->query("
            SELECT COUNT(*) FROM orders WHERE order_status = 'pending'
        ")->fetchColumn(),
        "low_stock" => 0
    ];

    $lowStockStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM products
        WHERE status = 'active' AND stock <= :threshold
    ");
    $lowStockStmt->execute(["threshold" => $lowStockThreshold]);
    $stats["low_stock"] = (int) $lowStockStmt->fetchColumn();

    $utc = new DateTimeZone("UTC");
    $currentMonthStart = (new DateTimeImmutable("first day of this month", $utc))->setTime(0, 0);
    $nextMonthStart = $currentMonthStart->modify("+1 month");
    $previousMonthStart = $currentMonthStart->modify("-1 month");
    $comparisonStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE
                WHEN created_at >= :current_start AND created_at < :current_end
                THEN final_amount ELSE 0 END), 0) AS current_revenue,
            COALESCE(SUM(CASE
                WHEN created_at >= :previous_start AND created_at < :previous_end
                THEN final_amount ELSE 0 END), 0) AS previous_revenue
        FROM orders
        WHERE payment_status = 'paid'
          AND order_status <> 'cancelled'
    ");
    $comparisonStmt->execute([
        "current_start" => $currentMonthStart->format("Y-m-d H:i:s"),
        "current_end" => $nextMonthStart->format("Y-m-d H:i:s"),
        "previous_start" => $previousMonthStart->format("Y-m-d H:i:s"),
        "previous_end" => $currentMonthStart->format("Y-m-d H:i:s")
    ]);
    $revenueComparison = $comparisonStmt->fetch(PDO::FETCH_ASSOC);
    $currentMonthRevenue = (float) $revenueComparison["current_revenue"];
    $previousMonthRevenue = (float) $revenueComparison["previous_revenue"];
    $revenueChangePercent = $previousMonthRevenue > 0
        ? (($currentMonthRevenue - $previousMonthRevenue) / $previousMonthRevenue) * 100
        : null;

    $startMonth = $currentMonthStart->modify("-5 months");
    $monthlyStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key,
               COALESCE(SUM(final_amount), 0) AS revenue
        FROM orders
        WHERE payment_status = 'paid'
          AND order_status <> 'cancelled'
          AND created_at >= :start_date
          AND created_at < :end_date
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month_key
    ");
    $monthlyStmt->execute([
        "start_date" => $startMonth->format("Y-m-d H:i:s"),
        "end_date" => $nextMonthStart->format("Y-m-d H:i:s")
    ]);
    $monthlyResults = [];
    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $monthlyResults[$row["month_key"]] = (float) $row["revenue"];
    }

    $months = [];
    for ($index = 0; $index < 6; $index++) {
        $month = $startMonth->modify("+" . $index . " months");
        $key = $month->format("Y-m");
        $months[] = [
            "key" => $key,
            "label" => $month->format("M"),
            "revenue" => $monthlyResults[$key] ?? 0.0
        ];
    }

    $recentOrders = $pdo->query("
        SELECT o.id, o.final_amount, o.payment_status, o.order_status, o.created_at,
               u.full_name AS customer_name
        FROM orders o
        JOIN users u ON u.id = o.user_id
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 7
    ")->fetchAll(PDO::FETCH_ASSOC);

    $topProducts = $pdo->query("
        SELECT oi.product_name, SUM(oi.quantity) AS units_sold,
               SUM(oi.subtotal) AS sales_amount
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.order_status <> 'cancelled'
        GROUP BY oi.product_name
        ORDER BY units_sold DESC, sales_amount DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $latestCustomers = $pdo->query("
        SELECT id, full_name, email, created_at
        FROM users
        WHERE role = 'customer'
        ORDER BY created_at DESC, id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $lowStockProductsStmt = $pdo->prepare("
        SELECT id, name, stock, image
        FROM products
        WHERE status = 'active' AND stock <= :threshold
        ORDER BY stock ASC, name ASC
        LIMIT 6
    ");
    $lowStockProductsStmt->execute(["threshold" => $lowStockThreshold]);

    return [
        "stats" => $stats,
        "months" => $months,
        "revenue_comparison" => [
            "current_month_label" => $currentMonthStart->format("F Y"),
            "previous_month_label" => $previousMonthStart->format("F Y"),
            "current_month_revenue" => $currentMonthRevenue,
            "previous_month_revenue" => $previousMonthRevenue,
            "change_percent" => $revenueChangePercent
        ],
        "recent_orders" => $recentOrders,
        "top_products" => $topProducts,
        "latest_customers" => $latestCustomers,
        "low_stock_products" => $lowStockProductsStmt->fetchAll(PDO::FETCH_ASSOC)
    ];
}

function adminSectionData(
    PDO $pdo,
    string $section,
    string $search,
    int $page,
    array $filters = [],
    ?int $viewId = null,
    ?int $currentAdminId = null
): array {
    $data = [];
    $like = "%" . $search . "%";

    if ($section === "overview") {
        $data["dashboard"] = adminDashboardData(
            $pdo,
            (int) adminStoreSettings($pdo)["low_stock_threshold"]
        );
        if ($search !== "") {
            $data["dashboard"]["search_results"] = adminDashboardSearchData($pdo, $search);
        }
    } elseif ($section === "products") {
        $where = ["1 = 1"];
        $params = [];
        if ($search !== "") {
            $where[] = "(p.name LIKE :name_search OR p.description LIKE :description_search)";
            $params[":name_search"] = $like;
            $params[":description_search"] = $like;
        }
        if (in_array($filters["status"] ?? "", ["active", "inactive"], true)) {
            $where[] = "p.status = :status_filter";
            $params[":status_filter"] = $filters["status"];
        }
        $categoryId = filter_var($filters["category"] ?? null, FILTER_VALIDATE_INT);
        if ($categoryId && $categoryId > 0) {
            $where[] = "p.category_id = :category_filter";
            $params[":category_filter"] = (int) $categoryId;
        }
        $whereSql = implode(" AND ", $where);
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT p.id, p.category_id, p.name, p.description, p.price, p.stock, p.image, p.status,
                    c.name AS category_name
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE {$whereSql}
             ORDER BY p.created_at DESC, p.id DESC",
            "SELECT COUNT(*) FROM products p WHERE {$whereSql}",
            $params,
            $page,
            12
        );
        $data["categories"] = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        if ($viewId !== null && $viewId > 0) {
            $productStmt = $pdo->prepare("
                SELECT id, category_id, name, description, price, stock, image, status
                FROM products
                WHERE id = :id
                LIMIT 1
            ");
            $productStmt->execute(["id" => $viewId]);
            $data["edit_product"] = $productStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } elseif ($section === "categories") {
        $where = "";
        $params = [];
        if ($search !== "") {
            $where = "WHERE c.name LIKE :name_search OR c.description LIKE :description_search";
            $params = [":name_search" => $like, ":description_search" => $like];
        }
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT c.id, c.name, c.description, c.created_at,
                    COUNT(p.id) AS product_count
             FROM categories c
             LEFT JOIN products p ON p.category_id = c.id
             {$where}
             GROUP BY c.id, c.name, c.description, c.created_at
             ORDER BY c.name",
            "SELECT COUNT(*) FROM categories c {$where}",
            $params,
            $page
        );
    } elseif ($section === "orders") {
        $where = ["1 = 1"];
        $params = [];
        $status = $filters["status"] ?? "";
        if (in_array($status, ["pending", "processing", "shipped", "delivered", "cancelled"], true)) {
            $where[] = "o.order_status = :status_filter";
            $params[":status_filter"] = $status;
        }
        $paymentStatus = $filters["payment"] ?? "";
        if (in_array($paymentStatus, ["pending", "paid", "failed", "refunded"], true)) {
            $where[] = "o.payment_status = :payment_filter";
            $params[":payment_filter"] = $paymentStatus;
        }
        if ($search !== "") {
            $where[] = "(o.shipping_name LIKE :shipping_search
                         OR u.email LIKE :email_search
                         OR CAST(o.id AS CHAR) = :order_search)";
            $params[":shipping_search"] = $like;
            $params[":email_search"] = $like;
            $params[":order_search"] = $search;
        }
        $whereSql = implode(" AND ", $where);
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT o.id, o.final_amount, o.payment_method, o.payment_status,
                    o.order_status, o.created_at, o.shipping_name,
                    u.email AS customer_email
             FROM orders o
             JOIN users u ON u.id = o.user_id
             WHERE {$whereSql}
             ORDER BY o.created_at DESC, o.id DESC",
            "SELECT COUNT(*) FROM orders o JOIN users u ON u.id = o.user_id WHERE {$whereSql}",
            $params,
            $page
        );
        if ($viewId !== null && $viewId > 0) {
            $orderStmt = $pdo->prepare("
                SELECT o.*, u.full_name AS customer_name, u.email AS customer_email,
                       u.phone AS customer_phone
                FROM orders o
                JOIN users u ON u.id = o.user_id
                WHERE o.id = :id
                LIMIT 1
            ");
            $orderStmt->execute(["id" => $viewId]);
            $data["order"] = $orderStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($data["order"]) {
                $itemsStmt = $pdo->prepare("
                    SELECT product_name, unit_price, quantity, subtotal
                    FROM order_items
                    WHERE order_id = :order_id
                    ORDER BY id
                ");
                $itemsStmt->execute(["order_id" => $viewId]);
                $data["order_items"] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } elseif ($section === "customers") {
        $where = ["1 = 1"];
        $params = [];
        if ($search !== "") {
            $where[] = "(u.full_name LIKE :name_search OR u.email LIKE :email_search)";
            $params[":name_search"] = $like;
            $params[":email_search"] = $like;
        }
        $whereSql = implode(" AND ", $where);
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.status,
                    u.store_credit, u.email_verified_at, u.created_at,
                    COUNT(o.id) AS order_count
             FROM users u
             LEFT JOIN orders o ON o.user_id = u.id
             WHERE {$whereSql}
             GROUP BY u.id, u.full_name, u.email, u.phone, u.role, u.status,
                      u.store_credit, u.email_verified_at, u.created_at
             ORDER BY u.created_at DESC, u.id DESC",
            "SELECT COUNT(*) FROM users u WHERE " . ($search === "" ? "1 = 1" : "(u.full_name LIKE :name_search OR u.email LIKE :email_search)"),
            $params,
            $page
        );
        if ($viewId !== null && $viewId > 0) {
            $customerStmt = $pdo->prepare("
                SELECT id, full_name, email, phone, role, status, store_credit,
                       email_verified_at, created_at
                FROM users
                WHERE id = :id
                LIMIT 1
            ");
            $customerStmt->execute(["id" => $viewId]);
            $data["customer"] = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($data["customer"]) {
                $ordersStmt = $pdo->prepare("
                    SELECT id, final_amount, payment_method, payment_status,
                           order_status, created_at
                    FROM orders
                    WHERE user_id = :user_id
                    ORDER BY created_at DESC, id DESC
                    LIMIT 10
                ");
                $ordersStmt->execute(["user_id" => $viewId]);
                $data["customer_orders"] = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $data["current_admin_id"] = $currentAdminId;
    } elseif ($section === "coupons") {
        $where = ["1 = 1"];
        $params = [];
        if ($search !== "") {
            $where[] = "(c.code LIKE :code_search OR c.assigned_email LIKE :email_search)";
            $params[":code_search"] = $like;
            $params[":email_search"] = $like;
        }
        if (in_array($filters["status"] ?? "", ["active", "inactive"], true)) {
            $where[] = "c.status = :status_filter";
            $params[":status_filter"] = $filters["status"];
        }
        $whereSql = implode(" AND ", $where);
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT c.id, c.code, c.discount_type, c.discount_value,
                    c.min_order_amount, c.max_uses, c.used_count,
                    c.assigned_email, c.starts_at, c.expires_at, c.status,
                    COUNT(DISTINCT cr.order_id) AS recorded_redemptions
             FROM coupons c
             LEFT JOIN coupon_redemptions cr ON cr.coupon_id = c.id
             WHERE {$whereSql}
             GROUP BY c.id, c.code, c.discount_type, c.discount_value,
                      c.min_order_amount, c.max_uses, c.used_count,
                      c.assigned_email, c.starts_at, c.expires_at, c.status
             ORDER BY c.created_at DESC, c.id DESC",
            "SELECT COUNT(*) FROM coupons c WHERE {$whereSql}",
            $params,
            $page
        );
    } elseif ($section === "newsletter") {
        $where = "";
        $params = [];
        if ($search !== "") {
            $where = "WHERE email LIKE :email_search";
            $params[":email_search"] = $like;
        }
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT id, email, status, created_at
             FROM newsletter_subscribers
             {$where}
             ORDER BY created_at DESC, id DESC",
            "SELECT COUNT(*) FROM newsletter_subscribers {$where}",
            $params,
            $page
        );
        $data["total_subscribers"] = (int) $pdo->query("SELECT COUNT(*) FROM newsletter_subscribers")->fetchColumn();
    } elseif ($section === "credits") {
        $where = ["1 = 1"];
        $params = [];
        if ($search !== "") {
            $where[] = "(u.full_name LIKE :name_search
                         OR u.email LIKE :email_search
                         OR CAST(ct.order_id AS CHAR) = :order_search)";
            $params[":name_search"] = $like;
            $params[":email_search"] = $like;
            $params[":order_search"] = $search;
        }
        $whereSql = implode(" AND ", $where);
        $data["list"] = adminFetchPage(
            $pdo,
            "SELECT ct.id, ct.user_id, ct.order_id, ct.transaction_type,
                    ct.amount, ct.description, ct.created_at,
                    u.full_name, u.email
             FROM credit_transactions ct
             JOIN users u ON u.id = ct.user_id
             WHERE {$whereSql}
             ORDER BY ct.created_at DESC, ct.id DESC",
            "SELECT COUNT(*) FROM credit_transactions ct JOIN users u ON u.id = ct.user_id WHERE {$whereSql}",
            $params,
            $page
        );
    } elseif ($section === "settings") {
        $data["settings"] = adminStoreSettings($pdo);
        require_once __DIR__ . "/../../config/mailer.php";
        $mailKeys = [
            "APP_BASE_URL",
            "SMTP_HOST",
            "SMTP_PORT",
            "SMTP_USERNAME",
            "SMTP_PASSWORD",
            "SMTP_ENCRYPTION",
            "MAIL_FROM_ADDRESS",
            "MAIL_FROM_NAME"
        ];
        $missingMailSettings = [];
        foreach ($mailKeys as $key) {
            if ($key === "APP_BASE_URL") {
                $baseUrl = rtrim((string) getAppMailSetting($key), "/");
                if (
                    filter_var($baseUrl, FILTER_VALIDATE_URL) === false ||
                    !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ["http", "https"], true)
                ) {
                    $missingMailSettings[] = $key;
                }
            } elseif (!isAppMailSettingConfigured($key)) {
                $missingMailSettings[] = $key;
            }
        }
        $data["mail_configured"] = $missingMailSettings === [];
        $data["missing_mail_settings"] = $missingMailSettings;
    }

    return $data;
}

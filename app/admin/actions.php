<?php

function adminPostString(string $key, string $default = ""): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function adminPositiveId(string $key): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT);
    if (!$value || $value < 1) {
        throw new DomainException("A valid record must be selected.");
    }

    return (int) $value;
}

function adminProductFields(PDO $pdo): array
{
    $name = adminPostString("name");
    $description = adminPostString("description");
    $price = adminPostString("price");
    $stock = adminPostString("stock");
    $categoryValue = adminPostString("category_id");
    $status = adminPostString("status", "active");
    $categoryId = $categoryValue === ""
        ? null
        : filter_var($categoryValue, FILTER_VALIDATE_INT);

    if (
        preg_match('/\A.{1,150}\z/us', $name) !== 1 ||
        strlen($description) > 20000 ||
        !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $price) ||
        (float) $price <= 0 ||
        !preg_match('/\A\d{1,8}\z/', $stock) ||
        !in_array($status, ["active", "inactive"], true) ||
        ($categoryValue !== "" && (!$categoryId || $categoryId < 1))
    ) {
        throw new DomainException("Enter valid product details.");
    }

    if ($categoryId !== null) {
        $categoryStmt = $pdo->prepare("SELECT id FROM categories WHERE id = :id");
        $categoryStmt->execute(["id" => $categoryId]);
        if (!$categoryStmt->fetchColumn()) {
            throw new DomainException("Select an existing category.");
        }
    }

    return [
        "name" => $name,
        "description" => $description,
        "price" => $price,
        "stock" => (int) $stock,
        "category_id" => $categoryId === null ? null : (int) $categoryId,
        "status" => $status
    ];
}

function adminSaveProduct(PDO $pdo, int $adminId, string $action): string
{
    $fields = adminProductFields($pdo);
    $productId = $action === "update_product" ? adminPositiveId("product_id") : null;
    $upload = null;
    $oldImage = null;

    try {
        $pdo->beginTransaction();
        if ($productId !== null) {
            $existingStmt = $pdo->prepare("
                SELECT id, image
                FROM products
                WHERE id = :id
                FOR UPDATE
            ");
            $existingStmt->execute(["id" => $productId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new DomainException("The selected product was not found.");
            }
            $oldImage = $existing["image"];
        }

        $upload = adminStoreProductImage($_FILES["image"] ?? []);
        $image = $upload["filename"] ?? $oldImage;

        if ($action === "create_product") {
            $stmt = $pdo->prepare("
                INSERT INTO products (category_id, name, description, price, stock, image, status)
                VALUES (:category_id, :name, :description, :price, :stock, :image, :status)
            ");
        } else {
            $stmt = $pdo->prepare("
                UPDATE products
                SET category_id = :category_id,
                    name = :name,
                    description = :description,
                    price = :price,
                    stock = :stock,
                    image = :image,
                    status = :status
                WHERE id = :id
            ");
            $stmt->bindValue(":id", $productId, PDO::PARAM_INT);
        }

        $stmt->bindValue(
            ":category_id",
            $fields["category_id"],
            $fields["category_id"] === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        $stmt->bindValue(":name", $fields["name"]);
        $stmt->bindValue(":description", $fields["description"]);
        $stmt->bindValue(":price", $fields["price"]);
        $stmt->bindValue(":stock", $fields["stock"], PDO::PARAM_INT);
        $stmt->bindValue(":image", $image, $image === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(":status", $fields["status"]);
        $stmt->execute();

        if ($productId === null) {
            $productId = (int) $pdo->lastInsertId();
        }
        adminAudit(
            $pdo,
            $adminId,
            $action,
            "product",
            $productId,
            ["name" => $fields["name"], "status" => $fields["status"]]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($upload !== null && is_file($upload["path"]) && !unlink($upload["path"])) {
            error_log("Could not remove unreferenced product image after failed save.");
        }
        throw $e;
    }

    if ($upload !== null && $oldImage !== null && $oldImage !== $upload["filename"]) {
        adminDeleteGeneratedProductImage((string) $oldImage, $pdo);
    }

    return $action === "create_product" ? "Product created." : "Product updated.";
}

function adminDeleteProduct(PDO $pdo, int $adminId): string
{
    $productId = adminPositiveId("product_id");
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT name, image FROM products WHERE id = :id FOR UPDATE
        ");
        $stmt->execute(["id" => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new DomainException("The selected product was not found.");
        }

        $deleteStmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
        $deleteStmt->execute(["id" => $productId]);
        adminAudit($pdo, $adminId, "delete_product", "product", $productId, [
            "name" => $product["name"]
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    adminDeleteGeneratedProductImage($product["image"], $pdo);
    return "Product deleted. Existing order snapshots were preserved.";
}

function adminSaveCategory(PDO $pdo, int $adminId, string $action): string
{
    $name = adminPostString("name");
    $description = adminPostString("description");
    $categoryId = $action === "update_category" ? adminPositiveId("category_id") : null;
    if (
        preg_match('/\A.{1,100}\z/us', $name) !== 1 ||
        strlen($description) > 10000
    ) {
        throw new DomainException("Enter a category name and a description under 10,000 characters.");
    }

    $pdo->beginTransaction();
    try {
        if ($categoryId === null) {
            $stmt = $pdo->prepare("
                INSERT INTO categories (name, description) VALUES (:name, :description)
            ");
        } else {
            $stmt = $pdo->prepare("
                UPDATE categories SET name = :name, description = :description
                WHERE id = :id
            ");
            $stmt->bindValue(":id", $categoryId, PDO::PARAM_INT);
        }
        $stmt->bindValue(":name", $name);
        $stmt->bindValue(":description", $description);
        $stmt->execute();

        if ($categoryId === null) {
            $categoryId = (int) $pdo->lastInsertId();
        }
        adminAudit(
            $pdo,
            $adminId,
            $action,
            "category",
            $categoryId,
            ["name" => $name]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $action === "create_category" ? "Category created." : "Category updated.";
}

function adminDeleteCategory(PDO $pdo, int $adminId): string
{
    $categoryId = adminPositiveId("category_id");
    $pdo->beginTransaction();
    try {
        $categoryStmt = $pdo->prepare("
            SELECT name FROM categories WHERE id = :id FOR UPDATE
        ");
        $categoryStmt->execute(["id" => $categoryId]);
        $category = $categoryStmt->fetch(PDO::FETCH_ASSOC);
        if (!$category) {
            throw new DomainException("The selected category was not found.");
        }

        $productStmt = $pdo->prepare("
            SELECT COUNT(*) FROM products WHERE category_id = :category_id
        ");
        $productStmt->execute(["category_id" => $categoryId]);
        if ((int) $productStmt->fetchColumn() > 0) {
            throw new DomainException("Move or delete this category's products before deleting it.");
        }

        $deleteStmt = $pdo->prepare("DELETE FROM categories WHERE id = :id");
        $deleteStmt->execute(["id" => $categoryId]);
        adminAudit($pdo, $adminId, "delete_category", "category", $categoryId, [
            "name" => $category["name"]
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return "Category deleted.";
}

function adminUpdateOrderStatus(PDO $pdo, int $adminId): string
{
    $orderId = adminPositiveId("order_id");
    $newStatus = adminPostString("order_status");
    $allowedTransitions = [
        "pending" => ["processing", "cancelled"],
        "processing" => ["shipped"],
        "shipped" => ["delivered"],
        "delivered" => [],
        "cancelled" => []
    ];
    if (!in_array($newStatus, ["pending", "processing", "shipped", "delivered", "cancelled"], true)) {
        throw new DomainException("Choose a valid order status.");
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT order_status, payment_status
            FROM orders
            WHERE id = :id
            FOR UPDATE
        ");
        $stmt->execute(["id" => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            throw new DomainException("The selected order was not found.");
        }
        $currentStatus = (string) $order["order_status"];
        if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            throw new DomainException("That order status transition is not allowed.");
        }
        if (
            $newStatus === "processing" &&
            in_array($order["payment_status"], ["failed", "refunded"], true)
        ) {
            throw new DomainException("An unpaid or refunded order cannot enter processing.");
        }

        if ($newStatus === "cancelled") {
            $itemsStmt = $pdo->prepare("
                SELECT product_id, quantity
                FROM order_items
                WHERE order_id = :order_id AND product_id IS NOT NULL
                FOR UPDATE
            ");
            $itemsStmt->execute(["order_id" => $orderId]);
            $stockUpdate = $pdo->prepare("
                UPDATE products SET stock = stock + :quantity WHERE id = :product_id
            ");
            foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $stockUpdate->execute([
                    "quantity" => (int) $item["quantity"],
                    "product_id" => (int) $item["product_id"]
                ]);
            }
        }

        $updateStmt = $pdo->prepare("
            UPDATE orders
            SET order_status = :new_status
            WHERE id = :id AND order_status = :current_status
        ");
        $updateStmt->execute([
            "new_status" => $newStatus,
            "id" => $orderId,
            "current_status" => $currentStatus
        ]);
        if ($updateStmt->rowCount() !== 1) {
            throw new DomainException("The order changed while you were updating it. Reload and try again.");
        }

        adminAudit($pdo, $adminId, "update_order_status", "order", $orderId, [
            "from" => $currentStatus,
            "to" => $newStatus
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return "Order status updated.";
}

function adminUpdateCustomer(PDO $pdo, int $adminId): string
{
    $customerId = adminPositiveId("customer_id");
    $role = adminPostString("role", "customer");
    $status = adminPostString("status");
    $creditInput = adminPostString("store_credit");
    if (
        !in_array($role, ["customer", "admin"], true) ||
        !in_array($status, ["active", "blocked"], true) ||
        !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $creditInput)
    ) {
        throw new DomainException("Enter valid customer account settings.");
    }

    $pdo->beginTransaction();
    try {
        $adminsStmt = $pdo->query("
            SELECT id FROM users
            WHERE role = 'admin' AND status = 'active'
            FOR UPDATE
        ");
        $activeAdminIds = array_map("intval", $adminsStmt->fetchAll(PDO::FETCH_COLUMN));

        $customerStmt = $pdo->prepare("
            SELECT role, status, store_credit
            FROM users
            WHERE id = :id
            FOR UPDATE
        ");
        $customerStmt->execute(["id" => $customerId]);
        $customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            throw new DomainException("The selected account was not found.");
        }

        if (
            $customerId === $adminId &&
            ($role !== "admin" || $status !== "active")
        ) {
            throw new DomainException("You cannot remove your own active administrator access.");
        }

        $isActiveAdmin = $customer["role"] === "admin" && $customer["status"] === "active";
        $willRemainActiveAdmin = $role === "admin" && $status === "active";
        if ($isActiveAdmin && !$willRemainActiveAdmin && count($activeAdminIds) <= 1) {
            throw new DomainException("The last active administrator cannot be disabled or demoted.");
        }

        $oldCredit = (float) $customer["store_credit"];
        $newCredit = (float) $creditInput;
        $creditDifference = round($newCredit - $oldCredit, 2);
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET role = :role, status = :status, store_credit = :store_credit
            WHERE id = :id
        ");
        $updateStmt->execute([
            "role" => $role,
            "status" => $status,
            "store_credit" => $creditInput,
            "id" => $customerId
        ]);

        if ($creditDifference !== 0.0) {
            $creditStmt = $pdo->prepare("
                INSERT INTO credit_transactions
                    (user_id, transaction_type, amount, description)
                VALUES
                    (:user_id, :transaction_type, :amount, 'Administrator balance adjustment')
            ");
            $creditStmt->execute([
                "user_id" => $customerId,
                "transaction_type" => $creditDifference > 0 ? "add" : "deduct",
                "amount" => abs($creditDifference)
            ]);
        }

        adminAudit($pdo, $adminId, "update_customer", "user", $customerId, [
            "role" => $role,
            "status" => $status,
            "credit_adjustment" => $creditDifference
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return "Customer account updated.";
}

function adminCouponFields(): array
{
    $code = strtoupper(adminPostString("code"));
    $type = adminPostString("discount_type");
    $value = adminPostString("discount_value");
    $minimum = adminPostString("min_order_amount", "0");
    $maximum = adminPostString("max_uses");
    $assignedEmail = adminPostString("assigned_email");
    $startsAt = adminParseDateTime(adminPostString("starts_at") ?: null, "start date");
    $expiresAt = adminParseDateTime(adminPostString("expires_at") ?: null, "expiration date");
    $status = adminPostString("status", "active");

    if (
        !preg_match('/\A[A-Z0-9_-]{3,50}\z/', $code) ||
        !in_array($type, ["percentage", "fixed"], true) ||
        !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $value) ||
        (float) $value <= 0 ||
        ($type === "percentage" && (float) $value > 100) ||
        !preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $minimum) ||
        ($maximum !== "" && !preg_match('/\A[1-9]\d{0,8}\z/', $maximum)) ||
        ($assignedEmail !== "" && (
            strlen($assignedEmail) > 254 ||
            !filter_var($assignedEmail, FILTER_VALIDATE_EMAIL)
        )) ||
        !in_array($status, ["active", "inactive"], true) ||
        ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt)
    ) {
        throw new DomainException("Enter valid coupon details and dates.");
    }

    return [
        "code" => $code,
        "discount_type" => $type,
        "discount_value" => $value,
        "min_order_amount" => $minimum,
        "max_uses" => $maximum === "" ? null : (int) $maximum,
        "assigned_email" => $assignedEmail === "" ? null : $assignedEmail,
        "starts_at" => $startsAt,
        "expires_at" => $expiresAt,
        "status" => $status
    ];
}

function adminSaveCoupon(PDO $pdo, int $adminId, string $action): string
{
    $fields = adminCouponFields();
    $couponId = $action === "update_coupon" ? adminPositiveId("coupon_id") : null;

    $pdo->beginTransaction();
    try {
        if ($couponId === null) {
            $stmt = $pdo->prepare("
                INSERT INTO coupons (
                    code, discount_type, discount_value, min_order_amount,
                    max_uses, assigned_email, starts_at, expires_at, status
                ) VALUES (
                    :code, :discount_type, :discount_value, :min_order_amount,
                    :max_uses, :assigned_email, :starts_at, :expires_at, :status
                )
            ");
        } else {
            $existingStmt = $pdo->prepare("
                SELECT used_count FROM coupons WHERE id = :id FOR UPDATE
            ");
            $existingStmt->execute(["id" => $couponId]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new DomainException("The selected coupon was not found.");
            }
            if ($fields["max_uses"] !== null && $fields["max_uses"] < (int) $existing["used_count"]) {
                throw new DomainException("The usage limit cannot be lower than uses already recorded.");
            }

            $stmt = $pdo->prepare("
                UPDATE coupons
                SET code = :code,
                    discount_type = :discount_type,
                    discount_value = :discount_value,
                    min_order_amount = :min_order_amount,
                    max_uses = :max_uses,
                    assigned_email = :assigned_email,
                    starts_at = :starts_at,
                    expires_at = :expires_at,
                    status = :status
                WHERE id = :id
            ");
            $stmt->bindValue(":id", $couponId, PDO::PARAM_INT);
        }
        foreach ($fields as $key => $value) {
            $stmt->bindValue(
                ":" . $key,
                $value,
                $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR
            );
        }
        $stmt->execute();

        if ($couponId === null) {
            $couponId = (int) $pdo->lastInsertId();
        }
        adminAudit(
            $pdo,
            $adminId,
            $action,
            "coupon",
            $couponId,
            ["code" => $fields["code"], "status" => $fields["status"]]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $action === "create_coupon" ? "Coupon created." : "Coupon updated.";
}

function adminDeleteCoupon(PDO $pdo, int $adminId): string
{
    $couponId = adminPositiveId("coupon_id");
    $stmt = $pdo->prepare("
        UPDATE coupons SET status = 'inactive' WHERE id = :id
    ");
    $stmt->execute(["id" => $couponId]);
    if ($stmt->rowCount() !== 1) {
        throw new DomainException("The selected coupon was not found or is already inactive.");
    }
    adminAudit($pdo, $adminId, "archive_coupon", "coupon", $couponId);
    return "Coupon archived. Order and newsletter history was preserved.";
}

function adminUpdateSettings(PDO $pdo, int $adminId): string
{
    $settings = [
        "shipping_fee" => adminPostString("shipping_fee"),
        "free_shipping_threshold" => adminPostString("free_shipping_threshold"),
        "low_stock_threshold" => adminPostString("low_stock_threshold")
    ];
    foreach ($settings as $value) {
        if (!preg_match('/\A\d{1,8}(?:\.\d{1,2})?\z/', $value)) {
            throw new DomainException("Enter valid non-negative store settings.");
        }
    }
    if ((int) $settings["low_stock_threshold"] > 100000) {
        throw new DomainException("The low-stock threshold cannot exceed 100,000 units.");
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO store_settings (setting_key, setting_value)
            VALUES (:setting_key, :setting_value)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        foreach ($settings as $key => $value) {
            $stmt->execute(["setting_key" => $key, "setting_value" => $value]);
        }
        adminAudit($pdo, $adminId, "update_store_settings", "settings", null, $settings);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return "Store settings saved.";
}

function adminCsvSafeCell($value): string
{
    $value = (string) $value;
    if ($value !== "" && preg_match('/\A[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function adminExportNewsletter(PDO $pdo, int $adminId): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM newsletter_subscribers")->fetchColumn();
    adminAudit($pdo, $adminId, "export_newsletter_csv", "newsletter", null, [
        "subscriber_count" => $count
    ]);

    $stmt = $pdo->query("
        SELECT email, status, created_at
        FROM newsletter_subscribers
        ORDER BY created_at DESC, id DESC
    ");

    header("Content-Type: text/csv; charset=UTF-8");
    header('Content-Disposition: attachment; filename="veloura-newsletter-subscribers.csv"');
    header("X-Content-Type-Options: nosniff");
    $output = fopen("php://output", "wb");
    if ($output === false) {
        throw new RuntimeException("The CSV export could not be opened.");
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ["Email", "Status", "Subscribed at"]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            adminCsvSafeCell($row["email"]),
            adminCsvSafeCell($row["status"]),
            adminCsvSafeCell($row["created_at"])
        ]);
    }
    fclose($output);
}

function adminHandlePost(PDO $pdo, int $adminId): void
{
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        return;
    }

    $token = $_POST["csrf_token"] ?? "";
    $section = adminPostString("return_section", "overview");
    $returnId = filter_var($_POST["return_id"] ?? null, FILTER_VALIDATE_INT);
    $redirectParams = $returnId && $returnId > 0
        ? ["view" => (int) $returnId]
        : [];

    if (!is_string($token) || !hash_equals($_SESSION["csrf_token"], $token)) {
        adminSetFlash("error", "Your session expired. Refresh the page and try again.");
        adminRedirect($section, $redirectParams);
    }

    $action = adminPostString("action");
    try {
        if ($action === "create_product" || $action === "update_product") {
            $message = adminSaveProduct($pdo, $adminId, $action);
            $section = "products";
        } elseif ($action === "delete_product") {
            $message = adminDeleteProduct($pdo, $adminId);
            $section = "products";
        } elseif ($action === "create_category" || $action === "update_category") {
            $message = adminSaveCategory($pdo, $adminId, $action);
            $section = "categories";
        } elseif ($action === "delete_category") {
            $message = adminDeleteCategory($pdo, $adminId);
            $section = "categories";
        } elseif ($action === "update_order_status") {
            $message = adminUpdateOrderStatus($pdo, $adminId);
            $section = "orders";
        } elseif ($action === "update_customer") {
            $message = adminUpdateCustomer($pdo, $adminId);
            $section = "customers";
        } elseif ($action === "create_coupon" || $action === "update_coupon") {
            $message = adminSaveCoupon($pdo, $adminId, $action);
            $section = "coupons";
        } elseif ($action === "delete_coupon") {
            $message = adminDeleteCoupon($pdo, $adminId);
            $section = "coupons";
        } elseif ($action === "update_settings") {
            $message = adminUpdateSettings($pdo, $adminId);
            $section = "settings";
        } elseif ($action === "export_newsletter_csv") {
            adminExportNewsletter($pdo, $adminId);
            exit;
        } else {
            throw new DomainException("Unknown administrator action.");
        }

        adminSetFlash("success", $message);
    } catch (DomainException $e) {
        adminSetFlash("error", $e->getMessage());
    } catch (PDOException $e) {
        error_log("Admin database action failed: " . $e->getMessage());
        adminSetFlash("error", "The change could not be saved. Check the values and try again.");
    } catch (Throwable $e) {
        error_log("Admin action failed: " . $e->getMessage());
        adminSetFlash("error", "The action could not be completed. Check the server log and try again.");
    }

    adminRedirect($section, $redirectParams);
}

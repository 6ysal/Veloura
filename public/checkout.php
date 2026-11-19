
<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/coupons.php';

// Login required
if (!isset($_SESSION["user_id"])) {
    header("Location: ../app/auth/login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

$storeSettings = [
    "shipping_fee" => "50",
    "free_shipping_threshold" => "1000"
];
$settingsStmt = $pdo->query("
    SELECT setting_key, setting_value
    FROM store_settings
    WHERE setting_key IN ('shipping_fee', 'free_shipping_threshold')
");
foreach ($settingsStmt->fetchAll(PDO::FETCH_ASSOC) as $setting) {
    $storeSettings[$setting["setting_key"]] = $setting["setting_value"];
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$error = "";
$couponCode = $_SESSION["applied_coupon"] ?? "";
$couponDiscount = 0.0;
$couponNotice = "";
$success = false;
$orderId = null;

// Shipping settings
$shippingFee = (float) $storeSettings["shipping_fee"];
$freeShippingThreshold = (float) $storeSettings["free_shipping_threshold"];

// Apply a coupon without placing the order.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["apply_coupon"])) {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $couponInput = $_POST["coupon_code"] ?? "";
    $couponCode = is_string($couponInput) ? strtoupper(trim($couponInput)) : "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif ($couponCode === "" || strlen($couponCode) > 64) {
        $error = "Enter a valid coupon code.";
    } else {
        try {
            $subtotalStmt = $pdo->prepare("
                SELECT COALESCE(SUM(p.price * ci.quantity), 0)
                FROM carts c
                JOIN cart_items ci ON ci.cart_id = c.id
                JOIN products p ON p.id = ci.product_id
                WHERE c.user_id = :user_id AND p.status = 'active'
            ");
            $subtotalStmt->execute(["user_id" => $userId]);
            $couponSubtotal = (float) $subtotalStmt->fetchColumn();

            $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id");
            $emailStmt->execute(["user_id" => $userId]);
            $userEmail = (string) $emailStmt->fetchColumn();

            $coupon = findEligibleCoupon(
                $pdo,
                $couponCode,
                $userId,
                $userEmail,
                $couponSubtotal
            );
            $_SESSION["applied_coupon"] = $coupon["code"];
            $couponDiscount = $coupon["discount"];
            $couponNotice = "Coupon applied successfully.";
        } catch (DomainException $e) {
            unset($_SESSION["applied_coupon"]);
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log("Coupon validation failed: " . $e->getMessage());
            $error = "We couldn't check this coupon right now. Please try again.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_coupon"])) {
    $csrfToken = $_POST["csrf_token"] ?? "";
    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        unset($_SESSION["applied_coupon"]);
        $couponCode = "";
        $couponDiscount = 0.0;
        $couponNotice = "Coupon removed.";
    }
}

// Handle order submission
if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    !isset($_POST["apply_coupon"]) &&
    !isset($_POST["remove_coupon"])
) {

    if (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {

        $couponInput = $_POST["coupon_code"] ?? "";
        $couponCode = is_string($couponInput) ? strtoupper(trim($couponInput)) : "";
        $shippingName = trim($_POST["shipping_name"] ?? "");
        $shippingPhone = trim($_POST["shipping_phone"] ?? "");
        $shippingAddress = trim($_POST["shipping_address"] ?? "");
        $paymentMethod = $_POST["payment_method"] ?? "";

        if (
            $shippingName === "" ||
            $shippingPhone === "" ||
            $shippingAddress === ""
        ) {
            $error = "Please complete all shipping information.";
        } elseif (
            !preg_match('/^[0-9+\-\s]{7,20}$/', $shippingPhone)
        ) {
            $error = "Please enter a valid phone number.";
        } elseif (
            !in_array($paymentMethod, ["cash_on_delivery", "store_credit"], true)
        ) {
            $error = "Please select a valid payment method.";
        } else {

            try {

                $pdo->beginTransaction();

                // Lock user's cart
                $stmt = $pdo->prepare("
                    SELECT id
                    FROM carts
                    WHERE user_id = :user_id
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute(["user_id" => $userId]);
                $cart = $stmt->fetch();

                if (!$cart) {
                    throw new Exception("Your shopping cart is empty.");
                }

                $cartId = (int) $cart["id"];

                // Get cart products and lock inventory rows
                $stmt = $pdo->prepare("
                    SELECT
                        ci.product_id,
                        ci.quantity,
                        p.name,
                        p.price,
                        p.stock,
                        p.status
                    FROM cart_items ci
                    JOIN products p ON p.id = ci.product_id
                    WHERE ci.cart_id = :cart_id
                    FOR UPDATE
                ");

                $stmt->execute(["cart_id" => $cartId]);
                $items = $stmt->fetchAll();

                if (empty($items)) {
                    throw new Exception("Your shopping cart is empty.");
                }

                $subtotal = 0;

                foreach ($items as $item) {

                    if ($item["status"] !== "active") {
                        throw new Exception(
                            "A product in your cart is no longer available."
                        );
                    }

                    if ((int) $item["quantity"] > (int) $item["stock"]) {
                        throw new Exception(
                            "Insufficient stock for " . $item["name"]
                        );
                    }

                    $subtotal +=
                        (float) $item["price"] * (int) $item["quantity"];
                }

                // Calculate shipping
                $shippingAmount = $subtotal >= $freeShippingThreshold ? 0 : $shippingFee;

                // Lock user balance
                $stmt = $pdo->prepare("
                    SELECT store_credit, email
                    FROM users
                    WHERE id = :user_id
                    FOR UPDATE
                ");

                $stmt->execute(["user_id" => $userId]);
                $user = $stmt->fetch();

                if (!$user) {
                    throw new Exception("User account not found.");
                }

                $coupon = null;
                $couponId = null;
                $discountAmount = 0;
                if ($couponCode !== "") {
                    if (strlen($couponCode) > 64) {
                        throw new DomainException("Enter a valid coupon code.");
                    }

                    $coupon = findEligibleCoupon(
                        $pdo,
                        $couponCode,
                        $userId,
                        (string) $user["email"],
                        $subtotal,
                        true
                    );
                    $couponId = $coupon["id"];
                    $discountAmount = $coupon["discount"];
                }

                $finalAmount = max(0, $subtotal - $discountAmount) + $shippingAmount;
                $storeCredit = (float) $user["store_credit"];

                // Check store credit
                if (
                    $paymentMethod === "store_credit" &&
                    $storeCredit < $finalAmount
                ) {
                    throw new Exception(
                        "Insufficient store credit. Your available balance is " .
                        number_format($storeCredit, 2) . " EGP."
                    );
                }

                // Set payment and order status
                if ($paymentMethod === "store_credit") {
                    $paymentStatus = "paid";
                    $orderStatus = "processing";
                } else {
                    $paymentStatus = "pending";
                    $orderStatus = "pending";
                }

                // Create order
                $stmt = $pdo->prepare("
                    INSERT INTO orders (
                        user_id,
                        coupon_id,
                        total_amount,
                        discount_amount,
                        shipping_amount,
                        final_amount,
                        payment_method,
                        payment_status,
                        order_status,
                        shipping_name,
                        shipping_phone,
                        shipping_address
                    ) VALUES (
                        :user_id,
                        :coupon_id,
                        :total_amount,
                        :discount_amount,
                        :shipping_amount,
                        :final_amount,
                        :payment_method,
                        :payment_status,
                        :order_status,
                        :shipping_name,
                        :shipping_phone,
                        :shipping_address
                    )
                ");

                $stmt->execute([
                    "user_id" => $userId,
                    "coupon_id" => $couponId,
                    "total_amount" => $subtotal,
                    "discount_amount" => $discountAmount,
                    "shipping_amount" => $shippingAmount,
                    "final_amount" => $finalAmount,
                    "payment_method" => $paymentMethod,
                    "payment_status" => $paymentStatus,
                    "order_status" => $orderStatus,
                    "shipping_name" => $shippingName,
                    "shipping_phone" => $shippingPhone,
                    "shipping_address" => $shippingAddress
                ]);

                $orderId = (int) $pdo->lastInsertId();

                if ($coupon !== null) {
                    $couponUpdate = $pdo->prepare("
                        UPDATE coupons
                        SET used_count = used_count + 1
                        WHERE id = :coupon_id
                          AND status = 'active'
                          AND (max_uses IS NULL OR used_count < max_uses)
                    ");
                    $couponUpdate->execute(["coupon_id" => $couponId]);

                    if ($couponUpdate->rowCount() !== 1) {
                        throw new DomainException("This coupon has already been used.");
                    }

                    $redemptionStmt = $pdo->prepare("
                        INSERT INTO coupon_redemptions (coupon_id, user_id, order_id)
                        VALUES (:coupon_id, :user_id, :order_id)
                    ");
                    $redemptionStmt->execute([
                        "coupon_id" => $couponId,
                        "user_id" => $userId,
                        "order_id" => $orderId
                    ]);
                }

                // Save order items and decrease stock
                foreach ($items as $item) {

                    $quantity = (int) $item["quantity"];
                    $price = (float) $item["price"];
                    $itemSubtotal = $price * $quantity;

                    $stmt = $pdo->prepare("
                        INSERT INTO order_items (
                            order_id,
                            product_id,
                            product_name,
                            unit_price,
                            quantity,
                            subtotal
                        ) VALUES (
                            :order_id,
                            :product_id,
                            :product_name,
                            :unit_price,
                            :quantity,
                            :subtotal
                        )
                    ");

                    $stmt->execute([
                        "order_id" => $orderId,
                        "product_id" => $item["product_id"],
                        "product_name" => $item["name"],
                        "unit_price" => $price,
                        "quantity" => $quantity,
                        "subtotal" => $itemSubtotal
                    ]);

                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET stock = stock - :quantity
                        WHERE id = :product_id
                        AND stock >= :check_quantity
                    ");

                    $stmt->execute([
                        "quantity" => $quantity,
                        "product_id" => $item["product_id"],
                        "check_quantity" => $quantity
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            "Stock could not be updated. Please try again."
                        );
                    }
                }

                // Deduct store credit if selected
                if ($paymentMethod === "store_credit") {

                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET store_credit = store_credit - :amount
                        WHERE id = :user_id
                        AND store_credit >= :check_amount
                    ");

                    $stmt->execute([
                        "amount" => $finalAmount,
                        "user_id" => $userId,
                        "check_amount" => $finalAmount
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            "Unable to deduct store credit."
                        );
                    }

                    // Record credit transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO credit_transactions (
                            user_id,
                            order_id,
                            transaction_type,
                            amount,
                            description
                        ) VALUES (
                            :user_id,
                            :order_id,
                            'deduct',
                            :amount,
                            :description
                        )
                    ");

                    $stmt->execute([
                        "user_id" => $userId,
                        "order_id" => $orderId,
                        "amount" => $finalAmount,
                        "description" => "Payment for order #" . $orderId
                    ]);
                }

                // Empty cart after successful order
                $stmt = $pdo->prepare("
                    DELETE FROM cart_items
                    WHERE cart_id = :cart_id
                ");

                $stmt->execute(["cart_id" => $cartId]);

                $pdo->commit();
                unset($_SESSION["applied_coupon"]);

                header(
                    "Location: checkout.php?success=1&order_id=" . $orderId
                );
                exit;

            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Checkout error: " . $e->getMessage());

                $error = $e->getMessage();
            }
        }
    }
}

// Successful order confirmation
if (isset($_GET["success"], $_GET["order_id"])) {

    $requestedOrderId = filter_var(
        $_GET["order_id"],
        FILTER_VALIDATE_INT
    );

    if ($requestedOrderId) {

        $stmt = $pdo->prepare("
            SELECT id, final_amount, payment_method, order_status
            FROM orders
            WHERE id = :order_id AND user_id = :user_id
            LIMIT 1
        ");

        $stmt->execute([
            "order_id" => $requestedOrderId,
            "user_id" => $userId
        ]);

        $confirmedOrder = $stmt->fetch();

        if ($confirmedOrder) {
            $success = true;
            $orderId = (int) $confirmedOrder["id"];
        }
    }
}

// Get current cart
$cartItems = [];
$subtotal = 0;
$cartId = null;

$stmt = $pdo->prepare("
    SELECT id
    FROM carts
    WHERE user_id = :user_id
    LIMIT 1
");

$stmt->execute(["user_id" => $userId]);
$cart = $stmt->fetch();

if ($cart) {

    $cartId = (int) $cart["id"];

    $stmt = $pdo->prepare("
        SELECT
            ci.product_id,
            ci.quantity,
            p.name,
            p.price,
            p.stock
        FROM cart_items ci
        JOIN products p ON p.id = ci.product_id
        WHERE ci.cart_id = :cart_id
    ");

    $stmt->execute(["cart_id" => $cartId]);
    $cartItems = $stmt->fetchAll();

    foreach ($cartItems as $item) {
        $subtotal +=
            (float) $item["price"] * (int) $item["quantity"];
    }
}

$shippingAmount = $subtotal >= $freeShippingThreshold ? 0 : $shippingFee;
$coupon = null;
if ($couponCode !== "" && !$success) {
    try {
        $emailStmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id");
        $emailStmt->execute(["user_id" => $userId]);
        $coupon = findEligibleCoupon(
            $pdo,
            $couponCode,
            $userId,
            (string) $emailStmt->fetchColumn(),
            $subtotal
        );
        $couponDiscount = $coupon["discount"];
    } catch (DomainException $e) {
        if ($couponNotice === "") {
            $couponNotice = $e->getMessage();
        }
        unset($_SESSION["applied_coupon"]);
        $coupon = null;
        $couponDiscount = 0.0;
    } catch (PDOException $e) {
        error_log("Coupon summary validation failed: " . $e->getMessage());
        if ($couponNotice === "") {
            $couponNotice = "We couldn't check this coupon right now.";
        }
        $coupon = null;
        $couponDiscount = 0.0;
    }
}
$finalAmount = max(0, $subtotal - $couponDiscount) + $shippingAmount;

// Get user's available credit
$stmt = $pdo->prepare("
    SELECT store_credit
    FROM users
    WHERE id = :user_id
");

$stmt->execute(["user_id" => $userId]);
$credit = (float) $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Checkout | VELOURA</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .checkout-page {
            max-width: 1100px;
            margin: 60px auto;
            padding: 0 20px;
        }

        .checkout-page h1 {
            margin-bottom: 30px;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 35px;
        }

        .checkout-box {
            background: #fff;
            border: 1px solid #eee;
            padding: 28px;
            margin-bottom: 20px;
        }

        .checkout-box h2 {
            margin-bottom: 22px;
            font-size: 22px;
        }

        .checkout-form-group {
            margin-bottom: 18px;
        }

        .checkout-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .checkout-form-group input,
        .checkout-form-group textarea,
        .checkout-form-group select {
            width: 100%;
            padding: 13px;
            border: 1px solid #ddd;
            font-family: inherit;
            font-size: 15px;
        }

        .checkout-form-group textarea {
            min-height: 110px;
            resize: vertical;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 15px;
            border: 1px solid #ddd;
            margin-bottom: 12px;
            cursor: pointer;
        }

        .payment-option input {
            accent-color: #8b5e62;
        }

        .checkout-summary-item {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }

        .checkout-summary-total {
            display: flex;
            justify-content: space-between;
            font-size: 20px;
            font-weight: bold;
            margin-top: 20px;
        }

        .checkout-alert {
            padding: 15px;
            margin-bottom: 20px;
            background: #f2ebe5;
            color: #382b29;
        }

        .checkout-submit {
            width: 100%;
            padding: 15px;
            border: none;
            background: #8b5e62;
            color: white;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }

        .checkout-submit:hover {
            background: #70484c;
        }

        .checkout-apply {
            margin-top: 0;
            padding: 12px;
            font-size: 14px;
        }

        .checkout-remove {
            margin-top: 10px;
            background: #f2ebe5;
            color: #382b29;
        }

        .checkout-remove:hover {
            background: #e9ddd4;
        }

        .coupon-message {
            margin: -4px 0 16px;
            color: #42613d;
            font-size: 14px;
        }

        .coupon-message.error {
            color: #914c50;
        }

        .checkout-success {
            max-width: 650px;
            margin: 70px auto;
            padding: 40px 25px;
            text-align: center;
            background: #fff;
            border: 1px solid #eee;
        }

        .checkout-success h1 {
            color: #6b8b65;
            margin-bottom: 15px;
        }

        .checkout-success p {
            margin: 12px 0;
        }

        .checkout-success a {
            display: inline-block;
            margin-top: 25px;
        }

        .checkout-note {
            font-size: 13px;
            color: #6f625e;
            margin-top: 10px;
        }

        @media (max-width: 750px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <a href="index.php" class="logo">VELOURA<span>.</span></a>

    <nav class="navbar">
        <a href="index.php">Home</a>
        <a href="products.php">Shop</a>
    </nav>

    <?php require __DIR__ . '/partials/header-actions.php'; ?>
</header>

<?php if ($success): ?>

    <main class="checkout-success">

        <h1>Thank You for Your Order!</h1>

        <p>Your order has been placed successfully.</p>

        <p>
            Order Number:
            <strong>#<?= $orderId ?></strong>
        </p>

        <p>
            Total:
            <strong>
                <?= number_format(
                    (float) $confirmedOrder["final_amount"],
                    2
                ) ?> EGP
            </strong>
        </p>

        <p>
            Payment Method:
            <strong>
                <?= $confirmedOrder["payment_method"] === "store_credit"
                    ? "Store Credit"
                    : "Cash on Delivery" ?>
            </strong>
        </p>

        <a href="products.php" class="primary-btn">
            Continue Shopping
        </a>

    </main>

<?php else: ?>

    <main class="checkout-page">

        <h1>Checkout</h1>

        <?php if ($error): ?>
            <div class="checkout-alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (empty($cartItems)): ?>

            <div class="checkout-box">
                <h2>Your cart is empty</h2>
                <a href="products.php" class="primary-btn">
                    Explore Products
                </a>
            </div>

        <?php else: ?>

            <div class="checkout-grid">

                <div>

                    <form method="POST" action="checkout.php">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                        <div class="checkout-box">

                            <h2>Shipping Information</h2>

                            <div class="checkout-form-group">
                                <label for="shipping_name">
                                    Full Name
                                </label>

                                <input
                                    type="text"
                                    id="shipping_name"
                                    name="shipping_name"
                                    maxlength="150"
                                    value="<?= htmlspecialchars(
                                        $_POST["shipping_name"] ?? ($_SESSION["user_name"] ?? ""),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div class="checkout-form-group">
                                <label for="shipping_phone">
                                    Phone Number
                                </label>

                                <input
                                    type="tel"
                                    id="shipping_phone"
                                    name="shipping_phone"
                                    maxlength="20"
                                    value="<?= htmlspecialchars(
                                        $_POST["shipping_phone"] ?? "",
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>"
                                    required
                                >
                            </div>

                            <div class="checkout-form-group">
                                <label for="shipping_address">
                                    Delivery Address
                                </label>

                                <textarea
                                    id="shipping_address"
                                    name="shipping_address"
                                    required
                                ><?= htmlspecialchars(
                                    $_POST["shipping_address"] ?? "",
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?></textarea>
                            </div>

                        </div>

                        <div class="checkout-box">

                            <h2>Discount Code</h2>
                            <div class="checkout-form-group">
                                <label for="coupon_code">Enter Coupon Code</label>
                                <input
                                    type="text"
                                    id="coupon_code"
                                    name="coupon_code"
                                    maxlength="64"
                                    value="<?= htmlspecialchars($couponCode, ENT_QUOTES, 'UTF-8') ?>"
                                    autocomplete="off"
                                >
                            </div>
                            <?php if ($couponNotice !== ""): ?>
                                <p class="coupon-message <?= $error !== "" ? "error" : "" ?>">
                                    <?= htmlspecialchars($couponNotice, ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            <?php endif; ?>
                            <button
                                type="submit"
                                name="apply_coupon"
                                value="1"
                                class="checkout-submit checkout-apply"
                                formnovalidate
                            >
                                Apply Coupon
                            </button>
                            <?php if ($couponCode !== ""): ?>
                                <button
                                    type="submit"
                                    name="remove_coupon"
                                    value="1"
                                    class="checkout-submit checkout-apply checkout-remove"
                                    formnovalidate
                                >
                                    Remove Coupon
                                </button>
                            <?php endif; ?>

                        </div>

                        <div class="checkout-box">

                            <h2>Payment Method</h2>

                            <label class="payment-option">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="cash_on_delivery"
                                    <?= ($_POST["payment_method"] ?? "cash_on_delivery") === "cash_on_delivery" ? "checked" : "" ?>
                                >
                                <span>Cash on Delivery</span>
                            </label>

                            <label class="payment-option">
                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="store_credit"
                                    <?= ($_POST["payment_method"] ?? "") === "store_credit" ? "checked" : "" ?>
                                >
                                <span>
                                    Store Credit
                                    (Available:
                                    <?= number_format($credit, 2) ?> EGP)
                                </span>
                            </label>

                            <p class="checkout-note">
                                Store Credit will be deducted from your account
                                after successful order confirmation.
                            </p>

                            <button
                                type="submit"
                                class="checkout-submit"
                            >
                                Place Order
                            </button>

                        </div>

                    </form>

                </div>

                <div>

                    <div class="checkout-box">

                        <h2>Order Summary</h2>

                        <?php foreach ($cartItems as $item): ?>

                            <div class="checkout-summary-item">

                                <span>
                                    <?= htmlspecialchars(
                                        $item["name"],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    × <?= (int) $item["quantity"] ?>
                                </span>

                                <strong>
                                    <?= number_format(
                                        (float) $item["price"] *
                                        (int) $item["quantity"],
                                        2
                                    ) ?> EGP
                                </strong>

                            </div>

                        <?php endforeach; ?>

                        <div class="checkout-summary-item">
                            <span>Subtotal</span>
                            <strong>
                                <?= number_format($subtotal, 2) ?> EGP
                            </strong>
                        </div>

                        <?php if ($couponDiscount > 0): ?>
                            <div class="checkout-summary-item">
                                <span>Discount<?= $coupon ? " (" . htmlspecialchars($coupon["code"], ENT_QUOTES, 'UTF-8') . ")" : "" ?></span>
                                <strong>-<?= number_format($couponDiscount, 2) ?> EGP</strong>
                            </div>
                        <?php endif; ?>

                        <div class="checkout-summary-item">
                            <span>Shipping</span>
                            <strong>
                                <?= $shippingAmount == 0
                                    ? "FREE"
                                    : number_format($shippingAmount, 2) . " EGP" ?>
                            </strong>
                        </div>

                        <div class="checkout-summary-total">
                            <span>Total</span>
                            <span>
                                <?= number_format($finalAmount, 2) ?> EGP
                            </span>
                        </div>

                        <p class="checkout-note">
                            Free shipping on orders over <?= number_format($freeShippingThreshold, 2) ?> EGP.
                        </p>

                    </div>

                </div>

            </div>

        <?php endif; ?>

    </main>

<?php endif; ?>

</body>
</html>

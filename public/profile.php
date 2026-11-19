<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: ../app/auth/login.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION["user_id"];
$profileStmt = $pdo->prepare("
    SELECT full_name, email, phone, store_credit
    FROM users
    WHERE id = :user_id
    LIMIT 1
");
$profileStmt->execute(["user_id" => $userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    http_response_code(404);
    exit("User profile not found.");
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = $_POST["full_name"] ?? "";
    $email = $_POST["email"] ?? "";
    $phone = $_POST["phone"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

    $name = is_string($name) ? trim($name) : "";
    $email = is_string($email) ? trim($email) : "";
    $phone = is_string($phone) ? trim($phone) : "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $error = "Your session expired. Please refresh the page and try again.";
    } elseif (
        preg_match('/\A.{1,150}\z/us', $name) !== 1 ||
        strlen($email) > 254 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/\A[0-9+\-\s()]{7,20}\z/D', $phone)
    ) {
        $error = "Enter a valid name, email address, and phone number.";
    } else {
        $profile["full_name"] = $name;
        $profile["email"] = $email;
        $profile["phone"] = $phone;

        try {
            $emailStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email AND id <> :user_id
                LIMIT 1
            ");
            $emailStmt->execute([
                "email" => $email,
                "user_id" => $userId
            ]);

            if ($emailStmt->fetch()) {
                $error = "That email address is already associated with another account.";
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET full_name = :full_name, email = :email, phone = :phone
                    WHERE id = :user_id
                ");
                $updateStmt->execute([
                    "full_name" => $name,
                    "email" => $email,
                    "phone" => $phone,
                    "user_id" => $userId
                ]);

                $_SESSION["user_name"] = $name;
                $_SESSION["user_email"] = $email;
                $message = "Your profile has been updated.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() === "23000") {
                $error = "That email address is already associated with another account.";
            } else {
                error_log("Profile update failed: " . $e->getMessage());
                $error = "We couldn't update your profile right now. Please try again later.";
            }
        }
    }
}

$ordersStmt = $pdo->prepare("
    SELECT id, final_amount, payment_method, payment_status, order_status, created_at
    FROM orders
    WHERE user_id = :user_id
    ORDER BY created_at DESC, id DESC
");
$ordersStmt->execute(["user_id" => $userId]);
$orders = $ordersStmt->fetchAll();

$orderItems = [];
if (!empty($orders)) {
    $orderPlaceholders = [];
    $orderParameters = [];

    foreach ($orders as $index => $order) {
        $placeholder = ":order_id_" . $index;
        $orderPlaceholders[] = $placeholder;
        $orderParameters["order_id_" . $index] = (int) $order["id"];
    }

    $itemsStmt = $pdo->prepare("
        SELECT order_id, product_name, quantity, subtotal
        FROM order_items
        WHERE order_id IN (" . implode(", ", $orderPlaceholders) . ")
        ORDER BY id ASC
    ");
    $itemsStmt->execute($orderParameters);

    foreach ($itemsStmt->fetchAll() as $item) {
        $orderItems[(int) $item["order_id"]][] = $item;
    }
}

$orderStatusLabels = [
    "pending" => "Order placed",
    "processing" => "Being prepared",
    "shipped" => "On the way",
    "delivered" => "Delivered",
    "cancelled" => "Cancelled"
];
$orderStatusSteps = [
    "pending" => 0,
    "processing" => 1,
    "shipped" => 2,
    "delivered" => 3
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | VELOURA</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="header">
    <a href="index.php" class="logo">VELOURA<span>.</span></a>
    <nav class="navbar">
        <a href="index.php">Home</a>
        <a href="products.php">Shop</a>
        <a href="index.php#contact">Contact</a>
    </nav>
    <?php require __DIR__ . '/partials/header-actions.php'; ?>
</header>

<main class="profile-page">
    <div class="profile-container">
        <div class="profile-heading">
            <p class="subtitle">MY VELOURA</p>
            <h1>Your Profile</h1>
            <p>Manage your personal information and account details.</p>
        </div>

        <?php if ($message !== ""): ?>
            <p class="profile-feedback" role="status">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <?php if ($error !== ""): ?>
            <p class="profile-feedback profile-error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <div class="profile-grid">
            <section class="profile-overview">
                <div class="profile-avatar" aria-hidden="true">V</div>
                <p class="profile-overview-label">SIGNED IN AS</p>
                <h2><?= htmlspecialchars($profile["full_name"], ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="profile-overview-email">
                    <?= htmlspecialchars($profile["email"], ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="profile-overview-phone">
                    <?= htmlspecialchars($profile["phone"] ?? "Phone not added", ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div class="profile-credit">
                    <span>Store credit</span>
                    <strong><?= number_format((float) $profile["store_credit"], 2) ?> EGP</strong>
                </div>
            </section>

            <section class="profile-form-card">
                <h2>Personal information</h2>
                <p>Keep your account details up to date.</p>

                <form method="POST" action="profile.php" class="profile-form">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <div class="profile-form-group">
                        <label for="full_name">Full name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            autocomplete="name"
                            maxlength="150"
                            value="<?= htmlspecialchars($profile["full_name"], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="profile-form-group">
                        <label for="email">Email address</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            autocomplete="email"
                            maxlength="254"
                            value="<?= htmlspecialchars($profile["email"], ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="profile-form-group">
                        <label for="phone">Phone number</label>
                        <input
                            type="tel"
                            id="phone"
                            name="phone"
                            autocomplete="tel"
                            maxlength="20"
                            value="<?= htmlspecialchars($profile["phone"] ?? "", ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <button type="submit">Save Changes</button>
                </form>

                <a class="profile-back-link" href="products.php">Continue shopping</a>
            </section>
        </div>

        <section class="profile-orders">
            <div class="profile-orders-heading">
                <div>
                    <p class="subtitle">YOUR PURCHASES</p>
                    <h2>Order history</h2>
                </div>
                <span><?= count($orders) ?> <?= count($orders) === 1 ? "order" : "orders" ?></span>
            </div>

            <?php if (empty($orders)): ?>
                <div class="profile-no-orders">
                    <h3>No orders yet</h3>
                    <p>Your orders and their delivery progress will appear here.</p>
                    <a href="products.php" class="secondary-btn">Explore the collection</a>
                </div>
            <?php else: ?>
                <div class="profile-order-list">
                    <?php foreach ($orders as $order): ?>
                        <?php
                        $status = $order["order_status"];
                        $statusLabel = $orderStatusLabels[$status]
                            ?? ucfirst(str_replace("_", " ", $status));
                        $isCancelled = $status === "cancelled";
                        $currentStep = $orderStatusSteps[$status] ?? 0;
                        ?>

                        <article class="profile-order-card">
                            <div class="profile-order-header">
                                <div>
                                    <p class="profile-order-number">ORDER #<?= (int) $order["id"] ?></p>
                                    <time datetime="<?= htmlspecialchars($order["created_at"], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= date("M j, Y", strtotime($order["created_at"])) ?>
                                    </time>
                                </div>
                                <span class="order-status-badge<?= $isCancelled ? " is-cancelled" : "" ?>">
                                    <?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <?php if ($isCancelled): ?>
                                <p class="order-cancelled-note">This order was cancelled.</p>
                            <?php else: ?>
                                <ol class="order-tracking" aria-label="Order progress">
                                    <?php
                                    $trackingSteps = ["Placed", "Preparing", "Shipped", "Delivered"];
                                    foreach ($trackingSteps as $stepIndex => $stepLabel):
                                        $stepClass = $stepIndex < $currentStep
                                            ? "is-complete"
                                            : ($stepIndex === $currentStep ? "is-current" : "");
                                    ?>
                                        <li class="<?= $stepClass ?>"
                                            <?= $stepIndex === $currentStep ? 'aria-current="step"' : "" ?>>
                                            <span><?= $stepLabel ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php endif; ?>

                            <ul class="profile-order-items">
                                <?php foreach ($orderItems[(int) $order["id"]] ?? [] as $item): ?>
                                    <li>
                                        <span>
                                            <?= htmlspecialchars($item["product_name"], ENT_QUOTES, 'UTF-8') ?>
                                            <small> x <?= (int) $item["quantity"] ?></small>
                                        </span>
                                        <strong><?= number_format((float) $item["subtotal"], 2) ?> EGP</strong>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <div class="profile-order-footer">
                                <span>
                                    <?= $order["payment_method"] === "store_credit"
                                        ? "Store credit"
                                        : "Cash on delivery" ?>
                                    &middot;
                                    Payment <?= htmlspecialchars(ucfirst($order["payment_status"]), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <strong><?= number_format((float) $order["final_amount"], 2) ?> EGP</strong>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

</body>
</html>

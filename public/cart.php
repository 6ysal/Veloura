
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Cart requires login
if (!isset($_SESSION["user_id"])) {
    header("Location: ../app/auth/login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];
$message = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    (
        !isset($_POST["csrf_token"]) ||
        !is_string($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    )
) {
    http_response_code(400);
    exit("Invalid request. Please refresh the page and try again.");
}

// Get or create user's cart
$stmt = $pdo->prepare(
    "SELECT id FROM carts WHERE user_id = :user_id LIMIT 1"
);
$stmt->execute(["user_id" => $userId]);
$cart = $stmt->fetch();

if (!$cart) {
    $stmt = $pdo->prepare(
        "INSERT INTO carts (user_id, created_at) VALUES (:user_id, NOW())"
    );
    $stmt->execute(["user_id" => $userId]);

    $cartId = (int) $pdo->lastInsertId();
} else {
    $cartId = (int) $cart["id"];
}

// Add product to cart
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["product_id"])) {

    $productId = filter_var(
        $_POST["product_id"],
        FILTER_VALIDATE_INT
    );

    $quantity = filter_var(
        $_POST["quantity"] ?? 1,
        FILTER_VALIDATE_INT
    );

    if (!$productId || !$quantity || $quantity < 1) {
        header("Location: products.php");
        exit;
    }

    // Check product availability
    $stmt = $pdo->prepare(
        "SELECT id, stock FROM products
         WHERE id = :product_id AND status = 'active'
         LIMIT 1"
    );
    $stmt->execute(["product_id" => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        $message = "This product is currently unavailable.";
    } else {
        // Check whether product already exists in cart
        $stmt = $pdo->prepare(
            "SELECT id, quantity FROM cart_items
             WHERE cart_id = :cart_id
             AND product_id = :product_id
             LIMIT 1"
        );

        $stmt->execute([
            "cart_id" => $cartId,
            "product_id" => $productId
        ]);

        $existingItem = $stmt->fetch();

        $newQuantity = $quantity;

        if ($existingItem) {
            $newQuantity += (int) $existingItem["quantity"];
        }

        if ($newQuantity > (int) $product["stock"]) {
            $message = "Sorry, the requested quantity is not available.";
        } elseif ($existingItem) {
            $stmt = $pdo->prepare(
                "UPDATE cart_items
                 SET quantity = :quantity
                 WHERE id = :id AND cart_id = :cart_id"
            );

            $stmt->execute([
                "quantity" => $newQuantity,
                "id" => $existingItem["id"],
                "cart_id" => $cartId
            ]);

            header("Location: cart.php?added=1");
            exit;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO cart_items
                 (cart_id, product_id, quantity, created_at)
                 VALUES (:cart_id, :product_id, :quantity, NOW())"
            );

            $stmt->execute([
                "cart_id" => $cartId,
                "product_id" => $productId,
                "quantity" => $quantity
            ]);

            header("Location: cart.php?added=1");
            exit;
        }
    }
}

// Remove product from cart
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["remove_item"])) {
    $itemId = filter_var($_POST["remove_item"], FILTER_VALIDATE_INT);

    if ($itemId) {
        $stmt = $pdo->prepare(
            "DELETE FROM cart_items
             WHERE id = :id AND cart_id = :cart_id"
        );

        $stmt->execute([
            "id" => $itemId,
            "cart_id" => $cartId
        ]);
    }

    header("Location: cart.php");
    exit;
}

// Update product quantity
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_item"])) {
    $itemId = filter_var($_POST["update_item"], FILTER_VALIDATE_INT);
    $quantity = filter_var($_POST["quantity"], FILTER_VALIDATE_INT);

    if ($itemId && $quantity && $quantity > 0) {
        $stmt = $pdo->prepare(
            "SELECT ci.product_id, p.stock
             FROM cart_items ci
             JOIN products p ON p.id = ci.product_id
             WHERE ci.id = :id AND ci.cart_id = :cart_id
             LIMIT 1"
        );

        $stmt->execute([
            "id" => $itemId,
            "cart_id" => $cartId
        ]);

        $item = $stmt->fetch();

        if ($item && $quantity <= (int) $item["stock"]) {
            $stmt = $pdo->prepare(
                "UPDATE cart_items
                 SET quantity = :quantity
                 WHERE id = :id AND cart_id = :cart_id"
            );

            $stmt->execute([
                "quantity" => $quantity,
                "id" => $itemId,
                "cart_id" => $cartId
            ]);
        }
    }

    header("Location: cart.php");
    exit;
}

// Fetch cart products
$stmt = $pdo->prepare(
    "SELECT
        ci.id AS item_id,
        ci.quantity,
        p.name,
        p.price,
        p.image,
        p.stock
     FROM cart_items ci
     JOIN products p ON p.id = ci.product_id
     WHERE ci.cart_id = :cart_id
     ORDER BY ci.created_at DESC"
);

$stmt->execute(["cart_id" => $cartId]);
$items = $stmt->fetchAll();

$total = 0;

foreach ($items as $item) {
    $total += (float) $item["price"] * (int) $item["quantity"];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart | VELOURA</title>
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .cart-page {
            max-width: 1100px;
            margin: 60px auto;
            padding: 0 20px;
        }

        .cart-page h1 {
            margin-bottom: 30px;
        }

        .cart-item {
            display: grid;
            grid-template-columns: 100px 1fr auto auto;
            gap: 20px;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid #eee;
        }

        .cart-item img {
            width: 100px;
            height: 110px;
            object-fit: cover;
            background: #f2ebe5;
        }

        .cart-item h3 {
            margin-bottom: 8px;
        }

        .cart-item input {
            width: 65px;
            padding: 8px;
        }

        .cart-item button,
        .cart-total button {
            padding: 10px 18px;
            border: none;
            cursor: pointer;
            background: #8b5e62;
            color: white;
        }

        .remove-button {
            background: transparent !important;
            color: #8b5e62 !important;
            text-decoration: underline;
        }

        .cart-total {
            text-align: right;
            margin-top: 30px;
        }

        .cart-total h2 {
            margin-bottom: 20px;
        }

        .empty-cart {
            padding: 50px 0;
            text-align: center;
        }

        .cart-message {
            background: #f2ebe5;
            padding: 15px;
            margin-bottom: 20px;
        }

        @media (max-width: 650px) {
            .cart-item {
                grid-template-columns: 75px 1fr;
            }

            .cart-item img {
                width: 75px;
                height: 85px;
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

<main class="cart-page">

    <h1>My Shopping Cart</h1>

    <?php if (isset($_GET["added"])): ?>
        <p class="cart-message">Product added to your cart successfully!</p>
    <?php endif; ?>

    <?php if ($message): ?>
        <p class="cart-message">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <?php if (empty($items)): ?>

        <div class="empty-cart">
            <h2>Your cart is empty</h2>
            <p>Discover something beautiful at VELOURA.</p>
            <a href="products.php" class="primary-btn">Explore Products</a>
        </div>

    <?php else: ?>

        <?php foreach ($items as $item): ?>

            <div class="cart-item">

                <?php
                $imagePath = trim($item["image"] ?? "");
                $imageUrl = "";

                if ($imagePath !== "") {
                    if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                        $imageUrl = $imagePath;
                    } elseif (strpos($imagePath, "assets/") === 0) {
                        $imageUrl = $imagePath;
                    } else {
                        $imageUrl = "assets/images/" . basename($imagePath);
                    }
                }
                ?>

                <?php if ($imageUrl !== ""): ?>
                    <img
                        src="<?= htmlspecialchars($imageUrl, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($item["name"], ENT_QUOTES, 'UTF-8') ?>"
                    >
                <?php else: ?>
                    <div></div>
                <?php endif; ?>

                <div>
                    <h3><?= htmlspecialchars($item["name"], ENT_QUOTES, 'UTF-8') ?></h3>
                    <p><?= number_format((float) $item["price"], 2) ?> EGP</p>
                </div>

                <form method="POST" action="cart.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="number" name="quantity"
                           value="<?= (int) $item["quantity"] ?>"
                           min="1"
                           max="<?= (int) $item["stock"] ?>"
                           required>

                    <button type="submit" name="update_item"
                            value="<?= (int) $item["item_id"] ?>">
                        Update
                    </button>
                </form>

                <form method="POST" action="cart.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" name="remove_item"
                            value="<?= (int) $item["item_id"] ?>"
                            class="remove-button">
                        Remove
                    </button>
                </form>

            </div>

        <?php endforeach; ?>

        <div class="cart-total">
            <h2>
                Total: <?= number_format($total, 2) ?> EGP
            </h2>

            <a href="checkout.php" class="primary-btn">
                Proceed to Checkout
            </a>
        </div>

    <?php endif; ?>

</main>

</body>
</html>


<?php
session_start();

require_once __DIR__ . '/../config/database.php';

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$productId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$productId || $productId < 1) {
    header("Location: products.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = :id AND p.status = 'active'
    LIMIT 1
");

$stmt->execute(["id" => $productId]);
$product = $stmt->fetch();

if (!$product) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

$availableStock = $notFound ? 0 : (int)$product["stock"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= $notFound ? 'Product Not Found' : htmlspecialchars($product['name']) . ' | VELOURA' ?>
    </title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .details-page {
            background: #fbf8f4;
            min-height: 100vh;
            padding: 55px 8%;
        }

        .breadcrumb {
            font-size: 13px;
            color: #8a7c75;
            margin-bottom: 35px;
        }

        .breadcrumb a {
            color: #8b5e62;
            text-decoration: none;
        }

        .details-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 65px;
            align-items: start;
            max-width: 1150px;
            margin: auto;
        }

        .details-image {
            background: #f2ebe5;
            height: 520px;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .details-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .details-placeholder {
            font-family: Georgia, serif;
            font-size: 28px;
            color: #8b5e62;
        }

        .details-info {
            padding-top: 30px;
        }

        .details-category {
            color: #b77b80;
            letter-spacing: 3px;
            text-transform: uppercase;
            font-size: 11px;
        }

        .details-info h1 {
            color: #382b29;
            font-family: Georgia, serif;
            font-weight: 400;
            font-size: 39px;
            margin: 18px 0;
        }

        .details-price {
            font-size: 23px;
            color: #8b5e62;
            font-weight: 600;
            margin-bottom: 25px;
        }

        .details-description {
            color: #6f625e;
            line-height: 1.9;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .stock-status {
            font-size: 13px;
            margin-bottom: 25px;
        }

        .in-stock {
            color: #63815d;
        }

        .out-stock {
            color: #b44b4b;
        }

        .quantity-label {
            display: block;
            color: #382b29;
            font-size: 13px;
            margin-bottom: 10px;
        }

        .quantity-input {
            width: 100px;
            padding: 12px;
            border: 1px solid #e5d9d1;
            background: white;
            border-radius: 4px;
            font-size: 15px;
        }

        .add-cart-button {
            display: block;
            width: 100%;
            margin-top: 25px;
            padding: 16px;
            background: #8b5e62;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            letter-spacing: 1px;
            cursor: pointer;
        }

        .add-cart-button:hover {
            background: #382b29;
        }

        .add-cart-button:disabled {
            background: #b9aaa5;
            cursor: not-allowed;
        }

        .back-link {
            display: inline-block;
            margin-top: 25px;
            color: #8b5e62;
            text-decoration: none;
            font-size: 13px;
        }

        .not-found {
            text-align: center;
            padding: 100px 20px;
        }

        @media (max-width: 750px) {
            .details-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .details-image {
                height: 350px;
            }

            .details-info {
                padding-top: 10px;
            }

            .details-info h1 {
                font-size: 30px;
            }

            .details-page {
                padding: 35px 5%;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <div class="logo">VELO<span>URA</span></div>

    <nav class="navbar">
        <a href="index.php">Home</a>
        <a href="products.php">Shop</a>
        <a href="index.php#about">About</a>
        <a href="index.php#contact">Contact</a>
    </nav>

    <?php require __DIR__ . '/partials/header-actions.php'; ?>
</header>

<main class="details-page">

    <div class="breadcrumb">
        <a href="index.php">Home</a> /
        <a href="products.php">Shop</a> /
        Product Details
    </div>

    <?php if ($notFound): ?>

        <div class="not-found">
            <h1>Product Not Found</h1>
            <p>This product is unavailable or no longer exists.</p>
            <a class="back-link" href="products.php">Back to Shop</a>
        </div>

    <?php else: ?>

        <section class="details-container">

            <div class="details-image">

                <?php
                $imagePath = trim($product["image"] ?? "");
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
                        src="<?= htmlspecialchars($imageUrl) ?>"
                        alt="<?= htmlspecialchars($product["name"]) ?>"
                        onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                    >
                <?php endif; ?>

                <div class="details-placeholder"
                     style="<?= $imageUrl !== "" ? 'display:none;' : '' ?>">
                    VELOURA
                </div>

            </div>

            <div class="details-info">

                <span class="details-category">
                    <?= htmlspecialchars($product["category_name"] ?? "Beauty") ?>
                </span>

                <h1><?= htmlspecialchars($product["name"]) ?></h1>

                <div class="details-price">
                    EGP <?= number_format((float)$product["price"], 2) ?>
                </div>

                <p class="details-description">
                    <?= nl2br(htmlspecialchars($product["description"] ?? "")) ?>
                </p>

                <div class="stock-status <?= $availableStock > 0 ? 'in-stock' : 'out-stock' ?>">
                    <?= $availableStock > 0
                        ? "In Stock — {$availableStock} available"
                        : "Currently Out of Stock" ?>
                </div>

                <form action="cart.php" method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <input
                        type="hidden"
                        name="product_id"
                        value="<?= (int)$product["id"] ?>"
                    >

                    <label class="quantity-label" for="quantity">
                        Quantity
                    </label>

                    <input
                        class="quantity-input"
                        type="number"
                        id="quantity"
                        name="quantity"
                        value="1"
                        min="1"
                        max="<?= $availableStock ?>"
                        required
                        <?= $availableStock < 1 ? "disabled" : "" ?>
                    >

                    <button
                        type="submit"
                        class="add-cart-button"
                        <?= $availableStock < 1 ? "disabled" : "" ?>
                    >
                        ADD TO CART
                    </button>

                </form>

                <a href="products.php" class="back-link">
                    ← Back to Shop
                </a>

            </div>

        </section>

    <?php endif; ?>

</main>

</body>
</html>


<?php
session_start();

require_once __DIR__ . '/../config/database.php';

$categoryId = filter_input(INPUT_GET, "category", FILTER_VALIDATE_INT);
$search = trim($_GET["search"] ?? "");
$sort = $_GET["sort"] ?? "newest";

$sql = "SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.status = 'active'";

$params = [];

if ($categoryId && $categoryId > 0) {
    $sql .= " AND p.category_id = :category_id";
    $params["category_id"] = $categoryId;
}

if ($search !== "") {
    $sql .= " AND (p.name LIKE :search_name OR p.description LIKE :search_description)";
    $params["search_name"] = "%$search%";
    $params["search_description"] = "%$search%";
}

switch ($sort) {
    case "price_low":
        $sql .= " ORDER BY p.price ASC";
        break;

    case "price_high":
        $sql .= " ORDER BY p.price DESC";
        break;

    case "name":
        $sql .= " ORDER BY p.name ASC";
        break;

    default:
        $sql .= " ORDER BY p.created_at DESC";
        break;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categoryStmt = $pdo->query(
    "SELECT id, name FROM categories ORDER BY name ASC"
);
$categories = $categoryStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Shop All Products | VELOURA</title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .shop-page {
            background: #fbf8f4;
            min-height: 100vh;
            padding: 55px 7%;
        }

        .shop-heading {
            text-align: center;
            margin-bottom: 45px;
        }

        .shop-heading .eyebrow {
            color: #b77b80;
            letter-spacing: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .shop-heading h1 {
            font-family: Georgia, serif;
            font-size: 45px;
            color: #382b29;
            font-weight: 400;
            margin: 12px 0;
        }

        .shop-heading p {
            color: #6f625e;
            font-size: 14px;
        }

        .shop-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 30px;
        }

        .shop-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .shop-filters a {
            text-decoration: none;
            color: #6f625e;
            border: 1px solid #e5d9d1;
            padding: 10px 17px;
            border-radius: 25px;
            font-size: 13px;
        }

        .shop-filters a.active,
        .shop-filters a:hover {
            background: #8b5e62;
            color: white;
            border-color: #8b5e62;
        }

        .shop-tools {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .shop-tools input,
        .shop-tools select {
            padding: 11px 14px;
            border: 1px solid #e5d9d1;
            border-radius: 5px;
            background: white;
            color: #382b29;
            outline: none;
        }

        .shop-tools button {
            padding: 11px 18px;
            border: none;
            background: #8b5e62;
            color: white;
            border-radius: 5px;
            cursor: pointer;
        }

        .products-count {
            color: #8a7c75;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 25px;
        }

        .product-card-link {
            display: block;
            color: inherit;
            text-decoration: none;
        }

        .product-card {
            background: white;
            overflow: hidden;
            border-radius: 7px;
            transition: transform 0.3s ease;
            height: 100%;
        }

        .product-card:hover {
            transform: translateY(-5px);
        }

        .product-image {
            height: 270px;
            background: #f2ebe5;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .image-placeholder {
            color: #8b5e62;
            font-family: Georgia, serif;
            font-size: 18px;
        }

        .product-info {
            padding: 18px;
        }

        .product-category {
            color: #b77b80;
            font-size: 10px;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .product-info h3 {
            color: #382b29;
            font-family: Georgia, serif;
            font-size: 19px;
            font-weight: 400;
            margin: 9px 0;
        }

        .product-description {
            color: #8a7c75;
            font-size: 12px;
            line-height: 1.6;
            min-height: 40px;
        }

        .product-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }

        .product-price {
            color: #8b5e62;
            font-weight: 600;
            font-size: 16px;
        }

        .stock-label {
            color: #6b8068;
            font-size: 11px;
        }

        .empty-products {
            grid-column: 1 / -1;
            text-align: center;
            padding: 70px 20px;
            color: #6f625e;
            background: white;
        }

        @media (max-width: 1000px) {
            .products-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .shop-page {
                padding: 35px 4%;
            }

            .shop-heading h1 {
                font-size: 34px;
            }

            .product-image {
                height: 190px;
            }
        }

        @media (max-width: 420px) {
            .products-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="header">
    <div class="logo">
        VELO<span>URA</span>
    </div>

    <nav class="navbar">
        <a href="index.php">Home</a>
        <a href="products.php">Shop</a>
        <a href="index.php#about">About</a>
        <a href="index.php#contact">Contact</a>
    </nav>

    <?php require __DIR__ . '/partials/header-actions.php'; ?>
</header>

<main class="shop-page">

    <section class="shop-heading">
        <p class="eyebrow">THE VELOURA COLLECTION</p>
        <h1>Discover Your Beauty</h1>
        <p>Explore our carefully selected collection of beauty essentials.</p>
    </section>

    <section class="shop-toolbar">

        <div class="shop-filters">
            <a href="products.php"
               class="<?= !$categoryId ? 'active' : '' ?>">
                All Products
            </a>

            <?php foreach ($categories as $category): ?>
                <a href="products.php?category=<?= (int)$category['id'] ?>"
                   class="<?= $categoryId == $category['id'] ? 'active' : '' ?>">
                    <?= htmlspecialchars($category['name']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="shop-tools">

            <?php if ($categoryId): ?>
                <input type="hidden" name="category"
                       value="<?= (int)$categoryId ?>">
            <?php endif; ?>

            <input
                type="search"
                name="search"
                placeholder="Search products..."
                value="<?= htmlspecialchars($search) ?>"
            >

            <select name="sort">
                <option value="newest" <?= $sort === "newest" ? "selected" : "" ?>>
                    Newest
                </option>
                <option value="price_low" <?= $sort === "price_low" ? "selected" : "" ?>>
                    Price: Low to High
                </option>
                <option value="price_high" <?= $sort === "price_high" ? "selected" : "" ?>>
                    Price: High to Low
                </option>
                <option value="name" <?= $sort === "name" ? "selected" : "" ?>>
                    Name
                </option>
            </select>

            <button type="submit">Apply</button>
        </form>

    </section>

    <p class="products-count">
        <?= count($products) ?> products found
    </p>

    <section class="products-grid">

        <?php if (count($products) > 0): ?>

            <?php foreach ($products as $product): ?>

                <a
                    class="product-card-link"
                    href="product-details.php?id=<?= (int)$product['id'] ?>"
                >

                    <article class="product-card">

                        <div class="product-image">

                            <?php
                            $imagePath = trim($product['image'] ?? '');
                            $imageUrl = '';

                            if ($imagePath !== '') {
                                if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                                    $imageUrl = $imagePath;
                                } elseif (strpos($imagePath, 'assets/') === 0) {
                                    $imageUrl = $imagePath;
                                } else {
                                    $imageUrl = 'assets/images/' . basename($imagePath);
                                }
                            }
                            ?>

                            <?php if ($imageUrl !== ''): ?>
                                <img
                                    src="<?= htmlspecialchars($imageUrl) ?>"
                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"
                                >
                            <?php endif; ?>

                            <div class="image-placeholder"
                                 style="<?= $imageUrl !== '' ? 'display:none;' : '' ?>">
                                VELOURA
                            </div>

                        </div>

                        <div class="product-info">

                            <span class="product-category">
                                <?= htmlspecialchars($product['category_name'] ?? 'Beauty') ?>
                            </span>

                            <h3><?= htmlspecialchars($product['name']) ?></h3>

                            <p class="product-description">
                                <?= htmlspecialchars($product['description'] ?? '') ?>
                            </p>

                            <div class="product-bottom">
                                <span class="product-price">
                                    EGP <?= number_format((float)$product['price'], 2) ?>
                                </span>

                                <span class="stock-label">
                                    <?= (int)$product['stock'] > 0 ? 'In Stock' : 'Out of Stock' ?>
                                </span>
                            </div>

                        </div>

                    </article>

                </a>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty-products">
                <h2>No products found</h2>
                <p>Try another search or category.</p>
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>

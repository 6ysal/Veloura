
<?php

require_once __DIR__ . '/../../config/session.php';
startAppSession();

require_once __DIR__ . '/../../config/database.php';

if (isset($_SESSION["user_id"])) {
    header("Location: ../../public/index.php");
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nameInput = $_POST["name"] ?? "";
    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

    $name = is_string($nameInput) ? trim($nameInput) : "";
    $email = is_string($emailInput) ? trim($emailInput) : "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $message = "Your session expired. Please refresh the page and try again.";
    } elseif (
        $name === "" ||
        $email === "" ||
        !is_string($password) ||
        !is_string($confirmPassword) ||
        $password === "" ||
        $confirmPassword === ""
    ) {
        $message = "All fields are required.";
    } elseif (
        preg_match('/\A.{1,150}\z/us', $name) !== 1 ||
        strlen($email) > 254 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        $message = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $message = "Password must be at least 8 characters.";
    } elseif ($password !== $confirmPassword) {
        $message = "Passwords do not match.";
    } else {
        $checkEmail = $pdo->prepare(
            "SELECT id FROM users WHERE email = :email LIMIT 1"
        );
        $checkEmail->execute(["email" => $email]);

        if ($checkEmail->fetch()) {
            $message = "This email is already registered.";
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $pdo->beginTransaction();

                $insertUser = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, email_verified_at)
                    VALUES (:full_name, :email, :password_hash, UTC_TIMESTAMP())
                ");
                $insertUser->execute([
                    "full_name" => $name,
                    "email" => $email,
                    "password_hash" => $passwordHash
                ]);
                $userId = (int) $pdo->lastInsertId();
                $pdo->commit();

                session_regenerate_id(true);
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                $_SESSION["user_id"] = $userId;
                $_SESSION["user_name"] = $name;
                $_SESSION["user_email"] = $email;
                $_SESSION["user_role"] = "customer";

                header("Location: ../../public/index.php");
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e->getCode() === "23000") {
                    $message = "This email is already registered.";
                } else {
                    error_log("Registration failed: " . $e->getMessage());
                    $message = "We couldn't create your account right now. Please try again later.";
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | VELOURA</title>

    <link rel="stylesheet" href="../../public/assets/css/style.css?v=2">
    <link rel="stylesheet" href="../../public/assets/css/auth.css">
</head>

<body>

    <?php
    $headerPublicPrefix = "../../public/";
    $headerAuthPrefix = "./";
    require __DIR__ . '/../../public/partials/store-header.php';
    ?>

    <main class="auth-container">

        <section class="auth-visual">
            <div class="visual-content">
                <p class="eyebrow">THE ART OF BEAUTY</p>
                <h2>Discover Your Natural Glow.</h2>
                <p>
                    Beauty begins with confidence. Discover products
                    that make you feel naturally beautiful.
                </p>
            </div>
        </section>

        <section class="auth-content">

            <div class="auth-form-wrapper">

                <a href="../../public/index.php" class="auth-logo">
                    VELO<span>URA</span>
                </a>

                <div class="auth-heading">
                    <p class="eyebrow">JOIN VELOURA</p>
                    <h1>Create Account</h1>
                    <p>Create your account and start your beauty journey.</p>
                </div>

                <?php if ($message !== ""): ?>
                    <div class="auth-alert">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                    <form method="POST" action="" class="auth-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8'); ?>"
                        >

                        <div class="form-group">
                            <label for="name">Full Name</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                placeholder="Enter your full name"
                                autocomplete="name"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                placeholder="Enter your email"
                                autocomplete="email"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="password">Password</label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                placeholder="At least 8 characters"
                                autocomplete="new-password"
                                minlength="8"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                placeholder="Confirm your password"
                                autocomplete="new-password"
                                required
                            >
                        </div>

                        <button type="submit" class="auth-button">
                            CREATE ACCOUNT
                        </button>

                    </form>

                    <p class="auth-switch">
                        Already have an account?
                        <a href="login.php">Sign In</a>
                    </p>

                <p class="auth-footer">
                    © 2026 VELOURA. All rights reserved.
                </p>

            </div>

        </section>

    </main>

</body>
</html>

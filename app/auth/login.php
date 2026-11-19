
<?php

session_start();

require_once __DIR__ . '/../../config/database.php';

if (isset($_SESSION["user_id"])) {
    header("Location: ../../public/index.php");
    exit;
}

$message = "";
$needsVerification = false;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $emailInput = $_POST["email"] ?? "";
    $password = $_POST["password"] ?? "";
    $email = is_string($emailInput) ? trim($emailInput) : "";

    if ($email === "" || !is_string($password) || $password === "") {

        $message = "Please enter your email and password.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Invalid email or password.";

    } else {

        $stmt = $pdo->prepare(
            "SELECT id, full_name, email, password_hash, role, status, email_verified_at
             FROM users
             WHERE email = :email
             LIMIT 1"
        );

        $stmt->execute(["email" => $email]);

        $user = $stmt->fetch();

        if ($user && password_verify($password, $user["password_hash"])) {

            if ($user["status"] !== "active") {

                $message = "Your account is not active.";

            } elseif ($user["email_verified_at"] === null) {

                $message = "Please verify your email before signing in.";
                $needsVerification = true;

            } else {

                session_regenerate_id(true);

                $_SESSION["user_id"] = $user["id"];
                $_SESSION["user_name"] = $user["full_name"];
                $_SESSION["user_email"] = $user["email"];
                $_SESSION["user_role"] = $user["role"];

                header("Location: ../../public/index.php");
                exit;
            }

        } else {

            $message = "Invalid email or password.";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | VELOURA</title>

    <link rel="stylesheet" href="../../public/assets/css/auth.css">
</head>

<body>

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
                    <p class="eyebrow">WELCOME BACK</p>
                    <h1>Sign In</h1>
                    <p>Enter your details to access your account.</p>
                </div>

                <?php if ($message !== ""): ?>
                    <div class="auth-alert">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($needsVerification): ?>
                    <p class="auth-switch">
                        <a href="resend-verification.php">Resend verification email</a>
                    </p>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">

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
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button type="submit" class="auth-button">
                        SIGN IN
                    </button>

                </form>

                <p class="auth-switch">
                    Don't have an account?
                    <a href="register.php">Create Account</a>
                </p>

                <p class="auth-footer">
                    © 2026 VELOURA. All rights reserved.
                </p>

            </div>

        </section>

    </main>

</body>
</html>

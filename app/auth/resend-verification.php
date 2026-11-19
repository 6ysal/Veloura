<?php

session_start();

require_once __DIR__ . '/../../config/database.php';

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $emailInput = $_POST["email"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";
    $email = is_string($emailInput) ? trim($emailInput) : "";

    if (!is_string($csrfToken) || !hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $error = "Your session expired. Please refresh the page and try again.";
    } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } else {
        try {
            $userStmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE email = :email
                  AND email_verified_at IS NULL
                  AND status = 'active'
                LIMIT 1
            ");
            $userStmt->execute(["email" => $email]);
            $user = $userStmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $tokenStmt = $pdo->prepare("
                    INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                    VALUES (:user_id, :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))
                    ON DUPLICATE KEY UPDATE
                        token_hash = VALUES(token_hash),
                        expires_at = VALUES(expires_at),
                        created_at = CURRENT_TIMESTAMP
                ");
                $tokenStmt->execute([
                    "user_id" => $user["id"],
                    "token_hash" => hash("sha256", $token)
                ]);

                require_once __DIR__ . '/../../config/mailer.php';
                $verifyUrl = getAppBaseUrl()
                    . "/app/auth/verify.php?token="
                    . rawurlencode($token);
                $safeUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

                sendAppEmail(
                    $email,
                    "Verify your VELOURA account",
                    "<p><a href=\"{$safeUrl}\">Verify your email address</a></p><p>This link expires in 24 hours.</p>",
                    "Verify your VELOURA email address: {$verifyUrl}\nThis link expires in 24 hours."
                );
            }

            $message = "If an unverified account exists for that address, a verification email has been sent.";
        } catch (PDOException $e) {
            error_log("Verification resend database error: " . $e->getMessage());
            $error = "We couldn't process the request right now. Please try again later.";
        } catch (RuntimeException $e) {
            error_log("Verification resend failed: " . $e->getMessage());
            $error = "We couldn't send the email. Check the SMTP configuration and try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resend Verification | VELOURA</title>
    <link rel="stylesheet" href="../../public/assets/css/auth.css">
</head>
<body>
    <main class="auth-content">
        <section class="auth-form-wrapper">
            <a href="../../public/index.php" class="auth-logo">VELO<span>URA</span></a>
            <div class="auth-heading">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h1>Resend Verification</h1>
                <p>Enter your email and we will send a fresh verification link if your account needs one.</p>
            </div>

            <?php if ($message !== ""): ?>
                <div class="auth-alert"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="auth-alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="auth-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, 'UTF-8') ?>"
                >
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" autocomplete="email" required>
                </div>
                <button type="submit" class="auth-button">SEND VERIFICATION LINK</button>
            </form>

            <p class="auth-switch"><a href="login.php">Back to Sign In</a></p>
        </section>
    </main>
</body>
</html>

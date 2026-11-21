<?php

require_once __DIR__ . '/../../config/database.php';

$verified = false;
$message = "This verification link is invalid or has expired.";
$token = $_GET["token"] ?? "";

if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1) {
    try {
        $pdo->beginTransaction();

        $tokenStmt = $pdo->prepare("
            SELECT user_id
            FROM email_verification_tokens
            WHERE token_hash = :token_hash AND expires_at > UTC_TIMESTAMP()
            LIMIT 1
            FOR UPDATE
        ");
        $tokenStmt->execute(["token_hash" => hash("sha256", $token)]);
        $verification = $tokenStmt->fetch();

        if ($verification) {
            $updateStmt = $pdo->prepare("
                UPDATE users
                SET email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP())
                WHERE id = :user_id
            ");
            $updateStmt->execute(["user_id" => $verification["user_id"]]);

            $deleteStmt = $pdo->prepare("
                DELETE FROM email_verification_tokens
                WHERE user_id = :user_id
            ");
            $deleteStmt->execute(["user_id" => $verification["user_id"]]);

            $pdo->commit();
            $verified = true;
            $message = "Your email is verified. You can now sign in.";
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Email verification failed: " . $e->getMessage());
        http_response_code(500);
        $message = "We couldn't verify your email right now. Please try again later.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification | VELOURA</title>
    <link rel="stylesheet" href="../../public/assets/css/style.css?v=2">
    <link rel="stylesheet" href="../../public/assets/css/auth.css">
</head>
<body>
    <?php
    $headerPublicPrefix = "../../public/";
    $headerAuthPrefix = "./";
    require __DIR__ . '/../../public/partials/store-header.php';
    ?>
    <main class="auth-content">
        <section class="auth-form-wrapper">
            <a href="../../public/index.php" class="auth-logo">VELO<span>URA</span></a>
            <div class="auth-heading">
                <p class="eyebrow">ACCOUNT SECURITY</p>
                <h1><?= $verified ? "Email Verified" : "Verification" ?></h1>
                <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <p class="auth-switch">
                <a href="login.php"><?= $verified ? "Sign In" : "Return to Sign In" ?></a>
            </p>
        </section>
    </main>
</body>
</html>

<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$email = trim($argv[1] ?? "");
if (
    $email === "" ||
    strlen($email) > 254 ||
    filter_var($email, FILTER_VALIDATE_EMAIL) === false
) {
    fwrite(STDERR, "Usage: php tools/promote_admin.php <verified-email>\n");
    exit(2);
}

try {
    $pdo->beginTransaction();
    $userStmt = $pdo->prepare("
        SELECT id, role, status, email_verified_at
        FROM users
        WHERE email = :email
        LIMIT 1
        FOR UPDATE
    ");
    $userStmt->execute(["email" => $email]);
    $user = $userStmt->fetch();

    if (!$user || $user["status"] !== "active" || $user["email_verified_at"] === null) {
        $pdo->rollBack();
        fwrite(STDERR, "No active, email-verified account was found for that address.\n");
        exit(1);
    }

    $updateStmt = $pdo->prepare("UPDATE users SET role = 'admin' WHERE id = :user_id");
    $updateStmt->execute(["user_id" => $user["id"]]);
    $pdo->commit();
    fwrite(STDOUT, "Administrator access enabled. Sign out and sign back in to refresh the session.\n");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Admin promotion failed: " . $e->getMessage());
    fwrite(STDERR, "Could not promote the account. Check the database connection and try again.\n");
    exit(1);
}

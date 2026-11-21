<?php

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");

    $httpsValue = strtolower((string) ($_SERVER["HTTPS"] ?? ""));
    $isHttps = in_array($httpsValue, ["on", "1", "https"], true);

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $isHttps,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
}

function requireActiveAccount(PDO $pdo, string $loginPath): int
{
    $userId = filter_var($_SESSION["user_id"] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        header("Location: " . $loginPath);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT status, email_verified_at
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $stmt->execute(["user_id" => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user["status"] !== "active" || $user["email_verified_at"] === null) {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), "", [
                "expires" => time() - 42000,
                "path" => $params["path"],
                "domain" => $params["domain"],
                "secure" => $params["secure"],
                "httponly" => $params["httponly"],
                "samesite" => $params["samesite"] ?? "Lax"
            ]);
        }
        session_destroy();
        header("Location: " . $loginPath);
        exit;
    }

    return (int) $userId;
}

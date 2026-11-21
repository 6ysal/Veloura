
<?php

require_once __DIR__ . '/../../config/session.php';
startAppSession();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    http_response_code(405);
    exit("Method not allowed.");
}

$csrfToken = $_POST["csrf_token"] ?? "";
if (
    !is_string($csrfToken) ||
    !hash_equals($_SESSION["csrf_token"], $csrfToken)
) {
    http_response_code(400);
    exit("Invalid request. Please refresh the page and try again.");
}

// Clear all session data
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page
header("Location: login.php");
exit;

?>

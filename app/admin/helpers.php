<?php

function adminRequireAccess(PDO $pdo): array
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../app/auth/login.php");
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, role, status
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $stmt->execute(["user_id" => (int) $_SESSION["user_id"]]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || $admin["role"] !== "admin" || $admin["status"] !== "active") {
        http_response_code(403);
        exit("You do not have permission to access this page.");
    }

    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    header("Cache-Control: no-store, private");
    return $admin;
}

function adminEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function adminCsrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        adminEscape($_SESSION["csrf_token"]) . '">';
}

function adminSetFlash(string $type, string $message): void
{
    $_SESSION["admin_flash"] = [
        "type" => $type === "error" ? "error" : "success",
        "message" => $message
    ];
}

function adminRedirect(string $section, array $params = []): void
{
    $allowedSections = [
        "overview",
        "products",
        "categories",
        "orders",
        "customers",
        "coupons",
        "newsletter",
        "credits",
        "settings"
    ];
    if (!in_array($section, $allowedSections, true)) {
        $section = "overview";
    }

    $query = array_merge(["section" => $section], $params);
    header("Location: admin.php?" . http_build_query($query));
    exit;
}

function adminAudit(
    PDO $pdo,
    int $adminId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $details = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO admin_audit_logs
            (admin_user_id, action, entity_type, entity_id, details, ip_address)
        VALUES
            (:admin_user_id, :action, :entity_type, :entity_id, :details, :ip_address)
    ");
    $encodedDetails = $details === []
        ? null
        : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $ipAddress = filter_var($_SERVER["REMOTE_ADDR"] ?? null, FILTER_VALIDATE_IP)
        ? $_SERVER["REMOTE_ADDR"]
        : null;
    $stmt->bindValue(":admin_user_id", $adminId, PDO::PARAM_INT);
    $stmt->bindValue(":action", $action);
    $stmt->bindValue(":entity_type", $entityType);
    $stmt->bindValue(
        ":entity_id",
        $entityId,
        $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT
    );
    $stmt->bindValue(
        ":details",
        $encodedDetails,
        $encodedDetails === null ? PDO::PARAM_NULL : PDO::PARAM_STR
    );
    $stmt->bindValue(
        ":ip_address",
        $ipAddress,
        $ipAddress === null ? PDO::PARAM_NULL : PDO::PARAM_STR
    );
    $stmt->execute();
}

function adminFetchPage(
    PDO $pdo,
    string $sql,
    string $countSql,
    array $params,
    int $requestedPage,
    int $perPage = 20
): array {
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $requestedPage), $pages);

    $stmt = $pdo->prepare($sql . " LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(":limit", $perPage, PDO::PARAM_INT);
    $stmt->bindValue(":offset", ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return [
        "rows" => $stmt->fetchAll(PDO::FETCH_ASSOC),
        "total" => $total,
        "page" => $page,
        "pages" => $pages
    ];
}

function adminParseDateTime(?string $value, string $label): ?string
{
    if ($value === null || trim($value) === "") {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i',
        $value,
        new DateTimeZone("UTC")
    );
    $errors = DateTimeImmutable::getLastErrors();
    if (
        !$date ||
        ($errors !== false && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
    ) {
        throw new DomainException("Enter a valid " . $label . ".");
    }

    return $date->format("Y-m-d H:i:s");
}

function adminStoreProductImage(array $upload): ?array
{
    $error = $upload["error"] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new DomainException("The product image could not be uploaded.");
    }

    $tmpName = $upload["tmp_name"] ?? "";
    $size = (int) ($upload["size"] ?? 0);
    if (
        !is_string($tmpName) ||
        !is_uploaded_file($tmpName) ||
        $size < 1 ||
        $size > 3 * 1024 * 1024 ||
        filesize($tmpName) !== $size
    ) {
        throw new DomainException("Choose a valid image smaller than 3 MB.");
    }

    $imageInfo = @getimagesize($tmpName);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extensions = [
        "image/jpeg" => "jpg",
        "image/png" => "png",
        "image/webp" => "webp"
    ];
    if (
        !$imageInfo ||
        !isset($extensions[$mime]) ||
        $imageInfo["mime"] !== $mime ||
        $imageInfo[0] < 1 ||
        $imageInfo[1] < 1 ||
        $imageInfo[0] > 6000 ||
        $imageInfo[1] > 6000 ||
        $imageInfo[0] * $imageInfo[1] > 25000000
    ) {
        throw new DomainException("Only valid JPEG, PNG, or WebP images are allowed.");
    }

    $directory = realpath(__DIR__ . '/../../public/assets/images');
    if ($directory === false || !is_writable($directory)) {
        throw new RuntimeException("The product image directory is not writable.");
    }

    $filename = "admin-" . bin2hex(random_bytes(16)) . "." . $extensions[$mime];
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException("The product image could not be saved.");
    }

    return [
        "filename" => $filename,
        "path" => $destination
    ];
}

function adminDeleteGeneratedProductImage(?string $filename, PDO $pdo): void
{
    if (
        $filename === null ||
        !preg_match('/\Aadmin-[a-f0-9]{32}\.(?:jpg|png|webp)\z/i', basename($filename))
    ) {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE image = :image");
    $stmt->execute(["image" => $filename]);
    if ((int) $stmt->fetchColumn() !== 0) {
        return;
    }

    $directory = realpath(__DIR__ . '/../../public/assets/images');
    if ($directory === false) {
        return;
    }

    $path = $directory . DIRECTORY_SEPARATOR . basename($filename);
    if (is_file($path) && !unlink($path)) {
        error_log("Could not remove unused product image: " . basename($filename));
    }
}

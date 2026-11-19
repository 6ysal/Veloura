<?php

function findEligibleCoupon(
    PDO $pdo,
    string $code,
    int $userId,
    string $userEmail,
    float $subtotal,
    bool $lockForUpdate = false
): array {
    $sql = "
        SELECT
            id,
            code,
            discount_type,
            discount_value,
            min_order_amount,
            max_uses,
            used_count,
            assigned_email,
            (starts_at IS NULL OR starts_at <= UTC_TIMESTAMP()) AS has_started,
            (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) AS has_not_expired
        FROM coupons
        WHERE code = :code AND status = 'active'
        LIMIT 1
    ";

    if ($lockForUpdate) {
        $sql .= " FOR UPDATE";
    }

    $couponStmt = $pdo->prepare($sql);
    $couponStmt->execute(["code" => $code]);
    $coupon = $couponStmt->fetch();

    if (!$coupon) {
        throw new DomainException("This coupon code is invalid or inactive.");
    }

    if (!(bool) $coupon["has_started"] || !(bool) $coupon["has_not_expired"]) {
        throw new DomainException("This coupon is not currently valid.");
    }

    if (
        $coupon["assigned_email"] !== null &&
        strcasecmp((string) $coupon["assigned_email"], $userEmail) !== 0
    ) {
        throw new DomainException("This coupon is not assigned to your account email.");
    }

    if (
        $coupon["max_uses"] !== null &&
        (int) $coupon["used_count"] >= (int) $coupon["max_uses"]
    ) {
        throw new DomainException("This coupon has already been used.");
    }

    if ($subtotal < (float) $coupon["min_order_amount"]) {
        throw new DomainException(
            "Your order does not meet this coupon's minimum amount."
        );
    }

    $redemptionStmt = $pdo->prepare("
        SELECT id
        FROM coupon_redemptions
        WHERE coupon_id = :coupon_id AND user_id = :user_id
        LIMIT 1
    ");
    $redemptionStmt->execute([
        "coupon_id" => $coupon["id"],
        "user_id" => $userId
    ]);

    if ($redemptionStmt->fetch()) {
        throw new DomainException("You have already used this coupon.");
    }

    $discountValue = (float) $coupon["discount_value"];
    if ($coupon["discount_type"] === "percentage") {
        if ($discountValue <= 0 || $discountValue > 100) {
            throw new DomainException("This coupon has an invalid discount.");
        }

        $discount = round($subtotal * $discountValue / 100, 2);
    } elseif ($coupon["discount_type"] === "fixed") {
        if ($discountValue <= 0) {
            throw new DomainException("This coupon has an invalid discount.");
        }

        $discount = min($subtotal, $discountValue);
    } else {
        throw new DomainException("This coupon has an invalid discount type.");
    }

    return [
        "id" => (int) $coupon["id"],
        "code" => (string) $coupon["code"],
        "discount" => $discount
    ];
}

function getAvailableCoupons(PDO $pdo, ?int $userId = null): array
{
    $userEmail = null;
    if ($userId !== null && $userId > 0) {
        $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute(["user_id" => $userId]);
        $email = $userStmt->fetchColumn();
        if (is_string($email) && $email !== "") {
            $userEmail = $email;
        } else {
            $userId = null;
        }
    } else {
        $userId = null;
    }

    $sql = "
        SELECT c.id, c.code, c.discount_type, c.discount_value,
               c.min_order_amount, c.max_uses, c.used_count,
               c.starts_at, c.expires_at, c.created_at
        FROM coupons c
        WHERE c.status = 'active'
          AND (c.starts_at IS NULL OR c.starts_at <= UTC_TIMESTAMP())
          AND (c.expires_at IS NULL OR c.expires_at > UTC_TIMESTAMP())
          AND (c.max_uses IS NULL OR c.used_count < c.max_uses)
          AND c.min_order_amount >= 0
          AND (
              (c.discount_type = 'percentage' AND c.discount_value > 0 AND c.discount_value <= 100)
              OR (c.discount_type = 'fixed' AND c.discount_value > 0)
          )
    ";
    $params = [];

    if ($userEmail === null) {
        $sql .= " AND c.assigned_email IS NULL";
    } else {
        $sql .= " AND (
            c.assigned_email IS NULL
            OR LOWER(c.assigned_email) = LOWER(:user_email)
        )";
        $params["user_email"] = $userEmail;
    }

    if ($userId !== null) {
        $sql .= " AND NOT EXISTS (
            SELECT 1
            FROM coupon_redemptions cr
            WHERE cr.coupon_id = c.id AND cr.user_id = :user_id
        )";
        $params["user_id"] = $userId;
    }

    $sql .= " ORDER BY (c.expires_at IS NULL), c.expires_at ASC, c.created_at DESC, c.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function couponOfferValue(array $coupon): string
{
    return rtrim(
        rtrim(number_format((float) $coupon["discount_value"], 2, ".", ","), "0"),
        "."
    );
}

function couponOfferDiscountLabel(array $coupon): string
{
    $value = couponOfferValue($coupon);
    return $coupon["discount_type"] === "percentage"
        ? $value . "% OFF"
        : $value . " EGP OFF";
}

function couponOfferDescription(array $coupon): string
{
    $value = couponOfferValue($coupon);
    $description = $coupon["discount_type"] === "percentage"
        ? "Enjoy {$value}% off your next beauty order."
        : "Save up to {$value} EGP on your next beauty order.";
    $minimum = (float) $coupon["min_order_amount"];

    if ($minimum > 0) {
        $description .= " Minimum order: " . number_format($minimum, 2) . " EGP.";
    }

    return $description;
}

function couponOfferExpiry(?string $expiresAt): string
{
    if ($expiresAt === null || $expiresAt === "") {
        return "";
    }

    $timestamp = strtotime($expiresAt . " UTC");
    return $timestamp === false ? "" : gmdate("M j, Y", $timestamp);
}

function newCouponNotificationCount(array $coupons, ?int $userId): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 0;
    }

    $audience = $userId !== null && $userId > 0
        ? "user:" . $userId
        : "guest";
    $seenByAudience = $_SESSION["coupon_offer_seen_ids"] ?? [];
    if (!is_array($seenByAudience)) {
        $seenByAudience = [];
    }

    $currentIds = array_values(array_unique(array_map(
        "intval",
        array_column($coupons, "id")
    )));
    if (!array_key_exists($audience, $seenByAudience)) {
        $seenByAudience[$audience] = $currentIds;
        $_SESSION["coupon_offer_seen_ids"] = $seenByAudience;
        return 0;
    }

    $seenIds = is_array($seenByAudience[$audience])
        ? array_map("intval", $seenByAudience[$audience])
        : [];
    $newIds = array_diff($currentIds, $seenIds);
    $seenByAudience[$audience] = array_slice(
        array_values(array_unique(array_merge($seenIds, $currentIds))),
        -500
    );
    $_SESSION["coupon_offer_seen_ids"] = $seenByAudience;

    return count($newIds);
}

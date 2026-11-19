<?php

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

function isAppMailConfigured(): bool
{
    if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
        return false;
    }

    foreach ([
        "SMTP_HOST",
        "SMTP_PORT",
        "SMTP_USERNAME",
        "SMTP_PASSWORD",
        "SMTP_ENCRYPTION",
        "MAIL_FROM_ADDRESS",
        "MAIL_FROM_NAME"
    ] as $key) {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === "") {
            return false;
        }
    }

    return true;
}

function getAppBaseUrl(): string
{
    $baseUrl = rtrim((string) getenv("APP_BASE_URL"), "/");

    if (
        $baseUrl === "" ||
        filter_var($baseUrl, FILTER_VALIDATE_URL) === false ||
        !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ["http", "https"], true)
    ) {
        throw new RuntimeException("APP_BASE_URL must be a valid HTTP or HTTPS URL.");
    }

    return $baseUrl;
}

function sendAppEmail(
    string $recipient,
    string $subject,
    string $htmlBody,
    string $textBody
): void {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoloadPath)) {
        throw new RuntimeException("Composer dependencies are missing. Run composer install.");
    }

    require_once $autoloadPath;

    $settings = [
        "SMTP_HOST" => getenv("SMTP_HOST"),
        "SMTP_PORT" => getenv("SMTP_PORT"),
        "SMTP_USERNAME" => getenv("SMTP_USERNAME"),
        "SMTP_PASSWORD" => getenv("SMTP_PASSWORD"),
        "SMTP_ENCRYPTION" => getenv("SMTP_ENCRYPTION"),
        "MAIL_FROM_ADDRESS" => getenv("MAIL_FROM_ADDRESS"),
        "MAIL_FROM_NAME" => getenv("MAIL_FROM_NAME")
    ];

    foreach ($settings as $key => $value) {
        if ($value === false || trim((string) $value) === "") {
            throw new RuntimeException("Missing mail setting: " . $key);
        }
    }

    if (
        filter_var($settings["MAIL_FROM_ADDRESS"], FILTER_VALIDATE_EMAIL) === false ||
        filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new RuntimeException("A valid sender and recipient email address are required.");
    }

    $encryption = strtolower((string) $settings["SMTP_ENCRYPTION"]);
    if (!in_array($encryption, ["tls", "ssl"], true)) {
        throw new RuntimeException("SMTP_ENCRYPTION must be either tls or ssl.");
    }

    $port = filter_var($settings["SMTP_PORT"], FILTER_VALIDATE_INT);
    if ($port === false || $port < 1 || $port > 65535) {
        throw new RuntimeException("SMTP_PORT must be a valid port number.");
    }

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) $settings["SMTP_HOST"];
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) $settings["SMTP_USERNAME"];
    $mailer->Password = (string) $settings["SMTP_PASSWORD"];
    $mailer->Port = $port;
    $mailer->SMTPSecure = $encryption === "ssl"
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout = 20;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->setFrom(
        (string) $settings["MAIL_FROM_ADDRESS"],
        (string) $settings["MAIL_FROM_NAME"]
    );
    $mailer->addAddress($recipient);
    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = $htmlBody;
    $mailer->AltBody = $textBody;

    try {
        $mailer->send();
    } catch (MailerException $e) {
        throw new RuntimeException("SMTP delivery failed.", 0, $e);
    }
}

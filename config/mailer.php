<?php

define('SMTP_HOST',          'smtp.gmail.com');
define('SMTP_PORT',          587);
define('SMTP_USER',          'banerasta4@gmail.com');
define('SMTP_PASS',          'lcyu deqa avhl nkdh');
define('SMTP_FROM_NAME',     'EventHub Pro — ENSA Marrakech');
define('SMTP_ENCRYPTION',    'tls');
define('SMTP_TEST_RECEIVER', 'walid.bouarifi@gmail.com');

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

function createMailer(): PHPMailer
{
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->Port       = SMTP_PORT;

    if (SMTP_USER !== '') {
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
    } else {
        $mail->SMTPAuth   = false;
    }

    if (SMTP_ENCRYPTION !== '') {
        $mail->SMTPSecure = SMTP_ENCRYPTION;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }

    $mail->CharSet    = 'UTF-8';

    $fromEmail = (SMTP_USER !== '') ? SMTP_USER : 'no-reply@ensa.ma';
    $mail->setFrom($fromEmail, SMTP_FROM_NAME);
    $mail->isHTML(true);

    return $mail;
}

function logMailError(PDO $pdo, string $type, string $to, string $error): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO mail_logs (type, recipient, error_message, created_at)
             VALUES (:type, :to, :error, NOW())'
        );
        $stmt->execute([
            ':type'  => $type,
            ':to'    => $to,
            ':error' => $error,
        ]);
    } catch (PDOException $e) {
        error_log('[EventHub] logMailError DB failed: ' . $e->getMessage());
    }
}

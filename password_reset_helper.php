<?php

require_once __DIR__ . '/vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
} catch (Dotenv\Exception\InvalidFileException $e) {
    error_log('The application .env file contains invalid syntax.');
}

function send_password_reset_code(string $recipient, string $username, string $code): void
{
    $env = static function (string $name): string|false {
        return $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    };
    $requiredSettings = [
        'SMTP_HOST' => $env('SMTP_HOST'),
        'SMTP_PORT' => $env('SMTP_PORT'),
        'SMTP_USERNAME' => $env('SMTP_USERNAME'),
        'SMTP_PASSWORD' => $env('SMTP_PASSWORD'),
        'MAIL_FROM_ADDRESS' => $env('MAIL_FROM_ADDRESS'),
    ];

    foreach ($requiredSettings as $name => $value) {
        if ($value === false || trim($value) === '') {
            throw new RuntimeException('SMTP email settings are incomplete.');
        }
    }

    $encryption = strtolower(trim((string)($env('SMTP_ENCRYPTION') ?: 'tls')));
    if (!in_array($encryption, ['tls', 'ssl'], true)) {
        throw new RuntimeException('SMTP_ENCRYPTION must be tls or ssl.');
    }

    $port = filter_var($requiredSettings['SMTP_PORT'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($port === false) {
        throw new RuntimeException('SMTP_PORT is invalid.');
    }

    $fromAddress = trim((string)$requiredSettings['MAIL_FROM_ADDRESS']);
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('MAIL_FROM_ADDRESS is invalid.');
    }

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = trim((string)$requiredSettings['SMTP_HOST']);
    $mailer->SMTPAuth = true;
    $mailer->Username = (string)$requiredSettings['SMTP_USERNAME'];
    $mailer->Password = (string)$requiredSettings['SMTP_PASSWORD'];
    $mailer->Port = $port;
    $mailer->SMTPSecure = $encryption === 'ssl'
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Timeout = 10;
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom(
        $fromAddress,
        trim((string)($env('MAIL_FROM_NAME') ?: 'Bean There Cafe'))
    );
    $mailer->addAddress($recipient);
    $mailer->isHTML(true);
    $mailer->Subject = 'Your Bean There Cafe password reset code';
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $mailer->Body = '<p>Hello ' . $safeUsername . ',</p>'
        . '<p>Your password reset code is:</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:8px;">' . $code . '</p>'
        . '<p>This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.</p>';
    $mailer->AltBody = "Hello {$username},\n\nYour password reset code is: {$code}\n"
        . "This code expires in 10 minutes. If you did not request a password reset, you can ignore this email.";
    $mailer->send();
}

<?php
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

function createMailer(): PHPMailer
{
    $localPath = __DIR__ . '/../config/mail.local.php';
    $local = is_file($localPath) ? require $localPath : [];
    $setting = function (string $name, string $fallback = '') use ($local): string {
        $value = getenv('SMTP_' . strtoupper($name));
        return $value !== false ? $value : (string) ($local[$name] ?? $fallback);
    };

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $setting('host', 'smtp.gmail.com');
    $mail->Username = $setting('username');
    $mail->Password = $setting('password');
    $mail->SMTPAuth = $mail->Username !== '';
    $mail->SMTPSecure = $setting('encryption', PHPMailer::ENCRYPTION_STARTTLS);
    $mail->Port = (int) $setting('port', '587');
    $mail->Timeout = 20;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($setting('from_email', $mail->Username), $setting('from_name', 'ProVendor Security'));
    return $mail;
}

function sendSignupCode(string $email, string $code): void
{
    $mail = createMailer();
    $mail->addAddress($email);
    $mail->Subject = 'Verify your ProVendor email';
    $mail->Body = "Your ProVendor verification code is: $code\n\nThis code expires in 10 minutes. Enter it on the sign-up page to create your account.\n\nIf you did not request this code, you can ignore this email.";
    $mail->send();
}

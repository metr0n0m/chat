<?php
declare(strict_types=1);

namespace Chat\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private static function make(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM, APP_NAME);
        return $mail;
    }

    public static function sendVerification(string $email, string $username, string $rawToken): void
    {
        $link = rtrim(APP_URL, '/') . '/auth/verify?token=' . urlencode($rawToken);

        $mail = self::make();
        $mail->addAddress($email, $username);
        $mail->Subject = 'Подтверждение email — ' . APP_NAME;
        $mail->Body    =
            "Здравствуйте, {$username}!\n\n" .
            "Для завершения регистрации перейдите по ссылке:\n{$link}\n\n" .
            "Ссылка действительна 24 часа.\n\n" .
            "Если вы не регистрировались — просто проигнорируйте это письмо.";
        $mail->send();
    }
}

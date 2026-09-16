<?php
/**
 * Email Configuration
 *
 * EXAMPLE FILE — copy to email.php (git-ignored) if you need a
 * committed reference. SMTP settings come from environment variables — see .env.example for
 * local dev, or set them in HostForge's Environment Variables tab for
 * production. No credentials are hardcoded or committed to git.
 *
 * For Gmail: Use an App Password (not the regular account password).
 * Go to Google Account > Security > App passwords.
 */
require_once __DIR__ . '/env.php';
load_env_file();

// Load PHPMailer
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// SMTP Settings — username/password are required: fail loudly instead of
// silently sending nothing (password resets, 2FA codes depend on this).
define('SMTP_HOST', env_optional('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int) env_optional('SMTP_PORT', 587));
define('SMTP_USERNAME', env_required('SMTP_USERNAME'));
define('SMTP_PASSWORD', env_required('SMTP_PASSWORD'));
define('SMTP_FROM_EMAIL', env_optional('SMTP_FROM_EMAIL', SMTP_USERNAME));
define('SMTP_FROM_NAME', env_optional('SMTP_FROM_NAME', 'LRDMS System'));

// Base URL for generating links
define('BASE_URL', env_optional('BASE_URL', 'http://localhost/lrdms-php'));

/**
 * Send an email using PHPMailer with SMTP
 *
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @return bool True if sent successfully
 */
function send_email($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_OFF;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

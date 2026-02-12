<?php
// Try to load email config, but don't fail if it doesn't exist
if (file_exists('config/email.php')) {
    require_once 'config/email.php';
} else {
    // Define default email constants if config doesn't exist
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_USERNAME', '');
    define('SMTP_PASSWORD', '');
    define('SMTP_FROM_EMAIL', '');
    define('SMTP_FROM_NAME', 'System');
}

// Try to load PHPMailer files, but don't fail if they don't exist
$phpmailer_loaded = false;
if (file_exists(__DIR__ . '/../phpmailer/src/Exception.php') && 
    file_exists(__DIR__ . '/../phpmailer/src/PHPMailer.php') && 
    file_exists(__DIR__ . '/../phpmailer/src/SMTP.php')) {
    
    require_once __DIR__ . '/../phpmailer/src/Exception.php';
    require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../phpmailer/src/SMTP.php';
    
    $phpmailer_loaded = true;
}

/**
 * Advanced email helper using PHPMailer with Gmail SMTP
 * This provides reliable email delivery compared to PHP's mail() function
 */

/**
 * Send email notification to user after successful registration
 * 
 * @param string $to_email Recipient email address
 * @param string $username Username for the account
 * @param string $password Password for the account
 * @param string $role Role of the user (President/Adviser)
 * @param string $organization_name Name of the organization or council
 * @param string $type Type of registration ('organization' or 'council')
 * @return bool True if email sent successfully, false otherwise
 */
function sendRegistrationEmailAdvanced($to_email, $username, $password, $role, $organization_name, $type = 'organization') {
    try {
        // Get email template based on type
        if ($type === 'council') {
            $email_data = getCouncilRegistrationEmailTemplate($username, $password, $role, $organization_name);
        } elseif ($type === 'mis') {
            $email_data = getMISRegistrationEmailTemplate($username, $password, $role, $organization_name);
        } else {
            $email_data = getRegistrationEmailTemplate($username, $password, $role, $organization_name);
        }
        
        $subject = $email_data['subject'];
        $body = $email_data['body'];
        
        // Use PHPMailer for reliable email delivery
        return sendEmailWithPHPMailer($to_email, $subject, $body);
        
    } catch (Exception $e) {
        error_log("Error sending registration email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email using PHPMailer with Gmail SMTP
 */
function sendEmailWithPHPMailer($to_email, $subject, $body) {
    global $phpmailer_loaded;
    
    if (!$phpmailer_loaded) {
        error_log("PHPMailer not loaded, cannot send email");
        return false;
    }
    
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        // Enable debug output (uncomment for troubleshooting)
        // $mail->SMTPDebug = 2;
        // $mail->Debugoutput = 'error_log';
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to_email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        $mail->send();
        error_log("Registration email sent successfully using PHPMailer to: " . $to_email);
        return true;
        
    } catch (Exception $e) {
        error_log("PHPMailer error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email notifications to both president and adviser after successful registration
 * 
 * @param array $president_data Array containing president's email, username, password
 * @param array $adviser_data Array containing adviser's email, username, password
 * @param string $organization_name Name of the organization or council
 * @param string $type Type of registration ('organization' or 'council')
 * @return array Array with success status for each email
 */
function sendRegistrationNotificationsAdvanced($president_data, $adviser_data, $organization_name, $type = 'organization') {
    $results = array();
    
    // Send email to president
    $president_role = ($type === 'council') ? 'Council President' : (($type === 'mis') ? 'MIS Coordinator' : 'Organization President');
    $results['president'] = sendRegistrationEmailAdvanced(
        $president_data['email'],
        $president_data['username'],
        $president_data['password'],
        $president_role,
        $organization_name,
        $type
    );
    
    // Send email to adviser
    $adviser_role = 'Organization Adviser';
    $results['adviser'] = sendRegistrationEmailAdvanced(
        $adviser_data['email'],
        $adviser_data['username'],
        $adviser_data['password'],
        $adviser_role,
        $organization_name,
        $type
    );
    
    return $results;
}

/**
 * Log email sending results for debugging
 * 
 * @param array $email_results Results from sendRegistrationNotifications
 * @param string $organization_name Name of the organization
 * @param string $type Type of registration
 */
function logEmailResultsAdvanced($email_results, $organization_name, $type) {
    $log_message = "Advanced email notification results for " . $type . " registration - " . $organization_name . ": ";
    $log_message .= "President email: " . ($email_results['president'] ? 'SUCCESS' : 'FAILED') . ", ";
    $log_message .= "Adviser email: " . ($email_results['adviser'] ? 'SUCCESS' : 'FAILED');
    
    error_log($log_message);
}

/**
 * Send password change notification email using PHPMailer
 * 
 * @param string $to_email Recipient email address
 * @param string $username Username of the account
 * @param string $role Role of the user
 * @return bool True if email sent successfully, false otherwise
 */
function sendPasswordChangeNotificationAdvanced($to_email, $username, $role) {
    try {
        $email_data = getPasswordChangeEmailTemplate($username, $role);
        
        $subject = $email_data['subject'];
        $body = $email_data['body'];
        
        // Use PHPMailer for reliable email delivery
        return sendEmailWithPHPMailer($to_email, $subject, $body);
        
    } catch (Exception $e) {
        error_log("Error sending password change notification: " . $e->getMessage());
        return false;
    }
}
?> 
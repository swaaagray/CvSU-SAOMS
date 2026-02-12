<?php
require_once 'config/email.php';

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
function sendRegistrationEmail($to_email, $username, $password, $role, $organization_name, $type = 'organization') {
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
        
        // Email headers
        $headers = array(
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'Reply-To: ' . SMTP_FROM_EMAIL,
            'X-Mailer: PHP/' . phpversion()
        );
        
        // Send email using PHP's mail function
        $result = mail($to_email, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            error_log("Registration email sent successfully to: " . $to_email);
            return true;
        } else {
            error_log("Failed to send registration email to: " . $to_email);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Error sending registration email: " . $e->getMessage());
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
function sendRegistrationNotifications($president_data, $adviser_data, $organization_name, $type = 'organization') {
    $results = array();
    
    // Send email to president
    $president_role = ($type === 'council') ? 'Council President' : (($type === 'mis') ? 'MIS Coordinator' : 'Organization President');
    $results['president'] = sendRegistrationEmail(
        $president_data['email'],
        $president_data['username'],
        $president_data['password'],
        $president_role,
        $organization_name,
        $type
    );
    
    // Send email to adviser
    $adviser_role = 'Organization Adviser';
    $results['adviser'] = sendRegistrationEmail(
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
function logEmailResults($email_results, $organization_name, $type) {
    $log_message = "Email notification results for " . $type . " registration - " . $organization_name . ": ";
    $log_message .= "President email: " . ($email_results['president'] ? 'SUCCESS' : 'FAILED') . ", ";
    $log_message .= "Adviser email: " . ($email_results['adviser'] ? 'SUCCESS' : 'FAILED');
    
    error_log($log_message);
}

/**
 * Send password change notification email
 * 
 * @param string $to_email Recipient email address
 * @param string $username Username of the account
 * @param string $role Role of the user
 * @return bool True if email sent successfully, false otherwise
 */
function sendPasswordChangeNotification($to_email, $username, $role) {
    try {
        $email_data = getPasswordChangeEmailTemplate($username, $role);
        
        $subject = $email_data['subject'];
        $body = $email_data['body'];
        
        // Email headers
        $headers = array(
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM_EMAIL . '>',
            'Reply-To: ' . SMTP_FROM_EMAIL,
            'X-Mailer: PHP/' . phpversion()
        );
        
        // Send email using PHP's mail function
        $result = mail($to_email, $subject, $body, implode("\r\n", $headers));
        
        if ($result) {
            error_log("Password change notification sent successfully to: " . $to_email);
            return true;
        } else {
            error_log("Failed to send password change notification to: " . $to_email);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Error sending password change notification: " . $e->getMessage());
        return false;
    }
}
?> 
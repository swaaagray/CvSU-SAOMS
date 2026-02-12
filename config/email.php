<?php
// Email Configuration for Gmail SMTP
define('SMTP_HOST', 'smtp.gmail.com');  // Gmail SMTP server
define('SMTP_PORT', 587);               // TLS port
define('SMTP_USERNAME', 'cvsu.academic.organizations@gmail.com');  // System Gmail account
define('SMTP_PASSWORD', 'cshz ywtf rwyb gbip');     // Gmail App Password
define('SMTP_FROM_EMAIL', 'cvsu.academic.organizations@gmail.com'); // System Gmail account
define('SMTP_FROM_NAME', 'CvSU Academic Organizations System');

// Email templates
function getRegistrationEmailTemplate($username, $password, $role, $organization_name) {
    $subject = "Account Registration Successful - CvSU Academic Organizations System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f8f9fa; }
            .credentials { background-color: #e9ecef; padding: 15px; margin: 15px 0; border-left: 4px solid #007bff; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>CvSU Academic Organizations System</h1>
                <h2>Account Registration Successful</h2>
            </div>
            
            <div class='content'>
                <p>Dear " . htmlspecialchars($role) . ",</p>
                
                <p>Your account has been successfully registered in the CvSU Academic Organizations System.</p>
                
                <div class='credentials'>
                    <h3>Your Login Credentials:</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p><strong>Organization:</strong> " . htmlspecialchars($organization_name) . "</p>
                    <p><strong>Role:</strong> " . htmlspecialchars($role) . "</p>
                </div>
                
                <div class='warning'>
                    <strong>Important Security Notice:</strong><br>
                    • Please keep your credentials secure and do not share them with others<br>
                    • <strong>We highly recommend changing your password</strong> after your first login<br>
                    • You can change your password by clicking your profile menu → 'Change Password'<br>
                    • Use a strong password with at least 8 characters
                </div>
                
                <p>You can now log in to the system using the credentials provided above.</p>
                
                <p>If you have any questions or need assistance, please contact the system administrator.</p>
                
                <p>Best regards,<br>
                CvSU Academic Organizations System Team</p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>Cavite State University - Academic Organizations Management System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}

function getMISRegistrationEmailTemplate($username, $password, $role, $organization_name) {
    $subject = "MIS Coordinator Account Registration Successful - CvSU Academic Organizations System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #6f42c1; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f8f9fa; }
            .credentials { background-color: #e9ecef; padding: 15px; margin: 15px 0; border-left: 4px solid #6f42c1; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; }
            .mis-info { background-color: #e7e3ff; border: 1px solid #d1c4e9; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #6f42c1; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>CvSU Academic Organizations System</h1>
                <h2>MIS Coordinator Account Registration Successful</h2>
            </div>
            
            <div class='content'>
                <p>Dear MIS Coordinator,</p>
                
                <p>Your MIS Coordinator account has been successfully registered in the CvSU Academic Organizations System.</p>
                
                <div class='credentials'>
                    <h3>Your Login Credentials:</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p><strong>College:</strong> " . htmlspecialchars($organization_name) . "</p>
                    <p><strong>Role:</strong> MIS Coordinator</p>
                </div>
            
                <div class='warning'>
                    <strong>Important Security Notice:</strong><br>
                    • Please keep your credentials secure and do not share them with others<br>
                    • <strong>We highly recommend changing your password</strong> after your first login<br>
                    • You can change your password by clicking your profile menu → 'Change Password'<br>
                    • Use a strong password with at least 8 characters
                </div>
                
                <p>You can now log in to the system using the credentials provided above to begin managing your college's academic organizations.</p>
                
                <p>If you have any questions or need assistance, please contact the system administrator.</p>
                
                <p>Best regards,<br>
                CvSU Academic Organizations System Team</p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>Cavite State University - Academic Organizations Management System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}

function getCouncilRegistrationEmailTemplate($username, $password, $role, $council_name) {
    $subject = "Council Account Registration Successful - CvSU Academic Organizations System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f8f9fa; }
            .credentials { background-color: #e9ecef; padding: 15px; margin: 15px 0; border-left: 4px solid #28a745; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>CvSU Academic Organizations System</h1>
                <h2>Council Account Registration Successful</h2>
            </div>
            
            <div class='content'>
                <p>Dear " . htmlspecialchars($role) . ",</p>
                
                <p>Your council account has been successfully registered in the CvSU Academic Organizations System.</p>
                
                <div class='credentials'>
                    <h3>Your Login Credentials:</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                    <p><strong>Council:</strong> " . htmlspecialchars($council_name) . "</p>
                    <p><strong>Role:</strong> " . htmlspecialchars($role) . "</p>
                </div>
                
                <div class='warning'>
                    <strong>Important Security Notice:</strong><br>
                    • Please keep your credentials secure and do not share them with others<br>
                    • <strong>We highly recommend changing your password</strong> after your first login<br>
                    • You can change your password by clicking your profile menu → 'Change Password'<br>
                    • Use a strong password with at least 8 characters
                </div>
                
                <p>You can now log in to the system using the credentials provided above.</p>
                
                <p>If you have any questions or need assistance, please contact the system administrator.</p>
                
                <p>Best regards,<br>
                CvSU Academic Organizations System Team</p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>Cavite State University - Academic Organizations Management System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}

/**
 * Convert role code to full role name
 * 
 * @param string $role Role code (e.g., 'org_adviser', 'org_president', etc.)
 * @return string Full role name
 */
function getRoleFullName($role) {
    $roleMap = array(
        'org_adviser' => 'Organization Adviser',
        'org_president' => 'Organization President',
        'council_president' => 'Council President',
        'council_adviser' => 'Council Adviser',
        'mis_coordinator' => 'MIS Coordinator',
        'osas' => 'OSAS'
    );
    
    return isset($roleMap[$role]) ? $roleMap[$role] : $role;
}

/**
 * Generate password change notification email template
 */
function getPasswordChangeEmailTemplate($username, $role) {
    // Convert role code to full name
    $roleFullName = getRoleFullName($role);
    
    $subject = "Password Changed Successfully - CvSU Academic Organizations System";
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #28a745; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f8f9fa; }
            .info-box { background-color: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #28a745; }
            .security-tips { background-color: #e2e3e5; padding: 15px; margin: 15px 0; border-radius: 4px; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
            .timestamp { color: #6c757d; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🔒 Password Changed Successfully</h1>
                <h2>CvSU Academic Organizations System</h2>
            </div>
            
            <div class='content'>
                <p>Dear " . htmlspecialchars($roleFullName) . ",</p>
                
                <p>This email confirms that your password has been successfully changed for your CvSU Academic Organizations System account.</p>
                
                <div class='info-box'>
                    <h3>Account Information:</h3>
                    <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                    <p><strong>Role:</strong> " . htmlspecialchars($roleFullName) . "</p>
                    <p><strong>Date & Time:</strong> <span class='timestamp'>" . date('F j, Y \a\t g:i A') . "</span></p>
                </div>
                
                <div class='security-tips'>
                    <h3>🛡️ Security Tips:</h3>
                    <ul>
                        <li>Keep your new password secure and do not share it with anyone</li>
                        <li>Use your new password for all future logins</li>
                        <li>If you did not make this change, please contact the system administrator immediately</li>
                        <li>Consider using a password manager to keep track of your passwords securely</li>
                    </ul>
                </div>
                
                <p><strong>What's Next?</strong></p>
                <p>You can now log in to the system using your new password. All previous passwords are no longer valid.</p>
                
                <p>If you have any questions or concerns about this password change, please don't hesitate to contact our support team.</p>
                
                <p>Best regards,<br>
                <strong>CvSU Academic Organizations System Team</strong></p>
            </div>
            
            <div class='footer'>
                <p>This is an automated security notification from the CvSU Academic Organizations System.</p>
                <p>Please do not reply to this email. If you need assistance, contact your system administrator.</p>
                <p>&copy; " . date('Y') . " Cavite State University - Academic Organizations System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}

/**
 * Generate OTP verification email template for organization applications
 */
function getApplicationOTPEmailTemplate($otp_code, $application_type, $org_name, $verifier_role) {
    $subject = "Verification Code - CvSU Academic Organization Application";
    
    $typeLabel = $application_type === 'council' ? 'Student Council' : 'Organization';
    $roleLabel = $verifier_role === 'president' ? 'President' : 'Adviser';
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #0b5d1e; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 30px; background-color: #f8f9fa; }
            .otp-box { background-color: #0b5d1e; color: white; font-size: 32px; font-weight: bold; letter-spacing: 8px; text-align: center; padding: 20px; margin: 20px 0; border-radius: 8px; }
            .info-box { background-color: #e8f5ed; border-left: 4px solid #0b5d1e; padding: 15px; margin: 20px 0; }
            .warning { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 4px; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>CvSU Academic Organizations System</h1>
                <h2>Email Verification</h2>
            </div>
            
            <div class='content'>
                <p>Dear " . htmlspecialchars($roleLabel) . ",</p>
                
                <p>You are receiving this email because a " . htmlspecialchars($typeLabel) . " application for <strong>" . htmlspecialchars($org_name) . "</strong> requires your email verification.</p>
                
                <p>Please use the following verification code to complete the application:</p>
                
                <div class='otp-box'>" . htmlspecialchars($otp_code) . "</div>
                
                <div class='info-box'>
                    <strong>Application Details:</strong><br>
                    • Type: " . htmlspecialchars($typeLabel) . " Registration<br>
                    • Name: " . htmlspecialchars($org_name) . "<br>
                    • Verified as: " . htmlspecialchars($roleLabel) . "
                </div>
                
                <div class='warning'>
                    <strong>⚠️ Important:</strong><br>
                    • This code will expire in <strong>10 minutes</strong><br>
                    • Do not share this code with anyone<br>
                    • If you did not initiate this application, please ignore this email
                </div>
                
                <p>Once verified, your application will be submitted to the Office of Student Affairs and Services (OSAS) for review.</p>
                
                <p>Best regards,<br>
                <strong>CvSU Academic Organizations System</strong></p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>Cavite State University - Academic Organizations Management System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}

/**
 * Generate application status notification email template
 */
function getApplicationStatusEmailTemplate($status, $application_type, $org_name, $rejection_reason = '') {
    $typeLabel = $application_type === 'council' ? 'Student Council' : 'Organization';
    
    if ($status === 'approved') {
        $subject = "Application Approved - " . $org_name;
        $headerColor = '#28a745';
        $statusText = 'APPROVED';
        $statusMessage = "Congratulations! Your " . $typeLabel . " application has been approved by OSAS. Your organization has been registered in the system and login credentials will be sent to the president and adviser email addresses.";
    } else {
        $subject = "Application Status Update - " . $org_name;
        $headerColor = '#dc3545';
        $statusText = 'REJECTED';
        $statusMessage = "Unfortunately, your " . $typeLabel . " application has been rejected by OSAS.";
    }
    
    $body = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: " . $headerColor . "; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
            .content { padding: 30px; background-color: #f8f9fa; }
            .status-box { font-size: 24px; font-weight: bold; text-align: center; padding: 15px; margin: 20px 0; border-radius: 8px; background-color: " . ($status === 'approved' ? '#d4edda' : '#f8d7da') . "; color: " . ($status === 'approved' ? '#155724' : '#721c24') . "; }
            .info-box { background-color: #e9ecef; padding: 15px; margin: 20px 0; border-radius: 4px; }
            .reason-box { background-color: #f8d7da; border-left: 4px solid #dc3545; padding: 15px; margin: 20px 0; }
            .footer { text-align: center; padding: 20px; color: #6c757d; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>CvSU Academic Organizations System</h1>
                <h2>Application Status Update</h2>
            </div>
            
            <div class='content'>
                <div class='status-box'>" . $statusText . "</div>
                
                <div class='info-box'>
                    <strong>Application Details:</strong><br>
                    • Type: " . htmlspecialchars($typeLabel) . " Registration<br>
                    • Name: " . htmlspecialchars($org_name) . "
                </div>
                
                <p>" . $statusMessage . "</p>";
    
    if ($status === 'rejected' && $rejection_reason) {
        $body .= "
                <div class='reason-box'>
                    <strong>Reason for Rejection:</strong><br>
                    " . nl2br(htmlspecialchars($rejection_reason)) . "
                </div>
                
                <p>You may submit a new application after addressing the issues mentioned above.</p>";
    }
    
    $body .= "
                <p>If you have any questions, please contact the Office of Student Affairs and Services (OSAS).</p>
                
                <p>Best regards,<br>
                <strong>CvSU Academic Organizations System</strong></p>
            </div>
            
            <div class='footer'>
                <p>This is an automated message. Please do not reply to this email.</p>
                <p>Cavite State University - Academic Organizations Management System</p>
            </div>
        </div>
    </body>
    </html>";
    
    return array('subject' => $subject, 'body' => $body);
}
?> 
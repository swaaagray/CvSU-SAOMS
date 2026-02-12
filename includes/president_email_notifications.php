<?php
require_once __DIR__ . '/../config/database.php';

// Try to load email helper, but don't fail if it doesn't exist
if (file_exists(__DIR__ . '/email_helper_advanced.php')) {
    require_once __DIR__ . '/email_helper_advanced.php';
}

/**
 * President Email Notification System
 * Ensures that presidents receive all notifications via email
 */

/**
 * Send email notification to president for any notification type
 * 
 * @param int $president_id President's user ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param int|null $related_id Related item ID
 * @param string|null $related_type Related item type
 * @param array $additional_data Additional data for email template
 * @return bool True if email sent successfully
 */
function sendPresidentEmailNotification($president_id, $title, $message, $type, $related_id = null, $related_type = null, $additional_data = []) {
    global $conn;
    
    try {
        // Get president's email and name
        $stmt = $conn->prepare("
            SELECT email, username, role
            FROM users 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $president_id);
        $stmt->execute();
        $president = $stmt->get_result()->fetch_assoc();
        
        if (!$president || !$president['email']) {
            error_log("President email not found for user ID: $president_id");
            return false;
        }
        
        // Resolve a proper full name for the president for email addressing
        $president_name = '';
        // Try organizations
        $org_stmt = $conn->prepare("SELECT president_name FROM organizations WHERE president_id = ? LIMIT 1");
        if ($org_stmt) {
            $org_stmt->bind_param("i", $president_id);
            if ($org_stmt->execute()) {
                $row = $org_stmt->get_result()->fetch_assoc();
                if (!empty($row['president_name'])) { $president_name = $row['president_name']; }
            }
            $org_stmt->close();
        }
        // Try council
        if ($president_name === '') {
            $c_stmt = $conn->prepare("SELECT president_name FROM council WHERE president_id = ? LIMIT 1");
            if ($c_stmt) {
                $c_stmt->bind_param("i", $president_id);
                if ($c_stmt->execute()) {
                    $row = $c_stmt->get_result()->fetch_assoc();
                    if (!empty($row['president_name'])) { $president_name = $row['president_name']; }
                }
                $c_stmt->close();
            }
        }
        // Fallback to users table fields
        if ($president_name === '') {
            $name_stmt = $conn->prepare("SELECT full_name, name, username FROM users WHERE id = ?");
            if ($name_stmt) {
                $name_stmt->bind_param("i", $president_id);
                if ($name_stmt->execute()) {
                    $n = $name_stmt->get_result()->fetch_assoc();
                    if (!empty($n['full_name'])) { $president_name = $n['full_name']; }
                    elseif (!empty($n['name'])) { $president_name = $n['name']; }
                    else { $president_name = $n['username'] ?? $president['username']; }
                } else {
                    $president_name = $president['username'];
                }
                $name_stmt->close();
            } else {
                $president_name = $president['username'];
            }
        }
        $president_email = $president['email'];
        
        // Get email template based on notification type
        $email_data = getPresidentNotificationEmailTemplate($title, $message, $type, $president_name, $additional_data);
        
        if (!$email_data) {
            error_log("No email template found for notification type: $type");
            return false;
        }
        
        // Send email using PHPMailer (with error handling)
        $success = false;
        if (function_exists('sendEmailWithPHPMailer')) {
            $success = sendEmailWithPHPMailer($president_email, $email_data['subject'], $email_data['body']);
        } else {
            error_log("sendEmailWithPHPMailer function not available");
        }
        
        if ($success) {
            error_log("President email notification sent successfully to: $president_email for type: $type");
        } else {
            error_log("Failed to send president email notification to: $president_email for type: $type");
        }
        
        return $success;
        
    } catch (Exception $e) {
        error_log("Error sending president email notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get email template for president notifications
 * 
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param string $president_name President's full name
 * @param array $additional_data Additional data for email template
 * @return array|false Email template data or false if not found
 */
function getPresidentNotificationEmailTemplate($title, $message, $type, $president_name, $additional_data = []) {
    // Get base URL safely
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $base_url = $protocol . "://" . $host . dirname($uri);
    
    // Determine email subject and styling based on notification type
    $subject = "";
    $icon = "";
    $color = "";
    $priority = "normal";
    
    switch ($type) {
        case 'document_approved':
            $subject = "Document Approved - " . $title;
            $icon = "✓";
            $color = "#28a745";
            break;
        case 'document_rejected':
            $subject = "Document Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        case 'event_approved':
            $subject = "Event Approved - " . $title;
            $icon = "✓";
            $color = "#28a745";
            break;
        case 'event_rejected':
            $subject = "Event Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        case 'document_submitted':
            $subject = "New Document Submitted - " . $title;
            $icon = "📄";
            $color = "#007bff";
            break;
        case 'event_submitted':
            $subject = "New Event Submitted - " . $title;
            $icon = "🎉";
            $color = "#007bff";
            break;
        case 'documents_for_review':
            $subject = "Documents Ready for Review - " . $title;
            $icon = "📋";
            $color = "#ffc107";
            break;
        case 'event_documents_for_review':
            $subject = "Event Documents Ready for Review - " . $title;
            $icon = "📋";
            $color = "#ffc107";
            break;
        case 'documents_sent_to_osas':
            $subject = "Documents Sent to OSAS - " . $title;
            $icon = "📤";
            $color = "#17a2b8";
            break;
        case 'deadline_reminder_1hour':
            $subject = "URGENT: Deadline Reminder - " . $title;
            $icon = "⚠";
            $color = "#dc3545";
            $priority = "urgent";
            break;
        case 'compliance_reminder':
            $subject = "Compliance Reminder - " . $title;
            $icon = "📋";
            $color = "#ffc107";
            $priority = "high";
            break;
        case 'council_document_approved':
            $subject = "Council Document Approved - " . $title;
            $icon = "✓";
            $color = "#28a745";
            break;
        case 'council_document_rejected':
            $subject = "Council Document Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        case 'council_event_approved':
            $subject = "Council Event Approved - " . $title;
            $icon = "✓";
            $color = "#28a745";
            break;
        case 'council_event_rejected':
            $subject = "Council Event Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        case 'event_document_rejected':
            $subject = "Event Document Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        case 'council_event_document_rejected':
            $subject = "Council Event Document Rejected - " . $title;
            $icon = "✗";
            $color = "#dc3545";
            $priority = "high";
            break;
        default:
            $subject = "System Notification - " . $title;
            $icon = "📢";
            $color = "#6c757d";
            break;
    }
    
    // Create email body
    $body = createPresidentNotificationEmailBody($title, $message, $type, $president_name, $icon, $color, $priority, $additional_data, $base_url);
    
    return [
        'subject' => $subject,
        'body' => $body
    ];
}

/**
 * Create email body for president notifications
 * 
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param string $president_name President's full name
 * @param string $icon Notification icon
 * @param string $color Notification color
 * @param string $priority Notification priority
 * @param array $additional_data Additional data
 * @param string $base_url Base URL for links
 * @return string HTML email body
 */
function createPresidentNotificationEmailBody($title, $message, $type, $president_name, $icon, $color, $priority, $additional_data, $base_url) {
    $priority_class = $priority === 'urgent' ? 'urgent' : ($priority === 'high' ? 'high' : 'normal');
    $header_bg = $priority === 'urgent' ? '#dc3545' : ($priority === 'high' ? '#ffc107' : $color);
    
        // Get action instructions based on notification type (no clickable links)
        $action_instructions = getActionInstructionsForNotificationType($type);
    
    // Get additional details if available
    $additional_details = getAdditionalDetailsForNotificationType($type, $additional_data);
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>$title</title>
        <style>
            body { 
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                margin: 0; 
                padding: 0; 
                background-color: #f8f9fa;
            }
            .container { 
                max-width: 600px; 
                margin: 20px auto; 
                background-color: white; 
                border-radius: 10px; 
                overflow: hidden; 
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .header { 
                background-color: $header_bg; 
                color: white; 
                padding: 25px; 
                text-align: center; 
            }
            .header h1 { 
                margin: 0; 
                font-size: 24px; 
                font-weight: 600;
            }
            .content { 
                padding: 30px; 
            }
            .notification-icon { 
                font-size: 48px; 
                margin-bottom: 15px; 
            }
            .notification-title { 
                font-size: 20px; 
                font-weight: 600; 
                color: $color; 
                margin-bottom: 15px;
            }
            .notification-message { 
                font-size: 16px; 
                margin-bottom: 25px; 
                line-height: 1.6;
            }
            .additional-details { 
                background-color: #f8f9fa; 
                padding: 20px; 
                border-radius: 8px; 
                margin: 20px 0; 
                border-left: 4px solid $color;
            }
            .action-button { 
                display: inline-block; 
                background-color: $color; 
                color: white; 
                padding: 12px 25px; 
                text-decoration: none; 
                border-radius: 6px; 
                font-weight: 600; 
                margin: 20px 0; 
                transition: background-color 0.3s;
            }
            .action-instructions { 
                background-color: #e9ecef; 
                padding: 15px; 
                border-radius: 6px; 
                margin: 20px 0; 
                border-left: 4px solid $color;
            }
            .footer { 
                background-color: #f8f9fa; 
                padding: 20px; 
                text-align: center; 
                color: #6c757d; 
                font-size: 14px; 
                border-top: 1px solid #dee2e6;
            }
            .urgent { 
                border: 2px solid #dc3545; 
                animation: pulse 2s infinite;
            }
            .high { 
                border: 2px solid #ffc107;
            }
            @keyframes pulse { 
                0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.7); } 
                70% { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); } 
                100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); } 
            }
            .system-info { 
                background-color: #e9ecef; 
                padding: 15px; 
                border-radius: 6px; 
                margin-top: 20px; 
                font-size: 14px; 
                color: #495057;
            }
        </style>
    </head>
    <body>
        <div class='container $priority_class'>
            <div class='header'>
                <div class='notification-icon'>$icon</div>
                <h1>$title</h1>
            </div>
            <div class='content'>
                <p>Dear $president_name,</p>
                
                <div class='notification-message'>
                    $message
                </div>
                
                $additional_details
                
                $action_instructions
                
                <div class='system-info'>
                    <strong>System Information:</strong><br>
                    • Notification Type: " . ucfirst(str_replace('_', ' ', $type)) . "<br>
                    • Priority Level: " . ucfirst($priority) . "<br>
                    • Timestamp: " . date('F j, Y \a\t g:i A') . " (Philippine Time)<br>
                    • This is an automated notification from the CvSU Academic Organizations System
                </div>
                
                <p>If you have any questions or need assistance, please contact the OSAS office.</p>
                
                <p>Best regards,<br>
                <strong>OSAS Team</strong><br>
                Cavite State University</p>
            </div>
            <div class='footer'>
                <p>This is an automated notification. Please do not reply to this email.</p>
                <p>© " . date('Y') . " Cavite State University - Office of Student Affairs and Services</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get action instructions for notification type (no clickable links)
 * 
 * @param string $type Notification type
 * @return string HTML action instructions
 */
function getActionInstructionsForNotificationType($type) {
    $instructions = [
        'document_approved' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your approved document and check the status of other pending documents.</div>',
        'document_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your document with the necessary corrections.</div>',
        'event_approved' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your approved event and proceed with your planned activities.</div>',
        'event_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your event proposal with the necessary adjustments.</div>',
        'document_submitted' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your submitted document and track its approval status.</div>',
        'event_submitted' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your submitted event and track its approval status.</div>',
        'documents_for_review' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to review the documents that are ready for your attention.</div>',
        'event_documents_for_review' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to review the event documents that are ready for your attention.</div>',
        'documents_sent_to_osas' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your documents that have been sent to OSAS for final review.</div>',
        'deadline_reminder_1hour' => '<div class="action-instructions" style="background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;"><strong>URGENT ACTION REQUIRED:</strong> Log into the system immediately to resubmit your document before the deadline expires. This is a critical deadline that cannot be extended.</div>',
        'compliance_reminder' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view and submit all missing documents to maintain compliance.</div>',
        'council_document_approved' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your approved council document and check the status of other pending documents.</div>',
        'council_document_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your council document with the necessary corrections.</div>',
        'council_event_approved' => '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your approved council event and proceed with your planned activities.</div>',
        'council_event_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your council event proposal with the necessary adjustments.</div>',
        'event_document_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your event document with the necessary corrections.</div>',
        'council_event_document_rejected' => '<div class="action-instructions"><strong>Action Required:</strong> Log into the system to view the rejection feedback and resubmit your council event document with the necessary corrections.</div>'
    ];
    
    return $instructions[$type] ?? '<div class="action-instructions"><strong>Next Steps:</strong> Log into the system to view your dashboard for more information.</div>';
}

/**
 * Get additional details for notification type
 * 
 * @param string $type Notification type
 * @param array $additional_data Additional data
 * @return string HTML additional details
 */
function getAdditionalDetailsForNotificationType($type, $additional_data) {
    $details = "";
    
    switch ($type) {
        case 'deadline_reminder_1hour':
            if (isset($additional_data['deadline'])) {
                $details = "
                <div class='additional-details'>
                    <h3>⚠️ Deadline Information</h3>
                    <p><strong>Deadline:</strong> " . $additional_data['deadline'] . "</p>
                    <p><strong>Time Remaining:</strong> Less than 1 hour</p>
                    <p><strong>Action Required:</strong> Please resubmit the document immediately to avoid missing the deadline.</p>
                </div>";
            }
            break;
        case 'compliance_reminder':
            if (isset($additional_data['missing_documents']) && is_array($additional_data['missing_documents'])) {
                $missing_docs = implode(', ', $additional_data['missing_documents']);
                $details = "
                <div class='additional-details'>
                    <h3>📋 Missing Documents</h3>
                    <p><strong>Required Documents:</strong> $missing_docs</p>
                    <p><strong>Action Required:</strong> Please submit all missing documents to maintain compliance.</p>
                </div>";
            }
            break;
        case 'document_rejected':
        case 'council_document_rejected':
        case 'event_document_rejected':
        case 'council_event_document_rejected':
            $details_html = "";
            if (isset($additional_data['rejection_reason']) || isset($additional_data['deadline'])) {
                $details_html = "
                <div class='additional-details'>
                    <h3>📝 Rejection Details</h3>";
                
                if (isset($additional_data['rejection_reason'])) {
                    $details_html .= "
                    <p><strong>Reason for Rejection:</strong> " . htmlspecialchars($additional_data['rejection_reason']) . "</p>";
                }
                
                if (isset($additional_data['event_title'])) {
                    $details_html .= "
                    <p><strong>Event:</strong> " . htmlspecialchars($additional_data['event_title']) . "</p>";
                }
                
                if (isset($additional_data['deadline'])) {
                    $details_html .= "
                    <p><strong>Resubmission Deadline:</strong> <span style='color: #dc3545; font-weight: bold;'>" . htmlspecialchars($additional_data['deadline']) . "</span></p>
                    <p><strong>Action Required:</strong> Please address the issues mentioned and resubmit the document before the deadline.</p>";
                } else {
                    $details_html .= "
                    <p><strong>Action Required:</strong> Please address the issues mentioned and resubmit the document.</p>";
                }
                
                $details_html .= "
                </div>";
            }
            $details = $details_html;
            break;
    }
    
    return $details;
}

/**
 * Darken a color by a percentage
 * 
 * @param string $hex_color Hex color code
 * @param int $percent Percentage to darken (0-100)
 * @return string Darkened hex color
 */
function darkenColor($hex_color, $percent) {
    $hex_color = ltrim($hex_color, '#');
    $r = hexdec(substr($hex_color, 0, 2));
    $g = hexdec(substr($hex_color, 2, 2));
    $b = hexdec(substr($hex_color, 4, 2));
    
    $r = max(0, min(255, $r - ($r * $percent / 100)));
    $g = max(0, min(255, $g - ($g * $percent / 100)));
    $b = max(0, min(255, $b - ($b * $percent / 100)));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

/**
 * Send email notification to all presidents for system-wide announcements
 * 
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param array $additional_data Additional data
 * @return array Results of email sending
 */
function sendSystemWidePresidentNotification($title, $message, $type, $additional_data = []) {
    global $conn;
    
    $results = [
        'total_presidents' => 0,
        'emails_sent' => 0,
        'emails_failed' => 0,
        'errors' => []
    ];
    
    try {
        // Get all presidents (both organization and council presidents)
        $stmt = $conn->prepare("
            SELECT id, email, username, role
            FROM users 
            WHERE role IN ('org_president', 'council_president')
            AND email IS NOT NULL
            AND email != ''
        ");
        $stmt->execute();
        $presidents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $results['total_presidents'] = count($presidents);
        
        foreach ($presidents as $president) {
            $success = sendPresidentEmailNotification(
                $president['id'],
                $title,
                $message,
                $type,
                null,
                null,
                $additional_data
            );
            
            if ($success) {
                $results['emails_sent']++;
            } else {
                $results['emails_failed']++;
                $results['errors'][] = "Failed to send email to: " . $president['email'];
            }
        }
        
        error_log("System-wide president notification sent: " . json_encode($results));
        
    } catch (Exception $e) {
        $results['errors'][] = "Error in system-wide notification: " . $e->getMessage();
        error_log("Error sending system-wide president notification: " . $e->getMessage());
    }
    
    return $results;
}

?>

<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/email_helper_advanced.php';

/**
 * Deadline Reminder Helper
 * Handles automated notifications for rejected documents approaching resubmission deadline
 */

/**
 * Check for documents with deadlines approaching (1 hour before deadline)
 * and send reminder notifications to presidents and advisers
 * 
 * @return array Array with results of the reminder process
 */
function checkAndSendDeadlineReminders() {
    global $conn;
    
    $results = [
        'organization_documents' => 0,
        'event_documents' => 0,
        'council_documents' => 0,
        'total_reminders_sent' => 0,
        'errors' => []
    ];
    
    try {
        // Ensure timezone is set to Philippine Time
        date_default_timezone_set('Asia/Manila');
        
        // Get current time and 1 hour from now in Philippine Time
        $now = date('Y-m-d H:i:s');
        $oneHourFromNow = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        error_log("Deadline reminder check - Current time (PHT): $now, One hour from now (PHT): $oneHourFromNow");
        
        // Check organization documents with deadlines approaching
        $results['organization_documents'] = checkOrganizationDocumentDeadlines($now, $oneHourFromNow);
        
        // Check event documents with deadlines approaching
        $results['event_documents'] = checkEventDocumentDeadlines($now, $oneHourFromNow);
        
        // Check council documents with deadlines approaching
        $results['council_documents'] = checkCouncilDocumentDeadlines($now, $oneHourFromNow);
        
        // Calculate total reminders sent
        $results['total_reminders_sent'] = $results['organization_documents'] + 
                                         $results['event_documents'] + 
                                         $results['council_documents'];
        
        // Clean up expired deadline notifications
        cleanupExpiredDeadlineNotifications();
        
        error_log("Deadline reminder check completed: " . json_encode($results));
        
    } catch (Exception $e) {
        $results['errors'][] = "Error in deadline reminder check: " . $e->getMessage();
        error_log("Error in deadline reminder check: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Check organization documents with approaching deadlines
 * 
 * @param string $now Current timestamp
 * @param string $oneHourFromNow Timestamp 1 hour from now
 * @return int Number of reminders sent
 */
function checkOrganizationDocumentDeadlines($now, $oneHourFromNow) {
    global $conn;
    
    $remindersSent = 0;
    
    try {
        // Get organization documents with deadlines approaching and not yet resubmitted
        $stmt = $conn->prepare("
            SELECT 
                od.id as document_id,
                od.document_type,
                od.resubmission_deadline,
                od.rejection_reason,
                o.org_name,
                o.president_id,
                o.adviser_id,
                u1.email as president_email,
                u1.first_name as president_first_name,
                u1.last_name as president_last_name,
                u2.email as adviser_email,
                u2.first_name as adviser_first_name,
                u2.last_name as adviser_last_name
            FROM organization_documents od
            JOIN organizations o ON od.organization_id = o.id
            LEFT JOIN users u1 ON o.president_id = u1.id
            LEFT JOIN users u2 ON o.adviser_id = u2.id
            WHERE od.status = 'rejected'
            AND od.resubmission_deadline IS NOT NULL
            AND od.resubmission_deadline BETWEEN ? AND ?
            AND od.osas_rejected_at IS NOT NULL
            AND od.adviser_approved_at IS NULL
            AND od.osas_approved_at IS NULL
        ");
        
        $stmt->bind_param("ss", $now, $oneHourFromNow);
        $stmt->execute();
        $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        error_log("Found " . count($documents) . " organization documents with approaching deadlines");
        
        foreach ($documents as $doc) {
            error_log("Processing organization document ID: " . $doc['document_id'] . " with deadline: " . $doc['resubmission_deadline']);
            if (sendDeadlineReminderNotification($doc, 'organization_document')) {
                $remindersSent++;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error checking organization document deadlines: " . $e->getMessage());
    }
    
    return $remindersSent;
}

/**
 * Check event documents with approaching deadlines
 * 
 * @param string $now Current timestamp
 * @param string $oneHourFromNow Timestamp 1 hour from now
 * @return int Number of reminders sent
 */
function checkEventDocumentDeadlines($now, $oneHourFromNow) {
    global $conn;
    
    $remindersSent = 0;
    
    try {
        // Get event documents with deadlines approaching and not yet resubmitted
        $stmt = $conn->prepare("
            SELECT 
                ed.id as document_id,
                ed.document_type,
                ed.resubmission_deadline,
                ed.rejection_reason,
                ea.title as event_title,
                o.org_name,
                o.president_id,
                o.adviser_id,
                u1.email as president_email,
                u1.first_name as president_first_name,
                u1.last_name as president_last_name,
                u2.email as adviser_email,
                u2.first_name as adviser_first_name,
                u2.last_name as adviser_last_name
            FROM event_documents ed
            JOIN event_approvals ea ON ed.event_approval_id = ea.id
            JOIN organizations o ON ea.organization_id = o.id
            LEFT JOIN users u1 ON o.president_id = u1.id
            LEFT JOIN users u2 ON o.adviser_id = u2.id
            WHERE ed.status = 'rejected'
            AND ed.resubmission_deadline IS NOT NULL
            AND ed.resubmission_deadline BETWEEN ? AND ?
            AND ed.osas_rejected_at IS NOT NULL
            AND ed.adviser_approved_at IS NULL
            AND ed.osas_approved_at IS NULL
        ");
        
        $stmt->bind_param("ss", $now, $oneHourFromNow);
        $stmt->execute();
        $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        error_log("Found " . count($documents) . " event documents with approaching deadlines");
        
        foreach ($documents as $doc) {
            error_log("Processing event document ID: " . $doc['document_id'] . " with deadline: " . $doc['resubmission_deadline']);
            if (sendDeadlineReminderNotification($doc, 'event_document')) {
                $remindersSent++;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error checking event document deadlines: " . $e->getMessage());
    }
    
    return $remindersSent;
}

/**
 * Check council documents with approaching deadlines
 * 
 * @param string $now Current timestamp
 * @param string $oneHourFromNow Timestamp 1 hour from now
 * @return int Number of reminders sent
 */
function checkCouncilDocumentDeadlines($now, $oneHourFromNow) {
    global $conn;
    
    $remindersSent = 0;
    
    try {
        // Get council documents with deadlines approaching and not yet resubmitted
        $stmt = $conn->prepare("
            SELECT 
                cd.id as document_id,
                cd.document_type,
                cd.resubmission_deadline,
                cd.reject_reason as rejection_reason,
                c.council_name as org_name,
                c.president_id,
                c.adviser_id,
                u1.email as president_email,
                u1.first_name as president_first_name,
                u1.last_name as president_last_name,
                u2.email as adviser_email,
                u2.first_name as adviser_first_name,
                u2.last_name as adviser_last_name
            FROM council_documents cd
            JOIN council c ON cd.council_id = c.id
            LEFT JOIN users u1 ON c.president_id = u1.id
            LEFT JOIN users u2 ON c.adviser_id = u2.id
            WHERE cd.status = 'rejected'
            AND cd.resubmission_deadline IS NOT NULL
            AND cd.resubmission_deadline BETWEEN ? AND ?
            AND cd.osas_rejected_at IS NOT NULL
            AND cd.adviser_approval_at IS NULL
            AND cd.osas_approved_at IS NULL
        ");
        
        $stmt->bind_param("ss", $now, $oneHourFromNow);
        $stmt->execute();
        $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        error_log("Found " . count($documents) . " council documents with approaching deadlines");
        
        foreach ($documents as $doc) {
            error_log("Processing council document ID: " . $doc['document_id'] . " with deadline: " . $doc['resubmission_deadline']);
            if (sendDeadlineReminderNotification($doc, 'council_document')) {
                $remindersSent++;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error checking council document deadlines: " . $e->getMessage());
    }
    
    return $remindersSent;
}

/**
 * Send deadline reminder notification to president and adviser
 * 
 * @param array $document Document data
 * @param string $documentType Type of document ('organization_document', 'event_document', 'council_document')
 * @return bool True if notifications sent successfully
 */
function sendDeadlineReminderNotification($document, $documentType) {
    $success = true;
    
    try {
        // Check if reminder already exists for this document (within the last 2 hours)
        if ($document['president_id'] && deadlineReminderExists($document['document_id'], $documentType, $document['president_id'])) {
            error_log("Deadline reminder already exists for document ID {$document['document_id']} ({$documentType}) for president - skipping");
            return true; // Don't send duplicate reminders
        }
        
        if ($document['adviser_id'] && deadlineReminderExists($document['document_id'], $documentType, $document['adviser_id'])) {
            error_log("Deadline reminder already exists for document ID {$document['document_id']} ({$documentType}) for adviser - skipping");
            return true; // Don't send duplicate reminders
        }
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'adviser_resume' => 'Adviser Resume',
            'student_profile' => 'Student Profile',
            'officers_list' => 'Officers List',
            'calendar_activities' => 'Calendar of Activities',
            'official_logo' => 'Official Logo',
            'officers_grade' => 'Officers Grade',
            'group_picture' => 'Group Picture',
            'constitution_bylaws' => 'Constitution & Bylaws',
            'members_list' => 'Members List',
            'good_moral' => 'Good Moral Certificate',
            'adviser_acceptance' => 'Adviser Acceptance',
            'budget_resolution' => 'Budget Resolution',
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'accomplishment_report' => 'Accomplishment Report',
            'previous_plan_of_activities' => 'Previous Plan of Activities',
            'financial_report' => 'Financial Report'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document['document_type']] ?? ucfirst(str_replace('_', ' ', $document['document_type']));
        
        // Format deadline for display
        $deadline = date('M d, Y g:i A', strtotime($document['resubmission_deadline']));
        
        // Create notification message
        $title = "URGENT: Resubmission Deadline Approaching";
        $message = "URGENT NOTICE: Your $documentTypeLabel for {$document['org_name']} has a resubmission deadline in 1 hour: $deadline. This is a critical deadline that cannot be extended. Please resubmit your document immediately to avoid missing the deadline and potential penalties.";
        
        // Add event title if it's an event document
        if ($documentType === 'event_document' && !empty($document['event_title'])) {
            $message = "URGENT NOTICE: Your $documentTypeLabel for event '{$document['event_title']}' ({$document['org_name']}) has a resubmission deadline in 1 hour: $deadline. This is a critical deadline that cannot be extended. Please resubmit your event document immediately to avoid missing the deadline and potential penalties.";
        }
        
        // Send in-app notification to president
        if ($document['president_id']) {
            $additional_data = [
                'deadline' => $deadline,
                'document_type' => $documentTypeLabel,
                'organization_name' => $document['org_name'],
                'rejection_reason' => $document['rejection_reason'] ?? null
            ];
            
            $success &= createNotification(
                $document['president_id'],
                $title,
                $message,
                'deadline_reminder_1hour',
                $document['document_id'],
                $documentType,
                $additional_data
            );
        }
        
        // Send in-app notification to adviser
        if ($document['adviser_id']) {
            $success &= createNotification(
                $document['adviser_id'],
                $title,
                $message,
                'deadline_reminder_1hour',
                $document['document_id'],
                $documentType
            );
        }
        
        // Send email notification to president
        if ($document['president_email']) {
            $presidentName = $document['president_first_name'] . ' ' . $document['president_last_name'];
            $success &= sendDeadlineReminderEmail(
                $document['president_email'],
                $presidentName,
                $documentTypeLabel,
                $document['org_name'],
                $deadline,
                $document['event_title'] ?? null
            );
        }
        
        // Send email notification to adviser
        if ($document['adviser_email']) {
            $adviserName = $document['adviser_first_name'] . ' ' . $document['adviser_last_name'];
            $success &= sendDeadlineReminderEmail(
                $document['adviser_email'],
                $adviserName,
                $documentTypeLabel,
                $document['org_name'],
                $deadline,
                $document['event_title'] ?? null
            );
        }
        
        error_log("Deadline reminder sent for document ID {$document['document_id']} ({$documentType})");
        
    } catch (Exception $e) {
        error_log("Error sending deadline reminder notification: " . $e->getMessage());
        $success = false;
    }
    
    return $success;
}

/**
 * Send deadline reminder email
 * 
 * @param string $email Recipient email
 * @param string $recipientName Recipient name
 * @param string $documentType Document type label
 * @param string $organizationName Organization/council name
 * @param string $deadline Formatted deadline
 * @param string|null $eventTitle Event title (if applicable)
 * @return bool True if email sent successfully
 */
function sendDeadlineReminderEmail($email, $recipientName, $documentType, $organizationName, $deadline, $eventTitle = null) {
    try {
        $subject = "URGENT: Document Resubmission Deadline in 1 Hour";
        
        // Create email body
        $body = getDeadlineReminderEmailTemplate($recipientName, $documentType, $organizationName, $deadline, $eventTitle);
        
        // Send email using PHPMailer
        return sendEmailWithPHPMailer($email, $subject, $body);
        
    } catch (Exception $e) {
        error_log("Error sending deadline reminder email: " . $e->getMessage());
        return false;
    }
}

/**
 * Get deadline reminder email template
 * 
 * @param string $recipientName Recipient name
 * @param string $documentType Document type label
 * @param string $organizationName Organization/council name
 * @param string $deadline Formatted deadline
 * @param string|null $eventTitle Event title (if applicable)
 * @return string Email HTML body
 */
function getDeadlineReminderEmailTemplate($recipientName, $documentType, $organizationName, $deadline, $eventTitle = null) {
    $eventInfo = $eventTitle ? " for event '$eventTitle'" : "";
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Document Resubmission Deadline Reminder</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
            .content { background-color: #f8f9fa; padding: 20px; border-radius: 0 0 5px 5px; }
            .deadline { background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .urgent { color: #dc3545; font-weight: bold; }
            .footer { text-align: center; margin-top: 20px; color: #6c757d; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>⚠️ URGENT: Document Resubmission Deadline</h1>
            </div>
            <div class='content'>
                <p>Dear $recipientName,</p>
                
                <p>This is an urgent reminder that you have a document resubmission deadline approaching.</p>
                
                <div class='deadline'>
                    <h3>Document Details:</h3>
                    <ul>
                        <li><strong>Document Type:</strong> $documentType</li>
                        <li><strong>Organization/Council:</strong> $organizationName</li>
                        " . ($eventTitle ? "<li><strong>Event:</strong> $eventTitle</li>" : "") . "
                        <li><strong>Deadline:</strong> <span class='urgent'>$deadline</span></li>
                    </ul>
                </div>
                
                <p class='urgent'>⚠️ You have less than 1 hour to resubmit this document before the deadline expires!</p>
                
                <p>Please log into the system immediately and resubmit the required document to avoid any penalties or delays in your organization's/council's recognition process.</p>
                
                <p>If you have already resubmitted this document, please disregard this reminder.</p>
                
                <p>Best regards,<br>
                OSAS Team</p>
            </div>
            <div class='footer'>
                <p>This is an automated reminder. Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Get statistics about upcoming deadlines
 * 
 * @return array Array with deadline statistics
 */
function getDeadlineStatistics() {
    global $conn;
    
    $stats = [
        'total_rejected_with_deadlines' => 0,
        'deadlines_today' => 0,
        'deadlines_tomorrow' => 0,
        'deadlines_this_week' => 0,
        'overdue_deadlines' => 0
    ];
    
    try {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $thisWeek = date('Y-m-d', strtotime('+7 days'));
        $now = date('Y-m-d H:i:s');
        
        // Organization documents
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM organization_documents 
            WHERE status = 'rejected' AND resubmission_deadline IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_rejected_with_deadlines'] += $result['count'];
        
        // Event documents
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM event_documents 
            WHERE status = 'rejected' AND resubmission_deadline IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_rejected_with_deadlines'] += $result['count'];
        
        // Council documents
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM council_documents 
            WHERE status = 'rejected' AND resubmission_deadline IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_rejected_with_deadlines'] += $result['count'];
        
        // Deadlines today
        $stmt = $conn->prepare("
            SELECT 
                (SELECT COUNT(*) FROM organization_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) +
                (SELECT COUNT(*) FROM event_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) +
                (SELECT COUNT(*) FROM council_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) as count
        ");
        $stmt->bind_param("sss", $today, $today, $today);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['deadlines_today'] = $result['count'];
        
        // Deadlines tomorrow
        $stmt = $conn->prepare("
            SELECT 
                (SELECT COUNT(*) FROM organization_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) +
                (SELECT COUNT(*) FROM event_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) +
                (SELECT COUNT(*) FROM council_documents WHERE status = 'rejected' AND DATE(resubmission_deadline) = ?) as count
        ");
        $stmt->bind_param("sss", $tomorrow, $tomorrow, $tomorrow);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['deadlines_tomorrow'] = $result['count'];
        
        // Deadlines this week
        $stmt = $conn->prepare("
            SELECT 
                (SELECT COUNT(*) FROM organization_documents WHERE status = 'rejected' AND resubmission_deadline BETWEEN ? AND ?) +
                (SELECT COUNT(*) FROM event_documents WHERE status = 'rejected' AND resubmission_deadline BETWEEN ? AND ?) +
                (SELECT COUNT(*) FROM council_documents WHERE status = 'rejected' AND resubmission_deadline BETWEEN ? AND ?) as count
        ");
        $stmt->bind_param("ssssss", $today, $thisWeek, $today, $thisWeek, $today, $thisWeek);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['deadlines_this_week'] = $result['count'];
        
        // Overdue deadlines
        $stmt = $conn->prepare("
            SELECT 
                (SELECT COUNT(*) FROM organization_documents WHERE status = 'rejected' AND resubmission_deadline < ?) +
                (SELECT COUNT(*) FROM event_documents WHERE status = 'rejected' AND resubmission_deadline < ?) +
                (SELECT COUNT(*) FROM council_documents WHERE status = 'rejected' AND resubmission_deadline < ?) as count
        ");
        $stmt->bind_param("sss", $now, $now, $now);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['overdue_deadlines'] = $result['count'];
        
    } catch (Exception $e) {
        error_log("Error getting deadline statistics: " . $e->getMessage());
    }
    
    return $stats;
}

/**
 * Clean up expired deadline notifications
 * Removes deadline reminder notifications that are no longer relevant
 * (deadline has passed or document has been resubmitted)
 */
function cleanupExpiredDeadlineNotifications() {
    global $conn;
    
    try {
        $now = date('Y-m-d H:i:s');
        
        // Get all deadline reminder notifications
        $stmt = $conn->prepare("
            SELECT n.id, n.related_id, n.related_type, n.created_at
            FROM notifications n
            WHERE n.type = 'deadline_reminder_1hour'
            AND n.is_read = FALSE
        ");
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $cleanedCount = 0;
        
        foreach ($notifications as $notification) {
            $shouldRemove = false;
            
            // Check if deadline has passed
            if ($notification['related_type'] === 'organization_document') {
                $stmt = $conn->prepare("
                    SELECT resubmission_deadline, adviser_approved_at, osas_approved_at
                    FROM organization_documents 
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $notification['related_id']);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                
                if ($doc) {
                    // Remove if deadline has passed or document has been resubmitted
                    if ($doc['resubmission_deadline'] < $now || 
                        $doc['adviser_approved_at'] !== null || 
                        $doc['osas_approved_at'] !== null) {
                        $shouldRemove = true;
                    }
                } else {
                    // Document no longer exists
                    $shouldRemove = true;
                }
            } elseif ($notification['related_type'] === 'event_document') {
                $stmt = $conn->prepare("
                    SELECT resubmission_deadline, adviser_approved_at, osas_approved_at
                    FROM event_documents 
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $notification['related_id']);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                
                if ($doc) {
                    // Remove if deadline has passed or document has been resubmitted
                    if ($doc['resubmission_deadline'] < $now || 
                        $doc['adviser_approved_at'] !== null || 
                        $doc['osas_approved_at'] !== null) {
                        $shouldRemove = true;
                    }
                } else {
                    // Document no longer exists
                    $shouldRemove = true;
                }
            } elseif ($notification['related_type'] === 'council_document') {
                $stmt = $conn->prepare("
                    SELECT resubmission_deadline, adviser_approval_at, osas_approved_at
                    FROM council_documents 
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $notification['related_id']);
                $stmt->execute();
                $doc = $stmt->get_result()->fetch_assoc();
                
                if ($doc) {
                    // Remove if deadline has passed or document has been resubmitted
                    if ($doc['resubmission_deadline'] < $now || 
                        $doc['adviser_approval_at'] !== null || 
                        $doc['osas_approved_at'] !== null) {
                        $shouldRemove = true;
                    }
                } else {
                    // Document no longer exists
                    $shouldRemove = true;
                }
            }
            
            // Remove the notification if it should be cleaned up
            if ($shouldRemove) {
                $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ?");
                $stmt->bind_param("i", $notification['id']);
                if ($stmt->execute()) {
                    $cleanedCount++;
                }
            }
        }
        
        if ($cleanedCount > 0) {
            error_log("Cleaned up $cleanedCount expired deadline notifications");
        }
        
    } catch (Exception $e) {
        error_log("Error cleaning up expired deadline notifications: " . $e->getMessage());
    }
}

/**
 * Check if a deadline reminder notification already exists for a document
 * Prevents duplicate notifications for the same document
 * 
 * @param int $documentId Document ID
 * @param string $documentType Document type
 * @param int $userId User ID
 * @return bool True if notification already exists
 */
function deadlineReminderExists($documentId, $documentType, $userId) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE type = 'deadline_reminder_1hour' 
            AND related_id = ? 
            AND related_type = ? 
            AND user_id = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ");
        $stmt->bind_param("isi", $documentId, $documentType, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking deadline reminder existence: " . $e->getMessage());
        return false;
    }
}

/**
 * Get active deadline reminders for a user
 * Returns all current deadline reminders that haven't expired
 * 
 * @param int $userId User ID
 * @return array Array of active deadline reminders
 */
function getActiveDeadlineReminders($userId) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT n.*, 
                   CASE 
                       WHEN n.related_type = 'organization_document' THEN od.document_type
                       WHEN n.related_type = 'event_document' THEN ed.document_type
                       WHEN n.related_type = 'council_document' THEN cd.document_type
                   END as document_type,
                   CASE 
                       WHEN n.related_type = 'organization_document' THEN od.resubmission_deadline
                       WHEN n.related_type = 'event_document' THEN ed.resubmission_deadline
                       WHEN n.related_type = 'council_document' THEN cd.resubmission_deadline
                   END as deadline
            FROM notifications n
            LEFT JOIN organization_documents od ON n.related_type = 'organization_document' AND n.related_id = od.id
            LEFT JOIN event_documents ed ON n.related_type = 'event_document' AND n.related_id = ed.id
            LEFT JOIN council_documents cd ON n.related_type = 'council_document' AND n.related_id = cd.id
            WHERE n.type = 'deadline_reminder' 
            AND n.user_id = ?
            AND n.is_read = FALSE
            ORDER BY n.created_at DESC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active deadline reminders: " . $e->getMessage());
        return [];
    }
}

?>

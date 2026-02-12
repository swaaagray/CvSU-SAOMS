<?php
require_once __DIR__ . '/../config/database.php';

// Try to load email notifications, but don't fail if it doesn't exist
if (file_exists(__DIR__ . '/president_email_notifications.php')) {
    require_once __DIR__ . '/president_email_notifications.php';
}

/**
 * Create a new notification
 * 
 * @param int $user_id User ID to receive notification
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type
 * @param int|null $related_id Related item ID
 * @param string|null $related_type Related item type
 * @param array $additional_data Additional data for email notifications
 * @param int|null $academic_year_id Academic year ID for document notifications
 * @return bool True if notification created successfully
 */
function createNotification($user_id, $title, $message, $type, $related_id = null, $related_type = null, $additional_data = [], $academic_year_id = null) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, type, related_id, related_type, academic_year_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssi", $user_id, $title, $message, $type, $related_id, $related_type, $academic_year_id);
        
        $success = $stmt->execute();
        
        // If notification was created successfully, check if user is a president and send email
        if ($success) {
            try {
                // Check if the user is a president
                $stmt = $conn->prepare("
                    SELECT role FROM users WHERE id = ?
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
                
                if ($user && in_array($user['role'], ['org_president', 'council_president'])) {
                    // Send email notification to president (with error handling)
                    if (function_exists('sendPresidentEmailNotification')) {
                        sendPresidentEmailNotification($user_id, $title, $message, $type, $related_id, $related_type, $additional_data);
                    }
                }
            } catch (Exception $e) {
                // Log email error but don't fail the notification creation
                error_log("Email notification error (notification still created): " . $e->getMessage());
            }
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notifications count for a user
 * 
 * @param int $user_id User ID
 * @return int Number of unread notifications
 */
function getUnreadNotificationCount($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting unread notification count: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get notifications for a user
 * 
 * @param int $user_id User ID
 * @param int $limit Number of notifications to return
 * @param bool $unread_only Whether to return only unread notifications
 * @return array Array of notifications
 */
function getNotifications($user_id, $limit = 10, $unread_only = false) {
    global $conn;
    
    try {
        $safeLimit = max(1, (int)$limit);
        $sql = "SELECT id, user_id, title, message, type, related_id, related_type, is_read, created_at, academic_year_id FROM notifications WHERE user_id = ?";
        if ($unread_only) {
            $sql .= " AND is_read = 0";
        }
        $sql .= " ORDER BY created_at DESC LIMIT $safeLimit";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("getNotifications prepare failed: " . $conn->error);
            return [];
        }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            error_log("getNotifications execute failed: " . $stmt->error);
            return [];
        }
        // Use bind_result to avoid mysqlnd dependency for get_result
        $stmt->bind_result(
            $id,
            $uid,
            $title,
            $message,
            $type,
            $related_id,
            $related_type,
            $is_read,
            $created_at,
            $academic_year_id
        );
        $rows = [];
        while ($stmt->fetch()) {
            $rows[] = [
                'id' => $id,
                'user_id' => $uid,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'related_id' => $related_id,
                'related_type' => $related_type,
                'is_read' => (bool)$is_read,
                'created_at' => $created_at,
                'academic_year_id' => $academic_year_id
            ];
        }
        $stmt->close();
        return $rows;
    } catch (Exception $e) {
        error_log("Error getting notifications: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 * 
 * @param int $notification_id Notification ID
 * @param int $user_id User ID (for security)
 * @return bool True if marked as read successfully
 */
function markNotificationAsRead($notification_id, $user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $notification_id, $user_id);
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error marking notification as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for a user
 * 
 * @param int $user_id User ID
 * @return bool True if marked as read successfully
 */
function markAllNotificationsAsRead($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error marking all notifications as read: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete old notifications (older than 30 days)
 * 
 * @return bool True if cleanup successful
 */
function cleanupOldNotifications() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error cleaning up old notifications: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for document approval/rejection by OSAS
 * 
 * @param int $organization_id Organization ID
 * @param string $action 'approved' or 'rejected'
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyDocumentAction($organization_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get organization president and adviser
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return false;
        
        // Get organization name first
        $org_stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
        $org_stmt->bind_param("i", $organization_id);
        $org_stmt->execute();
        $org_data = $org_stmt->get_result()->fetch_assoc();
        $org_name = $org_data['org_name'] ?? 'Organization';

        // Convert document type to a professional, readable label
        $documentTypeLabels = [
            'adviser_resume' => 'Adviser Resume',
            'student_profile' => 'Student Profile',
            'officers_list' => 'Officers List',
            'calendar_activities' => 'Calendar of Activities',
            'official_logo' => 'Official Logo',
            'officers_grade' => 'Official Grade',
            'group_picture' => 'Group Picture',
            'constitution_bylaws' => 'Constitution & Bylaws',
            'members_list' => 'Members List',
            'good_moral' => 'Good Moral Certificate',
            'adviser_acceptance' => 'Adviser Acceptance',
            'budget_resolution' => 'Budget Resolution',
            'accomplishment_report' => 'Accomplishment Report',
            'previous_plan_of_activities' => 'Previous Plan of Activities',
            'financial_report' => 'Financial Report'
        ];
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));

        $type = $action === 'approved' ? 'document_approved' : 'document_rejected';
        $title = "Document " . ucfirst($action);
        
        // Fetch deadline and rejection reason if document is rejected
        $deadline = null;
        $rejection_reason = null;
        if ($action === 'rejected') {
            $deadline_stmt = $conn->prepare("SELECT resubmission_deadline, rejection_reason FROM organization_documents WHERE id = ?");
            $deadline_stmt->bind_param("i", $document_id);
            $deadline_stmt->execute();
            $doc_info = $deadline_stmt->get_result()->fetch_assoc();
            $deadline_stmt->close();
            
            if ($doc_info) {
                $deadline = $doc_info['resubmission_deadline'];
                $rejection_reason = $doc_info['rejection_reason'];
            }
        }
        
        // Build message with deadline information if available
        if ($action === 'approved') {
            $message = "The {$documentTypeLabel} document for {$org_name} has been approved by OSAS.";
        } else {
            $deadline_text = '';
            if ($deadline) {
                $formatted_deadline = date('M d, Y g:i A', strtotime($deadline));
                $deadline_text = " You must resubmit this document by {$formatted_deadline}.";
            }
            $message = "The {$documentTypeLabel} document for {$org_name} has been rejected by OSAS.{$deadline_text} Please review the feedback and resubmit once the necessary revisions have been made.";
        }
        
        $success = true;
        
        // Get current academic year ID for document notifications
        $academic_year_id = getCurrentAcademicYearId();
        
        // Notify president
        if ($org['president_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'organization_name' => $org_name
            ];
            
            // Add deadline and rejection reason if available
            if ($action === 'rejected') {
                if ($deadline) {
                    $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                    $additional_data['deadline_raw'] = $deadline;
                }
                if ($rejection_reason) {
                    $additional_data['rejection_reason'] = $rejection_reason;
                }
            }
            
            $success &= createNotification(
                $org['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'document',
                $additional_data,
                $academic_year_id
            );
        }
        
        // Notify adviser
        if ($org['adviser_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'organization_name' => $org_name
            ];
            
            // Add deadline and rejection reason if available
            if ($action === 'rejected') {
                if ($deadline) {
                    $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                    $additional_data['deadline_raw'] = $deadline;
                }
                if ($rejection_reason) {
                    $additional_data['rejection_reason'] = $rejection_reason;
                }
            }
            
            $success &= createNotification(
                $org['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'document',
                $additional_data,
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for document approval/rejection by Adviser
 * 
 * @param int $organization_id Organization ID
 * @param string $action 'approved' or 'rejected'
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyAdviserDocumentAction($organization_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get organization president and name
        $stmt = $conn->prepare("
            SELECT president_id, org_name 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return false;
        
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
            'accomplishment_report' => 'Accomplishment Report',
            'previous_plan_of_activities' => 'Previous Plan of Activities',
            'financial_report' => 'Financial Report'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        $org_name = $org['org_name'] ?? 'Organization';
        
        $type = $action === 'approved' ? 'document_approved' : 'document_rejected';
        $title = "Document " . ucfirst($action) . " by Adviser: $documentTypeLabel";
        $message = "Your $documentTypeLabel for {$org_name} has been $action by your organization adviser and forwarded to OSAS for final review. " . ($action === 'approved' ? "Great job! Your adviser has approved this document and it's now in the hands of OSAS for final approval." : "Your adviser has requested some changes. Please review the feedback and resubmit your document.");
        
        $success = true;
        
        // Get current academic year ID for document notifications
        $academic_year_id = getCurrentAcademicYearId();
        
        // Notify president
        if ($org['president_id']) {
            $success &= createNotification(
                $org['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'document',
                [],
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating adviser document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when president submits a document
 * 
 * @param int $organization_id Organization ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyDocumentSubmission($organization_id, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get organization adviser
        $stmt = $conn->prepare("
            SELECT adviser_id 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org || !$org['adviser_id']) return false;
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'constitution_bylaws' => 'Constitution & Bylaws',
            'officers_list' => 'Officers List',
            'calendar_activities' => 'Calendar of Activities',
            'official_logo' => 'Official Logo',
            'members_list' => 'Members List',
            'budget_resolution' => 'Budget Resolution',
            'accomplishment_report' => 'Accomplishment Report',
            'previous_plan_of_activities' => 'Previous Plan of Activities',
            'financial_report' => 'Financial Report'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));

        // Pull organization name for better context in notifications
        $org_name = 'Organization';
        $org_stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
        if ($org_stmt) {
            $org_stmt->bind_param("i", $organization_id);
            if ($org_stmt->execute()) {
                $org_row = $org_stmt->get_result()->fetch_assoc();
                if (!empty($org_row['org_name'])) { $org_name = $org_row['org_name']; }
            }
            $org_stmt->close();
        }

        $title = "New Document Submitted: $documentTypeLabel";
        $message = "$org_name A new $documentTypeLabel has been submitted by the organization president for your review. Please review the document and provide feedback or approval as needed.";
        
        // Get current academic year ID for document notifications
        $academic_year_id = getCurrentAcademicYearId();
        
        $success = true;
        
        // Notify adviser
        $success &= createNotification(
            $org['adviser_id'], 
            $title, 
            $message, 
            'document_submitted', 
            $document_id, 
            'document',
            [],
            $academic_year_id
        );

        // OSAS will only be notified when documents are sent to them (after adviser approval)
        // via notifyDocumentsSentToOSAS() function

        return (bool)$success;
    } catch (Exception $e) {
        error_log("Error creating document submission notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when all council documents are approved by adviser and sent to OSAS
 * 
 * @param int $council_id Council ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilDocumentsSentToOSAS($council_id) {
    global $conn;
    
    try {
        // Get council president and all OSAS users
        $stmt = $conn->prepare("
            SELECT president_id 
            FROM councils 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        $success = true;
        
        // Notify OSAS users
        $osas_title = "New Council Documents for Review";
        $osas_message = "Council documents have been approved by adviser and are ready for OSAS review.";
        
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $osas_title, 
                $osas_message, 
                'council_documents_for_review', 
                $council_id, 
                'council'
            );
        }
        
        // President notifications are now handled individually for each document approval/rejection
        // No bulk notification needed since individual notifications are sent per document
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS council notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify OSAS when an individual council document is sent to them by adviser
 * This is called immediately when an adviser approves a document, not waiting for all documents
 * 
 * @param int $council_id Council ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyIndividualCouncilDocumentSentToOSAS($council_id, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        // Get council name
        $council_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
        $council_stmt->bind_param("i", $council_id);
        $council_stmt->execute();
        $council_data = $council_stmt->get_result()->fetch_assoc();
        $council_name = $council_data['council_name'] ?? 'Council';
        
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
            'other' => 'Other Documents'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $title = "New Council Document for Review: $documentTypeLabel";
        $message = "A $documentTypeLabel document from {$council_name} has been approved by the council adviser and is now ready for OSAS review.";
        
        $success = true;
        $academic_year_id = getCurrentAcademicYearId();
        
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $title, 
                $message, 
                'council_document_sent_to_osas', 
                $document_id, 
                'council_document',
                [],
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating individual OSAS council document notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when all documents are approved by adviser and sent to OSAS
 * 
 * @param int $organization_id Organization ID
 * @return bool True if notifications created successfully
 */
function notifyDocumentsSentToOSAS($organization_id) {
    global $conn;
    
    try {
        // Get organization president and all OSAS users
        $stmt = $conn->prepare("
            SELECT president_id 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        $success = true;
        
        // Notify OSAS users
        $osas_title = "New Documents for Review";
        $osas_message = "Organization documents have been approved by adviser and are ready for OSAS review.";
        
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $osas_title, 
                $osas_message, 
                'documents_for_review', 
                $organization_id, 
                'organization'
            );
        }
        
        // President notifications are now handled individually for each document approval/rejection
        // No bulk notification needed since individual notifications are sent per document
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify OSAS when an individual document is sent to them by adviser
 * This is called immediately when an adviser approves a document, not waiting for all documents
 * 
 * @param int $organization_id Organization ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyIndividualDocumentSentToOSAS($organization_id, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        // Get organization name
        $org_stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
        $org_stmt->bind_param("i", $organization_id);
        $org_stmt->execute();
        $org_data = $org_stmt->get_result()->fetch_assoc();
        $org_name = $org_data['org_name'] ?? 'Organization';
        
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
            'accomplishment_report' => 'Accomplishment Report',
            'previous_plan_of_activities' => 'Previous Plan of Activities',
            'financial_report' => 'Financial Report'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $title = "New Document for Review: $documentTypeLabel";
        $message = "A $documentTypeLabel document from {$org_name} has been approved by the adviser and is now ready for OSAS review.";
        
        $success = true;
        $academic_year_id = getCurrentAcademicYearId();
        
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $title, 
                $message, 
                'document_sent_to_osas', 
                $document_id, 
                'document',
                [],
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating individual OSAS document notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for event approval/rejection by OSAS
 * 
 * @param int $organization_id Organization ID
 * @param string $action 'approved' or 'rejected'
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyEventAction($organization_id, $action, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get organization president, adviser, and name
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id, org_name 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return false;
        
        $org_name = $org['org_name'] ?? 'Organization';
        $type = $action === 'approved' ? 'event_approved' : 'event_rejected';
        $title = "Event " . ucfirst($action);
        $message = "Your event '$event_title' for {$org_name} has been $action by OSAS. " . ($action === 'approved' ? "Congratulations! Your event is now approved and you can proceed with your planned activities." : "Please review the feedback and make the necessary adjustments before resubmitting your event proposal.");
        
        $success = true;
        
        // Notify president
        if ($org['president_id']) {
            $success &= createNotification(
                $org['president_id'], 
                $title, 
                $message, 
                $type, 
                $event_id, 
                'event',
                []
            );
        }
        
        // Notify adviser
        if ($org['adviser_id']) {
            $success &= createNotification(
                $org['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $event_id, 
                'event',
                []
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating event action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for event document rejection by OSAS
 * 
 * @param int $organization_id Organization ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @param string $event_title Event title
 * @return bool True if notifications created successfully
 */
function notifyOSASEventDocumentAction($organization_id, $document_type, $document_id, $event_title) {
    global $conn;
    
    try {
        // Get organization president and adviser
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id, org_name 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return false;
        
        $org_name = $org['org_name'] ?? 'Organization';
        
        // Fetch deadline and rejection reason from event document
        $deadline_stmt = $conn->prepare("SELECT resubmission_deadline, rejection_reason FROM event_documents WHERE id = ?");
        $deadline_stmt->bind_param("i", $document_id);
        $deadline_stmt->execute();
        $doc_info = $deadline_stmt->get_result()->fetch_assoc();
        $deadline_stmt->close();
        
        $deadline = $doc_info['resubmission_deadline'] ?? null;
        $rejection_reason = $doc_info['rejection_reason'] ?? null;
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $type = 'event_document_rejected';
        $title = "Event Document Rejected";
        
        // Build message with deadline information if available
        $deadline_text = '';
        if ($deadline) {
            $formatted_deadline = date('M d, Y g:i A', strtotime($deadline));
            $deadline_text = " You must resubmit this document by {$formatted_deadline}.";
        }
        $message = "Your event document '$documentTypeLabel' for event '$event_title' ({$org_name}) has been rejected by OSAS.{$deadline_text} Please review the feedback and resubmit once the necessary revisions have been made.";
        
        $success = true;
        
        // Get current academic year ID
        $academic_year_id = getCurrentAcademicYearId();
        
        // Notify president
        if ($org['president_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'organization_name' => $org_name,
                'event_title' => $event_title
            ];
            
            // Add deadline and rejection reason if available
            if ($deadline) {
                $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                $additional_data['deadline_raw'] = $deadline;
            }
            if ($rejection_reason) {
                $additional_data['rejection_reason'] = $rejection_reason;
            }
            
            $success &= createNotification(
                $org['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'event_document',
                $additional_data,
                $academic_year_id
            );
        }
        
        // Notify adviser
        if ($org['adviser_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'organization_name' => $org_name,
                'event_title' => $event_title
            ];
            
            // Add deadline and rejection reason if available
            if ($deadline) {
                $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                $additional_data['deadline_raw'] = $deadline;
            }
            if ($rejection_reason) {
                $additional_data['rejection_reason'] = $rejection_reason;
            }
            
            $success &= createNotification(
                $org['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'event_document',
                $additional_data,
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS event document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for event document approval/rejection by Adviser
 * 
 * @param int $organization_id Organization ID
 * @param string $action 'approved' or 'rejected'
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyAdviserEventAction($organization_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get organization president
        $stmt = $conn->prepare("
            SELECT president_id 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return false;
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $type = $action === 'approved' ? 'event_document_approved' : 'event_document_rejected';
        $title = "Event Document " . ucfirst($action) . " by Adviser: $documentTypeLabel";
        $message = "Your event document '$documentTypeLabel' for {$org_name} has been $action by your organization adviser. " . ($action === 'approved' ? "Your adviser has approved this event document and it's ready for the next step in the approval process." : "Your adviser has requested some changes to this event document. Please review the feedback and make the necessary corrections.");
        
        $success = true;
        
        // Get event_approval_id for the notification
        $stmt = $conn->prepare("
            SELECT event_approval_id 
            FROM event_documents 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $document_id);
        $stmt->execute();
        $eventDoc = $stmt->get_result()->fetch_assoc();
        
        $event_approval_id = $eventDoc ? $eventDoc['event_approval_id'] : $document_id;
        
        // Notify president
        if ($org['president_id']) {
            $success &= createNotification(
                $org['president_id'], 
                $title, 
                $message, 
                $type, 
                $event_approval_id, 
                'event_document'
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating adviser event action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when council adviser approves/rejects an event document
 * 
 * @param int $council_id Council ID
 * @param string $action Action taken (approved/rejected)
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilAdviserEventAction($council_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get council president
        $stmt = $conn->prepare("
            SELECT president_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) return false;
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $type = $action === 'approved' ? 'event_document_approved' : 'event_document_rejected';
        $title = "Event Document " . ucfirst($action) . " by Council Adviser: $documentTypeLabel";
        $message = "Your event document '$documentTypeLabel' for {$org_name} has been $action by your council adviser. " . ($action === 'approved' ? "Your council adviser has approved this event document and it's ready for the next step in the approval process." : "Your council adviser has requested some changes to this event document. Please review the feedback and make the necessary corrections.");
        
        $success = true;
        
        // Get event_approval_id for the notification
        $stmt = $conn->prepare("
            SELECT event_approval_id 
            FROM event_documents 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $document_id);
        $stmt->execute();
        $eventDoc = $stmt->get_result()->fetch_assoc();
        
        $event_approval_id = $eventDoc ? $eventDoc['event_approval_id'] : $document_id;
        
        // Notify council president
        if ($council['president_id']) {
            $success &= createNotification(
                $council['president_id'], 
                $title, 
                $message, 
                $type, 
                $event_approval_id, 
                'event_document'
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating council adviser event action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when president submits an event proposal
 * 
 * @param int $organization_id Organization ID
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyEventSubmission($organization_id, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get organization adviser
        $stmt = $conn->prepare("
            SELECT adviser_id 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org || !$org['adviser_id']) return false;
        
        // Get event documents to include in the message
        $stmt = $conn->prepare("
            SELECT document_type 
            FROM event_documents 
            WHERE event_approval_id = ?
        ");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $eventDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Convert document types to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        
        $documentTypes = [];
        foreach ($eventDocs as $doc) {
            $docType = $documentTypeLabels[$doc['document_type']] ?? ucfirst(str_replace('_', ' ', $doc['document_type']));
            $documentTypes[] = $docType;
        }
        
        $title = "New Event Proposal Submitted: $event_title";
        
        if (!empty($documentTypes)) {
            $docList = implode(', ', $documentTypes);
            $message = "A new event proposal '$event_title' has been submitted by the organization president with the following documents: $docList. Please review the proposal and all supporting documents to provide your feedback or approval.";
        } else {
            $message = "A new event proposal '$event_title' has been submitted by the organization president for your review. Please review the proposal and provide your feedback or approval.";
        }
        
        return createNotification(
            $org['adviser_id'], 
            $title, 
            $message, 
            'event_submitted', 
            $event_id, 
            'event',
            []
        );
    } catch (Exception $e) {
        error_log("Error creating event submission notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when a council event proposal is submitted
 * 
 * @param int $council_id Council ID
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilEventSubmission($council_id, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get council adviser
        $stmt = $conn->prepare("
            SELECT adviser_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council || !$council['adviser_id']) return false;
        
        // Get event documents to include in the message
        $stmt = $conn->prepare("
            SELECT document_type 
            FROM event_documents 
            WHERE event_approval_id = ?
        ");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $eventDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Convert document types to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        
        $documentTypes = [];
        foreach ($eventDocs as $doc) {
            $docType = $documentTypeLabels[$doc['document_type']] ?? ucfirst(str_replace('_', ' ', $doc['document_type']));
            $documentTypes[] = $docType;
        }
        
        $title = "New Council Event Proposal Submitted: $event_title";
        
        if (!empty($documentTypes)) {
            $docList = implode(', ', $documentTypes);
            $message = "A new council event proposal '$event_title' has been submitted by the council president with the following documents: $docList. Please review the proposal and all supporting documents to provide your feedback or approval.";
        } else {
            $message = "A new council event proposal '$event_title' has been submitted by the council president for your review. Please review the proposal and provide your feedback or approval.";
        }
        
        return createNotification(
            $council['adviser_id'], 
            $title, 
            $message, 
            'event_submitted', 
            $event_id, 
            'event'
        );
    } catch (Exception $e) {
        error_log("Error creating council event submission notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when council event documents are approved by adviser and sent to OSAS
 * 
 * @param int $council_id Council ID
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilEventDocumentsSentToOSAS($council_id, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        $title = "New Council Event Documents for Review";
        $message = "Council event documents for '$event_title' have been approved by the council adviser and are now ready for OSAS review. The event proposal is progressing well through the approval process.";
        
        $success = true;
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $title, 
                $message, 
                'council_event_documents_for_review', 
                $event_id, 
                'event'
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS council event notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when all event documents are approved by adviser and sent to OSAS
 * 
 * @param int $organization_id Organization ID
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyEventDocumentsSentToOSAS($organization_id, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get all OSAS users
        $stmt = $conn->prepare("
            SELECT id 
            FROM users 
            WHERE role = 'osas'
        ");
        $stmt->execute();
        $osas_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        if (empty($osas_users)) return false;
        
        $title = "New Event Documents for Review";
        $message = "Event documents for '$event_title' have been approved by the organization adviser and are now ready for OSAS review. The event proposal is progressing well through the approval process.";
        
        $success = true;
        foreach ($osas_users as $user) {
            $success &= createNotification(
                $user['id'], 
                $title, 
                $message, 
                'event_documents_for_review', 
                $event_id, 
                'event'
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS event notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Get time ago string
 */
function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return date('g:i A', $time);
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' min ago (' . date('g:i A', $time) . ')';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hr ago (' . date('g:i A', $time) . ')';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago (' . date('M j, g:i A', $time) . ')';
    } else {
        // Show actual date and time for older notifications
        return date('M j, Y g:i A', $time);
    }
}

/**
 * Get notification icon based on type
 */
function getNotificationIcon($type) {
    switch ($type) {
        case 'document_approved':
            return 'fas fa-check-circle text-success';
        case 'document_rejected':
            return 'fas fa-times-circle text-danger';
        case 'event_approved':
            return 'fas fa-calendar-check text-success';
        case 'event_rejected':
            return 'fas fa-calendar-times text-danger';
        case 'document_submitted':
            return 'fas fa-upload text-info';
        case 'event_submitted':
            return 'fas fa-calendar-plus text-info';
        case 'documents_for_review':
            return 'fas fa-file-alt text-warning';
        case 'event_documents_for_review':
            return 'fas fa-calendar-alt text-warning';
        case 'event_document_approved':
            return 'fas fa-check-circle text-success';
        case 'event_document_rejected':
            return 'fas fa-times-circle text-danger';
        case 'documents_sent_to_osas':
            return 'fas fa-paper-plane text-info';
        case 'document_sent_to_osas':
            return 'fas fa-file-alt text-info';
        case 'event_documents_sent_to_osas':
            return 'fas fa-calendar-alt text-info';
        case 'deadline_reminder':
            return 'fas fa-clock text-danger';
        case 'council_documents_for_review':
            return 'fas fa-file-alt text-warning';
        case 'council_event_documents_for_review':
            return 'fas fa-calendar-alt text-warning';
        case 'council_documents_sent_to_osas':
            return 'fas fa-paper-plane text-info';
        case 'council_document_sent_to_osas':
            return 'fas fa-file-alt text-info';
        case 'council_event_documents_sent_to_osas':
            return 'fas fa-calendar-alt text-info';
        case 'council_document_approved':
            return 'fas fa-check-circle text-success';
        case 'council_document_rejected':
            return 'fas fa-times-circle text-danger';
        case 'council_event_approved':
            return 'fas fa-calendar-check text-success';
        case 'council_event_rejected':
            return 'fas fa-calendar-times text-danger';
        default:
            return 'fas fa-bell text-primary';
    }
}

/**
 * Enforce notification cleanup based on current semester and academic year states.
 * - Event notifications: delete if semester_id IS NULL or semester archived
 * - Organization/council document notifications: delete if academic_year_id IS NULL or year archived
 */
function enforceNotificationCleanup() {
    global $conn;
    try {
        // Event-related notification types
        $event_types = [
            'event_approved',
            'event_rejected',
            'event_submitted',
            'event_documents_for_review',
            'event_document_approved',
            'event_document_rejected'
        ];

        // Document-related notification types
        $document_types = [
            'document_approved',
            'document_rejected',
            'document_submitted',
            'documents_for_review'
        ];

        // 1) No semester-based cleanup anymore (semester_id removed)
        $eventTypesList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $event_types)) . "'";

        // 3) Before deleting notifications, delete associated document files
        //    a) Files linked to notifications where academic_year_id IS NULL
        $docTypesList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $document_types)) . "'";

		// Delete organization document files for NULL academic_year_id notifications
		if ($result = $conn->query(
			"SELECT DISTINCT od.file_path " .
			"FROM notifications n " .
			"INNER JOIN organization_documents od ON n.related_id = od.id " .
			"AND n.related_type IN ('document','organization_document') " .
			"WHERE n.academic_year_id IS NULL " .
			"AND n.type IN ($docTypesList)"
		)) {
            while ($row = $result->fetch_assoc()) {
                $path = $row['file_path'] ?? '';
                if (!empty($path) && file_exists($path)) { @unlink($path); }
            }
        }

		// Delete council document files for NULL academic_year_id notifications
		if ($result = $conn->query(
			"SELECT DISTINCT cd.file_path " .
			"FROM notifications n " .
			"INNER JOIN council_documents cd ON n.related_id = cd.id " .
			"AND n.related_type = 'council_document' " .
			"WHERE n.academic_year_id IS NULL " .
			"AND n.type IN ($docTypesList)"
		)) {
            while ($row = $result->fetch_assoc()) {
                $path = $row['file_path'] ?? '';
                if (!empty($path) && file_exists($path)) { @unlink($path); }
            }
        }

		//    b) Files linked to notifications whose academic year is archived
		if ($result = $conn->query(
			"SELECT DISTINCT od.file_path " .
			"FROM notifications n " .
			"INNER JOIN academic_terms t ON t.id = n.academic_year_id AND t.status = 'archived' " .
			"INNER JOIN organization_documents od ON n.related_id = od.id " .
			"AND n.related_type IN ('document','organization_document') " .
			"WHERE n.type IN ($docTypesList)"
		)) {
			while ($row = $result->fetch_assoc()) {
				$path = $row['file_path'] ?? '';
				if (!empty($path) && file_exists($path)) { @unlink($path); }
			}
		}

		if ($result = $conn->query(
			"SELECT DISTINCT cd.file_path " .
			"FROM notifications n " .
			"INNER JOIN academic_terms t ON t.id = n.academic_year_id AND t.status = 'archived' " .
			"INNER JOIN council_documents cd ON n.related_id = cd.id " .
			"AND n.related_type = 'council_document' " .
			"WHERE n.type IN ($docTypesList)"
		)) {
            while ($row = $result->fetch_assoc()) {
                $path = $row['file_path'] ?? '';
                if (!empty($path) && file_exists($path)) { @unlink($path); }
            }
        }

        // 4) Delete document notifications where academic_year_id IS NULL
        $conn->query("DELETE FROM notifications WHERE academic_year_id IS NULL AND type IN ($docTypesList)");

        // 5) Delete document notifications for archived academic years
        $conn->query("DELETE n FROM notifications n INNER JOIN academic_terms t ON t.id = n.academic_year_id WHERE t.status = 'archived' AND n.type IN ($docTypesList)");
    } catch (Exception $e) {
        error_log('enforceNotificationCleanup error: ' . $e->getMessage());
    }
}


/**
 * Create notification for council document approval/rejection by OSAS
 * 
 * @param int $council_id Council ID
 * @param string $action 'approved' or 'rejected'
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilDocumentAction($council_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get council president and adviser
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) return false;
        
        // Get council name first
        $council_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
        $council_stmt->bind_param("i", $council_id);
        $council_stmt->execute();
        $council_data = $council_stmt->get_result()->fetch_assoc();
        $council_name = $council_data['council_name'] ?? 'Council';

        // Convert council document type to a professional, readable label
        $councilDocumentTypeLabels = [
            'adviser_resume' => 'Adviser Resume',
            'student_profile' => 'Student Profile',
            'officers_list' => 'Officers List',
            'calendar_activities' => 'Calendar of Activities',
            'official_logo' => 'Official Logo',
            'officers_grade' => 'Official Grade',
            'group_picture' => 'Group Picture',
            'constitution_bylaws' => 'Constitution & Bylaws',
            'members_list' => 'Members List',
            'good_moral' => 'Good Moral Certificate',
            'adviser_acceptance' => 'Adviser Acceptance',
            'budget_resolution' => 'Budget Resolution',
            'other' => 'Document'
        ];
        $councilDocumentTypeLabel = $councilDocumentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));

        $type = $action === 'approved' ? 'council_document_approved' : 'council_document_rejected';
        $title = "Council Document " . ucfirst($action);
        
        // Fetch deadline and rejection reason if document is rejected
        $deadline = null;
        $rejection_reason = null;
        if ($action === 'rejected') {
            $deadline_stmt = $conn->prepare("SELECT resubmission_deadline, reject_reason FROM council_documents WHERE id = ?");
            $deadline_stmt->bind_param("i", $document_id);
            $deadline_stmt->execute();
            $doc_info = $deadline_stmt->get_result()->fetch_assoc();
            $deadline_stmt->close();
            
            if ($doc_info) {
                $deadline = $doc_info['resubmission_deadline'];
                $rejection_reason = $doc_info['reject_reason'];
            }
        }
        
        // Build message with deadline information if available
        if ($action === 'approved') {
            $message = "The {$councilDocumentTypeLabel} document for {$council_name} has been approved by OSAS.";
        } else {
            $deadline_text = '';
            if ($deadline) {
                $formatted_deadline = date('M d, Y g:i A', strtotime($deadline));
                $deadline_text = " You must resubmit this document by {$formatted_deadline}.";
            }
            $message = "The {$councilDocumentTypeLabel} document for {$council_name} has been rejected by OSAS.{$deadline_text} Please review the feedback and resubmit once the necessary revisions have been made.";
        }
        
        $success = true;
        
        // Get current academic year ID for document notifications
        $academic_year_id = getCurrentAcademicYearId();
        
        // Notify president
        if ($council['president_id']) {
            $additional_data = [];
            
            // Add deadline and rejection reason if available
            if ($action === 'rejected') {
                if ($deadline) {
                    $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                    $additional_data['deadline_raw'] = $deadline;
                }
                if ($rejection_reason) {
                    $additional_data['rejection_reason'] = $rejection_reason;
                }
            }
            
            $success &= createNotification(
                $council['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'council_document',
                $additional_data,
                null,
                $academic_year_id
            );
        }
        
        // Notify adviser
        if ($council['adviser_id']) {
            $additional_data = [];
            
            // Add deadline and rejection reason if available
            if ($action === 'rejected') {
                if ($deadline) {
                    $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                    $additional_data['deadline_raw'] = $deadline;
                }
                if ($rejection_reason) {
                    $additional_data['rejection_reason'] = $rejection_reason;
                }
            }
            
            $success &= createNotification(
                $council['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'council_document',
                $additional_data,
                null,
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating council document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for council event document rejection by OSAS
 * 
 * @param int $council_id Council ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @param string $event_title Event title
 * @return bool True if notifications created successfully
 */
function notifyOSASCouncilEventDocumentAction($council_id, $document_type, $document_id, $event_title) {
    global $conn;
    
    try {
        // Get council president and adviser
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id, council_name 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) return false;
        
        $council_name = $council['council_name'] ?? 'Council';
        
        // Fetch deadline and rejection reason from event document
        $deadline_stmt = $conn->prepare("SELECT resubmission_deadline, rejection_reason FROM event_documents WHERE id = ?");
        $deadline_stmt->bind_param("i", $document_id);
        $deadline_stmt->execute();
        $doc_info = $deadline_stmt->get_result()->fetch_assoc();
        $deadline_stmt->close();
        
        $deadline = $doc_info['resubmission_deadline'] ?? null;
        $rejection_reason = $doc_info['rejection_reason'] ?? null;
        
        // Convert document type to readable format
        $documentTypeLabels = [
            'activity_proposal' => 'Activity Proposal',
            'letter_venue_equipment' => 'Letter for Venue & Equipment',
            'cv_speakers' => 'CV of Speakers',
            'other' => 'Other Documents'
        ];
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        $type = 'council_event_document_rejected';
        $title = "Council Event Document Rejected";
        
        // Build message with deadline information if available
        $deadline_text = '';
        if ($deadline) {
            $formatted_deadline = date('M d, Y g:i A', strtotime($deadline));
            $deadline_text = " You must resubmit this document by {$formatted_deadline}.";
        }
        $message = "Your council event document '$documentTypeLabel' for event '$event_title' ({$council_name}) has been rejected by OSAS.{$deadline_text} Please review the feedback and resubmit once the necessary revisions have been made.";
        
        $success = true;
        
        // Get current academic year ID
        $academic_year_id = getCurrentAcademicYearId();
        
        // Notify president
        if ($council['president_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'council_name' => $council_name,
                'event_title' => $event_title
            ];
            
            // Add deadline and rejection reason if available
            if ($deadline) {
                $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                $additional_data['deadline_raw'] = $deadline;
            }
            if ($rejection_reason) {
                $additional_data['rejection_reason'] = $rejection_reason;
            }
            
            $success &= createNotification(
                $council['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'council_event_document',
                $additional_data,
                null,
                $academic_year_id
            );
        }
        
        // Notify adviser
        if ($council['adviser_id']) {
            $additional_data = [
                'document_type' => $document_type,
                'council_name' => $council_name,
                'event_title' => $event_title
            ];
            
            // Add deadline and rejection reason if available
            if ($deadline) {
                $additional_data['deadline'] = date('M d, Y g:i A', strtotime($deadline));
                $additional_data['deadline_raw'] = $deadline;
            }
            if ($rejection_reason) {
                $additional_data['rejection_reason'] = $rejection_reason;
            }
            
            $success &= createNotification(
                $council['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'council_event_document',
                $additional_data,
                null,
                $academic_year_id
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating OSAS council event document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for council event approval/rejection by OSAS
 * 
 * @param int $council_id Council ID
 * @param string $action 'approved' or 'rejected'
 * @param string $event_title Event title
 * @param int $event_id Event ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilEventAction($council_id, $action, $event_title, $event_id) {
    global $conn;
    
    try {
        // Get council president and adviser
        $stmt = $conn->prepare("
            SELECT president_id, adviser_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) return false;
        
        // Get council name first
        $council_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
        $council_stmt->bind_param("i", $council_id);
        $council_stmt->execute();
        $council_data = $council_stmt->get_result()->fetch_assoc();
        $council_name = $council_data['council_name'] ?? 'Council';
        
        $type = $action === 'approved' ? 'council_event_approved' : 'council_event_rejected';
        $title = "Council Event " . ucfirst($action);
        $message = "Your council event '$event_title' for {$council_name} has been $action by OSAS. " . ($action === 'approved' ? "Congratulations! Your council event is now approved and you can proceed with your planned activities." : "Please review the feedback and make the necessary adjustments before resubmitting your council event proposal.");
        
        $success = true;
        
        // Notify president
        if ($council['president_id']) {
            $success &= createNotification(
                $council['president_id'], 
                $title, 
                $message, 
                $type, 
                $event_id, 
                'council_event',
                []
            );
        }
        
        // Notify adviser
        if ($council['adviser_id']) {
            $success &= createNotification(
                $council['adviser_id'], 
                $title, 
                $message, 
                $type, 
                $event_id, 
                'council_event',
                []
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating council event action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification when council president submits a new document
 * 
 * @param int $council_id Council ID
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilDocumentSubmission($council_id, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get council adviser
        $stmt = $conn->prepare("
            SELECT adviser_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council || !$council['adviser_id']) return false;
        
        // Convert council document type to readable format
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
            'other' => 'Other Documents'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        // Pull council name for better context in notifications
        $council_name = 'Council';
        $c_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
        if ($c_stmt) {
            $c_stmt->bind_param("i", $council_id);
            if ($c_stmt->execute()) {
                $c_row = $c_stmt->get_result()->fetch_assoc();
                if (!empty($c_row['council_name'])) { $council_name = $c_row['council_name']; }
            }
            $c_stmt->close();
        }

        $title = "New Council Document Submitted: $documentTypeLabel";
        $message = "$council_name A new council document '$documentTypeLabel' has been submitted by the council president for your review. Please review the document and provide feedback or approval as needed.";

        // Get current academic year ID to tag council document notifications
        $academic_year_id = getCurrentAcademicYearId();

        $success = true;

        // Notify council adviser
        $success &= createNotification(
            $council['adviser_id'], 
            $title, 
            $message, 
            'document_submitted', 
            $document_id, 
            'council_document',
            [],
            $academic_year_id
        );

        // OSAS will only be notified when documents are sent to them (after adviser approval)
        // via notifyCouncilDocumentsSentToOSAS() function

        return (bool)$success;
    } catch (Exception $e) {
        error_log("Error creating council document submission notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification for council document approval/rejection by Council Adviser
 * 
 * @param int $council_id Council ID
 * @param string $action 'approved' or 'rejected'
 * @param string $document_type Document type
 * @param int $document_id Document ID
 * @return bool True if notifications created successfully
 */
function notifyCouncilAdviserDocumentAction($council_id, $action, $document_type, $document_id) {
    global $conn;
    
    try {
        // Get council president
        $stmt = $conn->prepare("
            SELECT president_id 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) return false;
        
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
            'other' => 'Other Documents'
        ];
        
        $documentTypeLabel = $documentTypeLabels[$document_type] ?? ucfirst(str_replace('_', ' ', $document_type));
        
        // Get council name
        $council_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
        $council_stmt->bind_param("i", $council_id);
        $council_stmt->execute();
        $council_data = $council_stmt->get_result()->fetch_assoc();
        $council_name = $council_data['council_name'] ?? 'Council';
        
        $type = $action === 'approved' ? 'council_document_approved' : 'council_document_rejected';
        $title = "Council Document " . ucfirst($action) . " by Adviser: $documentTypeLabel";
        $message = "Your council document '$documentTypeLabel' for {$council_name} has been $action by your council adviser and forwarded to OSAS for final review. " . ($action === 'approved' ? "Great job! Your council adviser has approved this document and it's now in the hands of OSAS for final approval." : "Your council adviser has requested some changes. Please review the feedback and resubmit your council document.");
        
        $success = true;
        
        // Notify president
        if ($council['president_id']) {
            $success &= createNotification(
                $council['president_id'], 
                $title, 
                $message, 
                $type, 
                $document_id, 
                'council_document'
            );
        }
        
        return $success;
    } catch (Exception $e) {
        error_log("Error creating council adviser document action notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if organization has submitted all required documents for the current academic year
 * 
 * @param int $organization_id Organization ID
 * @param int $academic_year_id Academic year ID
 * @return array Array with compliance status and missing documents
 */
function checkOrganizationCompliance($organization_id, $academic_year_id) {
    global $conn;
    
    try {
        // Get the current academic year's document submission period
        $stmt = $conn->prepare("
            SELECT document_start_date, document_end_date, school_year 
            FROM academic_terms 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->bind_param("i", $academic_year_id);
        $stmt->execute();
        $academicYear = $stmt->get_result()->fetch_assoc();
        
        if (!$academicYear) {
            return ['compliant' => false, 'missing_documents' => [], 'academic_year' => null];
        }
        
        // Check if we're within the submission period
        $today = date('Y-m-d');
        $isWithinPeriod = $today >= $academicYear['document_start_date'] && $today <= $academicYear['document_end_date'];
        
		// Determine organization type (new/old) to apply conditional requirements
		$orgType = 'new';
		$typeStmt = $conn->prepare("SELECT type FROM organizations WHERE id = ?");
		if ($typeStmt) {
			$typeStmt->bind_param("i", $organization_id);
			if ($typeStmt->execute()) {
				$typeRow = $typeStmt->get_result()->fetch_assoc();
				if (!empty($typeRow['type'])) {
					$orgType = $typeRow['type'];
				}
			}
		}
		
		// Base required documents common to all organizations
		$requiredDocuments = [
			'adviser_resume',
			'student_profile',
			'officers_list',
			'calendar_activities',
			'official_logo',
			'officers_grade',
			'group_picture',
			'constitution_bylaws',
			'members_list',
			'good_moral',
			'adviser_acceptance'
		];
		// Additional documents only required for OLD organizations
		$oldOnlyDocuments = [
			'accomplishment_report',
			'previous_plan_of_activities',
			'financial_report'
		];
		if ($orgType === 'old') {
			$requiredDocuments = array_merge($requiredDocuments, $oldOnlyDocuments);
		}
        
        // Get submitted documents for this organization
        $stmt = $conn->prepare("
            SELECT document_type 
            FROM organization_documents 
            WHERE organization_id = ? AND status != 'rejected'
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $submittedDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $submittedTypes = array_column($submittedDocs, 'document_type');
        $missingDocuments = array_diff($requiredDocuments, $submittedTypes);
        
        return [
            'compliant' => empty($missingDocuments),
            'missing_documents' => $missingDocuments,
            'academic_year' => $academicYear,
            'is_within_period' => $isWithinPeriod
        ];
    } catch (Exception $e) {
        error_log("Error checking organization compliance: " . $e->getMessage());
        return ['compliant' => false, 'missing_documents' => [], 'academic_year' => null];
    }
}

/**
 * Check if council has submitted all required documents for the current academic year
 * 
 * @param int $council_id Council ID
 * @param int $academic_year_id Academic year ID
 * @return array Array with compliance status and missing documents
 */
function checkCouncilCompliance($council_id, $academic_year_id) {
    global $conn;
    
    try {
        // Get the current academic year's document submission period
        $stmt = $conn->prepare("
            SELECT document_start_date, document_end_date, school_year 
            FROM academic_terms 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->bind_param("i", $academic_year_id);
        $stmt->execute();
        $academicYear = $stmt->get_result()->fetch_assoc();
        
        if (!$academicYear) {
            return ['compliant' => false, 'missing_documents' => [], 'academic_year' => null];
        }
        
        // Check if we're within the submission period
        $today = date('Y-m-d');
        $isWithinPeriod = $today >= $academicYear['document_start_date'] && $today <= $academicYear['document_end_date'];
        
		// Determine council type (new/old) to apply conditional requirements
		$councilType = 'new';
 		$typeStmt = $conn->prepare("SELECT type FROM council WHERE id = ?");
 		if ($typeStmt) {
 			$typeStmt->bind_param("i", $council_id);
 			if ($typeStmt->execute()) {
 				$typeRow = $typeStmt->get_result()->fetch_assoc();
 				if (!empty($typeRow['type'])) {
 					$councilType = $typeRow['type'];
 				}
 			}
 		}
		
		// Base required documents common to all councils
		$requiredDocuments = [
			'adviser_resume',
			'student_profile',
			'officers_list',
			'calendar_activities',
			'official_logo',
			'officers_grade',
			'group_picture',
			'constitution_bylaws',
			'members_list',
			'good_moral',
			'adviser_acceptance'
		];
		// Additional documents only required for OLD councils
		$oldOnlyDocuments = [
			'accomplishment_report',
			'previous_plan_of_activities',
			'financial_report'
		];
		if ($councilType === 'old') {
			$requiredDocuments = array_merge($requiredDocuments, $oldOnlyDocuments);
		}
        
        // Get submitted documents for this council
        $stmt = $conn->prepare("
            SELECT document_type 
            FROM council_documents 
            WHERE council_id = ? AND status != 'rejected'
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $submittedDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $submittedTypes = array_column($submittedDocs, 'document_type');
        $missingDocuments = array_diff($requiredDocuments, $submittedTypes);
        
        return [
            'compliant' => empty($missingDocuments),
            'missing_documents' => $missingDocuments,
            'academic_year' => $academicYear,
            'is_within_period' => $isWithinPeriod
        ];
    } catch (Exception $e) {
        error_log("Error checking council compliance: " . $e->getMessage());
        return ['compliant' => false, 'missing_documents' => [], 'academic_year' => null];
    }
}

/**
 * Get compliance notification for organization presidents
 * 
 * @param int $organization_id Organization ID
 * @return array|null Compliance notification data or null if compliant
 */
function getOrganizationComplianceNotification($organization_id) {
    global $conn;
    
    try {
        // Get organization's academic year and status
        $stmt = $conn->prepare("
            SELECT academic_year_id, status 
            FROM organizations 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org || !$org['academic_year_id']) {
            return null;
        }
        
        // If organization is already recognized, don't show compliance reminder
        if ($org['status'] === 'recognized') {
            return null;
        }
        
        $compliance = checkOrganizationCompliance($organization_id, $org['academic_year_id']);
        
        // Check if compliance data is valid and has academic year information
        if (!$compliance || !isset($compliance['academic_year']) || !$compliance['academic_year']) {
            return null;
        }
        
        // Always show compliance reminder as long as academic year is active
        // (removed the compliant check as per user requirement)
        
        // Always show compliance reminder as long as academic year is active
        // Determine the type based on submission period status
        $today = date('Y-m-d');
        $docStartDate = safeNestedArrayAccess($compliance, 'academic_year', 'document_start_date', '');
        $docEndDate = safeNestedArrayAccess($compliance, 'academic_year', 'document_end_date', '');
        
        $daysUntilStart = (strtotime($docStartDate) - strtotime($today)) / (60 * 60 * 24);
        $daysSinceEnd = (strtotime($today) - strtotime($docEndDate)) / (60 * 60 * 24);
        
        if ($daysUntilStart > 0) {
            // Before submission period starts
            return [
                'type' => 'compliance_coming_soon',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'organization_documents.php',
                'days_until_start' => ceil($daysUntilStart),
                'submission_status' => 'not_started',
                'can_submit' => false,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Organization'
                ]
            ];
        } elseif ($daysSinceEnd > 0) {
            // After submission period ends
            return [
                'type' => 'compliance_ended',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'organization_documents.php',
                'days_since_end' => ceil($daysSinceEnd),
                'submission_status' => 'ended',
                'can_submit' => false,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Organization'
                ]
            ];
        } else {
            // Within submission period
            return [
                'type' => 'compliance_reminder',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'organization_documents.php',
                'submission_status' => 'active',
                'can_submit' => true,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Organization'
                ]
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting organization compliance notification: " . $e->getMessage());
        return null;
    }
}

/**
 * Get compliance notification for council presidents
 * 
 * @param int $council_id Council ID
 * @return array|null Compliance notification data or null if compliant
 */
function getCouncilComplianceNotification($council_id) {
    global $conn;
    
    try {
        // Get council's academic year and status
        $stmt = $conn->prepare("
            SELECT academic_year_id, status 
            FROM council 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council || !$council['academic_year_id']) {
            return null;
        }
        
        // If council is already recognized, don't show compliance reminder
        if ($council['status'] === 'recognized') {
            return null;
        }
        
        $compliance = checkCouncilCompliance($council_id, $council['academic_year_id']);
        
        // Check if compliance data is valid and has academic year information
        if (!$compliance || !isset($compliance['academic_year']) || !$compliance['academic_year']) {
            return null;
        }
        
        // Always show compliance reminder as long as academic year is active
        // (removed the compliant check as per user requirement)
        
        // Always show compliance reminder as long as academic year is active
        // Determine the type based on submission period status
        $today = date('Y-m-d');
        $docStartDate = safeNestedArrayAccess($compliance, 'academic_year', 'document_start_date', '');
        $docEndDate = safeNestedArrayAccess($compliance, 'academic_year', 'document_end_date', '');
        
        $daysUntilStart = (strtotime($docStartDate) - strtotime($today)) / (60 * 60 * 24);
        $daysSinceEnd = (strtotime($today) - strtotime($docEndDate)) / (60 * 60 * 24);
        
        if ($daysUntilStart > 0) {
            // Before submission period starts
            return [
                'type' => 'compliance_coming_soon',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'council_documents.php',
                'days_until_start' => ceil($daysUntilStart),
                'submission_status' => 'not_started',
                'can_submit' => false,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Council'
                ]
            ];
        } elseif ($daysSinceEnd > 0) {
            // After submission period ends
            return [
                'type' => 'compliance_ended',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'council_documents.php',
                'days_since_end' => ceil($daysSinceEnd),
                'submission_status' => 'ended',
                'can_submit' => false,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Council'
                ]
            ];
        } else {
            // Within submission period
            return [
                'type' => 'compliance_reminder',
                'academic_year' => $compliance['academic_year'],
                'missing_documents' => $compliance['missing_documents'],
                'document_page' => 'council_documents.php',
                'submission_status' => 'active',
                'can_submit' => true,
                'additional_data' => [
                    'missing_documents' => $compliance['missing_documents'],
                    'academic_year' => safeNestedArrayAccess($compliance, 'academic_year', 'school_year', 'Current Academic Year'),
                    'organization_name' => 'Council'
                ]
            ];
        }
    } catch (Exception $e) {
        error_log("Error getting council compliance notification: " . $e->getMessage());
        return null;
    }
}

/**
 * Send compliance notification to president with email support
 * 
 * @param int $user_id User ID (president)
 * @param array $compliance_data Compliance notification data
 * @return bool True if notification sent successfully
 */
function sendComplianceNotification($user_id, $compliance_data) {
    $title = "Compliance Reminder - Missing Documents";
    $message = "Your organization/council is missing required documents for the current academic year. To maintain compliance and ensure your organization's recognition status, please submit all missing documents as soon as possible. This is important for your organization's continued operation and recognition.";
    
    $additional_data = $compliance_data['additional_data'] ?? [];
    
    return createNotification(
        $user_id,
        $title,
        $message,
        'compliance_reminder',
        null,
        'compliance',
        $additional_data
    );
}

/**
 * Clear compliance notifications when all documents are submitted
 * This function is called automatically by the compliance check functions
 * when compliance is achieved, so no manual clearing is needed
 * 
 * @param int $user_id User ID (president)
 * @param string $type 'organization' or 'council'
 * @return bool True if cleared successfully
 */
function clearComplianceNotifications($user_id, $type) {
    global $conn;
    
    try {
        // Clear any existing compliance notifications for this user
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE user_id = ? 
            AND type = 'compliance_reminder'
        ");
        $stmt->bind_param("i", $user_id);
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error clearing compliance notifications: " . $e->getMessage());
        return false;
    }
}

/**
 * Delete event-related notifications when a semester ends
 * This function should be called when a semester status changes to 'archived'
 * 
 * @param int $semester_id Semester ID that has ended
 * @return array Results of the cleanup operation
 */
function cleanupEventNotifications($semester_id) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // First, get the academic_year_id for this semester
        $semesterStmt = $conn->prepare("SELECT academic_term_id FROM academic_semesters WHERE id = ?");
        $semesterStmt->bind_param("i", $semester_id);
        $semesterStmt->execute();
        $semesterResult = $semesterStmt->get_result()->fetch_assoc();
        
        if (!$semesterResult) {
            throw new Exception("Semester with ID $semester_id not found");
        }
        
        $academic_year_id = $semesterResult['academic_term_id'];
        
        // Event-related notification types
        $event_types = [
            'event_approved',
            'event_rejected', 
            'event_submitted',
            'event_documents_for_review',
            'event_document_approved',
            'event_document_rejected'
        ];
        
        // Create placeholders for the IN clause
        $placeholders = str_repeat('?,', count($event_types) - 1) . '?';
        
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE academic_year_id = ? 
            AND type IN ($placeholders)
        ");
        
        // Bind parameters: academic_year_id first (i), then all event types (s...)
        $types = 'i' . str_repeat('s', count($event_types));
        $bindParams = [$types, &$academic_year_id];
        foreach ($event_types as $idx => $val) { $bindParams[] = &$event_types[$idx]; }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        
        $conn->commit();
        
        return [
            'success' => true,
            'deleted_count' => $deleted_count,
            'semester_id' => $semester_id,
            'academic_year_id' => $academic_year_id
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error cleaning up event notifications for semester $semester_id: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'semester_id' => $semester_id
        ];
    }
}

/**
 * Delete document-related notifications when an academic year ends
 * This function should be called when an academic year status changes to 'archived'
 * 
 * @param int $academic_year_id Academic year ID that has ended
 * @return array Results of the cleanup operation
 */
function cleanupDocumentNotifications($academic_year_id) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        // Document-related notification types
        $document_types = [
            'document_approved',
            'document_rejected',
            'document_submitted',
            'documents_for_review'
        ];
        
        // Create placeholders for the IN clause
        $placeholders = str_repeat('?,', count($document_types) - 1) . '?';
        
        $stmt = $conn->prepare("
            DELETE FROM notifications 
            WHERE academic_year_id = ? 
            AND type IN ($placeholders)
        ");
        
        // Bind parameters: academic_year_id first (i), then all document types (s...)
        $types = 'i' . str_repeat('s', count($document_types));
        $bindParams = [$types, &$academic_year_id];
        foreach ($document_types as $idx => $val) { $bindParams[] = &$document_types[$idx]; }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        
        $stmt->execute();
        $deleted_count = $stmt->affected_rows;
        
        $conn->commit();
        
        return [
            'success' => true,
            'deleted_count' => $deleted_count,
            'academic_year_id' => $academic_year_id
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error cleaning up document notifications for academic year $academic_year_id: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'academic_year_id' => $academic_year_id
        ];
    }
}

/**
 * Check if document submission is allowed based on submission period
 * 
 * @param int $entity_id Organization or Council ID
 * @param string $entity_type 'organization' or 'council'
 * @return array Array with can_submit status and message
 */
function checkSubmissionPeriod($entity_id, $entity_type) {
    global $conn;
    
    try {
        // Get the entity's academic year
        $table = $entity_type === 'organization' ? 'organizations' : 'council';
        $stmt = $conn->prepare("
            SELECT academic_year_id 
            FROM {$table} 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $entity_id);
        $stmt->execute();
        $entity = $stmt->get_result()->fetch_assoc();
        
        if (!$entity || !$entity['academic_year_id']) {
            return [
                'can_submit' => false,
                'message' => 'No active academic year found for this ' . $entity_type . '.'
            ];
        }
        
        // Get the academic year's document submission period
        $stmt = $conn->prepare("
            SELECT document_start_date, document_end_date, school_year 
            FROM academic_terms 
            WHERE id = ? AND status = 'active'
        ");
        $stmt->bind_param("i", $entity['academic_year_id']);
        $stmt->execute();
        $academicYear = $stmt->get_result()->fetch_assoc();
        
        if (!$academicYear) {
            return [
                'can_submit' => false,
                'message' => 'No active academic year found.'
            ];
        }
        
        $today = date('Y-m-d');
        $docStartDate = $academicYear['document_start_date'];
        $docEndDate = $academicYear['document_end_date'];
        
        if ($today < $docStartDate) {
            $daysUntilStart = ceil((strtotime($docStartDate) - strtotime($today)) / (60 * 60 * 24));
            return [
                'can_submit' => false,
                'message' => "Document submission is not yet available. The submission period will begin on " . date('F d, Y', strtotime($docStartDate)) . " (in {$daysUntilStart} day" . ($daysUntilStart > 1 ? 's' : '') . ")."
            ];
        } elseif ($today > $docEndDate) {
            $daysSinceEnd = ceil((strtotime($today) - strtotime($docEndDate)) / (60 * 60 * 24));
            return [
                'can_submit' => false,
                'message' => "Document submission period has ended. The submission period ended on " . date('F d, Y', strtotime($docEndDate)) . " ({$daysSinceEnd} day" . ($daysSinceEnd > 1 ? 's' : '') . " ago). Please contact OSAS for further assistance."
            ];
        } else {
            return [
                'can_submit' => true,
                'message' => 'Document submission is currently allowed.'
            ];
        }
    } catch (Exception $e) {
        error_log("Error checking submission period: " . $e->getMessage());
        return [
            'can_submit' => false,
            'message' => 'Error checking submission period. Please try again later.'
        ];
    }
}

/**
 * Check if organization has uploaded student data for the current active semester
 * 
 * @param int $organization_id Organization ID
 * @return array Array with student data status and count
 */
function checkOrganizationStudentData($organization_id) {
    global $conn;
    
    try {
        // Get current active semester
        $stmt = $conn->prepare("
            SELECT id FROM academic_semesters 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $semester = $stmt->get_result()->fetch_assoc();
        
        if (!$semester) {
            return [
                'has_student_data' => false,
                'student_count' => 0,
                'message' => 'No active semester found'
            ];
        }
        
        // Check if organization has student data for current semester
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM student_data 
            WHERE organization_id = ? AND semester_id = ?
        ");
        $stmt->bind_param("ii", $organization_id, $semester['id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $student_count = $result['count'] ?? 0;
        
        return [
            'has_student_data' => $student_count > 0,
            'student_count' => $student_count,
            'semester_id' => $semester['id'],
            'message' => $student_count > 0 ? 'Student data available' : 'No student data uploaded'
        ];
        
    } catch (Exception $e) {
        error_log("Error checking organization student data: " . $e->getMessage());
        return [
            'has_student_data' => false,
            'student_count' => 0,
            'message' => 'Error checking student data'
        ];
    }
}

/**
 * Check if council has access to student data from organizations in its college
 * 
 * @param int $council_id Council ID
 * @return array Array with student data status and count
 */
function checkCouncilStudentData($council_id) {
    global $conn;
    
    try {
        // Get current active semester
        $stmt = $conn->prepare("
            SELECT id FROM academic_semesters 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $semester = $stmt->get_result()->fetch_assoc();
        
        if (!$semester) {
            return [
                'has_student_data' => false,
                'student_count' => 0,
                'message' => 'No active semester found'
            ];
        }
        
        // Get council's college
        $stmt = $conn->prepare("
            SELECT college_id FROM council WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council) {
            return [
                'has_student_data' => false,
                'student_count' => 0,
                'message' => 'Council not found'
            ];
        }
        
        // Check if any organizations in the council's college have student data
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM student_data sd
            INNER JOIN organizations o ON sd.organization_id = o.id
            WHERE o.college_id = ? AND sd.semester_id = ?
        ");
        $stmt->bind_param("ii", $council['college_id'], $semester['id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        $student_count = $result['count'] ?? 0;
        
        return [
            'has_student_data' => $student_count > 0,
            'student_count' => $student_count,
            'semester_id' => $semester['id'],
            'college_id' => $council['college_id'],
            'message' => $student_count > 0 ? 'Student data available from organizations' : 'No student data uploaded by organizations in this college'
        ];
        
    } catch (Exception $e) {
        error_log("Error checking council student data: " . $e->getMessage());
        return [
            'has_student_data' => false,
            'student_count' => 0,
            'message' => 'Error checking student data'
        ];
    }
}

/**
 * Get student data reminder notification for organization presidents
 * 
 * @param int $organization_id Organization ID
 * @return array|null Student data reminder notification data or null if data exists
 */
function getOrganizationStudentDataReminder($organization_id) {
    global $conn;
    
    try {
        // Check if organization is recognized first
        $stmt = $conn->prepare("
            SELECT status FROM organizations WHERE id = ?
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org || $org['status'] !== 'recognized') {
            return null; // Only show reminder for recognized organizations
        }
        
        $studentData = checkOrganizationStudentData($organization_id);
        
        if ($studentData['has_student_data']) {
            return null; // No reminder needed if data exists
        }
        
        return [
            'type' => 'student_data_reminder',
            'message' => 'You must first upload student data in the Student Registry before you can post events, awards, or add officers.',
            'message_html' => 'You must first upload student data in the <a href="registry.php" class="alert-link fw-bold">Student Registry</a> before you can post events, awards, or add officers.',
            'student_count' => $studentData['student_count'],
            'registry_page' => 'registry.php'
        ];
        
    } catch (Exception $e) {
        error_log("Error getting organization student data reminder: " . $e->getMessage());
        return null;
    }
}

/**
 * Get student data reminder notification for council presidents
 * 
 * @param int $council_id Council ID
 * @return array|null Student data reminder notification data or null if data exists
 */
function getCouncilStudentDataReminder($council_id) {
    global $conn;
    
    try {
        // Check if council is recognized first
        $stmt = $conn->prepare("
            SELECT status FROM council WHERE id = ?
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        $council = $stmt->get_result()->fetch_assoc();
        
        if (!$council || $council['status'] !== 'recognized') {
            return null; // Only show reminder for recognized councils
        }
        
        $studentData = checkCouncilStudentData($council_id);
        
        if ($studentData['has_student_data']) {
            return null; // No reminder needed if data exists
        }
        
        return [
            'type' => 'student_data_reminder',
            'message' => 'Organizations in your college must first upload student data in the Student Registry before you can post events, awards, or add officers.',
            'message_html' => 'Organizations in your college must first upload student data in the <a href="registry.php" class="alert-link fw-bold">Student Registry</a> before you can post events, awards, or add officers.',
            'student_count' => $studentData['student_count'],
            'registry_page' => 'registry.php'
        ];
        
    } catch (Exception $e) {
        error_log("Error getting council student data reminder: " . $e->getMessage());
        return null;
    }
}

/**
 * Clean up all notifications for archived semesters and academic years
 * This function checks for archived semesters and academic years and cleans up their notifications
 * 
 * @return array Results of the cleanup operation
 */
function cleanupArchivedNotifications() {
    global $conn;
    
    try {
        $results = [
            'success' => true,
            'semester_cleanups' => [],
            'academic_year_cleanups' => [],
            'total_deleted' => 0
        ];
        
        // Semester-based cleanup removed (semester_id dropped from notifications)
        
        // Get all archived academic years
        $stmt = $conn->prepare("
            SELECT id FROM academic_terms 
            WHERE status = 'archived'
        ");
        $stmt->execute();
        $archived_years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Clean up document notifications for each archived academic year
        foreach ($archived_years as $year) {
            $cleanup_result = cleanupDocumentNotifications($year['id']);
            $results['academic_year_cleanups'][] = $cleanup_result;
            if ($cleanup_result['success']) {
                $results['total_deleted'] += $cleanup_result['deleted_count'];
            }
        }
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Error in cleanupArchivedNotifications: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'total_deleted' => 0
        ];
    }
}

/**
 * Get current semester ID for event notifications
 * 
 * @return int|null Current active semester ID or null if none found
 */
function getCurrentSemesterId() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id FROM academic_semesters 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ? $result['id'] : null;
    } catch (Exception $e) {
        error_log("Error getting current semester ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Get current academic year ID for document notifications
 * 
 * @return int|null Current active academic year ID or null if none found
 */
function getCurrentAcademicYearId() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id FROM academic_terms 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ? $result['id'] : null;
    } catch (Exception $e) {
        error_log("Error getting current academic year ID: " . $e->getMessage());
        return null;
    }
}

/**
 * Clean up expired notifications for a specific user on login
 * This function checks if the user has notifications from ended semesters or academic years
 * and removes them to keep their notification list current
 * 
 * @param int $user_id User ID to clean up notifications for
 * @return array Results of the cleanup operation
 */
function cleanupUserExpiredNotifications($user_id) {
    global $conn;
    
    try {
        $results = [
            'success' => true,
            'deleted_count' => 0,
            'event_notifications_deleted' => 0,
            'document_notifications_deleted' => 0
        ];
        
        // Event-related notification types
        $event_types = [
            'event_approved',
            'event_rejected', 
            'event_submitted',
            'event_documents_for_review',
            'event_document_approved',
            'event_document_rejected'
        ];
        
        // Document-related notification types
        $document_types = [
            'document_approved',
            'document_rejected',
            'document_submitted',
            'documents_for_review'
        ];
        
        $conn->begin_transaction();
        
        // No event notification cleanup by semester anymore
        $results['event_notifications_deleted'] = 0;
        
        // Clean up document notifications from archived academic years
        $document_placeholders = str_repeat('?,', count($document_types) - 1) . '?';
        $stmt = $conn->prepare("
            DELETE n FROM notifications n
            INNER JOIN academic_terms t ON n.academic_year_id = t.id
            WHERE n.user_id = ? 
            AND n.type IN ($document_placeholders)
            AND t.status = 'archived'
        ");
        
        $types = 'i' . str_repeat('s', count($document_types));
        $bindParams = [$types, &$user_id];
        foreach ($document_types as $idx => $val) { $bindParams[] = &$document_types[$idx]; }
        call_user_func_array([$stmt, 'bind_param'], $bindParams);
        $stmt->execute();
        $results['document_notifications_deleted'] = $stmt->affected_rows;
        
        $results['deleted_count'] = $results['event_notifications_deleted'] + $results['document_notifications_deleted'];
        
        $conn->commit();
        
        // Log the cleanup if any notifications were deleted
        if ($results['deleted_count'] > 0) {
            error_log("User $user_id login cleanup: Deleted {$results['deleted_count']} expired notifications " .
                     "({$results['event_notifications_deleted']} event, {$results['document_notifications_deleted']} document)");
        }
        
        return $results;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error cleaning up expired notifications for user $user_id: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'deleted_count' => 0,
            'event_notifications_deleted' => 0,
            'document_notifications_deleted' => 0
        ];
    }
}

/**
 * Check if user has expired notifications without deleting them
 * This is useful for displaying a message to users about expired notifications
 * 
 * @param int $user_id User ID to check
 * @return array Information about expired notifications
 */
function checkUserExpiredNotifications($user_id) {
    global $conn;
    
    try {
        $results = [
            'has_expired' => false,
            'expired_event_count' => 0,
            'expired_document_count' => 0,
            'total_expired' => 0
        ];
        
        // No semester-based expiry check anymore
        $results['expired_event_count'] = 0;
        
        // Check for expired document notifications
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count
            FROM notifications n
            INNER JOIN academic_terms t ON n.academic_year_id = t.id
            WHERE n.user_id = ? 
            AND n.type IN ('document_approved', 'document_rejected', 'document_submitted', 'documents_for_review')
            AND t.status = 'archived'
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $results['expired_document_count'] = $result['count'];
        
        $results['total_expired'] = $results['expired_event_count'] + $results['expired_document_count'];
        $results['has_expired'] = $results['total_expired'] > 0;
        
        return $results;
        
    } catch (Exception $e) {
        error_log("Error checking expired notifications for user $user_id: " . $e->getMessage());
        return [
            'has_expired' => false,
            'expired_event_count' => 0,
            'expired_document_count' => 0,
            'total_expired' => 0,
            'error' => $e->getMessage()
        ];
    }
}

?> 
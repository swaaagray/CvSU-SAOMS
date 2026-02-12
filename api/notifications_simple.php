<?php
// Simplified notification API without email dependencies
// Minimize side-effects from global enforcement in session helpers for this API
if (!defined('SKIP_ENFORCEMENT')) { define('SKIP_ENFORCEMENT', true); }
// Start output buffering to catch any unwanted output
ob_start();

// Set error reporting to prevent warnings from breaking JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Temp trace logging to diagnose 500s
error_log('[notifications_simple] start');

try {
    // Check if required files exist
    if (!file_exists('../config/database.php')) {
        throw new Exception('Database config file not found');
    }
    if (!file_exists('../includes/session.php')) {
        throw new Exception('Session file not found');
    }

    require_once '../config/database.php';
    require_once '../includes/session.php';
    error_log('[notifications_simple] includes loaded');

    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        error_log('[notifications_simple] session started');
    }

    // Ensure user is logged in
    if (!function_exists('isLoggedIn')) {
        throw new Exception('isLoggedIn function not found');
    }
    if (!isLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Clear any output that might have been generated
    ob_clean();
    error_log('[notifications_simple] auth ok');
} catch (Exception $e) {
    // Clear any output and return error
    ob_clean();
    header('Content-Type: application/json');
    error_log("Notification API System Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'System error: ' . $e->getMessage()]);
    exit;
}

try {
    // Check if getCurrentUserId function exists
    if (!function_exists('getCurrentUserId')) {
        throw new Exception('getCurrentUserId function not found');
    }
    
    $user_id = getCurrentUserId();
    $action = $_GET['action'] ?? '';
    error_log('[notifications_simple] action=' . ($action ?: 'none') . ', user_id=' . (string)$user_id);
    
    header('Content-Type: application/json');

    switch ($action) {
    case 'debug':
        // Lightweight diagnostics to help identify 500 errors
        try {
            $diagnostics = [
                'success' => true,
                'checks' => []
            ];

            // Session/user check
            $diagnostics['checks']['user_id'] = $user_id;
            $diagnostics['checks']['session_active'] = session_status() === PHP_SESSION_ACTIVE;

            // DB connection check
            $diagnostics['checks']['db_connected'] = ($conn instanceof mysqli) && !$conn->connect_error;
            $diagnostics['checks']['db_error'] = ($conn instanceof mysqli) ? ($conn->connect_error ?? null) : 'Connection not established';

            // Table exists check
            $tblOk = false; $tblErr = null; $columns = [];
            try {
                if (!($conn instanceof mysqli) || $conn->connect_error) { throw new Exception('Database connection failed'); }
                $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
                $tblOk = $table_check && $table_check->num_rows > 0;
                if ($tblOk) {
                    $colRes = $conn->query("SHOW COLUMNS FROM notifications");
                    if ($colRes) {
                        while ($row = $colRes->fetch_assoc()) { $columns[] = $row['Field']; }
                    }
                }
            } catch (Throwable $t) { $tblErr = $t->getMessage(); }
            $diagnostics['checks']['notifications_table_exists'] = $tblOk;
            $diagnostics['checks']['notifications_table_error'] = $tblErr;
            $diagnostics['checks']['notifications_columns'] = $columns;

            // Probe query/prepare
            $probe = [ 'prepare_ok' => false, 'execute_ok' => false, 'error' => null, 'row_example' => null ];
            try {
                if (!($conn instanceof mysqli) || $conn->connect_error) { throw new Exception('Database connection failed'); }
                $sql = "SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $probe['prepare_ok'] = true;
                    $stmt->bind_param("i", $user_id);
                    if ($stmt->execute()) {
                        $probe['execute_ok'] = true;
                        $res = $stmt->get_result();
                        $probe['row_example'] = $res ? $res->fetch_assoc() : null;
                    } else {
                        $probe['error'] = $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $probe['error'] = $conn->error;
                }
            } catch (Throwable $t) { $probe['error'] = $t->getMessage(); }
            $diagnostics['checks']['probe'] = $probe;

            error_log('[notifications_simple] debug returning diagnostics');
            echo json_encode($diagnostics);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Debug error: ' . $e->getMessage()]);
        }
        break;
    case 'test':
        // Test endpoint to verify API is working
        error_log('[notifications_simple] test ok');
        echo json_encode(['success' => true, 'message' => 'API is working', 'user_id' => $user_id]);
        break;
        
    case 'get_count':
        // Get unread notification count
        try {
            if (!($conn instanceof mysqli) || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . (($conn instanceof mysqli) ? ($conn->connect_error ?: 'Unknown error') : 'Connection not established'));
            }
            // Ensure notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
            if (!$table_check || $table_check->num_rows === 0) {
                throw new Exception('Notifications table does not exist');
            }
			$stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
			if (!$stmt) { throw new Exception('Prepare failed: ' . $conn->error); }
			$stmt->bind_param("i", $user_id);
			if (!$stmt->execute()) { throw new Exception('Execute failed: ' . $stmt->error); }
			$stmt->bind_result($count);
			$stmt->fetch();
			$stmt->close();
            error_log('[notifications_simple] get_count ok count=' . (int)$count);
            echo json_encode(['success' => true, 'count' => (int)$count]);
        } catch (Exception $e) {
            error_log("Notification API Error (get_count): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_notifications':
        // Get notifications for user
        try {
            if (!($conn instanceof mysqli) || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . (($conn instanceof mysqli) ? ($conn->connect_error ?: 'Unknown error') : 'Connection not established'));
            }
            // Ensure notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
            if (!$table_check || $table_check->num_rows === 0) {
                throw new Exception('Notifications table does not exist');
            }
            $limit = $_GET['limit'] ?? 10;
            $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
            
            // Debug logging
            error_log("Getting notifications for user: $user_id, limit: $limit, unread_only: " . ($unread_only ? 'true' : 'false'));
            
            $where_clause = "WHERE user_id = ?";
            if ($unread_only) {
                $where_clause .= " AND is_read = FALSE";
            }
            
            // Use a safe integer for LIMIT; binding LIMIT causes prepare errors on some MySQL versions
            $safeLimit = max(1, (int)$limit);
            
            $sql = "SELECT id, title, message, type, is_read, created_at, related_id, related_type FROM notifications $where_clause ORDER BY created_at DESC LIMIT $safeLimit";
            $stmt = $conn->prepare($sql);
            if (!$stmt) { throw new Exception('Prepare failed: ' . $conn->error); }
            $stmt->bind_param("i", $user_id);
            if (!$stmt->execute()) { throw new Exception('Execute failed: ' . $stmt->error); }
            $stmt->bind_result($id, $title, $message, $type, $is_read, $created_at, $related_id, $related_type);
            $notifications = [];
            while ($stmt->fetch()) {
                $notifications[] = [
                    'id' => $id,
                    'title' => $title,
                    'message' => $message,
                    'type' => $type,
                    'is_read' => (bool)$is_read,
                    'created_at' => $created_at,
                    'related_id' => $related_id,
                    'related_type' => $related_type
                ];
            }
            $stmt->close();
            
            // Debug logging
            error_log("Retrieved " . count($notifications) . " notifications");
            
            // Format notifications for display
            $formatted_notifications = [];
            foreach ($notifications as $notification) {
                // Simple time ago calculation
                $time_ago = getTimeAgo($notification['created_at']);
                
                // Simple icon based on type
                $icon = getNotificationIcon($notification['type']);
                
                $formatted_notifications[] = [
                    'id' => $notification['id'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'type' => $notification['type'],
                    'is_read' => (bool)$notification['is_read'],
                    'created_at' => $notification['created_at'],
                    'time_ago' => $time_ago,
                    'icon' => $icon,
                    'related_id' => $notification['related_id'],
                    'related_type' => $notification['related_type']
                ];
            }
            
            // Debug logging
            error_log("Formatted " . count($formatted_notifications) . " notifications");
            
            error_log('[notifications_simple] get_notifications ok items=' . count($formatted_notifications));
            echo json_encode([
                'success' => true, 
                'notifications' => $formatted_notifications
            ]);
        } catch (Exception $e) {
            error_log("Notification API Error (get_notifications): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        break;
        
    case 'mark_read':
        // Mark notification as read
        try {
            if (!($conn instanceof mysqli) || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . (($conn instanceof mysqli) ? ($conn->connect_error ?: 'Unknown error') : 'Connection not established'));
            }
            // Ensure notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
            if (!$table_check || $table_check->num_rows === 0) {
                throw new Exception('Notifications table does not exist');
            }
            $notification_id = $_POST['notification_id'] ?? 0;
            
            if ($notification_id) {
                $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
                $stmt->bind_param("ii", $notification_id, $user_id);
                $success = $stmt->execute();
                error_log('[notifications_simple] mark_read ok id=' . (int)$notification_id . ' success=' . ($success ? '1' : '0'));
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Notification ID required']);
            }
        } catch (Exception $e) {
            error_log("Notification API Error (mark_read): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
    case 'mark_all_read':
        // Mark all notifications as read
        try {
            if (!($conn instanceof mysqli) || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . (($conn instanceof mysqli) ? ($conn->connect_error ?: 'Unknown error') : 'Connection not established'));
            }
            // Ensure notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
            if (!$table_check || $table_check->num_rows === 0) {
                throw new Exception('Notifications table does not exist');
            }
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $success = $stmt->execute();
            error_log('[notifications_simple] mark_all_read ok success=' . ($success ? '1' : '0'));
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            error_log("Notification API Error (mark_all_read): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
    default:
        error_log('[notifications_simple] invalid action');
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
    }
} catch (Exception $e) {
    // Clear any output and return error
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'API error: ' . $e->getMessage()]);
}

// End output buffering and send response
ob_end_flush();
error_log('[notifications_simple] end');

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
?>

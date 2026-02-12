<?php
// Minimize side-effects from global enforcement in session helpers for this API
define('SKIP_ENFORCEMENT', true);
// Start output buffering to catch any unwanted output
ob_start();
// Suppress mysqli error to exceptions; we'll handle manually and return JSON
if (function_exists('mysqli_report')) { @mysqli_report(MYSQLI_REPORT_OFF); }

// Set error reporting to prevent warnings from breaking JSON
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

try {
    // Check if required files exist
    if (!file_exists('../config/database.php')) {
        throw new Exception('Database config file not found');
    }
    if (!file_exists('../includes/session.php')) {
        throw new Exception('Session file not found');
    }
    if (!file_exists('../includes/notification_helper.php')) {
        throw new Exception('Notification helper file not found');
    }

    require_once '../config/database.php';
    require_once '../includes/session.php';
    require_once '../includes/notification_helper.php';

    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Ensure user is logged in
    if (!function_exists('requireLogin')) {
        throw new Exception('requireLogin function not found');
    }
    try {
        requireLogin();
    } catch (Throwable $t) {
        // Never allow auth gating/cleanup exceptions to bubble and break JSON
        error_log('Notification API requireLogin gate failed: ' . $t->getMessage());
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    // Clear any output that might have been generated
    ob_clean();
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

    // Check if database connection is working
    if (!isset($conn) || $conn->connect_error) {
        throw new Exception('Database connection failed: ' . ($conn->connect_error ?? 'Connection not established'));
    }
    
    // Check if notifications table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'notifications'");
    if ($table_check->num_rows === 0) {
        throw new Exception('Notifications table does not exist');
    }

    header('Content-Type: application/json');

    switch ($action) {
    case 'test':
        // Test endpoint to verify API is working
        echo json_encode(['success' => true, 'message' => 'API is working', 'user_id' => $user_id]);
        break;
        
    case 'get_count':
        // Get unread notification count
        try {
            if (!function_exists('getUnreadNotificationCount')) {
                throw new Exception('getUnreadNotificationCount function not found');
            }
            $count = getUnreadNotificationCount($user_id);
            echo json_encode(['success' => true, 'count' => $count]);
        } catch (Exception $e) {
            error_log("Notification API Error (get_count): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_notifications':
        // Get notifications for user
        try {
            // Check if getNotifications function exists
            if (!function_exists('getNotifications')) {
                throw new Exception('getNotifications function not found');
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $unread_only = isset($_GET['unread_only']) && $_GET['unread_only'] === 'true';
            
            // Debug logging
            error_log("Getting notifications for user: $user_id, limit: $limit, unread_only: " . ($unread_only ? 'true' : 'false'));
            
            $notifications = getNotifications($user_id, $limit, $unread_only);
            
            // Debug logging
            error_log("Retrieved " . count($notifications) . " notifications");
            
            // Format notifications for display
            $formatted_notifications = [];
            try {
                foreach ($notifications as $notification) {
                    // Defensive reads
                    $createdAt = $notification['created_at'] ?? null;
                    $type = $notification['type'] ?? '';
                    $time_ago = function_exists('getTimeAgo') && $createdAt ? getTimeAgo($createdAt) : '';
                    $icon = function_exists('getNotificationIcon') ? getNotificationIcon($type) : 'fas fa-bell text-primary';
                    
                    $formatted_notifications[] = [
                        'id' => (int)($notification['id'] ?? 0),
                        'title' => (string)($notification['title'] ?? ''),
                        'message' => (string)($notification['message'] ?? ''),
                        'type' => $type,
                        'is_read' => (bool)($notification['is_read'] ?? 0),
                        'created_at' => $createdAt,
                        'time_ago' => $time_ago,
                        'icon' => $icon,
                        'related_id' => $notification['related_id'] ?? null,
                        'related_type' => $notification['related_type'] ?? null
                    ];
                }
            } catch (Throwable $tf) {
                error_log('Notification formatting error: ' . $tf->getMessage());
                $formatted_notifications = [];
            }
            
            // Debug logging
            error_log("Formatted " . count($formatted_notifications) . " notifications");
            
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
            $notification_id = $_POST['notification_id'] ?? 0;
            
            if (!function_exists('markNotificationAsRead')) {
                throw new Exception('markNotificationAsRead function not found');
            }
            
            if ($notification_id) {
                $success = markNotificationAsRead($notification_id, $user_id);
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
            if (!function_exists('markAllNotificationsAsRead')) {
                throw new Exception('markAllNotificationsAsRead function not found');
            }
            $success = markAllNotificationsAsRead($user_id);
            echo json_encode(['success' => $success]);
        } catch (Exception $e) {
            error_log("Notification API Error (mark_all_read): " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
        
    default:
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
?> 
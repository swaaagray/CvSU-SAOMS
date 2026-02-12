<?php
/**
 * Notification Cleanup Cron Job
 * 
 * This script should be run daily to automatically clean up notifications
 * for archived semesters and academic years.
 * 
 * Event notifications are deleted when their semester ends (status = 'archived')
 * Document notifications are deleted when their academic year ends (status = 'archived')
 * 
 * Cron job setup:
 * Daily at 2:00 AM: 0 2 * * * php /path/to/notification_cleanup.php
 * 
 * Or for Windows Task Scheduler:
 * Create a batch file that runs this PHP script daily
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/notification_cleanup.log');

// Set timezone
date_default_timezone_set('Asia/Manila');

// Start execution log
$startTime = microtime(true);
$logMessage = "[" . date('Y-m-d H:i:s') . "] Notification cleanup cron job started\n";
error_log($logMessage);

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/notification_helper.php';
    
    // Check if database connection is available
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection not available");
    }
    
    // Run the notification cleanup
    $results = cleanupArchivedNotifications();
    
    // Log results
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Notification cleanup cron job completed in {$executionTime}ms\n";
    
    if ($results['success']) {
        $logMessage .= "Successfully deleted {$results['total_deleted']} notifications\n";
        
        // Log semester cleanup details
        if (!empty($results['semester_cleanups'])) {
            $logMessage .= "Semester cleanups:\n";
            foreach ($results['semester_cleanups'] as $cleanup) {
                if ($cleanup['success']) {
                    $logMessage .= "  - Semester {$cleanup['semester_id']}: {$cleanup['deleted_count']} notifications deleted\n";
                } else {
                    $logMessage .= "  - Semester {$cleanup['semester_id']}: ERROR - {$cleanup['error']}\n";
                }
            }
        }
        
        // Log academic year cleanup details
        if (!empty($results['academic_year_cleanups'])) {
            $logMessage .= "Academic year cleanups:\n";
            foreach ($results['academic_year_cleanups'] as $cleanup) {
                if ($cleanup['success']) {
                    $logMessage .= "  - Academic Year {$cleanup['academic_year_id']}: {$cleanup['deleted_count']} notifications deleted\n";
                } else {
                    $logMessage .= "  - Academic Year {$cleanup['academic_year_id']}: ERROR - {$cleanup['error']}\n";
                }
            }
        }
    } else {
        $logMessage .= "ERROR: " . ($results['error'] ?? 'Unknown error') . "\n";
    }
    
    error_log($logMessage);
    
    // Exit with success code
    exit(0);
    
} catch (Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Notification cleanup cron job failed after {$executionTime}ms\n";
    $logMessage .= "Error: " . $e->getMessage() . "\n";
    $logMessage .= "Stack trace: " . $e->getTraceAsString() . "\n";
    error_log($logMessage);
    
    // Exit with error code
    exit(1);
}
?>

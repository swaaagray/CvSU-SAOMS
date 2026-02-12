<?php
/**
 * Deadline Reminder Cron Job
 * 
 * This script should be run every 5 minutes via cron job to check for documents
 * with resubmission deadlines approaching (1 hour before deadline) and send
 * reminder notifications to presidents and advisers.
 * 
 * Cron job setup:
 * Every 5 minutes: Run this script every 5 minutes
 * 
 * Or for Windows Task Scheduler:
 * Create a batch file that runs this PHP script every 5 minutes
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/cron_errors.log');

// Set timezone
date_default_timezone_set('Asia/Manila');

// Start execution log
$startTime = microtime(true);
$logMessage = "[" . date('Y-m-d H:i:s') . "] Deadline reminder cron job started\n";
error_log($logMessage);

try {
    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/deadline_reminder_helper.php';
    
    // Check if database connection is available
    if (!isset($conn) || !$conn) {
        throw new Exception("Database connection not available");
    }
    
    // Run the deadline reminder check
    $results = checkAndSendDeadlineReminders();
    
    // Log results
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Deadline reminder cron job completed in {$executionTime}ms\n";
    $logMessage .= "Results: " . json_encode($results) . "\n";
    error_log($logMessage);
    
    // Exit with success code
    exit(0);
    
} catch (Exception $e) {
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    $logMessage = "[" . date('Y-m-d H:i:s') . "] Deadline reminder cron job failed after {$executionTime}ms\n";
    $logMessage .= "Error: " . $e->getMessage() . "\n";
    $logMessage .= "Stack trace: " . $e->getTraceAsString() . "\n";
    error_log($logMessage);
    
    // Exit with error code
    exit(1);
}
?>

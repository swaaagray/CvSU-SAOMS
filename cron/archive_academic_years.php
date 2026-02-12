<?php
/**
 * Academic Year and Semester Archiving Cron Job
 * This script should be run daily to automatically archive expired academic years
 * and update semester statuses
 * 
 * Usage: php archive_academic_years.php
 * Or set up as a cron job: 0 0 * * * php /path/to/archive_academic_years.php
 */

// Set timezone
date_default_timezone_set('Asia/Manila');

    // Include required files
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/academic_year_archiver.php';
    require_once __DIR__ . '/../includes/notification_helper.php';
    require_once __DIR__ . '/../includes/hybrid_status_checker.php';

// Log file for cron job
$logFile = __DIR__ . '/../logs/academic_year_archiving.log';

// Ensure logs directory exists
$logsDir = dirname($logFile);
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

/**
 * Log message to file
 */
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Update semester statuses based on current date
 */
function updateSemesterStatuses() {
    global $conn;
    
    $today = date('Y-m-d');
    
    try {
        $conn->begin_transaction();
        
        // Update all semesters based on current date
        $stmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = CASE 
                WHEN ? < start_date THEN 'inactive'
                WHEN ? BETWEEN start_date AND end_date THEN 'active'
                WHEN ? > end_date THEN 'archived'
            END,
            updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param("sss", $today, $today, $today);
        $stmt->execute();
        
        $affectedRows = $stmt->affected_rows;
        
        $conn->commit();
        
        return ['success' => true, 'affected_rows' => $affectedRows];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

// Start logging
logMessage("Starting academic year and semester archiving process");

try {
    // Run the comprehensive hybrid status check
    logMessage("Starting hybrid status check...");
    $results = runHybridStatusCheck();
    
    // Log results
    if ($results['academic_years_archived'] > 0) {
        logMessage("Successfully archived {$results['academic_years_archived']} academic years");
        logMessage("Reset {$results['organizations_reset']} organizations to unrecognized status");
        logMessage("Reset {$results['councils_reset']} councils to unrecognized status");
    } else {
        logMessage("No academic years needed archiving");
    }
    
    if ($results['academic_years_activated'] > 0) {
        logMessage("Successfully activated {$results['academic_years_activated']} academic years");
    }
    
    if ($results['semesters_archived'] > 0) {
        logMessage("Successfully archived {$results['semesters_archived']} semesters");
    }
    
    if ($results['semesters_activated'] > 0) {
        logMessage("Successfully activated {$results['semesters_activated']} semesters");
    }
    
    if ($results['notifications_cleaned'] > 0) {
        logMessage("Successfully cleaned up {$results['notifications_cleaned']} notifications");
    }
    
    // Log any errors
    if (!empty($results['errors'])) {
        foreach ($results['errors'] as $error) {
            logMessage("ERROR: $error");
        }
    }
    
    logMessage("Hybrid status check completed successfully");
    
} catch (Exception $e) {
    logMessage("FATAL ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
}

// Log completion
logMessage("Cron job execution finished");
?>

<?php
/**
 * Simplified Semester Status Updater
 * This function specifically handles updating semester statuses based on current date
 * It's designed to be reliable and not depend on complex functions that might have errors
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Update semester statuses based on current date
 * This function runs on every login to ensure semester statuses are always current
 * 
 * @return array Results of the update operation
 */
function updateSemesterStatusesOnLogin() {
    global $conn;
    
    $results = [
        'semesters_archived' => 0,
        'semesters_activated' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        $today = date('Y-m-d');
        
        // 1. Archive expired semesters (end_date < today)
        $archiveStmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = 'archived', updated_at = CURRENT_TIMESTAMP 
            WHERE status = 'active' 
            AND end_date < ?
        ");
        $archiveStmt->bind_param("s", $today);
        $archiveStmt->execute();
        $results['semesters_archived'] = $archiveStmt->affected_rows;
        
        // 2. Activate current semesters (today between start_date and end_date)
        $activateStmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = 'active', updated_at = CURRENT_TIMESTAMP 
            WHERE status != 'active' 
            AND ? BETWEEN start_date AND end_date
        ");
        $activateStmt->bind_param("s", $today);
        $activateStmt->execute();
        $results['semesters_activated'] = $activateStmt->affected_rows;
        
        // 3. Set inactive semesters (start_date > today)
        $inactiveStmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = 'inactive', updated_at = CURRENT_TIMESTAMP 
            WHERE status != 'inactive' 
            AND start_date > ?
        ");
        $inactiveStmt->bind_param("s", $today);
        $inactiveStmt->execute();
        
        $conn->commit();
        
        // Log the results
        if ($results['semesters_archived'] > 0 || $results['semesters_activated'] > 0) {
            error_log("Semester status update on login: {$results['semesters_archived']} archived, {$results['semesters_activated']} activated");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during semester status update: " . $e->getMessage();
        error_log("Semester status update failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Get current semester status summary for debugging
 * 
 * @return array Current semester status summary
 */
function getCurrentSemesterStatus() {
    global $conn;
    
    try {
        $summary = [
            'current_date' => date('Y-m-d'),
            'active_semesters' => [],
            'archived_semesters' => [],
            'inactive_semesters' => []
        ];
        
        // Get all semesters with their status
        $stmt = $conn->query("
            SELECT s.id, s.semester, s.status, s.start_date, s.end_date, t.school_year 
            FROM academic_semesters s
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            ORDER BY s.start_date DESC
        ");
        
        while ($row = $stmt->fetch_assoc()) {
            $semesterInfo = [
                'id' => $row['id'],
                'semester' => $row['semester'],
                'status' => $row['status'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'school_year' => $row['school_year']
            ];
            
            if ($row['status'] === 'active') {
                $summary['active_semesters'][] = $semesterInfo;
            } elseif ($row['status'] === 'archived') {
                $summary['archived_semesters'][] = $semesterInfo;
            } elseif ($row['status'] === 'inactive') {
                $summary['inactive_semesters'][] = $semesterInfo;
            }
        }
        
        return $summary;
        
    } catch (Exception $e) {
        error_log("Error getting semester status summary: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}
?>

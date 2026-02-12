<?php
require_once __DIR__ . '/../config/database.php';

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

        // Safety cleanup: delete student_data for semesters that have ended (archived)
        try {
            $cleanup = $conn->prepare("\n                DELETE sd FROM student_data sd\n                INNER JOIN academic_semesters s ON s.id = sd.semester_id\n                WHERE s.status = 'archived'\n            ");
            $cleanup->execute();
            error_log("Cron cleanup: deleted {$cleanup->affected_rows} student_data rows for archived semesters");
        } catch (Exception $e) {
            error_log("Cron cleanup failed deleting student_data for archived semesters: " . $e->getMessage());
        }

        $conn->commit();
        
        // Log the update
        error_log("Semester statuses updated for date: " . $today);
        
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error updating semester statuses: " . $e->getMessage());
        return false;
    }
}

// Run the update
if (updateSemesterStatuses()) {
    echo "Semester statuses updated successfully.\n";
} else {
    echo "Failed to update semester statuses.\n";
    exit(1);
}
?>
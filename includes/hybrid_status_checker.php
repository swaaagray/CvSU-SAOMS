<?php
/**
 * Hybrid Academic Year and Semester Status Checker
 * This system ensures academic year and semester statuses are always accurate
 * by checking and updating them both via cron jobs and user logins
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/academic_year_archiver.php';
require_once __DIR__ . '/notification_helper.php';

/**
 * Enforce student_data cleanup based on current semester states.
 * - Delete all rows for archived semesters
 * - If there is an active semester, delete all rows not belonging to it
 */
function enforceStudentDataCleanup() {
    global $conn;
    try {
        // 1) Delete any student_data where semester is archived
        $conn->query("DELETE sd FROM student_data sd INNER JOIN academic_semesters s ON s.id = sd.semester_id WHERE s.status = 'archived'");

        // 1a) Delete any student_data where semester_id is NULL (immediate cleanup)
        //     Requirement: once semester_id becomes NULL, the associated record must be deleted
        $conn->query("DELETE FROM student_data WHERE semester_id IS NULL");

        // 2) If an active semester exists, delete all student_data not in that semester
        $active = $conn->query("SELECT id FROM academic_semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
        if ($active && ($row = $active->fetch_assoc()) && !empty($row['id'])) {
            $activeId = (int)$row['id'];
            $stmt = $conn->prepare("DELETE FROM student_data WHERE semester_id IS NOT NULL AND semester_id <> ?");
            if ($stmt) {
                $stmt->bind_param("i", $activeId);
                $stmt->execute();
            }
        }
    } catch (Exception $e) {
        error_log('enforceStudentDataCleanup error: ' . $e->getMessage());
    }
}

/**
 * Enforce event_approvals cleanup based on current semester states.
 * - Delete event_approvals where semester_id is NULL (immediate cleanup)
 * - Delete event_approvals where semester_id references archived semesters
 * - Delete associated event_documents files before deleting event_approvals
 * - Update event_approvals to use currently active semester_id if needed
 */
function enforceEventApprovalsCleanup() {
    global $conn;
    try {
        // 1) Delete event_approvals where semester_id is NULL (immediate cleanup)
        //    First, delete associated event_documents files
        $stmt = $conn->prepare("
            SELECT ed.file_path 
            FROM event_documents ed 
            INNER JOIN event_approvals ea ON ed.event_approval_id = ea.id 
            WHERE ea.semester_id IS NULL
        ");
        if ($stmt && $stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $filePath = $row['file_path'] ?? '';
                if (!empty($filePath) && file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
        }
        
        // Delete event_approvals with NULL semester_id (cascade will delete event_documents)
        $conn->query("DELETE FROM event_approvals WHERE semester_id IS NULL");

        // 2) Delete event_approvals where semester_id references archived semesters
        //    First, delete associated event_documents files
        $stmt = $conn->prepare("
            SELECT ed.file_path 
            FROM event_documents ed 
            INNER JOIN event_approvals ea ON ed.event_approval_id = ea.id 
            INNER JOIN academic_semesters s ON ea.semester_id = s.id 
            WHERE s.status = 'archived'
        ");
        if ($stmt && $stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $filePath = $row['file_path'] ?? '';
                if (!empty($filePath) && file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
        }
        
        // Delete event_approvals with archived semester_id (cascade will delete event_documents)
        $conn->query("DELETE ea FROM event_approvals ea INNER JOIN academic_semesters s ON ea.semester_id = s.id WHERE s.status = 'archived'");

        // 3) Update event_approvals to use currently active semester_id if they have inactive semester_id
        $activeSemester = $conn->query("SELECT id FROM academic_semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
        if ($activeSemester && ($row = $activeSemester->fetch_assoc()) && !empty($row['id'])) {
            $activeId = (int)$row['id'];
            
            // Update event_approvals with inactive semester_id to use active semester_id
            $stmt = $conn->prepare("
                UPDATE event_approvals ea 
                INNER JOIN academic_semesters s ON ea.semester_id = s.id 
                SET ea.semester_id = ? 
                WHERE s.status = 'inactive'
            ");
            if ($stmt) {
                $stmt->bind_param("i", $activeId);
                $stmt->execute();
                $updatedCount = $stmt->affected_rows;
                if ($updatedCount > 0) {
                    error_log("Updated {$updatedCount} event_approvals to use active semester_id {$activeId}");
                }
            }

		// 4) Safety sweep: delete orphaned event_documents (if any exist due to legacy data)
		try {
			// Delete files for orphaned documents
			$orphanStmt = $conn->query("
				SELECT ed.file_path
				FROM event_documents ed
				LEFT JOIN event_approvals ea ON ea.id = ed.event_approval_id
				WHERE ea.id IS NULL
			");
			if ($orphanStmt) {
				while ($row = $orphanStmt->fetch_assoc()) {
					$filePath = $row['file_path'] ?? '';
					if (!empty($filePath) && file_exists($filePath)) { @unlink($filePath); }
				}
			}
			// Delete orphaned rows
			$conn->query("DELETE ed FROM event_documents ed LEFT JOIN event_approvals ea ON ea.id = ed.event_approval_id WHERE ea.id IS NULL");
		} catch (Exception $e) {
			error_log('Orphan event_documents cleanup failed: ' . $e->getMessage());
		}
        }

    } catch (Exception $e) {
        error_log('enforceEventApprovalsCleanup error: ' . $e->getMessage());
    }
}

/**
 * Comprehensive status checker that runs on every user login
 * This ensures academic year and semester statuses are always current
 * 
 * @return array Results of the status checking process
 */
function runHybridStatusCheck() {
    global $conn;
    
    $results = [
        'academic_years_archived' => 0,
        'academic_years_activated' => 0,
        'semesters_archived' => 0,
        'semesters_activated' => 0,
        'organizations_reset' => 0,
        'councils_reset' => 0,
        'mis_coordinators_reset' => 0,
        'notifications_cleaned' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // 1. Check and archive expired academic years
        $academicYearResults = checkAndArchiveAcademicYears();
        $results['academic_years_archived'] = $academicYearResults['archived_count'];
        $results['organizations_reset'] += $academicYearResults['organizations_reset'];
        $results['councils_reset'] += $academicYearResults['councils_reset'];
        $results['notifications_cleaned'] += $academicYearResults['notifications_cleaned'];
        $results['errors'] = array_merge($results['errors'], $academicYearResults['errors']);
        
        // 2. Check and activate new academic years
        $activateYearResults = checkAndActivateAcademicYears();
        $results['academic_years_activated'] = $activateYearResults['activated_count'];
        $results['organizations_reset'] += $activateYearResults['updated_organizations'] ?? 0;
        $results['councils_reset'] += $activateYearResults['updated_councils'] ?? 0;
        $results['errors'] = array_merge($results['errors'], $activateYearResults['errors']);
        
        // 3. Check and archive expired semesters
        $semesterArchiveResults = checkAndArchiveSemesters();
        $results['semesters_archived'] = $semesterArchiveResults['archived_count'];
        $results['notifications_cleaned'] += $semesterArchiveResults['notifications_cleaned'];
        $results['errors'] = array_merge($results['errors'], $semesterArchiveResults['errors']);
        
        // 4. Check and activate new semesters
        $semesterActivateResults = checkAndActivateSemesters();
        $results['semesters_activated'] = $semesterActivateResults['activated_count'];
        $results['errors'] = array_merge($results['errors'], $semesterActivateResults['errors']);
        
        $conn->commit();
        
        // Log the results
        if ($results['academic_years_archived'] > 0 || $results['academic_years_activated'] > 0 || 
            $results['semesters_archived'] > 0 || $results['semesters_activated'] > 0) {
            error_log("Hybrid status check completed: {$results['academic_years_archived']} years archived, {$results['academic_years_activated']} years activated, {$results['semesters_archived']} semesters archived, {$results['semesters_activated']} semesters activated");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during hybrid status check: " . $e->getMessage();
        error_log("Hybrid status check failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Check and archive expired academic years
 * 
 * @return array Results of academic year archiving
 */
function checkAndArchiveAcademicYears() {
    global $conn;
    
    $results = [
        'archived_count' => 0,
        'organizations_reset' => 0,
        'councils_reset' => 0,
        'notifications_cleaned' => 0,
        'errors' => []
    ];
    
    try {
        // Find academic years that have ended but are still active
        $stmt = $conn->prepare("
            SELECT id, school_year, end_date 
            FROM academic_terms 
            WHERE status = 'active' 
            AND end_date < CURDATE()
        ");
        $stmt->execute();
        $expiredYears = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($expiredYears as $year) {
            // Archive the academic year
            $archiveStmt = $conn->prepare("
                UPDATE academic_terms 
                SET status = 'archived' 
                WHERE id = ?
            ");
            $archiveStmt->bind_param("i", $year['id']);
            
            if ($archiveStmt->execute()) {
                $results['archived_count']++;
                
                // Archive all semesters belonging to this academic year
                $archiveSemestersStmt = $conn->prepare("
                    UPDATE academic_semesters 
                    SET status = 'archived', updated_at = CURRENT_TIMESTAMP
                    WHERE academic_term_id = ?
                ");
                $archiveSemestersStmt->bind_param("i", $year['id']);
                $archiveSemestersStmt->execute();
                
                // Reset all organizations linked to this academic year
                $resetOrgStmt = $conn->prepare("
                    UPDATE organizations 
                    SET status = 'unrecognized', academic_year_id = NULL, adviser_name = NULL, president_name = NULL
                    WHERE academic_year_id = ?
                ");
                $resetOrgStmt->bind_param("i", $year['id']);
                $resetOrgStmt->execute();
                $results['organizations_reset'] += $resetOrgStmt->affected_rows;
                
                // Reset all councils linked to this academic year
                $resetCouncilStmt = $conn->prepare("
                    UPDATE council 
                    SET status = 'unrecognized', academic_year_id = NULL, adviser_name = NULL, president_name = NULL
                    WHERE academic_year_id = ?
                ");
                $resetCouncilStmt->bind_param("i", $year['id']);
                $resetCouncilStmt->execute();
                $results['councils_reset'] += $resetCouncilStmt->affected_rows;

                // Reset all MIS coordinators linked to this academic year
				// Follow the same behavior as organizations/council: set to new active year if available, otherwise NULL
				$newActive = getCurrentActiveAcademicYear();
				$newActiveId = ($newActive && isset($newActive['id']) && (int)$newActive['id'] !== (int)$year['id']) ? (int)$newActive['id'] : null;

				if ($newActiveId !== null) {
					$resetMisStmt = $conn->prepare("
						UPDATE mis_coordinators 
						SET academic_year_id = ?, coordinator_name = NULL 
						WHERE academic_year_id = ?
					");
					$resetMisStmt->bind_param("ii", $newActiveId, $year['id']);
				} else {
					$resetMisStmt = $conn->prepare("
						UPDATE mis_coordinators 
						SET academic_year_id = NULL, coordinator_name = NULL 
						WHERE academic_year_id = ?
					");
					$resetMisStmt->bind_param("i", $year['id']);
				}
                $resetMisStmt->execute();
                $results['mis_coordinators_reset'] += $resetMisStmt->affected_rows;
                
                // Delete all data from organization_documents, council_documents, and student_data for this academic year
                try {
                    // Delete organization documents for this academic year
                    $deleteOrgDocsStmt = $conn->prepare("DELETE FROM organization_documents WHERE academic_year_id = ?");
                    $deleteOrgDocsStmt->bind_param("i", $year['id']);
                    $deleteOrgDocsStmt->execute();
                    $deletedOrgDocs = $deleteOrgDocsStmt->affected_rows;
                    
                    // Delete council documents for this academic year
                    $deleteCouncilDocsStmt = $conn->prepare("DELETE FROM council_documents WHERE academic_year_id = ?");
                    $deleteCouncilDocsStmt->bind_param("i", $year['id']);
                    $deleteCouncilDocsStmt->execute();
                    $deletedCouncilDocs = $deleteCouncilDocsStmt->affected_rows;
                    
                    // Delete student data for all semesters of this academic year
                    $deleteStudentDataStmt = $conn->prepare("
                        DELETE sd FROM student_data sd 
                        INNER JOIN academic_semesters s ON sd.semester_id = s.id 
                        WHERE s.academic_term_id = ?
                    ");
                    $deleteStudentDataStmt->bind_param("i", $year['id']);
                    $deleteStudentDataStmt->execute();
                    $deletedStudentData = $deleteStudentDataStmt->affected_rows;
                    
                    // Log the data deletion
                    error_log("Hybrid status check: Deleted data for archived academic year {$year['school_year']}: {$deletedOrgDocs} organization documents, {$deletedCouncilDocs} council documents, {$deletedStudentData} student data records");
                    
                } catch (Exception $e) {
                    error_log("Hybrid status check: Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage());
                    $results['errors'][] = "Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage();
                }
                
                // Clean up notifications for the archived academic year
                try {
                    $docCleanup = cleanupDocumentNotifications($year['id']);
                    if ($docCleanup['success']) {
                        $results['notifications_cleaned'] += $docCleanup['deleted_count'];
                    }
                } catch (Exception $e) {
                    $results['errors'][] = "Notification cleanup failed for academic year {$year['school_year']}: " . $e->getMessage();
                }
                
                error_log("Archived academic year {$year['school_year']} on login check");
            } else {
                $results['errors'][] = "Failed to archive academic year {$year['school_year']}";
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "Error checking academic years: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Check and activate new academic years
 * 
 * @return array Results of academic year activation
 */
function checkAndActivateAcademicYears() {
    global $conn;
    
    $results = [
        'activated_count' => 0,
        'updated_organizations' => 0,
        'updated_councils' => 0,
        'errors' => []
    ];
    
    try {
        // Find academic years that should be active but aren't
        $stmt = $conn->prepare("
            SELECT id, school_year, start_date, end_date 
            FROM academic_terms 
            WHERE status != 'active' 
            AND start_date <= CURDATE() 
            AND end_date >= CURDATE()
        ");
        $stmt->execute();
        $yearsToActivate = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($yearsToActivate as $year) {
            $activateStmt = $conn->prepare("
                UPDATE academic_terms 
                SET status = 'active' 
                WHERE id = ?
            ");
            $activateStmt->bind_param("i", $year['id']);
            
            if ($activateStmt->execute()) {
                $results['activated_count']++;
                
                // Update existing organizations and councils to link them to the new active academic year
                $updateResults = updateExistingRecordsToActiveAcademicYear();
                $results['updated_organizations'] += $updateResults['updated_organizations'];
                $results['updated_councils'] += $updateResults['updated_councils'];
                if (!empty($updateResults['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $updateResults['errors']);
                }
                
                error_log("Activated academic year {$year['school_year']} on login check");
            } else {
                $results['errors'][] = "Failed to activate academic year {$year['school_year']}";
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "Error checking academic year activation: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Check and archive expired semesters
 * 
 * @return array Results of semester archiving
 */
function checkAndArchiveSemesters() {
    global $conn;
    
    $results = [
        'archived_count' => 0,
        'notifications_cleaned' => 0,
        'errors' => []
    ];
    
    try {
        // Find semesters that have ended but are still active
        $stmt = $conn->prepare("
            SELECT id, semester, academic_term_id 
            FROM academic_semesters 
            WHERE status = 'active' 
            AND end_date < CURDATE()
        ");
        $stmt->execute();
        $expiredSemesters = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($expiredSemesters as $semester) {
            // Archive the semester
            $archiveStmt = $conn->prepare("
                UPDATE academic_semesters 
                SET status = 'archived', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $archiveStmt->bind_param("i", $semester['id']);
            
            if ($archiveStmt->execute()) {
                $results['archived_count']++;
				
				// Delete student_data rows for this archived semester
				try {
					$deleteSd = $conn->prepare("DELETE FROM student_data WHERE semester_id = ?");
					$deleteSd->bind_param("i", $semester['id']);
					$deleteSd->execute();
					$deletedCount = $deleteSd->affected_rows;
					error_log("Archived semester {$semester['semester']} (ID: {$semester['id']}): deleted {$deletedCount} student_data rows");
				} catch (Exception $e) {
					$results['errors'][] = "Failed deleting student_data for archived semester {$semester['id']}: " . $e->getMessage();
				}
				
                // Clean up event notifications for this semester
                try {
                    $eventCleanup = cleanupEventNotifications($semester['id']);
                    if ($eventCleanup['success']) {
                        $results['notifications_cleaned'] += $eventCleanup['deleted_count'];
                    }
                } catch (Exception $e) {
                    $results['errors'][] = "Notification cleanup failed for semester {$semester['id']}: " . $e->getMessage();
                }
                
                error_log("Archived semester {$semester['semester']} (ID: {$semester['id']}) on login check");
            } else {
                $results['errors'][] = "Failed to archive semester {$semester['id']}";
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "Error checking semesters: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Check and activate new semesters
 * 
 * @return array Results of semester activation
 */
function checkAndActivateSemesters() {
    global $conn;
    
    $results = [
        'activated_count' => 0,
        'errors' => []
    ];
    
    try {
        // Find semesters that should be active but aren't
        $stmt = $conn->prepare("
            SELECT id, semester, academic_term_id 
            FROM academic_semesters 
            WHERE status != 'active' 
            AND start_date <= CURDATE() 
            AND end_date >= CURDATE()
        ");
        $stmt->execute();
        $semestersToActivate = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($semestersToActivate as $semester) {
            $activateStmt = $conn->prepare("
                UPDATE academic_semesters 
                SET status = 'active', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $activateStmt->bind_param("i", $semester['id']);
            
			if ($activateStmt->execute()) {
				$results['activated_count']++;
				
				// On activating a semester, ensure only the now-active semester retains student_data
				try {
					$cleanupStmt = $conn->prepare("DELETE FROM student_data WHERE semester_id IS NOT NULL AND semester_id != ?");
					$cleanupStmt->bind_param("i", $semester['id']);
					$cleanupStmt->execute();
					$cleaned = $cleanupStmt->affected_rows;
					error_log("Activated semester {$semester['semester']} (ID: {$semester['id']}): cleaned {$cleaned} non-current student_data rows");
				} catch (Exception $e) {
					$results['errors'][] = "Failed cleaning student_data on semester activation {$semester['id']}: " . $e->getMessage();
				}
				
				error_log("Activated semester {$semester['semester']} (ID: {$semester['id']}) on login check");
            } else {
                $results['errors'][] = "Failed to activate semester {$semester['id']}";
            }
        }
        
    } catch (Exception $e) {
        $results['errors'][] = "Error checking semester activation: " . $e->getMessage();
    }
    
    return $results;
}

/**
 * Get current status summary for debugging
 * 
 * @return array Current status summary
 */
function getCurrentStatusSummary() {
    global $conn;
    
    try {
        $summary = [
            'academic_years' => [],
            'semesters' => [],
            'current_date' => date('Y-m-d')
        ];
        
        // Get academic year status
        $stmt = $conn->query("
            SELECT id, school_year, status, start_date, end_date 
            FROM academic_terms 
            ORDER BY created_at DESC
        ");
        $summary['academic_years'] = $stmt->fetch_all(MYSQLI_ASSOC);
        
        // Get semester status
        $stmt = $conn->query("
            SELECT s.id, s.semester, s.status, s.start_date, s.end_date, t.school_year 
            FROM academic_semesters s
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            ORDER BY s.created_at DESC
        ");
        $summary['semesters'] = $stmt->fetch_all(MYSQLI_ASSOC);
        
        return $summary;
        
    } catch (Exception $e) {
        error_log("Error getting status summary: " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}
?>

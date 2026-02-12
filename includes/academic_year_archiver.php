<?php
/**
 * Academic Year Archiving System
 * Handles automatic archiving of academic years and resetting of organization/council status
 * Includes notification cleanup for archived periods
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notification_helper.php';

/**
 * Archive expired academic years and reset organization/council status
 * This function should be called by a cron job daily
 * 
 * @return array Results of the archiving process
 */
function archiveExpiredAcademicYears() {
    global $conn;
    
    $results = [
        'archived_years' => [],
        'reset_organizations' => 0,
        'reset_councils' => 0,
        'reset_mis_coordinators' => 0,
        'notifications_cleaned' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Find academic years that have ended but are still active
        // Use database date to avoid PHP timezone mismatches
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
                $results['archived_years'][] = $year['school_year'];
                
                // Archive all semesters belonging to this academic year
                $archiveSemestersStmt = $conn->prepare("
                    UPDATE academic_semesters 
                    SET status = 'archived', updated_at = CURRENT_TIMESTAMP
                    WHERE academic_term_id = ?
                ");
                $archiveSemestersStmt->bind_param("i", $year['id']);
                $archiveSemestersStmt->execute();
                $archivedSemesters = $archiveSemestersStmt->affected_rows;
                
                // Determine new active academic year (if any)
                $newActive = getCurrentActiveAcademicYear();
                $newActiveId = ($newActive && isset($newActive['id']) && (int)$newActive['id'] !== (int)$year['id']) ? (int)$newActive['id'] : null;

                // Reset all organizations linked to this academic year
                if ($newActiveId !== null) {
                    $resetOrgStmt = $conn->prepare("
                        UPDATE organizations 
                        SET status = 'unrecognized', 
                            academic_year_id = ?, 
                            type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                        WHERE academic_year_id = ?
                    ");
                    $resetOrgStmt->bind_param("ii", $newActiveId, $year['id']);
                } else {
                    $resetOrgStmt = $conn->prepare("
                        UPDATE organizations 
                        SET status = 'unrecognized', 
                            academic_year_id = NULL, 
                            adviser_name = NULL, 
                            president_name = NULL,
                            type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                        WHERE academic_year_id = ?
                    ");
                    $resetOrgStmt->bind_param("i", $year['id']);
                }
                $resetOrgStmt->execute();
                $results['reset_organizations'] += $resetOrgStmt->affected_rows;
                
                // Reset all councils linked to this academic year
                if ($newActiveId !== null) {
                    $resetCouncilStmt = $conn->prepare("
                        UPDATE council 
                        SET status = 'unrecognized', 
                            academic_year_id = ?, 
                            type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                        WHERE academic_year_id = ?
                    ");
                    $resetCouncilStmt->bind_param("ii", $newActiveId, $year['id']);
                } else {
                    $resetCouncilStmt = $conn->prepare("
                        UPDATE council 
                        SET status = 'unrecognized', 
                            academic_year_id = NULL, 
                            adviser_name = NULL, 
                            president_name = NULL,
                            type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                        WHERE academic_year_id = ?
                    ");
                    $resetCouncilStmt->bind_param("i", $year['id']);
                }
                $resetCouncilStmt->execute();
                $results['reset_councils'] += $resetCouncilStmt->affected_rows;

				// Reset all MIS coordinators linked to this academic year
				if ($newActiveId !== null) {
					$resetMisStmt = $conn->prepare("\n                        UPDATE mis_coordinators \n                        SET academic_year_id = ?, coordinator_name = NULL \n                        WHERE academic_year_id = ?\n                    ");
					$resetMisStmt->bind_param("ii", $newActiveId, $year['id']);
				} else {
					$resetMisStmt = $conn->prepare("\n                        UPDATE mis_coordinators \n                        SET academic_year_id = NULL, coordinator_name = NULL \n                        WHERE academic_year_id = ?\n                    ");
					$resetMisStmt->bind_param("i", $year['id']);
				}
                $resetMisStmt->execute();
                $results['reset_mis_coordinators'] += $resetMisStmt->affected_rows;
                
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
                    error_log("Deleted data for archived academic year {$year['school_year']}: {$deletedOrgDocs} organization documents, {$deletedCouncilDocs} council documents, {$deletedStudentData} student data records");
                    
                } catch (Exception $e) {
                    error_log("Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage());
                    $results['errors'][] = "Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage();
                }
                
                // Clean up notifications for the archived academic year and its semesters
                try {
                    // Clean up document notifications for the archived academic year
                    $docCleanup = cleanupDocumentNotifications($year['id']);
                    if ($docCleanup['success']) {
                        $results['notifications_cleaned'] += $docCleanup['deleted_count'];
                        if ($docCleanup['deleted_count'] > 0) {
                            error_log("Cleaned up {$docCleanup['deleted_count']} document notifications for archived academic year {$year['school_year']}");
                        }
                    }
                    
                    // Clean up event notifications for all semesters of this academic year
                    $semesterStmt = $conn->prepare("SELECT id FROM academic_semesters WHERE academic_term_id = ?");
                    $semesterStmt->bind_param("i", $year['id']);
                    $semesterStmt->execute();
                    $semesters = $semesterStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    
                    $totalEventNotificationsDeleted = 0;
                    foreach ($semesters as $semester) {
                        $eventCleanup = cleanupEventNotifications($semester['id']);
                        if ($eventCleanup['success']) {
                            $totalEventNotificationsDeleted += $eventCleanup['deleted_count'];
                        }
                    }
                    
                    $results['notifications_cleaned'] += $totalEventNotificationsDeleted;
                    if ($totalEventNotificationsDeleted > 0) {
                        error_log("Cleaned up {$totalEventNotificationsDeleted} event notifications for archived semesters of academic year {$year['school_year']}");
                    }
                    
                } catch (Exception $e) {
                    error_log("Notification cleanup failed for academic year {$year['school_year']}: " . $e->getMessage());
                    $results['errors'][] = "Notification cleanup failed for academic year {$year['school_year']}: " . $e->getMessage();
                }
                
                // Log the archiving action
                error_log("Academic year {$year['school_year']} archived. Archived {$archivedSemesters} semesters. Reset {$results['reset_organizations']} organizations and {$results['reset_councils']} councils. Cleaned up {$results['notifications_cleaned']} notifications.");
                
            } else {
                $results['errors'][] = "Failed to archive academic year {$year['school_year']}";
            }
        }
        
        $conn->commit();
        
        // Log successful archiving
        if (!empty($results['archived_years'])) {
            error_log("Successfully archived " . count($results['archived_years']) . " academic years: " . implode(', ', $results['archived_years']) . ". Total notifications cleaned: {$results['notifications_cleaned']}");
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during archiving: " . $e->getMessage();
        error_log("Academic year archiving failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Archive a specific academic year manually
 * This function allows OSAS to manually archive a specific academic year
 * 
 * @param int $yearId The ID of the academic year to archive
 * @return array Results of the archiving process
 */
function archiveSpecificAcademicYear($yearId) {
    global $conn;
    
    $results = [
        'success' => false,
        'archived_year' => null,
        'reset_organizations' => 0,
        'reset_councils' => 0,
        'reset_mis_coordinators' => 0,
        'notifications_cleaned' => 0,
        'deleted_org_docs' => 0,
        'deleted_council_docs' => 0,
        'deleted_student_data' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Get the academic year details
        $stmt = $conn->prepare("
            SELECT id, school_year, end_date, status 
            FROM academic_terms 
            WHERE id = ?
        ");
        $stmt->bind_param("i", $yearId);
        $stmt->execute();
        $year = $stmt->get_result()->fetch_assoc();
        
        if (!$year) {
            $results['errors'][] = "Academic year not found";
            return $results;
        }
        
        if ($year['status'] === 'archived') {
            $results['errors'][] = "Academic year is already archived";
            return $results;
        }
        
        // Archive the academic year
        $archiveStmt = $conn->prepare("
            UPDATE academic_terms 
            SET status = 'archived' 
            WHERE id = ?
        ");
        $archiveStmt->bind_param("i", $yearId);
        
        if ($archiveStmt->execute()) {
            $results['archived_year'] = $year['school_year'];
            
            // Archive all semesters belonging to this academic year
            $archiveSemestersStmt = $conn->prepare("
                UPDATE academic_semesters 
                SET status = 'archived', updated_at = CURRENT_TIMESTAMP
                WHERE academic_term_id = ?
            ");
            $archiveSemestersStmt->bind_param("i", $yearId);
            $archiveSemestersStmt->execute();
            $archivedSemesters = $archiveSemestersStmt->affected_rows;
            
            // Determine new active academic year (if any)
            $newActive = getCurrentActiveAcademicYear();
            $newActiveId = ($newActive && isset($newActive['id']) && (int)$newActive['id'] !== (int)$yearId) ? (int)$newActive['id'] : null;

            // Reset all organizations linked to this academic year
            if ($newActiveId !== null) {
                $resetOrgStmt = $conn->prepare("
                    UPDATE organizations 
                    SET status = 'unrecognized', 
                        academic_year_id = ?, 
                        type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                    WHERE academic_year_id = ?
                ");
                $resetOrgStmt->bind_param("ii", $newActiveId, $yearId);
            } else {
                $resetOrgStmt = $conn->prepare("
                    UPDATE organizations 
                    SET status = 'unrecognized', 
                        academic_year_id = NULL, 
                        adviser_name = NULL, 
                        president_name = NULL,
                        type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                    WHERE academic_year_id = ?
                ");
                $resetOrgStmt->bind_param("i", $yearId);
            }
            $resetOrgStmt->execute();
            $results['reset_organizations'] = $resetOrgStmt->affected_rows;
            
            // Reset all councils linked to this academic year
            if ($newActiveId !== null) {
                $resetCouncilStmt = $conn->prepare("
                    UPDATE council 
                    SET status = 'unrecognized', 
                        academic_year_id = ?, 
                        type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                    WHERE academic_year_id = ?
                ");
                $resetCouncilStmt->bind_param("ii", $newActiveId, $yearId);
            } else {
                $resetCouncilStmt = $conn->prepare("
                    UPDATE council 
                    SET status = 'unrecognized', 
                        academic_year_id = NULL, 
                        adviser_name = NULL, 
                        president_name = NULL,
                        type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                    WHERE academic_year_id = ?
                ");
                $resetCouncilStmt->bind_param("i", $yearId);
            }
            $resetCouncilStmt->execute();
            $results['reset_councils'] = $resetCouncilStmt->affected_rows;

            // Reset all MIS coordinators linked to this academic year
            if ($newActiveId !== null) {
                $resetMisStmt = $conn->prepare("
                    UPDATE mis_coordinators 
                    SET academic_year_id = ?, coordinator_name = NULL 
                    WHERE academic_year_id = ?
                ");
                $resetMisStmt->bind_param("ii", $newActiveId, $yearId);
            } else {
                $resetMisStmt = $conn->prepare("
                    UPDATE mis_coordinators 
                    SET academic_year_id = NULL, coordinator_name = NULL 
                    WHERE academic_year_id = ?
                ");
                $resetMisStmt->bind_param("i", $yearId);
            }
            $resetMisStmt->execute();
            $results['reset_mis_coordinators'] = $resetMisStmt->affected_rows;
            
            // Delete all data from organization_documents, council_documents, and student_data for this academic year
            try {
                // Delete organization documents for this academic year
                $deleteOrgDocsStmt = $conn->prepare("DELETE FROM organization_documents WHERE academic_year_id = ?");
                $deleteOrgDocsStmt->bind_param("i", $yearId);
                $deleteOrgDocsStmt->execute();
                $results['deleted_org_docs'] = $deleteOrgDocsStmt->affected_rows;
                
                // Delete council documents for this academic year
                $deleteCouncilDocsStmt = $conn->prepare("DELETE FROM council_documents WHERE academic_year_id = ?");
                $deleteCouncilDocsStmt->bind_param("i", $yearId);
                $deleteCouncilDocsStmt->execute();
                $results['deleted_council_docs'] = $deleteCouncilDocsStmt->affected_rows;
                
                // Delete student data for all semesters of this academic year
                $deleteStudentDataStmt = $conn->prepare("
                    DELETE sd FROM student_data sd 
                    INNER JOIN academic_semesters s ON sd.semester_id = s.id 
                    WHERE s.academic_term_id = ?
                ");
                $deleteStudentDataStmt->bind_param("i", $yearId);
                $deleteStudentDataStmt->execute();
                $results['deleted_student_data'] = $deleteStudentDataStmt->affected_rows;
                
                // Log the data deletion
                error_log("Manually deleted data for archived academic year {$year['school_year']}: {$results['deleted_org_docs']} organization documents, {$results['deleted_council_docs']} council documents, {$results['deleted_student_data']} student data records");
                
            } catch (Exception $e) {
                error_log("Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage());
                $results['errors'][] = "Data deletion failed for academic year {$year['school_year']}: " . $e->getMessage();
            }
            
            // Clean up notifications for the archived academic year and its semesters
            try {
                // Clean up document notifications for the archived academic year
                $docCleanup = cleanupDocumentNotifications($yearId);
                if ($docCleanup['success']) {
                    $results['notifications_cleaned'] += $docCleanup['deleted_count'];
                    if ($docCleanup['deleted_count'] > 0) {
                        error_log("Cleaned up {$docCleanup['deleted_count']} document notifications for archived academic year {$year['school_year']}");
                    }
                }
                
                // Clean up event notifications for all semesters of this academic year
                $semesterStmt = $conn->prepare("SELECT id FROM academic_semesters WHERE academic_term_id = ?");
                $semesterStmt->bind_param("i", $yearId);
                $semesterStmt->execute();
                $semesters = $semesterStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                $totalEventNotificationsDeleted = 0;
                foreach ($semesters as $semester) {
                    $eventCleanup = cleanupEventNotifications($semester['id']);
                    if ($eventCleanup['success']) {
                        $totalEventNotificationsDeleted += $eventCleanup['deleted_count'];
                    }
                }
                
                $results['notifications_cleaned'] += $totalEventNotificationsDeleted;
                if ($totalEventNotificationsDeleted > 0) {
                    error_log("Cleaned up {$totalEventNotificationsDeleted} event notifications for archived semesters of academic year {$year['school_year']}");
                }
                
            } catch (Exception $e) {
                error_log("Notification cleanup failed for academic year {$year['school_year']}: " . $e->getMessage());
                $results['errors'][] = "Notification cleanup failed for academic year {$year['school_year']}: " . $e->getMessage();
            }
            
            // Log the archiving action
            error_log("Academic year {$year['school_year']} manually archived. Archived {$archivedSemesters} semesters. Reset {$results['reset_organizations']} organizations and {$results['reset_councils']} councils. Cleaned up {$results['notifications_cleaned']} notifications.");
            
            $results['success'] = true;
            
        } else {
            $results['errors'][] = "Failed to archive academic year {$year['school_year']}";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during archiving: " . $e->getMessage();
        error_log("Manual academic year archiving failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Archive expired academic semesters
 * Ensures that if a semester has ended, its status is set to archived
 * Includes notification cleanup for archived semesters
 *
 * @return array Results of the semester archiving process
 */
function archiveExpiredSemesters() {
    global $conn;
    
    $results = [
        'archived_semesters' => [],
        'archived_semesters_count' => 0,
        'notifications_cleaned' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Get semester details before archiving for notification cleanup
        $selectStmt = $conn->prepare("
            SELECT id, semester, academic_term_id 
            FROM academic_semesters 
            WHERE status = 'active' 
            AND end_date < CURDATE()
        ");
        $selectStmt->execute();
        $expiredSemesters = $selectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Perform a single bulk update for efficiency
        $updateStmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = 'archived', updated_at = CURRENT_TIMESTAMP 
            WHERE status = 'active' 
            AND end_date < CURDATE()
        ");
        $updateStmt->execute();
        $affected = $updateStmt->affected_rows;
        
        $results['archived_semesters_count'] = max(0, (int)$affected);
        if (!empty($expiredSemesters)) {
            $results['archived_semesters'] = array_map(function ($row) { return $row['id']; }, $expiredSemesters);
        }
        
        // Clean up event notifications for each archived semester
        if (!empty($expiredSemesters)) {
            foreach ($expiredSemesters as $semester) {
                try {
                    $eventCleanup = cleanupEventNotifications($semester['id']);
                    if ($eventCleanup['success']) {
                        $results['notifications_cleaned'] += $eventCleanup['deleted_count'];
                        if ($eventCleanup['deleted_count'] > 0) {
                            error_log("Cleaned up {$eventCleanup['deleted_count']} event notifications for archived semester {$semester['semester']} (ID: {$semester['id']})");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Notification cleanup failed for semester {$semester['id']}: " . $e->getMessage());
                    $results['errors'][] = "Notification cleanup failed for semester {$semester['id']}: " . $e->getMessage();
                }
            }
        }
        
        if ($affected > 0) {
            error_log("Archived {$affected} expired academic semesters (IDs: " . implode(', ', $results['archived_semesters']) . "). Cleaned up {$results['notifications_cleaned']} event notifications.");
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during semester archiving: " . $e->getMessage();
        error_log("Academic semester archiving failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Activate semesters whose start_date has arrived
 * Transitions semesters from inactive -> active when start_date <= today
 *
 * @return array Results of the semester activation process
 */
function activateSemesters() {
    global $conn;
    
    $results = [
        'activated_semesters' => [],
        'activated_semesters_count' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Capture IDs first (optional but useful for logs)
        $selectStmt = $conn->prepare("
            SELECT id 
            FROM academic_semesters 
            WHERE status = 'inactive' 
            AND start_date <= CURDATE()
        ");
        $selectStmt->execute();
        $semestersToActivate = $selectStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // Perform a single bulk update for efficiency
        $updateStmt = $conn->prepare("
            UPDATE academic_semesters 
            SET status = 'active', updated_at = CURRENT_TIMESTAMP 
            WHERE status = 'inactive' 
            AND start_date <= CURDATE()
        ");
        $updateStmt->execute();
        $affected = $updateStmt->affected_rows;
        
        $results['activated_semesters_count'] = max(0, (int)$affected);
        if (!empty($semestersToActivate)) {
            $results['activated_semesters'] = array_map(function ($row) { return $row['id']; }, $semestersToActivate);
        }
        
        if ($affected > 0) {
            error_log("Activated {$affected} academic semesters (IDs: " . implode(', ', $results['activated_semesters']) . ")");
        }
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during semester activation: " . $e->getMessage();
        error_log("Academic semester activation failed: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Get the current active academic year
 * 
 * @return array|null Current active academic year or null if none found
 */
function getCurrentActiveAcademicYear() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id, school_year, start_date, end_date, document_start_date, document_end_date 
            FROM academic_terms 
            WHERE status = 'active' 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ?: null;
    } catch (Exception $e) {
        error_log("Error getting current active academic year: " . $e->getMessage());
        return null;
    }
}

/**
 * Get the current active semester
 * 
 * @return array|null Current active semester or null if none found
 */
function getCurrentActiveSemester() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT s.id, s.semester, s.start_date, s.end_date, t.school_year 
            FROM academic_semesters s
            INNER JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE s.status = 'active' 
            ORDER BY s.created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result ?: null;
    } catch (Exception $e) {
        error_log("Error getting current active semester: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if a specific date falls within an active academic year
 * 
 * @param string $date Date to check (Y-m-d format)
 * @return bool True if date falls within an active academic year
 */
function isDateWithinActiveAcademicYear($date) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM academic_terms 
            WHERE status = 'active' 
            AND ? BETWEEN start_date AND end_date
        ");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking if date is within active academic year: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if a specific date falls within an active semester
 * 
 * @param string $date Date to check (Y-m-d format)
 * @return bool True if date falls within an active semester
 */
function isDateWithinActiveSemester($date) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM academic_semesters 
            WHERE status = 'active' 
            AND ? BETWEEN start_date AND end_date
        ");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking if date is within active semester: " . $e->getMessage());
        return false;
    }
}

/**
 * Update existing organizations, councils, and MIS coordinators to link them to the current active academic year
 * This function should be called when a new academic year is set as active
 * 
 * @return array Results of the update process
 */
function updateExistingRecordsToActiveAcademicYear() {
    global $conn;
    
    $results = [
        'updated_organizations' => 0,
        'updated_councils' => 0,
        'updated_mis_coordinators' => 0,
        'cleared_names_organizations' => 0,
        'cleared_names_councils' => 0,
        'cleared_names_mis' => 0,
        'cleared_non_osas_emails' => 0,
		'cleared_google_ids' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Get current active academic year
        $currentYear = getCurrentActiveAcademicYear();
        if (!$currentYear) {
            $results['errors'][] = "No active academic year found";
            return $results;
        }
        
        $activeYearId = $currentYear['id'];
        
        // Update organizations that don't have an academic_year_id, are linked to archived years, or are linked to a different active year
        $updateOrgStmt = $conn->prepare("
            UPDATE organizations o
            LEFT JOIN academic_terms t ON o.academic_year_id = t.id
            SET o.academic_year_id = ?, o.status = 'unrecognized', 
                o.type = CASE WHEN o.type = 'new' THEN 'old' ELSE o.type END
            WHERE o.academic_year_id IS NULL 
            OR (o.academic_year_id IS NOT NULL AND t.status = 'archived')
            OR (o.academic_year_id IS NOT NULL AND o.academic_year_id != ?)
        ");
        $updateOrgStmt->bind_param("ii", $activeYearId, $activeYearId);
        $updateOrgStmt->execute();
        $results['updated_organizations'] = $updateOrgStmt->affected_rows;
        
        // Update councils that don't have an academic_year_id, are linked to archived years, or are linked to a different active year
        $updateCouncilStmt = $conn->prepare("
            UPDATE council c
            LEFT JOIN academic_terms t ON c.academic_year_id = t.id
            SET c.academic_year_id = ?, c.status = 'unrecognized', 
                c.type = CASE WHEN c.type = 'new' THEN 'old' ELSE c.type END
            WHERE c.academic_year_id IS NULL 
            OR (c.academic_year_id IS NOT NULL AND t.status = 'archived')
            OR (c.academic_year_id IS NOT NULL AND c.academic_year_id != ?)
        ");
        $updateCouncilStmt->bind_param("ii", $activeYearId, $activeYearId);
        $updateCouncilStmt->execute();
        $results['updated_councils'] = $updateCouncilStmt->affected_rows;

		// Update MIS coordinators that don't have an academic_year_id, are linked to archived years, or are linked to a different active year
		$updateMisStmt = $conn->prepare("\n            UPDATE mis_coordinators mc\n            LEFT JOIN academic_terms t ON mc.academic_year_id = t.id\n            SET mc.academic_year_id = ?, mc.coordinator_name = NULL\n            WHERE mc.academic_year_id IS NULL \n            OR (mc.academic_year_id IS NOT NULL AND t.status = 'archived')\n            OR (mc.academic_year_id IS NOT NULL AND mc.academic_year_id != ?)\n        ");
        $updateMisStmt->bind_param("ii", $activeYearId, $activeYearId);
        $updateMisStmt->execute();
        $results['updated_mis_coordinators'] = $updateMisStmt->affected_rows;
        
        // When a new academic term becomes active, clear previous names and emails
        // 1) Clear adviser/president names in organizations (nullable columns)
        $clearOrgNamesStmt = $conn->prepare(
            "UPDATE organizations SET adviser_name = NULL, president_name = NULL"
        );
        if ($clearOrgNamesStmt) {
            $clearOrgNamesStmt->execute();
            $results['cleared_names_organizations'] = $clearOrgNamesStmt->affected_rows;
        }
        
        // 2) Clear adviser/president names in council (nullable columns)
        $clearCouncilNamesStmt = $conn->prepare(
            "UPDATE council SET adviser_name = NULL, president_name = NULL"
        );
        if ($clearCouncilNamesStmt) {
            $clearCouncilNamesStmt->execute();
            $results['cleared_names_councils'] = $clearCouncilNamesStmt->affected_rows;
        }
        
		// 3) Clear coordinator_name in mis_coordinators
		$clearMisNamesStmt = $conn->prepare(
			"UPDATE mis_coordinators SET coordinator_name = NULL"
		);
        if ($clearMisNamesStmt) {
            $clearMisNamesStmt->execute();
            $results['cleared_names_mis'] = $clearMisNamesStmt->affected_rows;
        }
        
        // 4) Clear users.email for all roles except OSAS using unique placeholders to satisfy NOT NULL + UNIQUE
        $clearEmailsStmt = $conn->prepare(
            "UPDATE users SET email = CONCAT('archived+', id, '@placeholder.local') WHERE role != 'osas'"
        );
        if ($clearEmailsStmt) {
            $clearEmailsStmt->execute();
            $results['cleared_non_osas_emails'] = $clearEmailsStmt->affected_rows;
        }

		// 5) Clear google_id for all users to prevent old Google-linked emails from accessing
		$clearGoogleIdsStmt = $conn->prepare(
			"UPDATE users SET google_id = NULL WHERE google_id IS NOT NULL"
		);
		if ($clearGoogleIdsStmt) {
			$clearGoogleIdsStmt->execute();
			$results['cleared_google_ids'] = $clearGoogleIdsStmt->affected_rows;
		}
        
        $conn->commit();
        
        // Log the update results
        if ($results['updated_organizations'] > 0 || $results['updated_councils'] > 0 || $results['updated_mis_coordinators'] > 0) {
            error_log("Updated existing records to active academic year {$currentYear['school_year']}: {$results['updated_organizations']} organizations, {$results['updated_councils']} councils, {$results['updated_mis_coordinators']} MIS coordinators");
        }
		error_log("Cleared names/emails on new active academic year: org names={$results['cleared_names_organizations']}, council names={$results['cleared_names_councils']}, mis names={$results['cleared_names_mis']}, non-OSAS emails={$results['cleared_non_osas_emails']}, google IDs={$results['cleared_google_ids']}");
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during record update: " . $e->getMessage();
        error_log("Failed to update existing records to active academic year: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Comprehensive academic year ID synchronization
 * This function ensures that all organizations, councils, and MIS coordinators have the correct academic_year_id
 * based on the current active academic year status. Runs on every login.
 * 
 * @return array Results of the synchronization process
 */
function syncAcademicYearIds() {
    global $conn;
    
    $results = [
        'updated_organizations' => 0,
        'updated_councils' => 0,
        'updated_mis_coordinators' => 0,
        'errors' => []
    ];
    
    try {
        $conn->begin_transaction();
        
        // Get current active academic year
        $currentYear = getCurrentActiveAcademicYear();
        $activeYearId = $currentYear ? $currentYear['id'] : null;
        
        if ($activeYearId) {
            // There is an active academic year - ensure all records are linked to it
            $updateOrgStmt = $conn->prepare("
                UPDATE organizations 
                SET academic_year_id = ?, status = 'unrecognized', 
                    type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                WHERE academic_year_id IS NULL 
                OR academic_year_id != ?
            ");
            $updateOrgStmt->bind_param("ii", $activeYearId, $activeYearId);
            $updateOrgStmt->execute();
            $results['updated_organizations'] = $updateOrgStmt->affected_rows;
            
            $updateCouncilStmt = $conn->prepare("
                UPDATE council 
                SET academic_year_id = ?, status = 'unrecognized', 
                    type = CASE WHEN type = 'new' THEN 'old' ELSE type END
                WHERE academic_year_id IS NULL 
                OR academic_year_id != ?
            ");
            $updateCouncilStmt->bind_param("ii", $activeYearId, $activeYearId);
            $updateCouncilStmt->execute();
            $results['updated_councils'] = $updateCouncilStmt->affected_rows;

			// MIS coordinators
			$updateMisStmt = $conn->prepare("\n                UPDATE mis_coordinators \n                SET academic_year_id = ?, coordinator_name = NULL\n                WHERE academic_year_id IS NULL \n                OR academic_year_id != ?\n            ");
            $updateMisStmt->bind_param("ii", $activeYearId, $activeYearId);
            $updateMisStmt->execute();
            $results['updated_mis_coordinators'] = $updateMisStmt->affected_rows;

            error_log("Synced academic year IDs to active year {$currentYear['school_year']} (ID: {$activeYearId}): {$results['updated_organizations']} organizations, {$results['updated_councils']} councils, {$results['updated_mis_coordinators']} MIS coordinators");
        } else {
            // No active academic year - set all records to NULL
            $updateOrgStmt = $conn->prepare("
                UPDATE organizations 
                SET academic_year_id = NULL, status = 'unrecognized', adviser_name = NULL, president_name = NULL
                WHERE academic_year_id IS NOT NULL
            ");
            $updateOrgStmt->execute();
            $results['updated_organizations'] = $updateOrgStmt->affected_rows;
            
            $updateCouncilStmt = $conn->prepare("
                UPDATE council 
                SET academic_year_id = NULL, status = 'unrecognized', adviser_name = NULL, president_name = NULL
                WHERE academic_year_id IS NOT NULL
            ");
            $updateCouncilStmt->execute();
            $results['updated_councils'] = $updateCouncilStmt->affected_rows;

            $updateMisStmt = $conn->prepare("\n                UPDATE mis_coordinators \n                SET academic_year_id = NULL, coordinator_name = NULL\n                WHERE academic_year_id IS NOT NULL\n            ");
            $updateMisStmt->execute();
            $results['updated_mis_coordinators'] = $updateMisStmt->affected_rows;

            // Clear connected user emails when academic year is reset (scoped by links)
            // 1) Organization advisers
            $clearOrgAdviserEmailsStmt = $conn->prepare("\n                UPDATE users u\n                INNER JOIN organizations o ON o.adviser_id = u.id\n                SET u.email = NULL\n                WHERE o.academic_year_id IS NULL AND u.role != 'osas'\n            ");
            if ($clearOrgAdviserEmailsStmt) { $clearOrgAdviserEmailsStmt->execute(); }

            // 2) Organization presidents
            $clearOrgPresidentEmailsStmt = $conn->prepare("\n                UPDATE users u\n                INNER JOIN organizations o ON o.president_id = u.id\n                SET u.email = NULL\n                WHERE o.academic_year_id IS NULL AND u.role != 'osas'\n            ");
            if ($clearOrgPresidentEmailsStmt) { $clearOrgPresidentEmailsStmt->execute(); }

            // 3) Council advisers
            $clearCouncilAdviserEmailsStmt = $conn->prepare("\n                UPDATE users u\n                INNER JOIN council c ON c.adviser_id = u.id\n                SET u.email = NULL\n                WHERE c.academic_year_id IS NULL AND u.role != 'osas'\n            ");
            if ($clearCouncilAdviserEmailsStmt) { $clearCouncilAdviserEmailsStmt->execute(); }

            // 4) Council presidents
            $clearCouncilPresidentEmailsStmt = $conn->prepare("\n                UPDATE users u\n                INNER JOIN council c ON c.president_id = u.id\n                SET u.email = NULL\n                WHERE c.academic_year_id IS NULL AND u.role != 'osas'\n            ");
            if ($clearCouncilPresidentEmailsStmt) { $clearCouncilPresidentEmailsStmt->execute(); }

            // 5) MIS coordinators
            $clearMisCoordinatorEmailsStmt = $conn->prepare("\n                UPDATE users u\n                INNER JOIN mis_coordinators mc ON mc.user_id = u.id\n                SET u.email = NULL\n                WHERE mc.academic_year_id IS NULL AND u.role != 'osas'\n            ");
            if ($clearMisCoordinatorEmailsStmt) { $clearMisCoordinatorEmailsStmt->execute(); }

            error_log("Synced academic year IDs to NULL (no active year): {$results['updated_organizations']} organizations, {$results['updated_councils']} councils, {$results['updated_mis_coordinators']} MIS coordinators");
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $results['errors'][] = "Database error during academic year ID sync: " . $e->getMessage();
        error_log("Failed to sync academic year IDs: " . $e->getMessage());
    }
    
    return $results;
}

/**
 * Run archive check on president login
 * This function is called when a president logs in to ensure their organization
 * is properly assigned to the current academic year
 */
function runArchiveCheckOnPresidentLogin() {
    global $conn;
    
    try {
        // Get current user's organization
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) return;
        
        $stmt = $conn->prepare("
            SELECT o.id, o.academic_year_id, o.status, t.status as academic_year_status
            FROM organizations o
            LEFT JOIN academic_terms t ON o.academic_year_id = t.id
            WHERE o.president_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $org = $stmt->get_result()->fetch_assoc();
        
        if (!$org) return;
        
        // If organization is linked to an archived academic year, reassign to current active year
        if ($org['academic_year_id'] && $org['academic_year_status'] === 'archived') {
            $currentYear = getCurrentActiveAcademicYear();
            $newYearId = $currentYear ? $currentYear['id'] : null;
            
            $updateStmt = $conn->prepare("
                UPDATE organizations 
                SET academic_year_id = ?, status = 'unrecognized' 
                WHERE id = ?
            ");
            $updateStmt->bind_param("ii", $newYearId, $org['id']);
            $updateStmt->execute();
            
            error_log("Reassigned organization {$org['id']} from archived academic year to " . ($newYearId ? "academic year {$newYearId}" : "no academic year"));
        }
        
    } catch (Exception $e) {
        error_log("Archive check on president login failed: " . $e->getMessage());
    }
}

/**
 * Get academic year statistics
 * 
 * @return array Academic year statistics
 */
function getAcademicYearStats() {
    global $conn;
    
    try {
        $stats = [
            'total_years' => 0,
            'active_years' => 0,
            'archived_years' => 0,
            'total_semesters' => 0,
            'active_semesters' => 0,
            'archived_semesters' => 0,
            'inactive_semesters' => 0
        ];
        
        // Academic year stats
        $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM academic_terms GROUP BY status");
        $stmt->execute();
        $yearStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($yearStats as $stat) {
            $stats['total_years'] += $stat['count'];
            if ($stat['status'] === 'active') {
                $stats['active_years'] = $stat['count'];
            } elseif ($stat['status'] === 'archived') {
                $stats['archived_years'] = $stat['count'];
            }
        }
        
        // Semester stats
        $stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM academic_semesters GROUP BY status");
        $stmt->execute();
        $semesterStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        foreach ($semesterStats as $stat) {
            $stats['total_semesters'] += $stat['count'];
            if ($stat['status'] === 'active') {
                $stats['active_semesters'] = $stat['count'];
            } elseif ($stat['status'] === 'archived') {
                $stats['archived_semesters'] = $stat['count'];
            } elseif ($stat['status'] === 'inactive') {
                $stats['inactive_semesters'] = $stat['count'];
            }
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting academic year stats: " . $e->getMessage());
        return [
            'total_years' => 0,
            'active_years' => 0,
            'archived_years' => 0,
            'total_semesters' => 0,
            'active_semesters' => 0,
            'archived_semesters' => 0,
            'inactive_semesters' => 0
        ];
    }
}
?>
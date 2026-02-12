<?php
/**
 * Dashboard Helper Functions
 * Functions to get active data for dashboard statistics
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Get active events for the current academic year
 * 
 * @return array Array of active events
 */
function getActiveEvents($collegeId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT e.*, o.org_name, s.semester, t.school_year
            FROM events e
            LEFT JOIN organizations o ON e.organization_id = o.id
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE (s.status = 'active' OR s.status IS NULL)
        ";
        
        $params = [];
        $types = "";
        
        // Filter by college if provided (for MIS coordinators)
        if ($collegeId !== null) {
            $query .= " AND o.college_id = ?";
            $params[] = $collegeId;
            $types = "i";
        }
        
        $query .= " ORDER BY e.created_at DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get active awards for the current academic year
 * 
 * @return array Array of active awards
 */
function getActiveAwards($collegeId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT a.*, o.org_name, s.semester, t.school_year
            FROM awards a
            LEFT JOIN organizations o ON a.organization_id = o.id
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE (s.status = 'active' OR s.status IS NULL)
        ";
        
        $params = [];
        $types = "";
        
        // Filter by college if provided (for MIS coordinators)
        if ($collegeId !== null) {
            $query .= " AND o.college_id = ?";
            $params[] = $collegeId;
            $types = "i";
        }
        
        $query .= " ORDER BY a.created_at DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get active financial reports for the current academic year
 * 
 * @return array Array of active financial reports
 */
function getActiveFinancialReports() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT f.*, o.org_name, s.semester, t.school_year
            FROM financial_reports f
            LEFT JOIN organizations o ON f.organization_id = o.id
            LEFT JOIN academic_semesters s ON f.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE (s.status = 'active' OR s.status IS NULL)
            ORDER BY f.created_at DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active financial reports: " . $e->getMessage());
        return [];
    }
}

/**
 * Get active organizations for the current academic year
 * 
 * @return array Array of active organizations
 */
function getActiveOrganizations($collegeId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT o.*, t.school_year, t.status as academic_year_status
            FROM organizations o
            LEFT JOIN academic_terms t ON o.academic_year_id = t.id
            WHERE (t.status = 'active' OR o.academic_year_id IS NULL)
        ";
        
        $params = [];
        $types = "";
        
        // Filter by college if provided (for MIS coordinators)
        if ($collegeId !== null) {
            $query .= " AND o.college_id = ?";
            $params[] = $collegeId;
            $types = "i";
        }
        
        $query .= " ORDER BY o.created_at DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active organizations: " . $e->getMessage());
        return [];
    }
}

/**
 * Get active councils for the current academic year
 * 
 * @return array Array of active councils
 */
function getActiveCouncils() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT c.*, col.name as college_name, t.school_year, t.status as academic_year_status
            FROM council c
            LEFT JOIN colleges col ON c.college_id = col.id
            LEFT JOIN academic_terms t ON c.academic_year_id = t.id
            WHERE (t.status = 'active' OR c.academic_year_id IS NULL)
            ORDER BY c.created_at DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting active councils: " . $e->getMessage());
        return [];
    }
}

/**
 * Get organization-specific events for the current academic year
 * 
 * @param int $organization_id Organization ID
 * @return array Array of organization events
 */
function getOrganizationEvents($organization_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT e.*, s.semester, t.school_year
            FROM events e
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE e.organization_id = ? 
            AND (s.status = 'active' OR s.status IS NULL)
            ORDER BY e.created_at DESC
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting organization events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get organization-specific awards for the current academic year
 * 
 * @param int $organization_id Organization ID
 * @return array Array of organization awards
 */
function getOrganizationAwards($organization_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT a.*, s.semester, t.school_year
            FROM awards a
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE a.organization_id = ? 
            AND (s.status = 'active' OR s.status IS NULL)
            ORDER BY a.created_at DESC
        ");
        $stmt->bind_param("i", $organization_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting organization awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get council-specific events for the current academic year
 * 
 * @param int $council_id Council ID
 * @return array Array of council events
 */
function getCouncilEvents($council_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT e.*, s.semester, t.school_year
            FROM events e
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE e.council_id = ? 
            AND (s.status = 'active' OR s.status IS NULL)
            ORDER BY e.created_at DESC
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting council events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get council-specific awards for the current academic year
 * 
 * @param int $council_id Council ID
 * @return array Array of council awards
 */
function getCouncilAwards($council_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT a.*, s.semester, t.school_year
            FROM awards a
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms t ON s.academic_term_id = t.id
            WHERE a.council_id = ? 
            AND (s.status = 'active' OR s.status IS NULL)
            ORDER BY a.created_at DESC
        ");
        $stmt->bind_param("i", $council_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting council awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get dashboard statistics for a specific role
 * 
 * @param string $role User role
 * @param int|null $organization_id Organization ID (for org roles)
 * @param int|null $council_id Council ID (for council roles)
 * @return array Dashboard statistics
 */
function getDashboardStats($role, $organization_id = null, $council_id = null) {
    $stats = [
        'total_orgs' => 0,
        'total_events' => 0,
        'total_awards' => 0,
        'total_reports' => 0,
        'total_members' => 0
    ];
    
    switch ($role) {
        case 'mis_coordinator':
            $activeOrgs = getActiveOrganizations();
            $stats['total_orgs'] = count($activeOrgs);
            
            $activeEvents = getActiveEvents();
            $stats['total_events'] = count($activeEvents);
            
            $activeAwards = getActiveAwards();
            $stats['total_awards'] = count($activeAwards);
            break;
        
        case 'osas':
            $activeEvents = getActiveEvents();
            $stats['total_events'] = count($activeEvents);
            
            $activeAwards = getActiveAwards();
            $stats['total_awards'] = count($activeAwards);
            
            $activeReports = getActiveFinancialReports();
            $stats['total_reports'] = count($activeReports);
            break;
        
        case 'org_adviser':
        case 'org_president':
            if ($organization_id) {
                $orgEvents = getOrganizationEvents($organization_id);
                $stats['total_events'] = count($orgEvents);
                
                $orgAwards = getOrganizationAwards($organization_id);
                $stats['total_awards'] = count($orgAwards);
                
                // Get member count
                global $conn;
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_officials WHERE organization_id = ?");
                $stmt->bind_param("i", $organization_id);
                $stmt->execute();
                $result = $stmt->get_result()->fetch_assoc();
                $stats['total_members'] = $result['count'] ?? 0;
            }
            break;
        
        case 'council_president':
        case 'council_adviser':
            if ($council_id) {
                $councilEvents = getCouncilEvents($council_id);
                $stats['total_events'] = count($councilEvents);
                
                $councilAwards = getCouncilAwards($council_id);
                $stats['total_awards'] = count($councilAwards);
            }
            break;
    }
    
    return $stats;
}
?>

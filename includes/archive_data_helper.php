<?php
/**
 * Archive Data Helper Functions
 * Provides functions to retrieve archived academic year data for reporting
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Get all archived academic years
 * 
 * @return array Array of archived academic years
 */
function getArchivedAcademicYears() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id, school_year, start_date, end_date, 
                   document_start_date, document_end_date, 
                   created_at, updated_at
            FROM academic_terms 
            WHERE status = 'archived'
            ORDER BY end_date DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived academic years: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived semesters for a specific academic year
 * 
 * @param int $academicYearId
 * @return array Array of archived semesters
 */
function getArchivedSemesters($academicYearId) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT id, semester, start_date, end_date, status
            FROM academic_semesters 
            WHERE academic_term_id = ? AND status = 'archived'
            ORDER BY start_date ASC
        ");
        $stmt->bind_param("i", $academicYearId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived semesters: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived events for an organization
 * 
 * @param int $organizationId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived events
 */
function getArchivedOrganizationEvents($organizationId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT e.*, s.semester, at.school_year,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
            FROM events e
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE e.organization_id = ? AND at.status = 'archived'
        ";
        
        $params = [$organizationId];
        $types = "i";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY e.event_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived organization events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived events for a council (college-wide)
 * 
 * @param int $collegeId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived events
 */
function getArchivedCollegeEvents($collegeId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT e.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
            FROM events e
            LEFT JOIN organizations o ON e.organization_id = o.id
            LEFT JOIN council cn ON e.council_id = cn.id
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'
        ";
        
        $params = [$collegeId, $collegeId];
        $types = "ii";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY e.event_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived college events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived awards for an organization
 * 
 * @param int $organizationId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived awards
 */
function getArchivedOrganizationAwards($organizationId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT a.*, s.semester, at.school_year,
                   (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count
            FROM awards a
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE a.organization_id = ? AND at.status = 'archived'
        ";
        
        $params = [$organizationId];
        $types = "i";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY a.award_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived organization awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived awards for a college
 * 
 * @param int $collegeId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived awards
 */
function getArchivedCollegeAwards($collegeId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT a.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name,
                   (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count
            FROM awards a
            LEFT JOIN organizations o ON a.organization_id = o.id
            LEFT JOIN council cn ON a.council_id = cn.id
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'
        ";
        
        $params = [$collegeId, $collegeId];
        $types = "ii";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY a.award_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived college awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived financial reports for an organization
 * 
 * @param int $organizationId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived financial reports
 */
function getArchivedOrganizationFinancialReports($organizationId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT fr.*, s.semester, at.school_year
            FROM financial_reports fr
            LEFT JOIN academic_semesters s ON fr.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE fr.organization_id = ? AND at.status = 'archived'
        ";
        
        $params = [$organizationId];
        $types = "i";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY fr.report_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived organization financial reports: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived financial reports for a college
 * 
 * @param int $collegeId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived financial reports
 */
function getArchivedCollegeFinancialReports($collegeId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT fr.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name
            FROM financial_reports fr
            LEFT JOIN organizations o ON fr.organization_id = o.id
            LEFT JOIN council cn ON fr.council_id = cn.id
            LEFT JOIN academic_semesters s ON fr.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'
        ";
        
        $params = [$collegeId, $collegeId];
        $types = "ii";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY fr.report_date DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived college financial reports: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archived student officials for an organization
 * 
 * @param int $organizationId
 * @param int $academicYearId (optional)
 * @return array Array of archived student officials
 */
function getArchivedOrganizationOfficials($organizationId, $academicYearId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT so.*, at.school_year
            FROM student_officials so
            LEFT JOIN academic_terms at ON so.academic_year_id = at.id
            WHERE so.organization_id = ? AND at.status = 'archived'
        ";
        
        $params = [$organizationId];
        $types = "i";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        $query .= " ORDER BY at.end_date DESC, so.position ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived organization officials: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all archived events (for OSAS and MIS)
 * 
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @param int $collegeId (optional) - for MIS coordinator
 * @param int $organizationId (optional) - for organization filter
 * @param int $councilId (optional) - for council filter
 * @return array Array of archived events
 */
function getAllArchivedEvents($academicYearId = null, $semesterId = null, $collegeId = null, $organizationId = null, $councilId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT e.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name, 
                   COALESCE(c.name, cc.name) as college_name,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
            FROM events e
            LEFT JOIN organizations o ON e.organization_id = o.id
            LEFT JOIN colleges c ON o.college_id = c.id
            LEFT JOIN council cn ON e.council_id = cn.id
            LEFT JOIN colleges cc ON cn.college_id = cc.id
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE at.status = 'archived'
        ";
        
        $params = [];
        $types = "";
        
        if ($collegeId) {
            $query .= " AND (o.college_id = ? OR cn.college_id = ?)";
            $params[] = $collegeId;
            $params[] = $collegeId;
            $types .= "ii";
        }
        
        if ($organizationId) {
            $query .= " AND e.organization_id = ?";
            $params[] = $organizationId;
            $types .= "i";
        }
        
        if ($councilId) {
            $query .= " AND e.council_id = ?";
            $params[] = $councilId;
            $types .= "i";
        }
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY e.event_date DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting all archived events: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all archived awards (for OSAS and MIS)
 * 
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @param int $collegeId (optional) - for MIS coordinator
 * @param int $organizationId (optional) - for organization filter
 * @param int $councilId (optional) - for council filter
 * @return array Array of archived awards
 */
function getAllArchivedAwards($academicYearId = null, $semesterId = null, $collegeId = null, $organizationId = null, $councilId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT a.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name, 
                   COALESCE(c.name, cc.name) as college_name,
                   (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count
            FROM awards a
            LEFT JOIN organizations o ON a.organization_id = o.id
            LEFT JOIN colleges c ON o.college_id = c.id
            LEFT JOIN council cn ON a.council_id = cn.id
            LEFT JOIN colleges cc ON cn.college_id = cc.id
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE at.status = 'archived'
        ";
        
        $params = [];
        $types = "";
        
        if ($collegeId) {
            $query .= " AND (o.college_id = ? OR cn.college_id = ?)";
            $params[] = $collegeId;
            $params[] = $collegeId;
            $types .= "ii";
        }
        
        if ($organizationId) {
            $query .= " AND a.organization_id = ?";
            $params[] = $organizationId;
            $types .= "i";
        }
        
        if ($councilId) {
            $query .= " AND a.council_id = ?";
            $params[] = $councilId;
            $types .= "i";
        }
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY a.award_date DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting all archived awards: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all archived financial reports (for OSAS and MIS)
 * 
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @param int $collegeId (optional) - for MIS coordinator
 * @param int $organizationId (optional) - for organization filter
 * @param int $councilId (optional) - for council filter
 * @return array Array of archived financial reports
 */
function getAllArchivedFinancialReports($academicYearId = null, $semesterId = null, $collegeId = null, $organizationId = null, $councilId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT fr.*, s.semester, at.school_year, 
                   COALESCE(o.org_name, cn.council_name) as org_name, 
                   COALESCE(c.name, cc.name) as college_name
            FROM financial_reports fr
            LEFT JOIN organizations o ON fr.organization_id = o.id
            LEFT JOIN colleges c ON o.college_id = c.id
            LEFT JOIN council cn ON fr.council_id = cn.id
            LEFT JOIN colleges cc ON cn.college_id = cc.id
            LEFT JOIN academic_semesters s ON fr.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE at.status = 'archived'
        ";
        
        $params = [];
        $types = "";
        
        if ($collegeId) {
            $query .= " AND (o.college_id = ? OR cn.college_id = ?)";
            $params[] = $collegeId;
            $params[] = $collegeId;
            $types .= "ii";
        }
        
        if ($organizationId) {
            $query .= " AND fr.organization_id = ?";
            $params[] = $organizationId;
            $types .= "i";
        }
        
        if ($councilId) {
            $query .= " AND fr.council_id = ?";
            $params[] = $councilId;
            $types .= "i";
        }
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query .= " ORDER BY fr.report_date DESC";
        
        $stmt = $conn->prepare($query);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting all archived financial reports: " . $e->getMessage());
        return [];
    }
}

/**
 * Get archive statistics for an organization
 * 
 * @param int $organizationId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Statistics array
 */
function getOrganizationArchiveStats($organizationId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $params = [$organizationId];
        $types = "i";
        
        // Build WHERE conditions
        $academicYearCondition = "";
        if ($academicYearId) {
            $academicYearCondition = " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        $semesterCondition = "";
        if ($semesterId) {
            $semesterCondition = " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query = "
            SELECT 
                (SELECT COUNT(*) FROM events e
                 LEFT JOIN academic_semesters s ON e.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE e.organization_id = ? AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_events,
                (SELECT COUNT(*) FROM awards a
                 LEFT JOIN academic_semesters s ON a.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE a.organization_id = ? AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_awards,
                (SELECT COUNT(*) FROM financial_reports fr
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE fr.organization_id = ? AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_reports,
                (SELECT SUM(revenue) FROM financial_reports fr
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE fr.organization_id = ? AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_revenue,
                (SELECT SUM(expenses) FROM financial_reports fr
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE fr.organization_id = ? AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_expenses
        ";
        
        $stmt = $conn->prepare($query);
        // Bind parameters for each subquery (5 subqueries)
        $allParams = array_merge($params, $params, $params, $params, $params);
        $allTypes = str_repeat($types, 5);
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return [
            'total_events' => $result['total_events'] ?? 0,
            'total_awards' => $result['total_awards'] ?? 0,
            'total_reports' => $result['total_reports'] ?? 0,
            'total_revenue' => $result['total_revenue'] ?? 0,
            'total_expenses' => $result['total_expenses'] ?? 0,
            'net_balance' => ($result['total_revenue'] ?? 0) - ($result['total_expenses'] ?? 0)
        ];
    } catch (Exception $e) {
        error_log("Error getting organization archive stats: " . $e->getMessage());
        return [
            'total_events' => 0,
            'total_awards' => 0,
            'total_reports' => 0,
            'total_revenue' => 0,
            'total_expenses' => 0,
            'net_balance' => 0
        ];
    }
}

/**
 * Get archive statistics for a college
 * 
 * @param int $collegeId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Statistics array
 */
function getCollegeArchiveStats($collegeId, $academicYearId = null, $semesterId = null) {
    global $conn;
    
    try {
        $params = [$collegeId];
        $types = "i";
        
        // Build WHERE conditions
        $academicYearCondition = "";
        if ($academicYearId) {
            $academicYearCondition = " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        $semesterCondition = "";
        if ($semesterId) {
            $semesterCondition = " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        
        $query = "
            SELECT 
                (SELECT COUNT(*) FROM events e
                 LEFT JOIN organizations o ON e.organization_id = o.id
                 LEFT JOIN council cn ON e.council_id = cn.id
                 LEFT JOIN academic_semesters s ON e.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_events,
                (SELECT COUNT(*) FROM awards a
                 LEFT JOIN organizations o ON a.organization_id = o.id
                 LEFT JOIN council cn ON a.council_id = cn.id
                 LEFT JOIN academic_semesters s ON a.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_awards,
                (SELECT COUNT(*) FROM financial_reports fr
                 LEFT JOIN organizations o ON fr.organization_id = o.id
                 LEFT JOIN council cn ON fr.council_id = cn.id
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_reports,
                (SELECT SUM(revenue) FROM financial_reports fr
                 LEFT JOIN organizations o ON fr.organization_id = o.id
                 LEFT JOIN council cn ON fr.council_id = cn.id
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_revenue,
                (SELECT SUM(expenses) FROM financial_reports fr
                 LEFT JOIN organizations o ON fr.organization_id = o.id
                 LEFT JOIN council cn ON fr.council_id = cn.id
                 LEFT JOIN academic_semesters s ON fr.semester_id = s.id
                 LEFT JOIN academic_terms at ON s.academic_term_id = at.id
                 WHERE (o.college_id = ? OR cn.college_id = ?) AND at.status = 'archived'$academicYearCondition$semesterCondition) as total_expenses
        ";
        
        $stmt = $conn->prepare($query);
        // Bind parameters for each subquery (5 subqueries, each needs 2 college_id params now)
        $doubleParams = [$collegeId, $collegeId];
        if ($academicYearId) $doubleParams[] = $academicYearId;
        if ($semesterId) $doubleParams[] = $semesterId;
        $allParams = array_merge($doubleParams, $doubleParams, $doubleParams, $doubleParams, $doubleParams);
        $doubleTypes = "ii" . ($academicYearId ? "i" : "") . ($semesterId ? "i" : "");
        $allTypes = str_repeat($doubleTypes, 5);
        $stmt->bind_param($allTypes, ...$allParams);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return [
            'total_events' => $result['total_events'] ?? 0,
            'total_awards' => $result['total_awards'] ?? 0,
            'total_reports' => $result['total_reports'] ?? 0,
            'total_revenue' => $result['total_revenue'] ?? 0,
            'total_expenses' => $result['total_expenses'] ?? 0,
            'net_balance' => ($result['total_revenue'] ?? 0) - ($result['total_expenses'] ?? 0)
        ];
    } catch (Exception $e) {
        error_log("Error getting college archive stats: " . $e->getMessage());
        return [
            'total_events' => 0,
            'total_awards' => 0,
            'total_reports' => 0,
            'total_revenue' => 0,
            'total_expenses' => 0,
            'net_balance' => 0
        ];
    }
}

/**
 * Get archived organizations for a college
 * 
 * @param int $collegeId
 * @param int $academicYearId (optional)
 * @return array Array of organizations
 */
function getArchivedCollegeOrganizations($collegeId, $academicYearId = null) {
    global $conn;
    
    try {
        $query = "
            SELECT DISTINCT o.*, at.school_year
            FROM organizations o
            LEFT JOIN academic_terms at ON o.academic_year_id = at.id
            WHERE o.college_id = ? AND at.status = 'archived'
        ";
        
        $params = [$collegeId];
        $types = "i";
        
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        
        $query .= " ORDER BY o.org_name ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived college organizations: " . $e->getMessage());
        return [];
    }
}

// ================= COUNCIL-ONLY archive fetchers =====================
/**
 * Get archived council events (council-only)
 *
 * @param int $councilId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived council events
 */
function getArchivedCouncilEvents($councilId, $academicYearId = null, $semesterId = null) {
    global $conn;
    try {
        $query = "
            SELECT e.*, s.semester, at.school_year, cn.council_name,
                   (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
            FROM events e
            LEFT JOIN council cn ON e.council_id = cn.id
            LEFT JOIN academic_semesters s ON e.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE e.council_id = ? AND at.status = 'archived'";
        $params = [$councilId];
        $types = "i";
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        $query .= " ORDER BY e.event_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived council events: " . $e->getMessage());
        return [];
    }
}
/**
 * Get archived council awards (council-only)
 *
 * @param int $councilId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived council awards
 */
function getArchivedCouncilAwards($councilId, $academicYearId = null, $semesterId = null) {
    global $conn;
    try {
        $query = "
            SELECT a.*, s.semester, at.school_year, cn.council_name,
                   (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count
            FROM awards a
            LEFT JOIN council cn ON a.council_id = cn.id
            LEFT JOIN academic_semesters s ON a.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE a.council_id = ? AND at.status = 'archived'";
        $params = [$councilId];
        $types = "i";
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        $query .= " ORDER BY a.award_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived council awards: " . $e->getMessage());
        return [];
    }
}
/**
 * Get archived council financial reports (council-only)
 *
 * @param int $councilId
 * @param int $academicYearId (optional)
 * @param int $semesterId (optional)
 * @return array Array of archived council financial reports
 */
function getArchivedCouncilFinancialReports($councilId, $academicYearId = null, $semesterId = null) {
    global $conn;
    try {
        $query = "
            SELECT fr.*, s.semester, at.school_year, cn.council_name
            FROM financial_reports fr
            LEFT JOIN council cn ON fr.council_id = cn.id
            LEFT JOIN academic_semesters s ON fr.semester_id = s.id
            LEFT JOIN academic_terms at ON s.academic_term_id = at.id
            WHERE fr.council_id = ? AND at.status = 'archived'";
        $params = [$councilId];
        $types = "i";
        if ($academicYearId) {
            $query .= " AND at.id = ?";
            $params[] = $academicYearId;
            $types .= "i";
        }
        if ($semesterId) {
            $query .= " AND s.id = ?";
            $params[] = $semesterId;
            $types .= "i";
        }
        $query .= " ORDER BY fr.report_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting archived council financial reports: " . $e->getMessage());
        return [];
    }
}

?>


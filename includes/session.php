<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set up error handler to catch null array access errors
set_error_handler(function($severity, $message, $file, $line) {
    // Check if this is a null array access error
    if (strpos($message, 'Trying to access array offset on value of type null') !== false ||
        strpos($message, 'Attempting to access array offset on a null value') !== false) {
        
        // Log the error for debugging
        error_log("NULL ARRAY ACCESS ERROR: $message in $file on line $line");
        
        // Return true to prevent the error from being displayed
        return true;
    }
    
    // For other errors, use the default error handler
    return false;
});

// Ensure database connection is available for all helpers in this file
if (!isset($conn) || $conn === null) {
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (file_exists($dbConfigPath)) {
        require_once $dbConfigPath;
    }
}

// Ensure cleanup/enforcement helpers are available on every request
$hybridHelperPath = __DIR__ . '/hybrid_status_checker.php';
// Skip expensive/global enforcement when explicitly disabled (e.g., lightweight APIs)
$skipEnforcement = defined('SKIP_ENFORCEMENT') && SKIP_ENFORCEMENT === true;
if (!$skipEnforcement && file_exists($hybridHelperPath)) {
    require_once $hybridHelperPath;
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserRole() {
    return $_SESSION['role'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }

    // Enforce new academic year onboarding (newTerm.php) before accessing the system
    // Allowlist certain endpoints to avoid redirect loops
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    $allowlist = ['newTerm.php', 'login.php', 'logout.php', 'oauth_callback.php', 'notifications_simple.php', 'notifications.php'];
    if (!in_array($currentScript, $allowlist)) {
        if (function_exists('needsNewTermUpdate') ? needsNewTermUpdate() : false) {
            header('Location: newTerm.php');
            exit();
        }

        // Optionally skip enforcement (e.g., for lightweight API endpoints)
        $skipEnforcement = defined('SKIP_ENFORCEMENT') && SKIP_ENFORCEMENT === true;

        if (!$skipEnforcement) {
            // Enforce student_data cleanup on every authenticated request
            try {
                if (function_exists('enforceStudentDataCleanup')) {
                    enforceStudentDataCleanup();
                }
            } catch (Exception $e) {
                error_log('Authenticated student_data cleanup failed: ' . $e->getMessage());
            }

            // Enforce notification cleanup on every authenticated request
            try {
                if (function_exists('enforceNotificationCleanup')) {
                    enforceNotificationCleanup();
                }
            } catch (Exception $e) {
                error_log('Authenticated notification cleanup failed: ' . $e->getMessage());
            }

            // Enforce event_approvals cleanup on every authenticated request
            try {
                if (function_exists('enforceEventApprovalsCleanup')) {
                    enforceEventApprovalsCleanup();
                }
            } catch (Exception $e) {
                error_log('Authenticated event_approvals cleanup failed: ' . $e->getMessage());
            }
        }
    }
}

function requireRole($roles) {
    requireLogin();
    if (!in_array(getUserRole(), (array)$roles)) {
        header('Location: unauthorized.php');
        exit();
    }
}

function isRole($roles) {
    return in_array(getUserRole(), (array)$roles);
}

function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function getUserEmail() {
    return $_SESSION['email'] ?? null;
}

function isOAuthUser() {
    return isset($_SESSION['oauth_provider']) && in_array($_SESSION['oauth_provider'], ['google', 'cvsu']);
}

function isGoogleUser() {
    return isset($_SESSION['oauth_provider']) && $_SESSION['oauth_provider'] === 'google';
}

function isCvSUUser() {
    return isset($_SESSION['oauth_provider']) && $_SESSION['oauth_provider'] === 'cvsu';
}



function isOSASApproved() {
    return isset($_SESSION['osas_approved']) && $_SESSION['osas_approved'] === true;
}

function getOSASApprovalInfo() {
    global $conn;
    $userId = getCurrentUserId();
    if (!$userId) return null;
    
    $stmt = $conn->prepare("SELECT osas_approved FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getCurrentOrganizationId() {
    global $conn;
    
    if (!isset($_SESSION['user_id'])) {
        error_log("No user_id in session");
        return 0;
    }
    
    error_log("Getting organization ID for user: " . $_SESSION['user_id']);
    
    $userRole = $_SESSION['role'] ?? '';
    
    // For council presidents, this function should not be used - use getCurrentCouncilId() instead
    if ($userRole === 'council_president') {
        error_log("Warning: getCurrentOrganizationId called for council_president. Use getCurrentCouncilId() instead.");
        return 0;
    }
    
    // For regular org presidents and advisers
    $stmt = $conn->prepare("
        SELECT o.id as organization_id 
        FROM organizations o
        JOIN users u ON (o.president_id = u.id OR o.adviser_id = u.id)
        WHERE u.id = ?
        LIMIT 1
    ");
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return 0;
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return 0;
    }
    
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        error_log("Found organization ID: " . $row['organization_id'] . " for user: " . $_SESSION['user_id']);
        return $row['organization_id'];
    }
    
    error_log("No organization found for user: " . $_SESSION['user_id']);
    return 0;
}

function getCurrentCouncilId() {
    global $conn;
    
    if (!isset($_SESSION['user_id'])) {
        error_log("No user_id in session");
        return 0;
    }
    
    error_log("Getting council ID for user: " . $_SESSION['user_id']);
    
    $userRole = $_SESSION['role'] ?? '';
    
    // Support both council_president and council_adviser
    if ($userRole !== 'council_president' && $userRole !== 'council_adviser') {
        error_log("getCurrentCouncilId called for non-council role: " . $userRole);
        return 0;
    }
    
    if ($userRole === 'council_president') {
        $stmt = $conn->prepare("
            SELECT c.id as council_id 
            FROM council c
            WHERE c.president_id = ?
            LIMIT 1
        ");
    } else { // council_adviser
        $stmt = $conn->prepare("
            SELECT c.id as council_id 
            FROM council c
            WHERE c.adviser_id = ?
            LIMIT 1
        ");
    }
    
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return 0;
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return 0;
    }
    
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        error_log("Found council ID: " . $row['council_id'] . " for user: " . $_SESSION['user_id']);
        return (int)$row['council_id'];
    }
    
    error_log("No council found for user: " . $_SESSION['user_id']);
    return 0;
}

function getCurrentOrganizationAdviserName() {
    global $conn;
    
    $orgId = getCurrentOrganizationId();
    if (!$orgId) {
        return '';
    }
    
    $stmt = $conn->prepare("SELECT adviser_name FROM organizations WHERE id = ?");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['adviser_name'] ?? '';
    }
    
    return '';
}

function getCurrentCouncilAdviserName() {
    global $conn;
    
    $councilId = getCurrentCouncilId();
    if (!$councilId) {
        return '';
    }
    
    $stmt = $conn->prepare("SELECT adviser_name FROM council WHERE id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['adviser_name'] ?? '';
    }
    
    return '';
}

// Helpers for current academic term and semester
function getCurrentAcademicTermId($conn) {
    // If table is missing (fresh setup), avoid fatal errors
    if (!function_exists('dbTableExists') || !dbTableExists($conn, 'academic_terms')) {
        return 0;
    }
    // Try by explicit active status
    $stmt = $conn->prepare("SELECT id FROM academic_terms WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['id'])) {
            return (int)$row['id'];
        }
    }
    // Fallback by date range
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT id FROM academic_terms WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['id'])) {
                return (int)$row['id'];
            }
        }
    }
    return 0;
}

function getCurrentActiveSemesterId($conn) {
    if (!function_exists('dbTableExists') || !dbTableExists($conn, 'academic_semesters')) {
        return 0;
    }
    // Prefer explicit active status
    $stmt = $conn->prepare("SELECT id FROM academic_semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['id'])) {
            return (int)$row['id'];
        }
    }
    // Fallback by date range within active term
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT s.id FROM academic_semesters s JOIN academic_terms t ON s.academic_term_id = t.id WHERE ? BETWEEN s.start_date AND s.end_date AND t.status = 'active' ORDER BY s.start_date DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['id'])) {
                return (int)$row['id'];
            }
        }
    }
    return 0;
}

// Get active academic year date range
function getActiveAcademicYearDateRange($conn) {
    if (!function_exists('dbTableExists') || !dbTableExists($conn, 'academic_terms')) {
        return [ 'start_date' => null, 'end_date' => null, 'found' => false ];
    }
    // Try by explicit active status first
    $stmt = $conn->prepare("SELECT start_date, end_date FROM academic_terms WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['start_date']) && !empty($row['end_date'])) {
            return [
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'found' => true
            ];
        }
    }
    
    // Fallback by date range
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT start_date, end_date FROM academic_terms WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['start_date']) && !empty($row['end_date'])) {
                return [
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'found' => true
                ];
            }
        }
    }
    
    return [
        'start_date' => null,
        'end_date' => null,
        'found' => false
    ];
}

// Validate if a date falls within the active academic year
function validateDateWithinAcademicYear($conn, $date) {
    $academicYear = getActiveAcademicYearDateRange($conn);
    
    if (!$academicYear['found']) {
        return [
            'valid' => false,
            'error' => 'No active academic year found. Please contact administrator.'
        ];
    }
    
    $dateObj = new DateTime($date);
    $startDate = new DateTime($academicYear['start_date']);
    $endDate = new DateTime($academicYear['end_date']);
    
    if ($dateObj < $startDate || $dateObj > $endDate) {
        $startFormatted = $startDate->format('M d, Y');
        $endFormatted = $endDate->format('M d, Y');
        return [
            'valid' => false,
            'error' => "Date must be within the active academic year ({$startFormatted} - {$endFormatted})."
        ];
    }
    
    return [
        'valid' => true,
        'error' => null
    ];
}

// Determine if user must complete newTerm.php for the new academic year
function needsNewTermUpdate() {
    global $conn;

    $userId = getCurrentUserId();
    $role = getUserRole();
    if (!$userId || !$role) {
        return false;
    }

    // If schema not ready, skip gating quietly
    if (function_exists('dbTableExists') && !dbTableExists($conn, 'academic_terms')) {
        return false;
    }

    // Require an active academic year; if none, do not gate
    $activeTermId = getCurrentAcademicTermId($conn);
    if (!$activeTermId) {
        return false;
    }

    // Fresh email from DB to detect cleared placeholder
    $emailCleared = false;
    $stmtUser = $conn->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    if ($stmtUser) {
        $stmtUser->bind_param("i", $userId);
        $stmtUser->execute();
        if ($row = $stmtUser->get_result()->fetch_assoc()) {
            $freshEmail = $row['email'] ?? '';
            if (!$freshEmail || preg_match('/^archived\+[0-9]+@placeholder\\.local$/', $freshEmail)) {
                $emailCleared = true;
            }
        }
    }

    // Name cleared per role in related table
    if (in_array($role, ['org_adviser', 'org_president'])) {
        $col = $role === 'org_adviser' ? 'adviser' : 'president';
        $q = $conn->prepare("SELECT {$col}_name AS nm FROM organizations WHERE {$col}_id = ? LIMIT 1");
        if ($q) {
            $q->bind_param("i", $userId);
            $q->execute();
            if ($r = $q->get_result()->fetch_assoc()) {
                $nameCleared = is_null($r['nm']) || $r['nm'] === '';
                return $nameCleared || $emailCleared;
            }
        }
    } elseif (in_array($role, ['council_adviser', 'council_president'])) {
        $col = $role === 'council_adviser' ? 'adviser' : 'president';
        $q = $conn->prepare("SELECT {$col}_name AS nm FROM council WHERE {$col}_id = ? LIMIT 1");
        if ($q) {
            $q->bind_param("i", $userId);
            $q->execute();
            if ($r = $q->get_result()->fetch_assoc()) {
                $nameCleared = is_null($r['nm']) || $r['nm'] === '';
                return $nameCleared || $emailCleared;
            }
        }
    } elseif ($role === 'mis_coordinator') {
        $q = $conn->prepare("SELECT coordinator_name AS nm FROM mis_coordinators WHERE user_id = ? LIMIT 1");
        if ($q) {
            $q->bind_param("i", $userId);
            $q->execute();
            if ($r = $q->get_result()->fetch_assoc()) {
                $nameCleared = is_null($r['nm']) || $r['nm'] === '';
                return $nameCleared || $emailCleared;
            }
        }
    }

    return $emailCleared;
}

/**
 * Safely access array elements with null checks
 * Prevents "Trying to access array offset on value of type null" errors
 * 
 * @param array|null $array The array to access
 * @param string|int $key The key to access
 * @param mixed $default The default value if key doesn't exist or array is null
 * @return mixed The value or default
 */
function safeArrayAccess($array, $key, $default = null) {
    if ($array === null || !is_array($array) || !array_key_exists($key, $array)) {
        return $default;
    }
    return $array[$key];
}

/**
 * Safely access nested array elements with null checks
 * Prevents "Trying to access array offset on value of type null" errors
 * 
 * @param array|null $array The array to access
 * @param string|int $key1 The first key
 * @param string|int $key2 The second key
 * @param mixed $default The default value if any key doesn't exist or array is null
 * @return mixed The value or default
 */
function safeNestedArrayAccess($array, $key1, $key2, $default = null) {
    if ($array === null || !is_array($array) || !array_key_exists($key1, $array)) {
        return $default;
    }
    $nested = $array[$key1];
    if ($nested === null || !is_array($nested) || !array_key_exists($key2, $nested)) {
        return $default;
    }
    return $nested[$key2];
}

/**
 * Check if an array key exists and is not null
 * 
 * @param array|null $array The array to check
 * @param string|int $key The key to check
 * @return bool True if key exists and is not null
 */
function arrayKeyExistsAndNotNull($array, $key) {
    return $array !== null && is_array($array) && array_key_exists($key, $array) && $array[$key] !== null;
}

/**
 * Validate that an array is safe to access
 * 
 * @param mixed $array The variable to validate
 * @param string $context Context for error logging
 * @return bool True if array is safe to access
 */
function validateArrayAccess($array, $context = 'unknown') {
    if ($array === null) {
        error_log("ARRAY VALIDATION FAILED: Array is null in context: $context");
        return false;
    }
    if (!is_array($array)) {
        error_log("ARRAY VALIDATION FAILED: Variable is not an array in context: $context");
        return false;
    }
    return true;
}

/**
 * Safely access array with comprehensive validation and logging
 * 
 * @param array|null $array The array to access
 * @param string|int $key The key to access
 * @param mixed $default The default value
 * @param string $context Context for error logging
 * @return mixed The value or default
 */
function safeArrayAccessWithValidation($array, $key, $default = null, $context = 'unknown') {
    if (!validateArrayAccess($array, $context)) {
        return $default;
    }
    
    if (!array_key_exists($key, $array)) {
        error_log("ARRAY KEY MISSING: Key '$key' not found in context: $context");
        return $default;
    }
    
    return $array[$key];
}
// Utility: Check if a DB table exists (safe for fresh installs)
if (!function_exists('dbTableExists')) {
function dbTableExists($conn, $tableName) {
    if (!$conn) { return false; }
    try {
        $safe = $conn->real_escape_string($tableName);
        $res = $conn->query("SHOW TABLES LIKE '" . $safe . "'");
        return $res && $res->num_rows > 0;
    } catch (Throwable $e) {
        return false;
    }
}
}

?> 
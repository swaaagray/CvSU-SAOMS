<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/notification_helper.php';
require_once 'includes/academic_year_archiver.php';
require_once 'includes/dashboard_helper.php';

requireLogin();

$role = getUserRole();
$userId = getCurrentUserId();
$orgId = getCurrentOrganizationId();
$councilId = null;

// Run comprehensive academic year ID synchronization on every login
// This ensures all organizations and councils have the correct academic_year_id
$syncResults = syncAcademicYearIds();
if (!empty($syncResults['errors'])) {
    error_log("Academic year ID sync errors: " . implode(', ', $syncResults['errors']));
}

// If a president logs in, immediately run archive check to switch expired active years to archived
if (in_array($role, ['org_president', 'council_president'])) {
    runArchiveCheckOnPresidentLogin();
}

// For council presidents and advisers, get council ID instead
if (in_array($role, ['council_president', 'council_adviser'])) {
    $councilId = getCurrentCouncilId();
}

// Check for compliance notifications for presidents and advisers
$complianceNotification = null;
if (($role === 'org_president' || $role === 'org_adviser') && $orgId) {
    $complianceNotification = getOrganizationComplianceNotification($orgId);
} elseif (($role === 'council_president' || $role === 'council_adviser') && $councilId) {
    $complianceNotification = getCouncilComplianceNotification($councilId);
}

// Get user's organization or council information
$organization = null;
$council = null;
$college = null;
$course = null;
$misCoordinator = null;

if (in_array($role, ['org_adviser', 'org_president'])) {
    // For organization users, get organization details with college and course info
    $stmt = $conn->prepare("
        SELECT o.*, c.name as college_name, co.name as course_name 
        FROM organizations o
        LEFT JOIN colleges c ON o.college_id = c.id
        LEFT JOIN courses co ON o.course_id = co.id
        WHERE o.id = ?
    ");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $organization = $stmt->get_result()->fetch_assoc();
} elseif (in_array($role, ['council_president', 'council_adviser']) && $councilId) {
    // For council presidents and advisers, get council details with college info
    $stmt = $conn->prepare("
        SELECT c.*, co.name as college_name 
        FROM council c
        LEFT JOIN colleges co ON c.college_id = co.id
        WHERE c.id = ?
    ");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $council = $stmt->get_result()->fetch_assoc();
} elseif ($role === 'mis_coordinator') {
    // Check if academic_year_id column exists in mis_coordinators table
    $checkColumn = $conn->query("SHOW COLUMNS FROM mis_coordinators LIKE 'academic_year_id'");
    
    if ($checkColumn && $checkColumn->num_rows > 0) {
        // Column exists - use academic year filtering
        $stmt = $conn->prepare("
            SELECT mc.*, c.name as college_name, c.code as college_code, at.school_year
            FROM mis_coordinators mc
            LEFT JOIN colleges c ON mc.college_id = c.id
            LEFT JOIN academic_terms at ON mc.academic_year_id = at.id
            WHERE mc.user_id = ? AND (at.status = 'active' OR mc.academic_year_id IS NULL)
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $misCoordinator = $stmt->get_result()->fetch_assoc();
    } else {
        // Column doesn't exist - use original query
        $stmt = $conn->prepare("
            SELECT mc.*, c.name as college_name, c.code as college_code
            FROM mis_coordinators mc
            LEFT JOIN colleges c ON mc.college_id = c.id
            WHERE mc.user_id = ?
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $misCoordinator = $stmt->get_result()->fetch_assoc();
    }
}

// Determine display name based on role
$displayName = $_SESSION['username']; // Default to username

if (in_array($role, ['org_adviser', 'org_president']) && $organization) {
    if ($role === 'org_president' && !empty($organization['president_name'])) {
        $displayName = $organization['president_name'];
    } elseif ($role === 'org_adviser' && !empty($organization['adviser_name'])) {
        $displayName = $organization['adviser_name'];
    }
} elseif (in_array($role, ['council_president', 'council_adviser']) && $council) {
    if ($role === 'council_president' && !empty($council['president_name'])) {
        $displayName = $council['president_name'];
    } elseif ($role === 'council_adviser' && !empty($council['adviser_name'])) {
        $displayName = $council['adviser_name'];
    }
} elseif ($role === 'mis_coordinator' && $misCoordinator && !empty($misCoordinator['coordinator_name'])) {
    $displayName = $misCoordinator['coordinator_name'];
} elseif ($role === 'osas') {
    // For OSAS, use appropriate title
    $displayName = "OSAS Administrator";
}

// Get statistics based on role
$stats = [
    'total_events' => 0,
    'total_awards' => 0,
    'total_members' => 0,
    'total_orgs' => 0,
    'total_reports' => 0
];

switch ($role) {
    case 'mis_coordinator':
        // Get MIS coordinator's college ID
        $misCollegeId = $misCoordinator['college_id'] ?? null;
        
        // Get total organizations, events, and awards for active academic year only (filtered by college)
        $activeOrgs = getActiveOrganizations($misCollegeId);
        $stats['total_orgs'] = count($activeOrgs);
        
        $activeEvents = getActiveEvents($misCollegeId);
        $stats['total_events'] = count($activeEvents);
        
        $activeAwards = getActiveAwards($misCollegeId);
        $stats['total_awards'] = count($activeAwards);
        break;
    
    case 'osas':
        // Get total counts for OSAS dashboard (active academic year only)
        $activeEvents = getActiveEvents();
        $stats['total_events'] = count($activeEvents);
        
        $activeAwards = getActiveAwards();
        $stats['total_awards'] = count($activeAwards);
        
        $activeReports = getActiveFinancialReports();
        $stats['total_reports'] = count($activeReports);
        break;
    
    case 'org_adviser':
    case 'org_president':
        if ($orgId) {
            // Get organization-specific stats for active semesters only
            // Modified: ensure officer counts exclude those tied to past academic years.
            // Check if student_officials has an academic_year_id column
            $checkSO = $conn->query("SHOW COLUMNS FROM student_officials LIKE 'academic_year_id'");
            
            if ($checkSO && $checkSO->num_rows > 0) {
                // student_officials has its own academic_year_id — filter by that (active or NULL)
                $stmt = $conn->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM events e 
                         LEFT JOIN academic_semesters s ON e.semester_id = s.id
                         WHERE e.organization_id = ? AND (s.status = 'active' OR e.semester_id IS NULL)) as total_events,
                        (SELECT COUNT(*) FROM awards a 
                         LEFT JOIN academic_semesters s ON a.semester_id = s.id
                         WHERE a.organization_id = ? AND (s.status = 'active' OR a.semester_id IS NULL)) as total_awards,
                        (SELECT COUNT(*) FROM student_officials so
                         LEFT JOIN academic_terms at_so ON so.academic_year_id = at_so.id
                         WHERE so.organization_id = ? AND (at_so.status = 'active' OR so.academic_year_id IS NULL)) as total_members
                ");
            } else {
                // student_officials does not have academic_year_id — fall back to organization's academic_year_id
                $stmt = $conn->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM events e 
                         LEFT JOIN academic_semesters s ON e.semester_id = s.id
                         WHERE e.organization_id = ? AND (s.status = 'active' OR e.semester_id IS NULL)) as total_events,
                        (SELECT COUNT(*) FROM awards a 
                         LEFT JOIN academic_semesters s ON a.semester_id = s.id
                         WHERE a.organization_id = ? AND (s.status = 'active' OR a.semester_id IS NULL)) as total_awards,
                        (SELECT COUNT(*) FROM student_officials so 
                         JOIN organizations o ON so.organization_id = o.id
                         LEFT JOIN academic_terms at ON o.academic_year_id = at.id
                         WHERE so.organization_id = ? AND (at.status = 'active' OR o.academic_year_id IS NULL)) as total_members
                ");
            }
            
            if ($stmt) {
                $stmt->bind_param("iii", $orgId, $orgId, $orgId);
                if ($stmt->execute()) {
                    $result = $stmt->get_result()->fetch_assoc();
                    if ($result) {
                        $stats['total_events'] = $result['total_events'] ?? 0;
                        $stats['total_awards'] = $result['total_awards'] ?? 0;
                        $stats['total_members'] = $result['total_members'] ?? 0;
                    }
                }
            }
        }
        break;
    
    case 'council_president':
        if ($council && $council['college_id']) {
            // Get college-wide stats for all organizations in the college (active semesters only)
            $stmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM events e 
                     JOIN organizations o ON e.organization_id = o.id 
                     LEFT JOIN academic_semesters s ON e.semester_id = s.id
                     WHERE o.college_id = ? AND (s.status = 'active' OR e.semester_id IS NULL)) as total_events,
                    (SELECT COUNT(*) FROM awards a 
                     JOIN organizations o ON a.organization_id = o.id 
                     LEFT JOIN academic_semesters s ON a.semester_id = s.id
                     WHERE o.college_id = ? AND (s.status = 'active' OR a.semester_id IS NULL)) as total_awards,
                    (SELECT COUNT(*) FROM organizations o 
                     LEFT JOIN academic_terms at ON o.academic_year_id = at.id
                     WHERE o.college_id = ? AND (at.status = 'active' OR o.academic_year_id IS NULL)) as total_orgs
            ");
            
            if ($stmt) {
                $stmt->bind_param("iii", $council['college_id'], $council['college_id'], $council['college_id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result()->fetch_assoc();
                    if ($result) {
                        $stats['total_events'] = $result['total_events'] ?? 0;
                        $stats['total_awards'] = $result['total_awards'] ?? 0;
                        $stats['total_orgs'] = $result['total_orgs'] ?? 0;
                    }
                }
            }
        }
        break;
    
    case 'council_adviser':
        if ($council && $council['college_id']) {
            // Get college-wide stats for all organizations in the college (active semesters only)
            $stmt = $conn->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM events e 
                     JOIN organizations o ON e.organization_id = o.id 
                     LEFT JOIN academic_semesters s ON e.semester_id = s.id
                     WHERE o.college_id = ? AND (s.status = 'active' OR e.semester_id IS NULL)) as total_events,
                    (SELECT COUNT(*) FROM awards a 
                     JOIN organizations o ON a.organization_id = o.id 
                     LEFT JOIN academic_semesters s ON a.semester_id = s.id
                     WHERE o.college_id = ? AND (s.status = 'active' OR a.semester_id IS NULL)) as total_awards,
                    (SELECT COUNT(*) FROM organizations o 
                     LEFT JOIN academic_terms at ON o.academic_year_id = at.id
                     WHERE o.college_id = ? AND (at.status = 'active' OR o.academic_year_id IS NULL)) as total_orgs
            ");
            
            if ($stmt) {
                $stmt->bind_param("iii", $council['college_id'], $council['college_id'], $council['college_id']);
                if ($stmt->execute()) {
                    $result = $stmt->get_result()->fetch_assoc();
                    if ($result) {
                        $stats['total_events'] = $result['total_events'] ?? 0;
                        $stats['total_awards'] = $result['total_awards'] ?? 0;
                        $stats['total_orgs'] = $result['total_orgs'] ?? 0;
                    }
                }
            }
        }
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-left: 4px solid transparent;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }
        .dashboard-card:nth-child(1) {
            border-left-color: #F4C430; /* CVSU Gold */
        }
        .dashboard-card:nth-child(2) {
            border-left-color: #166534; /* CVSU Green */
        }
        .dashboard-card:nth-child(3) {
            border-left-color: #0d9488; /* Teal accent */
        }
        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        .dashboard-card:nth-child(1) .card-icon {
            color: #F4C430; /* CVSU Gold */
        }
        .dashboard-card:nth-child(2) .card-icon {
            color: #166534; /* CVSU Green */
        }
        .dashboard-card:nth-child(3) .card-icon {
            color: #0d9488; /* Teal accent */
        }
        .recent-activity {
            max-height: 300px;
            overflow-y: auto;
        }
        .activity-item {
            padding: 10px;
            border-left: 3px solid #F4C430; /* CVSU Gold */
            margin-bottom: 10px;
            background: linear-gradient(90deg, rgba(244, 196, 48, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border-radius: 5px;
        }
        .activity-item .d-flex {
            min-width: 0; /* Allow flex items to shrink below content size */
        }
        .activity-item .activity-title {
            flex: 1;
            min-width: 0; /* Important for ellipsis to work in flexbox */
            overflow: hidden;
        }
        .activity-item .activity-title strong {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .activity-item:nth-child(even) {
            border-left-color: #166534; /* CVSU Green */
            background: linear-gradient(90deg, rgba(22, 101, 52, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
        }
        .activity-item:nth-child(3n) {
            border-left-color: #0d9488; /* Teal accent */
            background: linear-gradient(90deg, rgba(13, 148, 136, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
        }
        .upcoming-events {
            max-height: 300px;
            overflow-y: auto;
        }
        .event-item {
            padding: 12px;
            border-left: 3px solid #F4C430; /* CVSU Gold */
            margin-bottom: 12px;
            background: linear-gradient(90deg, rgba(244, 196, 48, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border-radius: 5px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .event-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .event-item .d-flex {
            min-width: 0;
        }
        .event-item .event-title {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }
        .event-item .event-title strong {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .event-item:nth-child(even) {
            border-left-color: #166534; /* CVSU Green */
            background: linear-gradient(90deg, rgba(22, 101, 52, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
        }
        .event-item:nth-child(3n) {
            border-left-color: #0d9488; /* Teal accent */
            background: linear-gradient(90deg, rgba(13, 148, 136, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
        }
        .badge.bg-primary {
            background: linear-gradient(45deg, #F4C430, #FFD700) !important;
            color: #333333;
            font-weight: 600;
        }
        .alert-info {
            background: linear-gradient(90deg, rgba(244, 196, 48, 0.1) 0%, rgba(22, 101, 52, 0.1) 100%);
            border: 1px solid rgba(22, 101, 52, 0.3);
            color: #495057;
        }
        .alert-info .fas {
            color: #166534; /* CVSU Green */
        }
        h2.mb-0 {
            color: #343a40;
            font-weight: 600;
        }
        .card.dashboard-card .card-title {
            color: #495057;
            font-weight: 600;
        }
        .card.dashboard-card .display-4 {
            color: #343a40;
            font-weight: 700;
        }
        
        /* Enhanced organization/council info styling */
        .alert-info {
            background: linear-gradient(135deg, rgba(244, 196, 48, 0.05) 0%, rgba(22, 101, 52, 0.05) 100%);
            border: 1px solid rgba(22, 101, 52, 0.2);
            border-radius: 12px;
        }
        
        .badge.bg-success {
            background: linear-gradient(45deg, #166534, #198754) !important;
            box-shadow: 0 2px 4px rgba(22, 101, 52, 0.3);
        }
        
        .badge.bg-warning {
            background: linear-gradient(45deg, #F4C430, #FFD700) !important;
            box-shadow: 0 2px 4px rgba(244, 196, 48, 0.3);
            color: #333333;
        }
        
        .text-muted {
            color: #6c757d !important;
        }
        
        .fa-2x {
            opacity: 0.8;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                <div>
                    <h2 class="page-title">Welcome, <?php echo htmlspecialchars($displayName); ?></h2>
                    <p class="page-subtitle">Your dashboard overview and quick access</p>
                </div>
            </div>
            
            <?php if ($organization): ?>
            <div class="alert alert-info">
                <div>
                    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-2 gap-2">
                        <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center">
                            <h3 class="mb-0 me-md-3 mb-2 mb-md-0">
                                <i class="fas fa-users me-2"></i>
                                <?php echo htmlspecialchars($organization['org_name']); ?>
                            </h3>
                            <?php 
                            $statusClass = $organization['status'] === 'recognized' ? 'success' : 'warning';
                            $statusIcon = $organization['status'] === 'recognized' ? 'fa-check-circle' : 'fa-exclamation-triangle';
                            ?>
                            <span class="badge bg-<?php echo $statusClass; ?> d-flex align-items-center">
                                <i class="fas <?php echo $statusIcon; ?> me-1"></i>
                                <?php echo ucfirst($organization['status']); ?>
                            </span>
                        </div>
                        <a href="organization_settings.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-gear me-1"></i>Settings
                        </a>
                    </div>
                    <?php if ($organization['college_name']): ?>
                        <div class="mb-1">
                            <i class="fas fa-university me-2 text-muted"></i>
                            <strong>College:</strong> <?php echo htmlspecialchars($organization['college_name']); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($organization['course_name']): ?>
                        <div>
                            <i class="fas fa-graduation-cap me-2 text-muted"></i>
                            <strong>Course:</strong> <?php echo htmlspecialchars($organization['course_name']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($council): ?>
            <div class="alert alert-info">
                <div>
                    <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between mb-2 gap-2">
                        <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center">
                            <h3 class="mb-0 me-md-3 mb-2 mb-md-0">
                                <i class="fas fa-crown me-2"></i>
                                <?php echo htmlspecialchars($council['council_name']); ?>
                            </h3>
                            <?php 
                            $statusClass = $council['status'] === 'recognized' ? 'success' : 'warning';
                            $statusIcon = $council['status'] === 'recognized' ? 'fa-check-circle' : 'fa-exclamation-triangle';
                            ?>
                            <span class="badge bg-<?php echo $statusClass; ?> d-flex align-items-center">
                                <i class="fas <?php echo $statusIcon; ?> me-1"></i>
                                <?php echo ucfirst($council['status']); ?>
                            </span>
                        </div>
                        <a href="council_settings.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-gear me-1"></i>Settings
                        </a>
                    </div>
                    <?php if ($council['college_name']): ?>
                        <div>
                            <i class="fas fa-university me-2 text-muted"></i>
                            <strong>College:</strong> <?php echo htmlspecialchars($council['college_name']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($misCoordinator): ?>
            <div class="alert alert-info">
                <div>
                    <div class="d-flex align-items-center mb-2">
                        <h3 class="mb-0">
                            <i class="fas fa-user-tie me-2"></i>
                            MIS Coordinator
                        </h3>
                    </div>
                    <?php if ($misCoordinator['college_name']): ?>
                        <div class="mb-1">
                            <i class="fas fa-university me-2 text-muted"></i>
                            <strong>College:</strong> <?php echo htmlspecialchars($misCoordinator['college_name']); ?>
                            <?php if ($misCoordinator['college_code']): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($misCoordinator['college_code']); ?>)</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($misCoordinator['school_year']) && $misCoordinator['school_year']): ?>
                        <div>
                            <i class="fas fa-calendar-alt me-2 text-muted"></i>
                            <strong>Academic Year:</strong> <?php echo htmlspecialchars($misCoordinator['school_year']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>


            <?php if ($complianceNotification): ?>
            <?php
            // Determine alert class and icon based on notification type
            $alertClass = 'alert-info';
            $iconClass = 'fa-info-circle text-info';
            $titleText = 'Document Compliance Reminder';
            
            if ($complianceNotification['type'] === 'compliance_coming_soon') {
                $alertClass = 'alert-info';
                $iconClass = 'fa-info-circle text-info';
                $titleText = 'Document Submission Period Starting Soon';
            } elseif ($complianceNotification['type'] === 'compliance_ended') {
                $alertClass = 'alert-danger';
                $iconClass = 'fa-exclamation-triangle text-danger';
                $titleText = 'Document Submission Period Ended';
            } else {
                $alertClass = 'alert-warning';
                $iconClass = 'fa-exclamation-triangle text-warning';
                $titleText = 'Document Compliance Reminder';
            }
            ?>
            <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <div class="me-3">
                        <i class="fas <?php echo $iconClass; ?> fa-2x"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">
                            <i class="fas fa-bell me-2"></i>
                            <?php echo $titleText; ?>
                        </h5>
                        
                        <?php if ($complianceNotification['type'] === 'compliance_coming_soon'): ?>
                        <p class="mb-2">
                            The document submission period for the annual 
                            <strong>STUDENT ORGANIZATIONS' RECOGNITION SY <?php echo htmlspecialchars($complianceNotification['academic_year']['school_year']); ?></strong> 
                            will begin in 
                            <strong><?php echo $complianceNotification['days_until_start']; ?> day<?php echo $complianceNotification['days_until_start'] > 1 ? 's' : ''; ?></strong> 
                            on 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_start_date'])); ?></strong> 
                            and end on 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_end_date'])); ?></strong>. 
                            Please prepare your required documents for submission.
                        </p>
                        <p class="mb-0">
                            <strong class="text-info">Note:</strong> 
                            Document submission is not yet available. You will be able to submit documents once the submission period begins.
                        </p>
                        
                        <?php elseif ($complianceNotification['type'] === 'compliance_ended'): ?>
                        <p class="mb-2">
                            The document submission period for the annual 
                            <strong>STUDENT ORGANIZATIONS' RECOGNITION SY <?php echo htmlspecialchars($complianceNotification['academic_year']['school_year']); ?></strong> 
                            ended 
                            <strong><?php echo $complianceNotification['days_since_end']; ?> day<?php echo $complianceNotification['days_since_end'] > 1 ? 's' : ''; ?> ago</strong> 
                            on 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_end_date'])); ?></strong>. 
                            The submission period was from 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_start_date'])); ?></strong> 
                            to 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_end_date'])); ?></strong>.
                        </p>
                        <p class="mb-0">
                            <strong class="text-danger">Important note:</strong> 
                            Document submission is no longer available as the submission period has ended. Please contact OSAS for further assistance.
                        </p>
                        
                        <?php elseif (in_array($role, ['org_adviser', 'council_adviser'])): ?>
                        <p class="mb-2">
                            The application and submission of complete requirements for the annual 
                            <strong>STUDENT ORGANIZATIONS' RECOGNITION SY <?php echo htmlspecialchars($complianceNotification['academic_year']['school_year']); ?></strong> 
                            is currently active from 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_start_date'])); ?></strong> 
                            until 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_end_date'])); ?></strong>. 
                            Instruct the President of the <?php echo $role === 'org_adviser' ? 'Organization' : 'Council'; ?> regarding compliance.
                        </p>
                        <p class="mb-0">
                            <strong class="text-danger">Important note:</strong> 
                            Failure to comply with the given list of documentary requirements within the prescribed period will result in the forfeiture of your organization's application, accreditation, and recognition.
                        </p>
                        
                        <?php else: ?>
                        <p class="mb-2">
                            The application and submission of complete requirements for the annual 
                            <strong>STUDENT ORGANIZATIONS' RECOGNITION SY <?php echo htmlspecialchars($complianceNotification['academic_year']['school_year']); ?></strong> 
                            is currently active from 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_start_date'])); ?></strong> 
                            until 
                            <strong><?php echo date('F d, Y', strtotime($complianceNotification['academic_year']['document_end_date'])); ?></strong>. 
                            <?php if ($complianceNotification['can_submit']): ?>
                            Go to 
                            <a href="<?php echo $complianceNotification['document_page']; ?>" class="alert-link fw-bold">
                                <?php echo $role === 'org_president' ? 'Organization Documents' : 'Council Documents'; ?>
                            </a> 
                            and comply with the documents listed there.
                            <?php else: ?>
                            <span class="text-muted">Document submission is currently not available.</span>
                            <?php endif; ?>
                        </p>
                        <p class="mb-0">
                            <strong class="text-danger">Important note:</strong> 
                            Failure to comply with the given list of documentary requirements within the prescribed period will result in the forfeiture of your organization's application, accreditation, and recognition.
                        </p>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // Show profile update reminder below compliance reminder for presidents, advisers, and MIS coordinators
            $showProfileReminder = false;
            $hasActiveAcademicYear = false;

            // Check for any active academic year
            $ayStmt = $conn->query("SELECT 1 FROM academic_terms WHERE status = 'active' LIMIT 1");
            if ($ayStmt && $ayStmt->num_rows > 0) {
                $hasActiveAcademicYear = true;
            }

            if ($hasActiveAcademicYear) {
                // Fetch fresh email from DB (session may be stale after clearing)
                $freshEmail = null;
                $ueStmt = $conn->prepare("SELECT email, role FROM users WHERE id = ?");
                if ($ueStmt) {
                    $ueStmt->bind_param("i", $userId);
                    $ueStmt->execute();
                    $ueRes = $ueStmt->get_result()->fetch_assoc();
                    if ($ueRes) { $freshEmail = $ueRes['email']; }
                }

                // Detect cleared email pattern used during rollover
                $emailCleared = $freshEmail && preg_match('/^archived\+[0-9]+@placeholder\\.local$/', $freshEmail);

                if (in_array($role, ['org_president', 'org_adviser']) && $organization) {
                    $nameCleared = ($role === 'org_president')
                        ? (is_null($organization['president_name']) || $organization['president_name'] === '')
                        : (is_null($organization['adviser_name']) || $organization['adviser_name'] === '');
                    $showProfileReminder = $nameCleared || $emailCleared;
                } elseif (in_array($role, ['council_president', 'council_adviser']) && $council) {
                    $nameCleared = ($role === 'council_president')
                        ? (is_null($council['president_name']) || $council['president_name'] === '')
                        : (is_null($council['adviser_name']) || $council['adviser_name'] === '');
                    $showProfileReminder = $nameCleared || $emailCleared;
                } elseif ($role === 'mis_coordinator' && $misCoordinator) {
                    $nameCleared = !isset($misCoordinator['coordinator_name']) || $misCoordinator['coordinator_name'] === '';
                    $showProfileReminder = $nameCleared || $emailCleared;
                }
            }
            ?>

            <?php /* New academic year profile reminder removed; gating is now enforced globally via requireLogin() */ ?>

            <div class="row mb-4">
                <?php if ($role === 'mis_coordinator'): ?>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-building card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-users me-2"></i>
                                    Total Organizations
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_orgs']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Total Events
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_events']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-award card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-trophy me-2"></i>
                                    Total Awards
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_awards']; ?></p>
                            </div>
                        </div>
                    </div>
                <?php elseif ($role === 'osas'): ?>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Total Events
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_events']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-award card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-trophy me-2"></i>
                                    Total Awards
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_awards']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-chart-line card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-file-alt me-2"></i>
                                    Total Reports
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_reports']; ?></p>
                            </div>
                        </div>
                    </div>
                <?php elseif (in_array($role, ['council_president', 'council_adviser'])): ?>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    College Events
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_events']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-award card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-trophy me-2"></i>
                                    College Awards
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_awards']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-building card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-users me-2"></i>
                                    Organizations
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_orgs']; ?></p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-calendar-alt me-2"></i>
                                    Total Events
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_events']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-award card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-trophy me-2"></i>
                                    Total Awards
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_awards']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="dashboard-card card h-100">
                            <div class="card-body text-center">
                                <i class="fas fa-user-tie card-icon"></i>
                                <h5 class="card-title">
                                    <i class="fas fa-users me-2"></i>
                                    Total Officers
                                </h5>
                                <p class="card-text display-4"><?php echo $stats['total_members']; ?></p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="row">
                <div class="col-md-8 mb-4">
                    <div class="card dashboard-card">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="fas fa-calendar-alt me-2"></i>
                                Upcoming Events
                            </h5>
                            <div class="upcoming-events">
                                <?php
                                // Get upcoming events based on role (active semesters only)
                                if ($role === 'mis_coordinator') {
                                    $misCollegeId = $misCoordinator['college_id'] ?? null;
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN council c ON e.council_id = c.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.event_date >= CURRENT_DATE()
                                             AND (o.college_id = ? OR c.college_id = ?)
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date ASC LIMIT 8";
                                } elseif ($role === 'osas') {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN council c ON e.council_id = c.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.event_date >= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date ASC LIMIT 8";
                                } elseif (in_array($role, ['council_president', 'council_adviser'])) {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.council_id = ? 
                                             AND e.event_date >= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date ASC LIMIT 8";
                                } else {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.organization_id = ? 
                                             AND e.event_date >= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date ASC LIMIT 8";
                                }
                                
                                $stmt = $conn->prepare($query);
                                if ($role === 'mis_coordinator' && $misCoordinator) {
                                    $misCollegeId = $misCoordinator['college_id'];
                                    $stmt->bind_param("ii", $misCollegeId, $misCollegeId);
                                } elseif (in_array($role, ['council_president', 'council_adviser']) && $council) {
                                    $stmt->bind_param("i", $councilId);
                                } elseif ($role !== 'osas') {
                                    $stmt->bind_param("i", $orgId);
                                }
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $hasUpcomingEvents = false;
                                
                                while ($event = $result->fetch_assoc()): 
                                    $hasUpcomingEvents = true;
                                    $eventDate = strtotime($event['date']);
                                    $daysUntil = ceil(($eventDate - time()) / (60 * 60 * 24));
                                    $isToday = $daysUntil === 0;
                                    $isTomorrow = $daysUntil === 1;
                                    ?>
                                    <div class="event-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center event-title">
                                                <i class="fas fa-calendar-check me-2 flex-shrink-0 <?php echo $isToday ? 'text-danger' : ($isTomorrow ? 'text-warning' : 'text-primary'); ?>"></i>
                                                <div class="flex-grow-1">
                                                    <strong class="text-truncate d-block"><?php echo htmlspecialchars($event['title']); ?></strong>
                                                    <?php if ($isToday): ?>
                                                        <small class="text-danger fw-bold">Today</small>
                                                    <?php elseif ($isTomorrow): ?>
                                                        <small class="text-warning fw-bold">Tomorrow</small>
                                                    <?php elseif ($daysUntil <= 7): ?>
                                                        <small class="text-info">In <?php echo $daysUntil; ?> day<?php echo $daysUntil > 1 ? 's' : ''; ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <small class="text-muted flex-shrink-0 ms-2">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M d, Y', $eventDate); ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endwhile; 
                                
                                if (!$hasUpcomingEvents): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p class="mb-0">No upcoming events found</p>
                                        <small>Events from active semesters will appear here</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-4">
                    <div class="card dashboard-card">
                        <div class="card-body">
                            <h5 class="card-title mb-4">
                                <i class="fas fa-clock me-2"></i>
                                Recent Activities
                            </h5>
                            <div class="recent-activity">
                                <?php
                                // Get recent activities based on role (active semesters only)
                                $activities = [];
                                if ($role === 'mis_coordinator') {
                                    $misCollegeId = $misCoordinator['college_id'] ?? null;
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN council c ON e.council_id = c.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.event_date <= CURRENT_DATE()
                                             AND (o.college_id = ? OR c.college_id = ?)
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date DESC LIMIT 5";
                                } elseif ($role === 'osas') {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN council c ON e.council_id = c.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.event_date <= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date DESC LIMIT 5";
                                } elseif (in_array($role, ['council_president', 'council_adviser'])) {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.council_id = ? 
                                             AND e.event_date <= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date DESC LIMIT 5";
                                } else {
                                    $query = "SELECT e.title, e.event_date as date 
                                             FROM events e
                                             LEFT JOIN organizations o ON e.organization_id = o.id
                                             LEFT JOIN academic_semesters s ON e.semester_id = s.id
                                             WHERE e.organization_id = ? 
                                             AND e.event_date <= CURRENT_DATE()
                                             AND (s.status = 'active' OR s.status IS NULL OR e.semester_id IS NULL)
                                             ORDER BY e.event_date DESC LIMIT 5";
                                }
                                
                                $stmt = $conn->prepare($query);
                                if ($role === 'mis_coordinator' && $misCoordinator) {
                                    $misCollegeId = $misCoordinator['college_id'];
                                    $stmt->bind_param("ii", $misCollegeId, $misCollegeId);
                                } elseif (in_array($role, ['council_president', 'council_adviser']) && $council) {
                                    $stmt->bind_param("i", $councilId);
                                } elseif ($role !== 'osas') {
                                    $stmt->bind_param("i", $orgId);
                                }
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $hasActivities = false;
                                
                                while ($activity = $result->fetch_assoc()): 
                                    $hasActivities = true; ?>
                                    <div class="activity-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center activity-title">
                                                <i class="fas fa-calendar-check me-2 text-primary flex-shrink-0"></i>
                                                <strong class="text-truncate"><?php echo htmlspecialchars($activity['title']); ?></strong>
                                            </div>
                                            <small class="text-muted flex-shrink-0 ms-2">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M d, Y', strtotime($activity['date'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                <?php endwhile; 
                                
                                if (!$hasActivities): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="fas fa-calendar-times fa-2x mb-2"></i>
                                        <p class="mb-0">No recent activities found</p>
                                        <small>Events from active semesters will appear here</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
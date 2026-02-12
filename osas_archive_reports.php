<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/archive_data_helper.php';

requireLogin();

$role = getUserRole();

// Only allow OSAS
if ($role !== 'osas') {
    header('Location: unauthorized.php');
    exit;
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;
$selectedSemester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$selectedCollege = isset($_GET['college']) ? (int)$_GET['college'] : null;
$selectedOrganization = isset($_GET['organization']) ? $_GET['organization'] : null;
$reportType = isset($_GET['type']) ? $_GET['type'] : 'summary';

// Reset semester filter if no year is selected
if (!$selectedYear) {
    $selectedSemester = null;
}

// Reset organization filter if no college is selected
if (!$selectedCollege) {
    $selectedOrganization = null;
}

// Get archived academic years
$archivedYears = getArchivedAcademicYears();

// Get archived semesters for selected year
$archivedSemesters = [];
if ($selectedYear) {
    $archivedSemesters = getArchivedSemesters($selectedYear);
}

// Get all colleges for filter
$colleges = [];
$stmt = $conn->query("SELECT id, name, code FROM colleges ORDER BY name ASC");
if ($stmt) {
    $colleges = $stmt->fetch_all(MYSQLI_ASSOC);
}

// Get college council if college is selected
$collegeCouncil = null;
if ($selectedCollege) {
    $stmt = $conn->prepare("SELECT id, council_name FROM council WHERE college_id = ? LIMIT 1");
    $stmt->bind_param("i", $selectedCollege);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $collegeCouncil = $result->fetch_assoc();
    }
    $stmt->close();
}

// Get organizations for filter (all organizations for OSAS, or filtered by college if selected)
$organizations = [];
if ($selectedCollege) {
    $stmt = $conn->prepare("SELECT id, org_name FROM organizations WHERE college_id = ? ORDER BY org_name ASC");
    $stmt->bind_param("i", $selectedCollege);
    $stmt->execute();
    $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $stmt = $conn->query("SELECT id, org_name FROM organizations ORDER BY org_name ASC");
    if ($stmt) {
        $organizations = $stmt->fetch_all(MYSQLI_ASSOC);
    }
}

// Parse organization parameter to handle council selection (format: "council_ID" for councils)
$selectedCouncilId = null;
$selectedOrgId = null;
if ($selectedOrganization) {
    if (strpos($selectedOrganization, 'council_') === 0) {
        // It's a council selection
        $councilIdFromParam = (int)str_replace('council_', '', $selectedOrganization);
        // Validate that the council belongs to the selected college
        if ($selectedCollege && $collegeCouncil && $collegeCouncil['id'] == $councilIdFromParam) {
            $selectedCouncilId = $councilIdFromParam;
        }
    } else {
        // It's an organization selection - validate it belongs to selected college
        $orgIdFromParam = (int)$selectedOrganization;
        if ($selectedCollege) {
            // Check if this organization belongs to the selected college
            $orgFound = false;
            foreach ($organizations as $org) {
                if ($org['id'] == $orgIdFromParam) {
                    $orgFound = true;
                    break;
                }
            }
            if ($orgFound) {
                $selectedOrgId = $orgIdFromParam;
            }
        } else {
            $selectedOrgId = $orgIdFromParam;
        }
    }
}

// Get archived data based on filters (university-wide or college-specific)
// Pass council ID or organization ID separately
$events = getAllArchivedEvents($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);
$awards = getAllArchivedAwards($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);
$financialReports = getAllArchivedFinancialReports($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);

// Organizations are not archivable - they persist across academic years
// Removed organizations fetching logic

// Calculate statistics
$stats = [
    'total_events' => count($events),
    'total_awards' => count($awards),
    'total_reports' => count($financialReports),
    'total_revenue' => 0,
    'total_expenses' => 0,
    'net_balance' => 0
];

foreach ($financialReports as $report) {
    $stats['total_revenue'] += $report['revenue'];
    $stats['total_expenses'] += $report['expenses'];
}
$stats['net_balance'] = $stats['total_revenue'] - $stats['total_expenses'];

// Determine which columns to show based on filters
$showYearColumn = !$selectedYear; // Hide if year is selected
$showSemesterColumn = !$selectedSemester; // Hide if semester is selected
$showCollegeColumn = !$selectedCollege; // Hide if college is selected
$showOrganizationColumn = !$selectedOrganization; // Hide if organization is selected
// For Events and Awards: always show participants
$showParticipantsColumn = ($reportType == 'events' || $reportType == 'awards');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Reports - OSAS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-600: #0ea5e9; /* sky-500 */
            --primary-700: #0284c7; /* sky-600 */
            --secondary-600: #8b5cf6; /* violet-500 */
            --secondary-700: #7c3aed; /* violet-600 */
            --success-600: #22c55e; /* green-500 */
            --success-700: #16a34a; /* green-600 */
            --info-600: #06b6d4;   /* cyan-500 */
            --info-700: #0891b2;   /* cyan-600 */
            --slate-700: #334155;  /* slate-700 */
            --slate-800: #1f2937;  /* gray-800 */
            --surface: #f8fafc;    /* slate-50 */
            /* CvSU colorway */
            --cvsu-green-800: #14532d;
            --cvsu-green-700: #166534;
            --cvsu-green-600: #15803d;
            --cvsu-green-500: #22c55e;
            --cvsu-green-400: #34d399;
            --cvsu-moss-700: #3f6212;
            --cvsu-moss-600: #4d7c0f;
            /* Reference palette */
            --ref-red: #d32f2f;
            --ref-green: #166534;
            --ref-orange: #f97316;
            --ref-gray: #4b5563;
        }

        .archive-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(2, 132, 199, 0.08);
            margin-bottom: 20px;
            background: #ffffff;
        }

        .filter-section {
            background: var(--surface);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            border: 1px solid rgba(2, 132, 199, 0.1);
        }
        /* Form controls */
        .filter-section .form-select { border-color: rgba(2, 132, 199, 0.25); }
        .filter-section .form-select:focus {
            border-color: var(--primary-600);
            box-shadow: 0 0 0 0.2rem rgba(14, 165, 233, 0.25);
        }

        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(2, 132, 199, 0.08);
        }
        /* Tables */
        .table thead th {
            position: sticky;
            top: 0;
            background: #f1f5f9;
            color: #0f172a;
            border-bottom: 1px solid rgba(2, 132, 199, 0.12);
            z-index: 2;
        }
        .table-hover tbody tr:hover { background-color: #f0f9ff; }
        .table td, .table th { vertical-align: middle; }

        .stat-card {
            color: #ffffff;
            padding: 22px;
            border-radius: 14px;
            margin-bottom: 20px;
            box-shadow: 0 10px 22px rgba(2, 132, 199, 0.15);
        }
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .stat-card p {
            margin: 0;
            opacity: 0.95;
        }

        /* Stat cards mapped to reference colors */
        .gradient-primary { background: var(--ref-orange); }   /* Total Events */
        .gradient-magenta { background: var(--ref-red); }      /* Total Awards */
        .gradient-cyan { background: var(--ref-green); }       /* Total Revenue */
        .gradient-green { background: var(--ref-gray); }       /* Net Balance */

        .badge-archived { background: var(--slate-700); }
        .no-data-message {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .export-buttons { margin-bottom: 20px; }

        /* Headers override to match monochrome theme */
        .card-header.bg-primary,
        .card-header.bg-success,
        .card-header.bg-info {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white !important;
            border-radius: 15px 15px 0 0 !important;
        }
        .card-header h5 { 
            font-weight: 700;
            color: white !important;
        }

        /* Buttons */
        .btn-dark {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            border: none !important;
            color: white !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-dark:hover {
            background: linear-gradient(90deg, #212529, #343a40) !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
        }
        .btn-dark:focus,
        .btn-dark:active,
        .btn-dark.active {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            border: none !important;
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3) !important;
            outline: none !important;
            color: white !important;
        }
        
        /* KPI cards (subtle CvSU-themed colors) */
        .kpi-card {
            border-radius: 10px;
            padding: 12px 16px;
            background: #f1f5f9;
            border-left: 8px solid #64748b;
        }
        .kpi-revenue { background: rgba(34,197,94,0.08); border-left-color: #16a34a; }
        .kpi-expenses { background: rgba(220,38,38,0.08); border-left-color: #dc2626; }
        .kpi-balance { background: rgba(75,85,99,0.1); border-left-color: #4b5563; }
        /* Informational banner */
        .alert-info { background-color: #e0f2fe; border-color: #bae6fd; color: #075985; }

        /* Page heading spacing to match reference */
        .page-title { margin-top: 0 !important; margin-bottom: .5rem !important; font-weight: 700; font-size: 2.25rem; color: #1f2937; }
        .page-subtitle { margin-top: 0; margin-bottom: .75rem; color: #6b7280; }
        /* Table text truncation - prevent wrapping */
        .table-responsive table {
            table-layout: fixed;
            width: 100%;
        }
        .table-responsive table td,
        .table-responsive table th {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Set minimum widths for better column distribution */
        .table-responsive table th:first-child,
        .table-responsive table td:first-child {
            width: 50px;
        }
        .table-responsive table th:nth-child(2),
        .table-responsive table td:nth-child(2) {
            min-width: 100px;
        }
        .table-responsive table th:nth-child(3),
        .table-responsive table td:nth-child(3) {
            min-width: 120px;
        }
        .table-responsive table th:nth-child(4),
        .table-responsive table td:nth-child(4) {
            min-width: 150px;
        }
        .table-responsive table th:nth-child(5),
        .table-responsive table td:nth-child(5) {
            min-width: 100px;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Academic Archive Repository</h2>
                    <p class="page-subtitle">Browse historical records and statistics from archived academic years across all colleges</p>
                </div>
                <?php if (!empty($archivedYears)): ?>
                <div>
                    <a href="export_archive_report_pdf.php?year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&college=<?php echo $selectedCollege; ?>&organization=<?php echo htmlspecialchars($selectedOrganization ?? ''); ?>&type=<?php echo $reportType; ?>" 
                       class="btn btn-dark me-2" target="_blank">
                        <i class="fas fa-file-pdf me-2"></i>Export PDF
                    </a>
                    <a href="export_archive_report_csv.php?year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&college=<?php echo $selectedCollege; ?>&organization=<?php echo htmlspecialchars($selectedOrganization ?? ''); ?>&type=<?php echo $reportType; ?>" 
                       class="btn btn-success" target="_blank">
                        <i class="fas fa-file-csv me-2"></i>Export CSV
                    </a>
                </div>
                <?php endif; ?>
            </div>

            

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="osas_archive_reports.php" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-calendar me-1"></i>Academic Year</label>
                        <select name="year" class="form-select" onchange="this.form.submit()">
                            <option value="">All Archived Years</option>
                            <?php foreach ($archivedYears as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $selectedYear == $year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['school_year']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-calendar-alt me-1"></i>Semester</label>
                        <select name="semester" class="form-select" <?php echo !$selectedYear ? 'disabled' : ''; ?> onchange="this.form.submit()">
                            <option value="">All Semesters</option>
                            <?php foreach ($archivedSemesters as $semester): ?>
                                <option value="<?php echo $semester['id']; ?>" <?php echo $selectedSemester == $semester['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($semester['semester']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label"><i class="fas fa-university me-1"></i>College</label>
                        <select name="college" class="form-select" onchange="this.form.submit()">
                            <option value="">All Colleges</option>
                            <?php foreach ($colleges as $college): ?>
                                <option value="<?php echo $college['id']; ?>" <?php echo $selectedCollege == $college['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($college['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-users me-1"></i>Organization</label>
                        <select name="organization" class="form-select" <?php echo !$selectedCollege ? 'disabled' : ''; ?> onchange="this.form.submit()">
                            <option value="">All Organizations</option>
                            <?php if ($selectedCollege && $collegeCouncil): ?>
                                <option value="council_<?php echo $collegeCouncil['id']; ?>" <?php echo $selectedCouncilId == $collegeCouncil['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($collegeCouncil['council_name']); ?>
                                </option>
                            <?php endif; ?>
                            <?php foreach ($organizations as $org): ?>
                                <option value="<?php echo $org['id']; ?>" <?php echo $selectedOrgId == $org['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($org['org_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-filter me-1"></i>Report Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="summary" <?php echo $reportType == 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                            <option value="events" <?php echo $reportType == 'events' ? 'selected' : ''; ?>>Events Report</option>
                            <option value="awards" <?php echo $reportType == 'awards' ? 'selected' : ''; ?>>Awards Report</option>
                            <option value="financial" <?php echo $reportType == 'financial' ? 'selected' : ''; ?>>Financial Report</option>
                        </select>
                    </div>
                </form>
            </div>

            <?php if (empty($archivedYears)): ?>
                <div class="archive-card card">
                    <div class="card-body no-data-message">
                        <i class="fas fa-inbox fa-3x mb-3 text-muted"></i>
                        <h5>No Archived Data Available</h5>
                        <p>There are no archived academic years yet. Archive data will appear here after academic years are archived.</p>
                    </div>
                </div>
            <?php else: ?>

            <!-- Statistics Overview -->
            <?php if ($reportType == 'summary'): ?>
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stat-card gradient-primary">
                            <h3><?php echo $stats['total_events']; ?></h3>
                            <p><i class="fas fa-calendar-check me-2"></i>Total Events</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card gradient-magenta">
                            <h3><?php echo $stats['total_awards']; ?></h3>
                            <p><i class="fas fa-trophy me-2"></i>Total Awards</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card gradient-cyan">
                            <h3>₱<?php echo number_format($stats['total_revenue'], 2); ?></h3>
                            <p><i class="fas fa-coins me-2"></i>Total Revenue</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card gradient-green">
                            <h3>₱<?php echo number_format($stats['net_balance'], 2); ?></h3>
                            <p><i class="fas fa-balance-scale me-2"></i>Net Balance</p>
                        </div>
                    </div>
                </div>

                <!-- Summary Tables -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="archive-card card">
                            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Recent Events</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($events)): ?>
                                    <p class="text-muted text-center py-3">No archived events found</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <?php if ($showOrganizationColumn): ?>
                                                        <th>Organization</th>
                                                    <?php endif; ?>
                                                    <th>Title</th>
                                                    <th>Date</th>
                                                    <?php if ($showYearColumn): ?>
                                                        <th>Year</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($events, 0, 5) as $event): ?>
                                                    <tr>
                                                        <?php if ($showOrganizationColumn): ?>
                                                            <td><small><?php echo htmlspecialchars($event['org_name'] ?? 'N/A'); ?></small></td>
                                                        <?php endif; ?>
                                                        <td><small><?php echo htmlspecialchars(substr($event['title'], 0, 20)); ?></small></td>
                                                        <td class="text-nowrap"><small><?php echo date('M d, Y', strtotime($event['event_date'])); ?></small></td>
                                                        <?php if ($showYearColumn): ?>
                                                            <td><small><span class="badge badge-archived"><?php echo htmlspecialchars($event['school_year'] ?? 'N/A'); ?></span></small></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($events) > 5): ?>
                                        <a href="?type=events&year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&college=<?php echo $selectedCollege; ?>&organization=<?php echo htmlspecialchars($selectedOrganization ?? ''); ?>" class="btn btn-sm btn-outline-secondary">
                                            View All Events (<?php echo count($events); ?>)
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="archive-card card">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Recent Awards</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($awards)): ?>
                                    <p class="text-muted text-center py-3">No archived awards found</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <?php if ($showOrganizationColumn): ?>
                                                        <th>Organization</th>
                                                    <?php endif; ?>
                                                    <th>Title</th>
                                                    <th>Date</th>
                                                    <?php if ($showYearColumn): ?>
                                                        <th>Year</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($awards, 0, 5) as $award): ?>
                                                    <tr>
                                                        <?php if ($showOrganizationColumn): ?>
                                                            <td><small><?php echo htmlspecialchars($award['org_name'] ?? 'N/A'); ?></small></td>
                                                        <?php endif; ?>
                                                        <td><small><?php echo htmlspecialchars(substr($award['title'], 0, 20)); ?></small></td>
                                                        <td class="text-nowrap"><small><?php echo date('M d, Y', strtotime($award['award_date'])); ?></small></td>
                                                        <?php if ($showYearColumn): ?>
                                                            <td><small><span class="badge badge-archived"><?php echo htmlspecialchars($award['school_year'] ?? 'N/A'); ?></span></small></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($awards) > 5): ?>
                                        <a href="?type=awards&year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&college=<?php echo $selectedCollege; ?>&organization=<?php echo htmlspecialchars($selectedOrganization ?? ''); ?>" class="btn btn-sm btn-outline-secondary">
                                            View All Awards (<?php echo count($awards); ?>)
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Events Report -->
            <?php if ($reportType == 'events'): ?>
                <div class="archive-card card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Archived Events (University-wide)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($events)): ?>
                            <p class="text-muted text-center py-3">No archived events found for the selected filters</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <?php if ($showCollegeColumn): ?>
                                                <th>College</th>
                                            <?php endif; ?>
                                            <?php if ($showOrganizationColumn): ?>
                                                <th>Organization</th>
                                            <?php endif; ?>
                                            <th>Title</th>
                                            <th>Date</th>
                                            <th>Venue</th>
                                            <?php if ($showSemesterColumn): ?>
                                                <th>Semester</th>
                                            <?php endif; ?>
                                            <?php if ($showYearColumn): ?>
                                                <th>Academic Year</th>
                                            <?php endif; ?>
                                            <?php if ($showParticipantsColumn): ?>
                                                <th>Number of Participants</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($events as $index => $event): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <?php if ($showCollegeColumn): ?>
                                                    <td><?php echo htmlspecialchars($event['college_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showOrganizationColumn): ?>
                                                    <td><?php echo htmlspecialchars($event['org_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                <td class="text-nowrap"><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($event['venue'] ?? 'N/A'); ?></td>
                                                <?php if ($showSemesterColumn): ?>
                                                    <td><?php echo htmlspecialchars($event['semester'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showYearColumn): ?>
                                                    <td><span class="badge badge-archived"><?php echo htmlspecialchars($event['school_year'] ?? 'N/A'); ?></span></td>
                                                <?php endif; ?>
                                                <?php if ($showParticipantsColumn): ?>
                                                    <td><?php echo $event['participant_count'] ?? 0; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Awards Report -->
            <?php if ($reportType == 'awards'): ?>
                <div class="archive-card card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Archived Awards (University-wide)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($awards)): ?>
                            <p class="text-muted text-center py-3">No archived awards found for the selected filters</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <?php if ($showCollegeColumn): ?>
                                                <th>College</th>
                                            <?php endif; ?>
                                            <?php if ($showOrganizationColumn): ?>
                                                <th>Organization</th>
                                            <?php endif; ?>
                                            <th>Title</th>
                                            <th>Date</th>
                                            <th>Venue</th>
                                            <?php if ($showSemesterColumn): ?>
                                                <th>Semester</th>
                                            <?php endif; ?>
                                            <?php if ($showYearColumn): ?>
                                                <th>Academic Year</th>
                                            <?php endif; ?>
                                            <?php if ($showParticipantsColumn): ?>
                                                <th>Number of Participants</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($awards as $index => $award): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <?php if ($showCollegeColumn): ?>
                                                    <td><?php echo htmlspecialchars($award['college_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showOrganizationColumn): ?>
                                                    <td><?php echo htmlspecialchars($award['org_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <td><?php echo htmlspecialchars($award['title']); ?></td>
                                                <td class="text-nowrap"><?php echo date('M d, Y', strtotime($award['award_date'])); ?></td>
                                                <td><?php echo htmlspecialchars($award['venue'] ?? 'N/A'); ?></td>
                                                <?php if ($showSemesterColumn): ?>
                                                    <td><?php echo htmlspecialchars($award['semester'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showYearColumn): ?>
                                                    <td><span class="badge badge-archived"><?php echo htmlspecialchars($award['school_year'] ?? 'N/A'); ?></span></td>
                                                <?php endif; ?>
                                                <?php if ($showParticipantsColumn): ?>
                                                    <td><?php echo $award['participant_count'] ?? 0; ?></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Financial Report -->
            <?php if ($reportType == 'financial'): ?>
                <div class="archive-card card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Archived Financial Reports (University-wide)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($financialReports)): ?>
                            <p class="text-muted text-center py-3">No archived financial reports found for the selected filters</p>
                        <?php else: ?>
                                    <div class="mb-3">
                                <div class="row">
                                    <div class="col-md-4">
                                                <div class="kpi-card kpi-revenue"><strong>Total Revenue:</strong> ₱<?php echo number_format($stats['total_revenue'], 2); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                                <div class="kpi-card kpi-expenses"><strong>Total Expenses:</strong> ₱<?php echo number_format($stats['total_expenses'], 2); ?></div>
                                    </div>
                                    <div class="col-md-4">
                                                <div class="kpi-card kpi-balance"><strong>Net Balance:</strong> ₱<?php echo number_format($stats['net_balance'], 2); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <?php if ($showCollegeColumn): ?>
                                                <th>College</th>
                                            <?php endif; ?>
                                            <?php if ($showOrganizationColumn): ?>
                                                <th>Organization</th>
                                            <?php endif; ?>
                                            <th>Title</th>
                                            <th>Date</th>
                                            <th>Revenue</th>
                                            <th>Expenses</th>
                                            <th>Balance</th>
                                            <?php if ($showSemesterColumn): ?>
                                                <th>Semester</th>
                                            <?php endif; ?>
                                            <?php if ($showYearColumn): ?>
                                                <th>Academic Year</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($financialReports as $index => $report): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <?php if ($showCollegeColumn): ?>
                                                    <td><?php echo htmlspecialchars($report['college_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showOrganizationColumn): ?>
                                                    <td><?php echo htmlspecialchars($report['org_name'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <td><?php echo htmlspecialchars($report['title']); ?></td>
                                                <td class="text-nowrap"><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                                <td class="text-dark">₱<?php echo number_format($report['revenue'], 2); ?></td>
                                                <td class="text-dark">₱<?php echo number_format($report['expenses'], 2); ?></td>
                                                <td class="text-dark">₱<?php echo number_format($report['balance'], 2); ?></td>
                                                <?php if ($showSemesterColumn): ?>
                                                    <td><?php echo htmlspecialchars($report['semester'] ?? 'N/A'); ?></td>
                                                <?php endif; ?>
                                                <?php if ($showYearColumn): ?>
                                                    <td><span class="badge badge-archived"><?php echo htmlspecialchars($report['school_year'] ?? 'N/A'); ?></span></td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php endif; ?>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


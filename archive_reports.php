<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/archive_data_helper.php';

requireLogin();

$role = getUserRole();
$userId = getCurrentUserId();
$orgId = getCurrentOrganizationId();

// Only allow organization presidents and advisers
if (!in_array($role, ['org_president', 'org_adviser'])) {
    header('Location: unauthorized.php');
    exit;
}

// Get organization details
$organization = null;
if ($orgId) {
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
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;
$selectedSemester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$reportType = isset($_GET['type']) ? $_GET['type'] : 'summary';

// Reset semester filter if no year is selected
if (!$selectedYear) {
    $selectedSemester = null;
}

// Get archived academic years
$archivedYears = getArchivedAcademicYears();

// Get archived semesters for selected year
$archivedSemesters = [];
if ($selectedYear) {
    $archivedSemesters = getArchivedSemesters($selectedYear);
}

// Get archived data based on filters
$events = [];
$awards = [];
$financialReports = [];
$officials = [];
$stats = [
    'total_events' => 0,
    'total_awards' => 0,
    'total_reports' => 0,
    'total_revenue' => 0,
    'total_expenses' => 0,
    'net_balance' => 0
];

if ($orgId) {
    $events = getArchivedOrganizationEvents($orgId, $selectedYear, $selectedSemester);
    $awards = getArchivedOrganizationAwards($orgId, $selectedYear, $selectedSemester);
    $financialReports = getArchivedOrganizationFinancialReports($orgId, $selectedYear, $selectedSemester);
    $officials = getArchivedOrganizationOfficials($orgId, $selectedYear);
    $stats = getOrganizationArchiveStats($orgId, $selectedYear, $selectedSemester);
}

// Determine which columns to show based on filters
$showYearColumn = !$selectedYear; // Hide if year is selected
$showSemesterColumn = !$selectedSemester; // Hide if semester is selected
// For Events and Awards: always show participants
$showParticipantsColumn = ($reportType == 'events' || $reportType == 'awards');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive Reports - <?php echo htmlspecialchars($organization['org_name'] ?? 'Organization'); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --ref-red:#d32f2f; --ref-green:#166534; --ref-orange:#f97316; --ref-gray:#4b5563; }
        h2 {
            color: #343a40;
            margin-bottom: 0.5rem;
        }
        .archive-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .stat-card {
            background: var(--ref-orange);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-card p {
            margin: 0;
            opacity: 0.9;
        }
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        /* Monochrome headers for sections */
        .card-header.bg-primary, .card-header.bg-success, .card-header.bg-info { background: var(--ref-gray) !important; }
        .card-header h5 { font-weight: 700; }
        /* KPI cards */
        .kpi-card { border-radius: 10px; padding: 12px 16px; background: #f1f5f9; border-left: 8px solid #64748b; }
        .kpi-revenue { background: rgba(34,197,94,0.08); border-left-color: #16a34a; }
        .kpi-expenses { background: rgba(220,38,38,0.08); border-left-color: #dc2626; }
        .kpi-balance { background: rgba(75,85,99,0.1); border-left-color: #4b5563; }
        .badge-archived { background: var(--ref-gray); }
        .no-data-message {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        .export-buttons {
            margin-bottom: 20px;
        }
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
            min-width: 150px;
        }
        .table-responsive table th:nth-child(3),
        .table-responsive table td:nth-child(3) {
            min-width: 120px;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Archive Reports</h2>
                    <p class="page-subtitle">View historical data from archived academic years</p>
                </div>
                <?php if (!empty($archivedYears)): ?>
                <div>
                    <a href="export_archive_report_pdf.php?year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&type=<?php echo $reportType; ?>" 
                       class="btn btn-dark me-2" target="_blank">
                        <i class="fas fa-file-pdf me-2"></i>Export PDF
                    </a>
                    <a href="export_archive_report_csv.php?year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>&type=<?php echo $reportType; ?>" 
                       class="btn btn-success" target="_blank">
                        <i class="fas fa-file-csv me-2"></i>Export CSV
                    </a>
                </div>
                <?php endif; ?>
            </div>

            

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="archive_reports.php" class="row g-3">
                    <div class="col-md-4">
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
                    <div class="col-md-4">
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
                    <div class="col-md-4">
                        <label class="form-label"><i class="fas fa-filter me-1"></i>Report Type</label>
                        <select name="type" class="form-select" onchange="this.form.submit()">
                            <option value="summary" <?php echo $reportType == 'summary' ? 'selected' : ''; ?>>Summary Report</option>
                            <option value="events" <?php echo $reportType == 'events' ? 'selected' : ''; ?>>Events Report</option>
                            <option value="awards" <?php echo $reportType == 'awards' ? 'selected' : ''; ?>>Awards Report</option>
                            <option value="financial" <?php echo $reportType == 'financial' ? 'selected' : ''; ?>>Financial Report</option>
                            <option value="officials" <?php echo $reportType == 'officials' ? 'selected' : ''; ?>>Officials Report</option>
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
                        <div class="stat-card" style="background: var(--ref-orange);">
                            <h3><?php echo $stats['total_events']; ?></h3>
                            <p><i class="fas fa-calendar-check me-2"></i>Total Events</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: var(--ref-red);">
                            <h3><?php echo $stats['total_awards']; ?></h3>
                            <p><i class="fas fa-trophy me-2"></i>Total Awards</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: var(--ref-green);">
                            <h3>₱<?php echo number_format($stats['total_revenue'], 2); ?></h3>
                            <p><i class="fas fa-coins me-2"></i>Total Revenue</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card" style="background: var(--ref-gray);">
                            <h3>₱<?php echo number_format($stats['net_balance'], 2); ?></h3>
                            <p><i class="fas fa-balance-scale me-2"></i>Net Balance</p>
                        </div>
                    </div>
                </div>

                <!-- Summary Tables -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="archive-card card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Recent Events</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($events)): ?>
                                    <p class="text-muted text-center py-3">No archived events found</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
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
                                                        <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                        <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                                                        <?php if ($showYearColumn): ?>
                                                            <td><span class="badge badge-archived"><?php echo htmlspecialchars($event['school_year'] ?? 'N/A'); ?></span></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($events) > 5): ?>
                                        <a href="?type=events&year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>" class="btn btn-sm btn-outline-secondary">
                                            View All Events (<?php echo count($events); ?>)
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="archive-card card">
                            <div class="card-header bg-success text-white">
                                <h5 class="mb-0">Recent Awards</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($awards)): ?>
                                    <p class="text-muted text-center py-3">No archived awards found</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
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
                                                        <td><?php echo htmlspecialchars($award['title']); ?></td>
                                                        <td><?php echo date('M d, Y', strtotime($award['award_date'])); ?></td>
                                                        <?php if ($showYearColumn): ?>
                                                            <td><span class="badge badge-archived"><?php echo htmlspecialchars($award['school_year'] ?? 'N/A'); ?></span></td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <?php if (count($awards) > 5): ?>
                                        <a href="?type=awards&year=<?php echo $selectedYear; ?>&semester=<?php echo $selectedSemester; ?>" class="btn btn-sm btn-outline-secondary">
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
                        <h5 class="mb-0">Archived Events</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($events)): ?>
                            <p class="text-muted text-center py-3">No archived events found for the selected filters</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Title</th>
                                            <th>Description</th>
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
                                                <td><?php echo htmlspecialchars($event['title']); ?></td>
                                                <td><?php echo htmlspecialchars(substr($event['description'] ?? '', 0, 50)) . (strlen($event['description'] ?? '') > 50 ? '...' : ''); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
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
                        <h5 class="mb-0">Archived Awards</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($awards)): ?>
                            <p class="text-muted text-center py-3">No archived awards found for the selected filters</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Title</th>
                                            <th>Description</th>
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
                                                <td><?php echo htmlspecialchars($award['title']); ?></td>
                                                <td><?php echo htmlspecialchars(substr($award['description'] ?? '', 0, 50)) . (strlen($award['description'] ?? '') > 50 ? '...' : ''); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($award['award_date'])); ?></td>
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
                        <h5 class="mb-0">Archived Financial Reports</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($financialReports)): ?>
                            <p class="text-muted text-center py-3">No archived financial reports found for the selected filters</p>
                        <?php else: ?>
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col-md-4"><div class="kpi-card kpi-revenue"><strong>Total Revenue:</strong> ₱<?php echo number_format($stats['total_revenue'], 2); ?></div></div>
                                    <div class="col-md-4"><div class="kpi-card kpi-expenses"><strong>Total Expenses:</strong> ₱<?php echo number_format($stats['total_expenses'], 2); ?></div></div>
                                    <div class="col-md-4"><div class="kpi-card kpi-balance"><strong>Net Balance:</strong> ₱<?php echo number_format($stats['net_balance'], 2); ?></div></div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
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
                                                <td><?php echo htmlspecialchars($report['title']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
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

            <!-- Officials Report -->
            <?php if ($reportType == 'officials'): ?>
                <div class="archive-card card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-user-tie me-2"></i>Archived Officials</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($officials)): ?>
                            <p class="text-muted text-center py-3">No archived officials found for the selected filters</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Student Number</th>
                                            <th>Position</th>
                                            <th>Course</th>
                                            <th>Year & Section</th>
                                            <?php if ($showYearColumn): ?>
                                                <th>Academic Year</th>
                                            <?php endif; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($officials as $index => $official): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($official['name']); ?></td>
                                                <td><?php echo htmlspecialchars($official['student_number']); ?></td>
                                                <td><?php echo htmlspecialchars($official['position']); ?></td>
                                                <td><?php echo htmlspecialchars($official['course'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($official['year_section'] ?? 'N/A'); ?></td>
                                                <?php if ($showYearColumn): ?>
                                                    <td><span class="badge badge-archived"><?php echo htmlspecialchars($official['school_year'] ?? 'N/A'); ?></span></td>
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


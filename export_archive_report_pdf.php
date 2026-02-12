<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/archive_data_helper.php';
require_once 'phpspreadsheet/vendor/autoload.php';

use Mpdf\Mpdf;

requireLogin();

$role = getUserRole();
$userId = getCurrentUserId();

// Get filter parameters
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : null;
$selectedSemester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;
$selectedCollege = isset($_GET['college']) ? (int)$_GET['college'] : null;
$selectedOrganization = isset($_GET['organization']) ? $_GET['organization'] : null;
$reportType = isset($_GET['type']) ? $_GET['type'] : 'summary';

// Parse organization parameter to handle council selection (format: "council_ID" for councils)
$selectedCouncilId = null;
$selectedOrgId = null;
if ($selectedOrganization) {
    if (strpos($selectedOrganization, 'council_') === 0) {
        // It's a council selection
        $selectedCouncilId = (int)str_replace('council_', '', $selectedOrganization);
    } else {
        // It's an organization selection
        $selectedOrgId = (int)$selectedOrganization;
    }
}

// Reset semester filter if no year is selected
if (!$selectedYear) {
    $selectedSemester = null;
}

// Determine user context
$orgId = null;
$councilId = null;
$collegeId = null;
$organizationName = '';
$collegeName = '';
$reportScope = '';

if (in_array($role, ['org_president', 'org_adviser'])) {
    $orgId = getCurrentOrganizationId();
    $stmt = $conn->prepare("SELECT o.org_name, c.name as college_name FROM organizations o LEFT JOIN colleges c ON o.college_id = c.id WHERE o.id = ?");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $organizationName = $result['org_name'] ?? '';
    $collegeName = $result['college_name'] ?? '';
    $reportScope = $organizationName;
} elseif (in_array($role, ['council_president', 'council_adviser'])) {
    $councilId = getCurrentCouncilId();
    $stmt = $conn->prepare("SELECT c.council_name, co.name as college_name, co.id as college_id FROM council c LEFT JOIN colleges co ON c.college_id = co.id WHERE c.id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $organizationName = $result['council_name'] ?? '';
    $collegeName = $result['college_name'] ?? '';
    $collegeId = $result['college_id'] ?? null;
    // Scope should be the council itself
    $reportScope = $organizationName;
} elseif ($role === 'mis_coordinator') {
    $stmt = $conn->prepare("SELECT c.name, c.id FROM mis_coordinators mc LEFT JOIN colleges c ON mc.college_id = c.id WHERE mc.user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $collegeName = $result['name'] ?? '';
    $collegeId = $result['id'] ?? null;
    $reportScope = $collegeName . ' College';
} elseif ($role === 'osas') {
    if ($selectedCollege) {
        $stmt = $conn->prepare("SELECT name FROM colleges WHERE id = ?");
        $stmt->bind_param("i", $selectedCollege);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $collegeName = $result['name'] ?? '';
        $reportScope = $collegeName . ' College';
    } else {
        $reportScope = 'University-wide';
    }
}

// Get academic year info
$yearInfo = null;
if ($selectedYear) {
    $stmt = $conn->prepare("SELECT school_year, start_date, end_date FROM academic_terms WHERE id = ? AND status = 'archived'");
    $stmt->bind_param("i", $selectedYear);
    $stmt->execute();
    $yearInfo = $stmt->get_result()->fetch_assoc();
}

// Get semester info
$semesterInfo = null;
if ($selectedSemester) {
    $stmt = $conn->prepare("SELECT semester FROM academic_semesters WHERE id = ?");
    $stmt->bind_param("i", $selectedSemester);
    $stmt->execute();
    $semesterInfo = $stmt->get_result()->fetch_assoc();
}

// Fetch data based on role and filters
$events = [];
$awards = [];
$financialReports = [];
$officials = [];
// Organizations are not archivable - they persist across academic years
$stats = [];

if ($role === 'osas') {
    $events = getAllArchivedEvents($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);
    $awards = getAllArchivedAwards($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);
    $financialReports = getAllArchivedFinancialReports($selectedYear, $selectedSemester, $selectedCollege, $selectedOrgId, $selectedCouncilId);
    // Calculate stats
    $stats = [
        'total_events' => count($events),
        'total_awards' => count($awards),
        'total_reports' => count($financialReports),
        'total_revenue' => 0,
        'total_expenses' => 0
    ];
    foreach ($financialReports as $report) {
        $stats['total_revenue'] += $report['revenue'];
        $stats['total_expenses'] += $report['expenses'];
    }
    $stats['net_balance'] = $stats['total_revenue'] - $stats['total_expenses'];
} elseif ($role === 'mis_coordinator') {
    $events = getAllArchivedEvents($selectedYear, $selectedSemester, $collegeId, $selectedOrgId, $selectedCouncilId);
    $awards = getAllArchivedAwards($selectedYear, $selectedSemester, $collegeId, $selectedOrgId, $selectedCouncilId);
    $financialReports = getAllArchivedFinancialReports($selectedYear, $selectedSemester, $collegeId, $selectedOrgId, $selectedCouncilId);
    // Organizations are not archivable - they persist across academic years
    $stats = getCollegeArchiveStats($collegeId, $selectedYear, $selectedSemester);
} elseif (in_array($role, ['council_president', 'council_adviser'])) {
    // Council-only data
    $events = getArchivedCouncilEvents($councilId, $selectedYear, $selectedSemester);
    $awards = getArchivedCouncilAwards($councilId, $selectedYear, $selectedSemester);
    $financialReports = getArchivedCouncilFinancialReports($councilId, $selectedYear, $selectedSemester);
    $stats = [
        'total_events' => count($events),
        'total_awards' => count($awards),
        'total_reports' => count($financialReports),
        'total_revenue' => 0,
        'total_expenses' => 0,
        'net_balance' => 0
    ];
    foreach ($financialReports as $report) {
        $stats['total_revenue'] += (float)($report['revenue'] ?? 0);
        $stats['total_expenses'] += (float)($report['expenses'] ?? 0);
    }
    $stats['net_balance'] = $stats['total_revenue'] - $stats['total_expenses'];
} else {
    $events = getArchivedOrganizationEvents($orgId, $selectedYear, $selectedSemester);
    $awards = getArchivedOrganizationAwards($orgId, $selectedYear, $selectedSemester);
    $financialReports = getArchivedOrganizationFinancialReports($orgId, $selectedYear, $selectedSemester);
    $officials = getArchivedOrganizationOfficials($orgId, $selectedYear);
    $stats = getOrganizationArchiveStats($orgId, $selectedYear, $selectedSemester);
}

// Generate PDF
try {
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'L', // Landscape for better table display
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 8,
        'margin_bottom' => 15,
        'margin_header' => 5,
        'margin_footer' => 5
    ]);

    // Build HTML for PDF
    $html = generatePDFHTML($reportType, $reportScope, $yearInfo, $semesterInfo, $stats, $events, $awards, $financialReports, $officials, $role);

    $mpdf->WriteHTML($html);

    // Generate filename
    $filename = 'Archive_Report_' . ucfirst($reportType) . '_' . date('Y-m-d') . '.pdf';
    if ($yearInfo) {
        $filename = 'Archive_Report_' . str_replace('/', '-', $yearInfo['school_year']) . '_' . ucfirst($reportType) . '.pdf';
    }

    // Output PDF
    $mpdf->Output($filename, 'D'); // D = Download
    exit;

} catch (Exception $e) {
    error_log("PDF Generation Error: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}

/**
 * Generate HTML content for PDF
 */
function generatePDFHTML($reportType, $reportScope, $yearInfo, $semesterInfo, $stats, $events, $awards, $financialReports, $officials, $role) {
    // Determine which columns to show based on filters
    $showYearColumn = !$yearInfo; // Hide if year is selected
    $showSemesterColumn = !$semesterInfo; // Hide if semester is selected
    // For Events and Awards: always show participants (including Summary Report)
    $showParticipantsColumn = ($reportType === 'summary' || $reportType === 'events' || $reportType === 'awards');
    
    $html = '
    <style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        h1 { color: #2c3e50; font-size: 18pt; margin-bottom: 5px; }
        h2 { color: #34495e; font-size: 14pt; margin-top: 15px; margin-bottom: 10px; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
        h3 { color: #7f8c8d; font-size: 12pt; margin-top: 10px; margin-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 9pt; }
        th { background-color: #3498db; color: white; padding: 8px; text-align: left; font-weight: bold; }
        td { padding: 6px 8px; border-bottom: 1px solid #ecf0f1; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .stats-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .stats-table td { width: 25%; padding: 8px; background: #ecf0f1; border-left: 4px solid #3498db; border-right: 1px solid #fff; vertical-align: top; }
        .stats-table td:last-child { border-right: none; }
        .stat-value { font-size: 16pt; font-weight: bold; color: #2c3e50; display: block; }
        .stat-label { font-size: 9pt; color: #7f8c8d; margin-top: 3px; display: block; }
        .header-info { margin-bottom: 15px; color: #7f8c8d; font-size: 9pt; }
        .no-data { text-align: center; padding: 30px; color: #95a5a6; font-style: italic; }
        .page-break { page-break-before: always; }

        /* QF-style header adapted for landscape */
        .qf-header { margin-top: 10px; position: relative; min-height: 80px; }
        .qf-logo { position: absolute; left: 0; top: 0; width: 100px; height: 80px; margin-left: 28.5%; z-index: 1; }
        .qf-header-text { text-align: center; line-height: 1.2; position: relative; margin-top: -7%; z-index: 2; }
        .qf-rp { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
        .qf-uni { font-weight: bold; font-size: 12pt; font-family: "Bookman Old Style", serif; }
        .qf-campus { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
        .qf-campus-bold { font-weight: bold; }
        .qf-title-spacer { height: 6px; }
    </style>
    ';

    // QF-style Header (logo + university text)
    $html .= '<div class="qf-header">'
        . '<img src="assets/img/cvsu_logo.png" class="qf-logo" />'
        . '<div class="qf-header-text">'
            . '<div class="qf-rp">Republic of the Philippines</div>'
            . '<div class="qf-uni">CAVITE STATE UNIVERSITY</div>'
            . '<div class="qf-campus"><span class="qf-campus-bold">Don Severino delas Alas Campus</span><br/>Indang, Cavite</div>'
        . '</div>'
      . '</div>';

    // Report Title below header
    $html .= '<div class="qf-title-spacer"></div>';
    $html .= '<h1>Archive Report - ' . htmlspecialchars(ucfirst($reportType)) . '</h1>';
    $html .= '<div class="header-info">';
    $html .= '<strong>Scope:</strong> ' . htmlspecialchars($reportScope) . '<br>';
    if ($yearInfo) {
        $html .= '<strong>Academic Year:</strong> ' . htmlspecialchars($yearInfo['school_year']) . '<br>';
    }
    if ($semesterInfo) {
        $html .= '<strong>Semester:</strong> ' . htmlspecialchars($semesterInfo['semester']) . '<br>';
    }
    $html .= '<strong>Generated:</strong> ' . date('F d, Y h:i A') . '<br>';
    $html .= '</div>';

    // Statistics Section (for summary report)
    if ($reportType === 'summary') {
        $html .= '<h2>Statistics Overview</h2>';
        $html .= '<table class="stats-table">';
        $html .= '<tr>';
        $html .= '<td><div class="stat-value">' . $stats['total_events'] . '</div><div class="stat-label">Total Events</div></td>';
        $html .= '<td><div class="stat-value">' . $stats['total_awards'] . '</div><div class="stat-label">Total Awards</div></td>';
        $html .= '<td><div class="stat-value">₱' . number_format($stats['total_revenue'], 2) . '</div><div class="stat-label">Total Revenue</div></td>';
        $html .= '<td><div class="stat-value">₱' . number_format($stats['net_balance'], 2) . '</div><div class="stat-label">Net Balance</div></td>';
        $html .= '</tr>';
        $html .= '</table>';
    }

    // Events Section
    if ($reportType === 'summary' || $reportType === 'events') {
        $html .= '<h2>Events' . ($reportType === 'summary' ? ' (Recent)' : '') . '</h2>';
        if (empty($events)) {
            $html .= '<div class="no-data">No events found</div>';
        } else {
            $displayEvents = ($reportType === 'summary') ? array_slice($events, 0, 10) : $events;
            $html .= '<table>';
            $html .= '<thead><tr><th>#</th><th>Title</th><th>Date</th><th>Venue</th>';
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $html .= '<th>Organization</th>';
            }
            if ($showSemesterColumn) {
                $html .= '<th>Semester</th>';
            }
            if ($showYearColumn) {
                $html .= '<th>Academic Year</th>';
            }
            if ($showParticipantsColumn) {
                $html .= '<th>Number of Participants</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($displayEvents as $index => $event) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . htmlspecialchars($event['title']) . '</td>';
                $html .= '<td>' . date('M d, Y', strtotime($event['event_date'])) . '</td>';
                $html .= '<td>' . htmlspecialchars($event['venue'] ?? 'N/A') . '</td>';
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $html .= '<td>' . htmlspecialchars($event['org_name'] ?? 'N/A') . '</td>';
                }
                if ($showSemesterColumn) {
                    $html .= '<td>' . htmlspecialchars($event['semester'] ?? 'N/A') . '</td>';
                }
                if ($showYearColumn) {
                    $html .= '<td>' . htmlspecialchars($event['school_year'] ?? 'N/A') . '</td>';
                }
                if ($showParticipantsColumn) {
                    $html .= '<td>' . ($event['participant_count'] ?? 0) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
    }

    // Awards Section
    if ($reportType === 'summary' || $reportType === 'awards') {
        $html .= '<h2>Awards' . ($reportType === 'summary' ? ' (Recent)' : '') . '</h2>';
        if (empty($awards)) {
            $html .= '<div class="no-data">No awards found</div>';
        } else {
            $displayAwards = ($reportType === 'summary') ? array_slice($awards, 0, 10) : $awards;
            $html .= '<table>';
            $html .= '<thead><tr><th>#</th><th>Title</th><th>Date</th><th>Venue</th>';
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $html .= '<th>Organization</th>';
            }
            if ($showSemesterColumn) {
                $html .= '<th>Semester</th>';
            }
            if ($showYearColumn) {
                $html .= '<th>Academic Year</th>';
            }
            if ($showParticipantsColumn) {
                $html .= '<th>Number of Participants</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($displayAwards as $index => $award) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . htmlspecialchars($award['title']) . '</td>';
                $html .= '<td>' . date('M d, Y', strtotime($award['award_date'])) . '</td>';
                $html .= '<td>' . htmlspecialchars($award['venue'] ?? 'N/A') . '</td>';
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $html .= '<td>' . htmlspecialchars($award['org_name'] ?? 'N/A') . '</td>';
                }
                if ($showSemesterColumn) {
                    $html .= '<td>' . htmlspecialchars($award['semester'] ?? 'N/A') . '</td>';
                }
                if ($showYearColumn) {
                    $html .= '<td>' . htmlspecialchars($award['school_year'] ?? 'N/A') . '</td>';
                }
                if ($showParticipantsColumn) {
                    $html .= '<td>' . ($award['participant_count'] ?? 0) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
    }

    // Financial Section
    if ($reportType === 'financial') {
        $html .= '<h2>Financial Reports</h2>';
        $html .= '<table class="stats-table">';
        $html .= '<tr>';
        $html .= '<td><div class="stat-value">₱' . number_format($stats['total_revenue'], 2) . '</div><div class="stat-label">Total Revenue</div></td>';
        $html .= '<td><div class="stat-value">₱' . number_format($stats['total_expenses'], 2) . '</div><div class="stat-label">Total Expenses</div></td>';
        $html .= '<td><div class="stat-value">₱' . number_format($stats['net_balance'], 2) . '</div><div class="stat-label">Net Balance</div></td>';
        $html .= '<td style="background: transparent; border: none;"></td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        if (empty($financialReports)) {
            $html .= '<div class="no-data">No financial reports found</div>';
        } else {
            $html .= '<table>';
            $html .= '<thead><tr><th>#</th><th>Title</th><th>Date</th><th>Revenue</th><th>Expenses</th><th>Balance</th>';
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $html .= '<th>Organization</th>';
            }
            if ($showSemesterColumn) {
                $html .= '<th>Semester</th>';
            }
            if ($showYearColumn) {
                $html .= '<th>Academic Year</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($financialReports as $index => $report) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . htmlspecialchars($report['title']) . '</td>';
                $html .= '<td>' . date('M d, Y', strtotime($report['report_date'])) . '</td>';
                $html .= '<td style="color: #27ae60;">₱' . number_format($report['revenue'], 2) . '</td>';
                $html .= '<td style="color: #e74c3c;">₱' . number_format($report['expenses'], 2) . '</td>';
                $html .= '<td style="color: #3498db;">₱' . number_format($report['balance'], 2) . '</td>';
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $html .= '<td>' . htmlspecialchars($report['org_name'] ?? 'N/A') . '</td>';
                }
                if ($showSemesterColumn) {
                    $html .= '<td>' . htmlspecialchars($report['semester'] ?? 'N/A') . '</td>';
                }
                if ($showYearColumn) {
                    $html .= '<td>' . htmlspecialchars($report['school_year'] ?? 'N/A') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
    }

    // Officials Section (org only)
    if (($reportType === 'summary' || $reportType === 'officials') && in_array($role, ['org_president', 'org_adviser'])) {
        $html .= '<h2>Student Officials' . ($reportType === 'summary' ? ' (Recent)' : '') . '</h2>';
        if (empty($officials)) {
            $html .= '<div class="no-data">No officials found</div>';
        } else {
            $displayOfficials = ($reportType === 'summary') ? array_slice($officials, 0, 10) : $officials;
            $html .= '<table>';
            $html .= '<thead><tr><th>#</th><th>Name</th><th>Student Number</th><th>Position</th><th>Course</th><th>Year & Section</th>';
            if ($showYearColumn) {
                $html .= '<th>Academic Year</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($displayOfficials as $index => $official) {
                $html .= '<tr>';
                $html .= '<td>' . ($index + 1) . '</td>';
                $html .= '<td>' . htmlspecialchars($official['name']) . '</td>';
                $html .= '<td>' . htmlspecialchars($official['student_number']) . '</td>';
                $html .= '<td>' . htmlspecialchars($official['position']) . '</td>';
                $html .= '<td>' . htmlspecialchars($official['course'] ?? 'N/A') . '</td>';
                $html .= '<td>' . htmlspecialchars($official['year_section'] ?? 'N/A') . '</td>';
                if ($showYearColumn) {
                    $html .= '<td>' . htmlspecialchars($official['school_year'] ?? 'N/A') . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
        }
    }

    // Organizations are not archivable - they persist across academic years
    // Removed organizations section from PDF export

    return $html;
}
?>


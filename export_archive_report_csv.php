<?php
// Prevent any output before headers
if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/archive_data_helper.php';

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

// Generate CSV
try {
    // Clear any previous output and start output buffering
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . generateCSVFilename($reportType, $yearInfo) . '"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8 Excel compatibility (without newline)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write header information (no empty line before)
    fputcsv($output, ['Archive Report - ' . ucfirst($reportType)]);
    fputcsv($output, ['Scope: ' . $reportScope]);
    if ($yearInfo) {
        fputcsv($output, ['Academic Year: ' . $yearInfo['school_year']]);
    }
    if ($semesterInfo) {
        fputcsv($output, ['Semester: ' . $semesterInfo['semester']]);
    }
    fputcsv($output, ['Generated: ' . date('F d, Y h:i A')]);
    fputcsv($output, []); // Empty row
    
    // Determine which columns to show based on filters
    $showYearColumn = !$yearInfo;
    $showSemesterColumn = !$semesterInfo;
    $showParticipantsColumn = ($reportType === 'summary' || $reportType === 'events' || $reportType === 'awards');
    
    // Statistics Section (for summary report)
    if ($reportType === 'summary') {
        fputcsv($output, ['Statistics Overview']);
        fputcsv($output, ['Total Events', 'Total Awards', 'Total Revenue', 'Net Balance']);
        fputcsv($output, [
            $stats['total_events'],
            $stats['total_awards'],
            '₱' . number_format($stats['total_revenue'], 2),
            '₱' . number_format($stats['net_balance'], 2)
        ]);
        fputcsv($output, []); // Empty row
    }
    
    // Events Section
    if ($reportType === 'summary' || $reportType === 'events') {
        fputcsv($output, ['Events' . ($reportType === 'summary' ? ' (Recent)' : '')]);
        
        $displayEvents = ($reportType === 'summary') ? array_slice($events, 0, 10) : $events;
        
        if (empty($displayEvents)) {
            fputcsv($output, ['No events found']);
        } else {
            // Header row
            $headers = ['#', 'Title', 'Date', 'Venue'];
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $headers[] = 'Organization';
            }
            if ($showSemesterColumn) {
                $headers[] = 'Semester';
            }
            if ($showYearColumn) {
                $headers[] = 'Academic Year';
            }
            if ($showParticipantsColumn) {
                $headers[] = 'Number of Participants';
            }
            fputcsv($output, $headers);
            
            // Data rows
            foreach ($displayEvents as $index => $event) {
                $row = [
                    $index + 1,
                    $event['title'],
                    date('M d, Y', strtotime($event['event_date'])),
                    $event['venue'] ?? 'N/A'
                ];
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $row[] = $event['org_name'] ?? 'N/A';
                }
                if ($showSemesterColumn) {
                    $row[] = $event['semester'] ?? 'N/A';
                }
                if ($showYearColumn) {
                    $row[] = $event['school_year'] ?? 'N/A';
                }
                if ($showParticipantsColumn) {
                    $row[] = $event['participant_count'] ?? 0;
                }
                fputcsv($output, $row);
            }
        }
        fputcsv($output, []); // Empty row
    }
    
    // Awards Section
    if ($reportType === 'summary' || $reportType === 'awards') {
        fputcsv($output, ['Awards' . ($reportType === 'summary' ? ' (Recent)' : '')]);
        
        $displayAwards = ($reportType === 'summary') ? array_slice($awards, 0, 10) : $awards;
        
        if (empty($displayAwards)) {
            fputcsv($output, ['No awards found']);
        } else {
            // Header row
            $headers = ['#', 'Title', 'Date', 'Venue'];
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $headers[] = 'Organization';
            }
            if ($showSemesterColumn) {
                $headers[] = 'Semester';
            }
            if ($showYearColumn) {
                $headers[] = 'Academic Year';
            }
            if ($showParticipantsColumn) {
                $headers[] = 'Number of Participants';
            }
            fputcsv($output, $headers);
            
            // Data rows
            foreach ($displayAwards as $index => $award) {
                $row = [
                    $index + 1,
                    $award['title'],
                    date('M d, Y', strtotime($award['award_date'])),
                    $award['venue'] ?? 'N/A'
                ];
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $row[] = $award['org_name'] ?? 'N/A';
                }
                if ($showSemesterColumn) {
                    $row[] = $award['semester'] ?? 'N/A';
                }
                if ($showYearColumn) {
                    $row[] = $award['school_year'] ?? 'N/A';
                }
                if ($showParticipantsColumn) {
                    $row[] = $award['participant_count'] ?? 0;
                }
                fputcsv($output, $row);
            }
        }
        fputcsv($output, []); // Empty row
    }
    
    // Financial Section
    if ($reportType === 'financial') {
        fputcsv($output, ['Financial Reports']);
        fputcsv($output, ['Total Revenue', 'Total Expenses', 'Net Balance']);
        fputcsv($output, [
            '₱' . number_format($stats['total_revenue'], 2),
            '₱' . number_format($stats['total_expenses'], 2),
            '₱' . number_format($stats['net_balance'], 2)
        ]);
        fputcsv($output, []); // Empty row
        
        if (empty($financialReports)) {
            fputcsv($output, ['No financial reports found']);
        } else {
            // Header row
            $headers = ['#', 'Title', 'Date', 'Revenue', 'Expenses', 'Balance'];
            if ($role === 'osas' || $role === 'mis_coordinator') {
                $headers[] = 'Organization';
            }
            if ($showSemesterColumn) {
                $headers[] = 'Semester';
            }
            if ($showYearColumn) {
                $headers[] = 'Academic Year';
            }
            fputcsv($output, $headers);
            
            // Data rows
            foreach ($financialReports as $index => $report) {
                $row = [
                    $index + 1,
                    $report['title'],
                    date('M d, Y', strtotime($report['report_date'])),
                    '₱' . number_format($report['revenue'], 2),
                    '₱' . number_format($report['expenses'], 2),
                    '₱' . number_format($report['balance'], 2)
                ];
                if ($role === 'osas' || $role === 'mis_coordinator') {
                    $row[] = $report['org_name'] ?? 'N/A';
                }
                if ($showSemesterColumn) {
                    $row[] = $report['semester'] ?? 'N/A';
                }
                if ($showYearColumn) {
                    $row[] = $report['school_year'] ?? 'N/A';
                }
                fputcsv($output, $row);
            }
        }
        fputcsv($output, []); // Empty row
    }
    
    // Officials Section (org only)
    if (($reportType === 'summary' || $reportType === 'officials') && in_array($role, ['org_president', 'org_adviser'])) {
        fputcsv($output, ['Student Officials' . ($reportType === 'summary' ? ' (Recent)' : '')]);
        
        $displayOfficials = ($reportType === 'summary') ? array_slice($officials, 0, 10) : $officials;
        
        if (empty($displayOfficials)) {
            fputcsv($output, ['No officials found']);
        } else {
            // Header row
            $headers = ['#', 'Name', 'Student Number', 'Position', 'Course', 'Year & Section'];
            if ($showYearColumn) {
                $headers[] = 'Academic Year';
            }
            fputcsv($output, $headers);
            
            // Data rows
            foreach ($displayOfficials as $index => $official) {
                $row = [
                    $index + 1,
                    $official['name'],
                    $official['student_number'],
                    $official['position'],
                    $official['course'] ?? 'N/A',
                    $official['year_section'] ?? 'N/A'
                ];
                if ($showYearColumn) {
                    $row[] = $official['school_year'] ?? 'N/A';
                }
                fputcsv($output, $row);
            }
        }
    }
    
    fclose($output);
    
    // Clean output buffer and send
    ob_end_flush();
    exit;
    
} catch (Exception $e) {
    error_log("CSV Generation Error: " . $e->getMessage());
    die("Error generating CSV: " . $e->getMessage());
}

/**
 * Generate CSV filename
 */
function generateCSVFilename($reportType, $yearInfo) {
    $filename = 'Archive_Report_' . ucfirst($reportType) . '_' . date('Y-m-d') . '.csv';
    if ($yearInfo) {
        $filename = 'Archive_Report_' . str_replace('/', '-', $yearInfo['school_year']) . '_' . ucfirst($reportType) . '.csv';
    }
    return $filename;
}
?>


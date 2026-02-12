<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator']);

// Get MIS coordinator's college
$userId = getCurrentUserId();
$stmt = $conn->prepare("
    SELECT mc.college_id, c.name as college_name
    FROM mis_coordinators mc
    LEFT JOIN colleges c ON mc.college_id = c.id
    WHERE mc.user_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$collegeId = $result['college_id'] ?? null;
$collegeName = $result['college_name'] ?? 'Unknown College';
$stmt->close();

// Get all organizations in the MIS coordinator's college
$organizations = [];
if ($collegeId) {
    $stmt = $conn->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM events WHERE organization_id = o.id) as event_count,
               (SELECT COUNT(*) FROM awards WHERE organization_id = o.id) as award_count
        FROM organizations o
        WHERE o.college_id = ?
        ORDER BY o.org_name ASC
    ");
    $stmt->bind_param("i", $collegeId);
    $stmt->execute();
    $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// CSV headers
$csvHeaders = ['Organization Name', 'Code', 'Description', 'Status', 'President', 'Adviser', 'Events', 'Awards'];

// Output CSV
if (ob_get_length()) ob_end_clean();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="MIS_Organizations_' . date('Y-m-d') . '.csv"');
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write header information
fputcsv($output, ['Organizations Report - ' . $collegeName]);
fputcsv($output, ['Generated: ' . date('F d, Y h:i A')]);
fputcsv($output, []); // Empty row

// Write CSV headers
fputcsv($output, $csvHeaders);

// Write data rows
foreach ($organizations as $org) {
    $data = [
        $org['org_name'] ?? '',
        $org['code'] ?? '',
        $org['description'] ?? '',
        $org['status'] ?? '',
        $org['president_name'] ?? '',
        $org['adviser_name'] ?? '',
        $org['event_count'] ?? 0,
        $org['award_count'] ?? 0
    ];
    fputcsv($output, $data);
}

fclose($output);
exit;
?>

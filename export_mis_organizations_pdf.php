<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'phpspreadsheet/vendor/autoload.php';

use Mpdf\Mpdf;

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

// Generate PDF
try {
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',
        'orientation' => 'L', // Landscape for better table display
        'margin_left' => 10,
        'margin_right' => 10,
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_header' => 5,
        'margin_footer' => 5
    ]);

    // Build HTML for PDF
    $html = '<html><head><style>
        body { font-family: Arial, sans-serif; font-size: 10pt; }
        h1 { color: #333; font-size: 18pt; margin-bottom: 5px; }
        h2 { color: #666; font-size: 12pt; margin-top: 0; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background-color: #495057; color: white; padding: 8px; text-align: left; font-weight: bold; border: 1px solid #333; }
        td { padding: 6px; border: 1px solid #ddd; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .header-info { margin-bottom: 15px; }
        .footer { text-align: center; font-size: 8pt; color: #666; margin-top: 20px; }
    </style></head><body>';
    
    $html .= '<div class="header-info">';
    $html .= '<h1>Organizations Report</h1>';
    $html .= '<h2>' . htmlspecialchars($collegeName) . '</h2>';
    $html .= '<p>Generated: ' . date('F d, Y h:i A') . '</p>';
    $html .= '</div>';
    
    $html .= '<table>';
    $html .= '<thead>';
    $html .= '<tr>';
    $html .= '<th style="width: 5%;">#</th>';
    $html .= '<th style="width: 25%;">Organization Name</th>';
    $html .= '<th style="width: 10%;">Code</th>';
    $html .= '<th style="width: 20%;">Description</th>';
    $html .= '<th style="width: 8%;">Status</th>';
    $html .= '<th style="width: 12%;">President</th>';
    $html .= '<th style="width: 12%;">Adviser</th>';
    $html .= '<th style="width: 4%;">Events</th>';
    $html .= '<th style="width: 4%;">Awards</th>';
    $html .= '</tr>';
    $html .= '</thead>';
    $html .= '<tbody>';
    
    if (empty($organizations)) {
        $html .= '<tr><td colspan="9" style="text-align: center; padding: 20px;">No organizations found in this college.</td></tr>';
    } else {
        foreach ($organizations as $index => $org) {
            $html .= '<tr>';
            $html .= '<td>' . ($index + 1) . '</td>';
            $html .= '<td>' . htmlspecialchars($org['org_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($org['code'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars(substr($org['description'] ?? '', 0, 50)) . (strlen($org['description'] ?? '') > 50 ? '...' : '') . '</td>';
            $html .= '<td>' . htmlspecialchars(ucfirst($org['status'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars($org['president_name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($org['adviser_name'] ?? '') . '</td>';
            $html .= '<td style="text-align: center;">' . ($org['event_count'] ?? 0) . '</td>';
            $html .= '<td style="text-align: center;">' . ($org['award_count'] ?? 0) . '</td>';
            $html .= '</tr>';
        }
    }
    
    $html .= '</tbody>';
    $html .= '</table>';
    
    $html .= '<div class="footer">';
    $html .= '<p>Total Organizations: ' . count($organizations) . '</p>';
    $html .= '</div>';
    
    $html .= '</body></html>';

    $mpdf->WriteHTML($html);

    // Generate filename
    $filename = 'MIS_Organizations_' . date('Y-m-d') . '.pdf';
    $safeCollegeName = preg_replace('/[^a-zA-Z0-9_]+/', '_', $collegeName);
    if ($safeCollegeName) {
        $filename = 'MIS_Organizations_' . $safeCollegeName . '_' . date('Y-m-d') . '.pdf';
    }

    // Output PDF
    $mpdf->Output($filename, 'D'); // D = Download
    exit;

} catch (Exception $e) {
    error_log("PDF Generation Error: " . $e->getMessage());
    die("Error generating PDF: " . $e->getMessage());
}
?>

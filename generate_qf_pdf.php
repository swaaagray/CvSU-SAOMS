<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'phpspreadsheet/vendor/autoload.php';
require_once 'includes/qf_templates.php';

use Mpdf\Mpdf;

requireRole(['org_president', 'council_president', 'osas']);

// Determine ownership context (organization vs council)
$role = getUserRole();

// For OSAS, we get org/council info from form data; for presidents, from their association
if ($role === 'osas') {
    $orgId = null;
    $councilId = null;
} else {
    $orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
    $councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;
}

// Check if this is a preview request
if (isset($_GET['preview']) && $_GET['preview'] == '1') {
    if (!isset($_SESSION['qf_form_preview'])) {
        // Log the error for debugging
        error_log('QF PDF Generation Error: No preview data available in session');
        die('No preview data available');
    }
    
    $formData = $_SESSION['qf_form_preview'];
    
    // For OSAS, use the org/council IDs from form data
    if ($role === 'osas') {
        $orgId = $formData['organization_id'] ?? null;
        $councilId = $formData['council_id'] ?? null;
    }
    
    // Ensure organization_id or council_id is set
    if (!isset($formData['organization_id']) && !isset($formData['council_id'])) {
        if ($role === 'org_president') {
            $formData['organization_id'] = $orgId;
        } elseif ($role === 'council_president') {
            $formData['council_id'] = $councilId;
        }
    }
    
    $userName = 'Preview User';
    $ownerName = 'Preview Organization';
    
    // Get actual user name
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(username, ''), email) as full_name FROM users WHERE id = ?");
    $stmt->bind_param("i", getCurrentUserId());
    $stmt->execute();
    $userResult = $stmt->get_result();
    $userName = $userResult->fetch_assoc()['full_name'] ?? 'Preview User';
    
    // Get organization/council name based on form data or role
    if ($orgId) {
        $stmt = $conn->prepare("SELECT org_name, adviser_name FROM organizations WHERE id = ?");
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $ownerName = $row['org_name'] ?? 'Preview Organization';
            $formData['adviser_name'] = $row['adviser_name'] ?? '';
        }
    } elseif ($councilId) {
        $stmt = $conn->prepare("SELECT council_name, adviser_name FROM council WHERE id = ?");
        $stmt->bind_param("i", $councilId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $ownerName = $row['council_name'] ?? 'Preview Council';
            $formData['adviser_name'] = $row['adviser_name'] ?? '';
        }
    }
} else {
    // Use session-stored form data
    if (!isset($_SESSION['qf_form_preview'])) {
        error_log('QF PDF Generation Error: No form data available in session');
        die('No form data available');
    }
    $formData = $_SESSION['qf_form_preview'];
    
    // For OSAS, use the org/council IDs from form data
    if ($role === 'osas') {
        $orgId = $formData['organization_id'] ?? null;
        $councilId = $formData['council_id'] ?? null;
    }
    
    // Ensure organization_id or council_id is set
    if (!isset($formData['organization_id']) && !isset($formData['council_id'])) {
        if ($role === 'org_president') {
            $formData['organization_id'] = $orgId;
        } elseif ($role === 'council_president') {
            $formData['council_id'] = $councilId;
        }
    }
    
    // Resolve user name
    $stmt = $conn->prepare("SELECT COALESCE(NULLIF(username, ''), email) as full_name FROM users WHERE id = ?");
    $stmt->bind_param("i", getCurrentUserId());
    $stmt->execute();
    $userResult = $stmt->get_result();
    $userName = $userResult->fetch_assoc()['full_name'] ?? 'User';
    
    // Get organization/council name and adviser name based on IDs
    if ($orgId) {
        $stmt = $conn->prepare("SELECT org_name, adviser_name FROM organizations WHERE id = ?");
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $ownerName = $row['org_name'] ?? '';
            $formData['adviser_name'] = $row['adviser_name'] ?? '';
        }
    } elseif ($councilId) {
        $stmt = $conn->prepare("SELECT council_name, adviser_name FROM council WHERE id = ?");
        $stmt->bind_param("i", $councilId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $ownerName = $row['council_name'] ?? '';
            $formData['adviser_name'] = $row['adviser_name'] ?? '';
        }
    }
}

// Create mPDF instance
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 20,
    'margin_bottom' => 20
]);

// Set document properties
$mpdf->SetTitle('QF Form - ' . ucfirst(str_replace('_', ' ', $formData['form_type'])));
$mpdf->SetAuthor($userName);
$mpdf->SetCreator('Academic Organization System');

// Generate HTML content based on form type
$html = '';

if ($formData['form_type'] === 'acceptance_letter') {
    $html = generateAcceptanceLetterHTML($formData, $userName, $ownerName);
} elseif ($formData['form_type'] === 'activity_permit') {
    $html = generateActivityPermitHTML($formData, $userName, $ownerName, $conn);
}

// Write HTML to PDF
$mpdf->WriteHTML($html);

// Output PDF
$filenameBase = ($formData['form_type'] === 'activity_permit') ? 'QF-Form_Activity-Permit_' : 'QF-Form_Acceptance-Letter_';
$filename = $filenameBase . date('Y-m-d_H-i-s') . '.pdf';

if (isset($_GET['download']) && $_GET['download'] == '1') {
    // Force download when download=1 is set
    $mpdf->Output($filename, 'D');
} elseif (isset($_GET['preview']) && $_GET['preview'] == '1') {
    // Inline preview for modal
    $mpdf->Output($filename, 'I');
} else {
    // Default to download
    $mpdf->Output($filename, 'D');
}
?>

<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'phpspreadsheet/vendor/autoload.php';
require_once 'includes/qf_templates.php';

use Mpdf\Mpdf;

requireRole(['org_president', 'council_president']);

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;
$ownerId = ($role === 'org_president') ? $orgId : $councilId;
$currentUserId = getCurrentUserId();

$message = '';
$error = '';

// Helper: Get current academic term id with fallbacks (active status, or date range)
if (!function_exists('getCurrentAcademicTermId')) {
    function getCurrentAcademicTermId($conn) {
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
        return null;
    }
}

// Get user's display name (fallback to email if username is empty)
$stmt = $conn->prepare("SELECT COALESCE(NULLIF(username, ''), email) as full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$userResult = $stmt->get_result();
$userName = $userResult->fetch_assoc()['full_name'] ?? 'Unknown User';

// Get organization/council name and adviser name
$ownerName = '';
$adviserName = '';
if ($role === 'org_president') {
    $stmt = $conn->prepare("SELECT org_name, adviser_name FROM organizations WHERE id = ?");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $ownerName = $row['org_name'] ?? '';
        $adviserName = $row['adviser_name'] ?? '';
    }
} else {
    $stmt = $conn->prepare("SELECT council_name, adviser_name FROM council WHERE id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $ownerName = $row['council_name'] ?? '';
        $adviserName = $row['adviser_name'] ?? '';
    }
}

// Check if acceptance letter already submitted for current academic year
$hasSubmittedAcceptanceLetter = false;
$currentAcademicYearId = getCurrentAcademicTermId($conn);
if ($currentAcademicYearId !== null) {
    if ($role === 'org_president') {
        $stmt = $conn->prepare("SELECT id FROM organization_documents WHERE organization_id = ? AND academic_year_id = ? AND document_type = 'adviser_acceptance' LIMIT 1");
        $stmt->bind_param("ii", $orgId, $currentAcademicYearId);
    } else {
        $stmt = $conn->prepare("SELECT id FROM council_documents WHERE council_id = ? AND academic_year_id = ? AND document_type = 'adviser_acceptance' LIMIT 1");
        $stmt->bind_param("ii", $councilId, $currentAcademicYearId);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $hasSubmittedAcceptanceLetter = ($result->num_rows > 0);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'preview_form':
                // Check if trying to preview acceptance letter without adviser
                if (empty($adviserName) || $adviserName === null) {
                    $_SESSION['error'] = 'You cannot generate an acceptance letter until an adviser is set.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Check if acceptance letter already submitted for current academic year
                if ($hasSubmittedAcceptanceLetter) {
                    $_SESSION['error'] = 'You have already submitted an acceptance letter for the current academic year.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Store form data in session for preview page (legacy path)
                $formData = [
                    'form_type' => 'acceptance_letter',
                    'organization_id' => $role === 'org_president' ? $orgId : null,
                    'council_id' => $role === 'council_president' ? $councilId : null,
                    'organization_name' => $role === 'org_president' ? $ownerName : '',
                    'council_name' => $role === 'council_president' ? $ownerName : '',
                    'adviser_name' => $adviserName
                ];
                $_SESSION['qf_form_preview'] = $formData;
                header('Location: qf_forms.php?action=preview');
                exit;
                break;

            case 'open_preview_modal':
                // Check if trying to preview acceptance letter without adviser
                if (empty($adviserName) || $adviserName === null) {
                    $_SESSION['error'] = 'You cannot generate an acceptance letter until an adviser is set.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Check if acceptance letter already submitted for current academic year
                if ($hasSubmittedAcceptanceLetter) {
                    $_SESSION['error'] = 'You have already submitted an acceptance letter for the current academic year.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Prepare modal preview from current form fields
                $formData = [
                    'form_type' => 'acceptance_letter',
                    'organization_id' => $role === 'org_president' ? $orgId : null,
                    'council_id' => $role === 'council_president' ? $councilId : null,
                    'organization_name' => $role === 'org_president' ? $ownerName : '',
                    'council_name' => $role === 'council_president' ? $ownerName : '',
                    'adviser_name' => $adviserName
                ];
                $_SESSION['qf_form_preview'] = $formData;
                $_SESSION['show_qf_modal'] = true;
                header('Location: qf_forms.php?show_modal=1');
                exit;
                break;

            case 'download_pdf':
                // Check if trying to download acceptance letter without adviser
                if (empty($adviserName) || $adviserName === null) {
                    $_SESSION['error'] = 'You cannot generate an acceptance letter until an adviser is set.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Check if acceptance letter already submitted for current academic year
                if ($hasSubmittedAcceptanceLetter) {
                    $_SESSION['error'] = 'You have already submitted an acceptance letter for the current academic year.';
                    header('Location: qf_forms.php');
                    exit;
                }
                
                // Prepare preview data then redirect to PDF generator in preview mode
                $formData = [
                    'form_type' => 'acceptance_letter',
                    'organization_id' => $role === 'org_president' ? $orgId : null,
                    'council_id' => $role === 'council_president' ? $councilId : null,
                    'organization_name' => $role === 'org_president' ? $ownerName : '',
                    'council_name' => $role === 'council_president' ? $ownerName : '',
                    'adviser_name' => $adviserName
                ];
                $_SESSION['qf_form_preview'] = $formData;
                header('Location: generate_qf_pdf.php?preview=1');
                exit;
                break;

            case 'submit_document':
                if (isset($_SESSION['qf_form_preview'])) {
                    $formData = $_SESSION['qf_form_preview'];
                    // Ensure organization_id or council_id is set
                    if (!isset($formData['organization_id']) && !isset($formData['council_id'])) {
                        if ($role === 'org_president') {
                            $formData['organization_id'] = $orgId;
                        } else {
                            $formData['council_id'] = $councilId;
                        }
                    }
                    $formType = $formData['form_type'];

                    // Server-side validation for Acceptance Letter
                    if ($formType === 'acceptance_letter') {
                        // Check if adviser_name is NULL or empty
                        if (empty($adviserName) || $adviserName === null) {
                            $_SESSION['error'] = 'You cannot generate an acceptance letter until an adviser is set.';
                            header('Location: qf_forms.php');
                            exit;
                        }
                        
                        // Check if acceptance letter already submitted for current academic year
                        if ($hasSubmittedAcceptanceLetter) {
                            $_SESSION['error'] = 'You have already submitted an acceptance letter for the current academic year.';
                            header('Location: qf_forms.php');
                            exit;
                        }
                    }
                    
                    // Determine document type for documents
                    $documentType = 'adviser_acceptance';
                    
                    // Generate a unique filename for the document
                    $timestamp = date('Y-m-d_H-i-s');
                    $filename = 'Quality_Form_' . ucfirst(str_replace('_', '_', $formType)) . '_' . $timestamp . '.pdf';
                    
                    // Create uploads directory if it doesn't exist
                    if ($role === 'org_president') {
                        $upload_dir = './uploads/organization_documents/';
                    } else {
                        $upload_dir = './uploads/council_documents/';
                    }
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Generate a real PDF using mPDF and save to file
                    $filePath = $upload_dir . $filename;
                    
                    $mpdf = new Mpdf([
                        'mode' => 'utf-8',
                        'format' => 'A4',
                        'margin_left' => 20,
                        'margin_right' => 20,
                        'margin_top' => 20,
                        'margin_bottom' => 20
                    ]);
                    
                    // Build HTML based on form type via shared templates
                    $html = '';
                    if ($formType === 'acceptance_letter') {
                        $html = generateAcceptanceLetterHTML($formData, $userName, $ownerName);
                    }
                    
                    if ($html !== '') {
                        $mpdf->WriteHTML($html);
                        $mpdf->Output($filePath, 'F'); // Save to file
                    } else {
                        // Fallback: write minimal content to avoid empty file
                        file_put_contents($filePath, '');
                    }
                    
                    // Resolve academic term id (active term, else date-range match, else fallback to owner record)
                    $academicYearId = getCurrentAcademicTermId($conn);
                    if ($academicYearId === null) {
                        if ($role === 'org_president') {
                            $ayStmt = $conn->prepare("SELECT academic_year_id FROM organizations WHERE id = ?");
                            $ayStmt->bind_param("i", $orgId);
                            $ayStmt->execute();
                            $ayRow = $ayStmt->get_result()->fetch_assoc();
                            $academicYearId = (int)($ayRow['academic_year_id'] ?? 0) ?: null;
                        } else {
                            $ayStmt = $conn->prepare("SELECT academic_year_id FROM council WHERE id = ?");
                            $ayStmt->bind_param("i", $councilId);
                            $ayStmt->execute();
                            $ayRow = $ayStmt->get_result()->fetch_assoc();
                            $academicYearId = (int)($ayRow['academic_year_id'] ?? 0) ?: null;
                        }
                    }

                    if ($role === 'org_president') {
                        // Save directly to organization documents with academic term id
                        $stmt = $conn->prepare("INSERT INTO organization_documents (organization_id, academic_year_id, document_type, file_path, submitted_by, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                        $stmt->bind_param("iissi", $orgId, $academicYearId, $documentType, $filePath, $currentUserId);
                    } else {
                        $stmt = $conn->prepare("INSERT INTO council_documents (council_id, academic_year_id, document_type, file_path, submitted_by, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                        $stmt->bind_param("iissi", $councilId, $academicYearId, $documentType, $filePath, $currentUserId);
                    }
                    
                    if ($stmt->execute()) {
                        $documentId = $stmt->insert_id;
                        
                        // Notify adviser about new document submission
                        require_once 'includes/notification_helper.php';
                        if ($role === 'org_president') {
                            notifyDocumentSubmission($orgId, $documentType, $documentId);
                        } else {
                            notifyCouncilDocumentSubmission($councilId, $documentType, $documentId);
                        }
                        
                        $_SESSION['message'] = 'Document submitted successfully! It is now pending adviser approval.';
                        unset($_SESSION['qf_form_preview']);
                        unset($_SESSION['show_qf_modal']);
                        header('Location: qf_forms.php');
                        exit;
                    } else {
                        $_SESSION['error'] = 'Error submitting document: ' . $stmt->error;
                    }
                } else {
                    $_SESSION['error'] = 'No form data to submit';
                }
                break;
        }
    }
}


// Display messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Modal flag
$showModal = isset($_GET['show_modal']) || (!empty($_SESSION['show_qf_modal']));

// Check if we're in preview mode (legacy)
$isPreview = isset($_GET['action']) && $_GET['action'] === 'preview';
$previewData = $_SESSION['qf_form_preview'] ?? null;

function generatePDFContent($formData, $userName, $ownerName) {
    $content = "Quality Form - Acceptance Letter\n";
    $content .= "Submitted by: " . $userName . "\n";
    $content .= "Organization: " . $ownerName . "\n";
    $content .= "Date: " . date('Y-m-d H:i:s') . "\n";
    
    // Use adviser name from form data (already retrieved based on current user's role)
    $adviserName = $formData['adviser_name'] ?? '';
    $content .= "Adviser: " . $adviserName . "\n";
    if (isset($formData['organization_id']) && $formData['organization_id']) {
        $content .= "Organization: " . ($formData['organization_name'] ?? '') . "\n";
    } elseif (isset($formData['council_id']) && $formData['council_id']) {
        $content .= "Council: " . ($formData['council_name'] ?? '') . "\n";
    }
    
    return $content;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quality Form - <?php echo htmlspecialchars($ownerName); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        .form-container {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .form-title {
            color: #212529;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
            border-bottom: 3px solid #495057;
            padding-bottom: 1rem;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(73, 80, 87, 0.35);
            color: #fff;
        }
        
        /* Remove default blue focus ring on buttons */
        .btn:focus,
        .btn:active,
        .btn:focus-visible,
        .btn-primary:focus,
        .btn-primary:active,
        .btn-secondary:focus,
        .btn-secondary:active {
            outline: none !important;
            box-shadow: none !important;
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5c636a, #6c757d);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(108, 117, 125, 0.35);
            color: #fff;
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(243, 156, 18, 0.4);
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-bottom: 2px solid #dee2e6;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
        }
        
        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .status-draft { background: #f39c12; color: white; }
        .status-submitted { background: #3498db; color: white; }
        .status-approved { background: #27ae60; color: white; }
        .status-rejected { background: #e74c3c; color: white; }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
            transform: translateY(-1px);
        }
        
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 20px 20px;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;

        }
        
        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0.5rem 0 0 0;
        }
        
        .form-section {
            display: none;
        }
        
        .form-section.active {
            display: block;
        }
        
        .preview-container {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .preview-title {
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            text-align: center;
            font-size: 1.2rem;
        }
        
        .preview-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main>
        
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="page-title">Quality Form</h2>
                <p class="page-subtitle">Generate acceptance letters</p>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Main Form with Tabbed Interface -->
        <div class="form-container">
            <h3 class="form-title">
                <i class="bi bi-file-earmark-text me-2"></i>
                Acceptance Letter
            </h3>
            
            <form method="POST" id="qfForm">
                <input type="hidden" name="form_type" id="selected_form_type" value="acceptance_letter">
                
                <!-- Acceptance Letter Fields -->
                <div id="acceptance_letter_section" class="form-section active">
                    <?php if (empty($adviserName) || $adviserName === null): ?>
                        <div class="alert alert-warning text-center mb-3" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Adviser Required:</strong> You cannot generate an acceptance letter until an adviser is set.
                        </div>
                        <div class="text-center mb-3">
                            <button type="button" id="btnGenerateAcceptanceLetter" class="btn btn-primary" disabled>
                                <i class="bi bi-file-earmark-text me-2"></i>
                                Generate Acceptance Letter
                            </button>
                        </div>
                    <?php elseif ($hasSubmittedAcceptanceLetter): ?>
                        <div class="alert alert-info text-center mb-3" role="alert">
                            <i class="bi bi-info-circle-fill me-2"></i>
                            <strong>Already Submitted:</strong> You have already submitted an acceptance letter for the current academic year.
                        </div>
                        <div class="text-center mb-3">
                            <button type="button" id="btnGenerateAcceptanceLetter" class="btn btn-primary" disabled>
                                <i class="bi bi-file-earmark-text me-2"></i>
                                Generate Acceptance Letter
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="text-center mb-3">
                            <button type="button" id="btnGenerateAcceptanceLetter" class="btn btn-primary">
                                <i class="bi bi-file-earmark-text me-2"></i>
                                Generate Acceptance Letter
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    </div>
    
    <!-- Submission Preview Modal -->
    <div class="modal fade" id="submissionPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Document Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="height: 80vh; padding: 0;">
                    <div class="pdf-container" style="height:100%; margin:0; border-radius:0;">
                        <div id="qf-pdf-viewer" class="pdf-viewer-container" style="height:100%;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <form method="POST" id="confirmSubmissionForm">
                        <input type="hidden" name="action" value="submit_document">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle me-2"></i>Submit Document
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        // Pass PHP variables to JavaScript
        const adviserName = <?php echo json_encode($adviserName); ?>;
        const hasAdviser = adviserName && adviserName.trim() !== '';
        const hasSubmittedAcceptanceLetter = <?php echo json_encode($hasSubmittedAcceptanceLetter); ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            // Dynamic preview: AJAX post form data, then show modal and render PDF
            const formEl = document.getElementById('qfForm');
            const modalEl = document.getElementById('submissionPreviewModal');
            const modal = new bootstrap.Modal(modalEl);
            let viewerInstance = null;

            function postPreviewData() {
                const formData = new FormData(formEl);
                formData.set('action', 'open_preview_modal');
                return fetch('qf_forms.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            }

            function ensureViewer() {
                const container = document.getElementById('qf-pdf-viewer');
                if (!viewerInstance && container) {
                    viewerInstance = new CustomPDFViewer(container, {
                        showToolbar: true,
                        enableZoom: true,
                        enableDownload: true,
                        enablePrint: true
                    });
                }
                return viewerInstance;
            }

            // Handle Generate Acceptance Letter button
            const generateAcceptanceLetterBtn = document.getElementById('btnGenerateAcceptanceLetter');
            if (generateAcceptanceLetterBtn) {
                generateAcceptanceLetterBtn.addEventListener('click', function() {
                    // Check if adviser is set before proceeding
                    if (!hasAdviser) {
                        alert('You cannot generate an acceptance letter until an adviser is set.');
                        return;
                    }
                    
                    // Check if acceptance letter already submitted
                    if (hasSubmittedAcceptanceLetter) {
                        alert('You have already submitted an acceptance letter for the current academic year.');
                        return;
                    }
                    
                    // Set form type to acceptance letter
                    document.getElementById('selected_form_type').value = 'acceptance_letter';

                    // Post data and show modal
                    postPreviewData()
                        .then(() => {
                            modal.show();
                            // Initialize viewer once modal is shown
                            const onShown = function() {
                                const v = ensureViewer();
                                if (v) {
                                    v.loadDocument('generate_qf_pdf.php?preview=1');
                                }
                                modalEl.removeEventListener('shown.bs.modal', onShown);
                            };
                            modalEl.addEventListener('shown.bs.modal', onShown);
                        })
                        .catch((error) => {
                            console.error('Error posting preview data:', error);
                            // fallback: regular submit if needed
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'action';
                            hidden.value = 'open_preview_modal';
                            formEl.appendChild(hidden);
                            formEl.submit();
                        });
                });
            }

            // Prevent confirm submission if the main form is invalid
            const confirmForm = document.getElementById('confirmSubmissionForm');
            if (confirmForm) {
                confirmForm.addEventListener('submit', function(e) {
                    if (!formEl.checkValidity()) {
                        e.preventDefault();
                        formEl.reportValidity();
                    }
                });
            }
        });
    </script>
</body>
</html>

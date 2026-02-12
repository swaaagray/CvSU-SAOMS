<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/notification_helper.php';

requireRole(['council_president']);

$councilId = getCurrentCouncilId();
$councilType = 'new';
// Fetch council type to conditionally show required documents
$typeStmt = $conn->prepare("SELECT type FROM council WHERE id = ?");
if ($typeStmt) {
	$typeStmt->bind_param("i", $councilId);
	if ($typeStmt->execute()) {
		$typeRow = $typeStmt->get_result()->fetch_assoc();
		if (!empty($typeRow['type'])) {
			$councilType = $typeRow['type'];
		}
	}
}
$message = '';
$error = '';

// Check submission period status
$submissionStatus = checkSubmissionPeriod($councilId, 'council');
$canSubmit = $submissionStatus['can_submit'];

// Cleanup: When a new active academic year exists, remove old or untagged documents
try {
    $activeAcademicYearId = null;
    if (function_exists('getCurrentAcademicTermId')) {
        $activeAcademicYearId = getCurrentAcademicTermId($conn);
    }
    if (!empty($activeAcademicYearId)) {
        // Find files to delete for documents not in the active academic year (or NULL)
        $selectStmt = $conn->prepare("SELECT id, file_path FROM council_documents WHERE council_id = ? AND (academic_year_id IS NULL OR academic_year_id <> ?)");
        if ($selectStmt) {
            $selectStmt->bind_param("ii", $councilId, $activeAcademicYearId);
            if ($selectStmt->execute()) {
                $result = $selectStmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $path = $row['file_path'] ?? '';
                    if (!empty($path) && file_exists($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        // Delete records not in the active academic year (or NULL)
        $deleteStmt = $conn->prepare("DELETE FROM council_documents WHERE council_id = ? AND (academic_year_id IS NULL OR academic_year_id <> ?)");
        if ($deleteStmt) {
            $deleteStmt->bind_param("ii", $councilId, $activeAcademicYearId);
            $deleteStmt->execute();
        }
    }
} catch (Exception $e) {
    error_log('Council documents cleanup failed: ' . $e->getMessage());
}

// Helper: Get current academic term id with fallbacks (active status, date range)
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'upload':
                $document_type = $_POST['document_type'] ?? '';
                
                if (!empty($document_type)) {
                    // Check if submission is allowed based on submission period
                    $submissionCheck = checkSubmissionPeriod($councilId, 'council');
                    if (!$submissionCheck['can_submit']) {
                        $_SESSION['error'] = $submissionCheck['message'];
                        header('Location: council_documents.php');
                        exit;
                    }
                    
                    // Prevent duplicate submissions for the same document type
                    $existsStmt = $conn->prepare("SELECT 1 FROM council_documents WHERE council_id = ? AND document_type = ? LIMIT 1");
                    $existsStmt->bind_param("is", $councilId, $document_type);
                    $existsStmt->execute();
                    $existsResult = $existsStmt->get_result();
                    if ($existsResult && $existsResult->num_rows > 0) {
                        $_SESSION['error'] = 'This document type has already been submitted. You cannot submit it again.';
                        header('Location: council_documents.php');
                        exit;
                    }
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = './uploads/council_documents/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    if (!empty($_FILES['document']['name'])) {
                        // Determine academic term id with fallback to council's stored term
                        $currentAcademicTermId = getCurrentAcademicTermId($conn);
                        if ($currentAcademicTermId === null) {
                            $ayStmt = $conn->prepare("SELECT academic_year_id FROM council WHERE id = ?");
                            $ayStmt->bind_param("i", $councilId);
                            $ayStmt->execute();
                            $ayRow = $ayStmt->get_result()->fetch_assoc();
                            $currentAcademicTermId = (int)($ayRow['academic_year_id'] ?? 0) ?: null;
                        }

                        $file_name = $_FILES['document']['name'];
                        $file_size = $_FILES['document']['size'] ?? 0;
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        // Validate file size (10MB max) - MUST BE CHECKED FIRST
                        if ($file_size > 10 * 1024 * 1024) {
                            $_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
                            header('Location: council_documents.php');
                            exit;
                        }
                        
                        // Validate file extension
                        if (!in_array($file_ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'csv'])) {
                            $_SESSION['error'] = 'Invalid file type. Please upload PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX, or CSV files.';
                            header('Location: council_documents.php');
                            exit;
                        }
                        
                        // Validate MIME type
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = $finfo ? finfo_file($finfo, $_FILES['document']['tmp_name']) : '';
                        if ($finfo) { finfo_close($finfo); }
                        
                        $allowed_mimes = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'image/jpeg',
                            'image/png',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'text/csv',
                            'application/csv',
                            'text/plain'
                        ];
                        
                        if (!in_array($mime, $allowed_mimes)) {
                            $_SESSION['error'] = 'Invalid file type detected. Please upload a valid file.';
                            header('Location: council_documents.php');
                            exit;
                        }
                        
                        if (in_array($file_ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'csv'])) {
                            // Preserve original filename but ensure uniqueness
                            $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                            $counter = 1;
                            $new_name = $original_name . '.' . $file_ext;
                            $upload_path = $upload_dir . $new_name;
                            
                            // If file already exists, add a number suffix
                            while (file_exists($upload_path)) {
                                $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                $upload_path = $upload_dir . $new_name;
                                $counter++;
                            }
                            
                                                if (move_uploaded_file($_FILES['document']['tmp_name'], $upload_path)) {
                        $stmt = $conn->prepare("INSERT INTO council_documents (council_id, academic_year_id, document_type, file_path, submitted_by) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("iissi", $councilId, $currentAcademicTermId, $document_type, $upload_path, $_SESSION['user_id']);
                        
                        if ($stmt->execute()) {
                            $documentId = $stmt->insert_id;
                            
                            // Notify council adviser about new document submission
                            require_once 'includes/notification_helper.php';
                            notifyCouncilDocumentSubmission($councilId, $document_type, $documentId);
                            
                            // New/updated submission means council cannot be recognized yet
                            $statusStmt = $conn->prepare("UPDATE council SET status = 'unrecognized' WHERE id = ?");
                            $statusStmt->bind_param("i", $councilId);
                            $statusStmt->execute();
                                    
                                    $_SESSION['message'] = 'Document uploaded successfully!';
                                } else {
                                    $_SESSION['error'] = 'Error uploading document: ' . $stmt->error;
                                }
                            } else {
                                $_SESSION['error'] = 'Error moving uploaded file';
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid file type. Please upload PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX, or CSV files.';
                        }
                    } else {
                        $_SESSION['error'] = 'Please select a file to upload';
                    }
                } else {
                    $_SESSION['error'] = 'Please select a document type';
                }
                header('Location: council_documents.php');
                exit;
                break;
                
            case 'delete':
                $documentId = $_POST['document_id'] ?? 0;
                if ($documentId) {
                    // Get file path before deleting
                    $stmt = $conn->prepare("SELECT file_path FROM council_documents WHERE id = ? AND council_id = ?");
                    $stmt->bind_param("ii", $documentId, $councilId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $document = $result->fetch_assoc();
                    
                    // Delete file if exists
                    if ($document && $document['file_path'] && file_exists($document['file_path'])) {
                        unlink($document['file_path']);
                    }
                    
                    // Delete document record
                    $stmt = $conn->prepare("DELETE FROM council_documents WHERE id = ? AND council_id = ?");
                    $stmt->bind_param("ii", $documentId, $councilId);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Document deleted successfully!';
                        // Deletion means council cannot be recognized
                        $statusStmt = $conn->prepare("UPDATE council SET status = 'unrecognized' WHERE id = ?");
                        $statusStmt->bind_param("i", $councilId);
                        $statusStmt->execute();
                    } else {
                        $_SESSION['error'] = 'Error deleting document';
                    }
                    header('Location: council_documents.php');
                    exit;
                }
                break;

            case 'resubmit':
                $documentId = $_POST['document_id'] ?? 0;
                $documentType = $_POST['document_type'] ?? '';
                
                if (!empty($documentType) && !empty($_FILES['new_document']['name'])) {
                    // Get current active academic term (for backfilling if missing)
                    $ayStmt = $conn->prepare("SELECT id FROM academic_terms WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
                    $ayStmt->execute();
                    $ayRow = $ayStmt->get_result()->fetch_assoc();
                    $currentAcademicTermId = (int)($ayRow['id'] ?? 0) ?: null;

                    // Create uploads directory if it doesn't exist
                    $upload_dir = './uploads/council_documents/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_name = $_FILES['new_document']['name'];
                    $file_size = $_FILES['new_document']['size'] ?? 0;
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    // Validate file size (10MB max) - MUST BE CHECKED FIRST
                    if ($file_size > 10 * 1024 * 1024) {
                        $_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
                        header('Location: council_documents.php');
                        exit;
                    }
                    
                    if (in_array($file_ext, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'xls', 'xlsx', 'csv'])) {
                        // Preserve original filename but ensure uniqueness
                        $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                        $counter = 1;
                        $new_name = $original_name . '.' . $file_ext;
                        $upload_path = $upload_dir . $new_name;
                        
                        // If file already exists, add a number suffix
                        while (file_exists($upload_path)) {
                            $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                            $upload_path = $upload_dir . $new_name;
                            $counter++;
                        }
                        
                        if (move_uploaded_file($_FILES['new_document']['tmp_name'], $upload_path)) {
                            // Get the old file path before updating
                            $stmt = $conn->prepare("SELECT file_path FROM council_documents WHERE id = ? AND council_id = ?");
                            $stmt->bind_param("ii", $documentId, $councilId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $oldDocument = $result->fetch_assoc();
                            $oldFilePath = $oldDocument['file_path'] ?? null;
                            
                            // Update existing document entry instead of creating a new one
                            $stmt = $conn->prepare("UPDATE council_documents SET file_path = ?, status = 'pending', submitted_at = NOW(), adviser_approval_at = NULL, adviser_rejected_at = NULL, osas_approved_at = NULL, osas_rejected_at = NULL, approved_by_adviser = NULL, approved_by_osas = NULL, reject_reason = NULL, academic_year_id = IFNULL(academic_year_id, ?) WHERE id = ? AND council_id = ?");
                            $stmt->bind_param("siii", $upload_path, $currentAcademicTermId, $documentId, $councilId);
                            
                            if ($stmt->execute()) {
                                // Delete the old file if it exists
                                if ($oldFilePath && file_exists($oldFilePath)) {
                                    unlink($oldFilePath);
                                }
                                
                                // Resubmission resets recognition status
                                $statusStmt = $conn->prepare("UPDATE council SET status = 'unrecognized' WHERE id = ?");
                                $statusStmt->bind_param("i", $councilId);
                                $statusStmt->execute();

                                // Notify council adviser and OSAS about resubmission (treated as a new submission)
                                require_once 'includes/notification_helper.php';
                                notifyCouncilDocumentSubmission($councilId, $documentType, $documentId);
                                
                                $_SESSION['message'] = 'Document resubmitted successfully!';
                            } else {
                                $_SESSION['error'] = 'Error resubmitting document: ' . $stmt->error;
                            }
                        } else {
                            $_SESSION['error'] = 'Error moving uploaded file';
                        }
                    } else {
                        $_SESSION['error'] = 'Invalid file type. Please upload PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX, or CSV files.';
                    }
                } else {
                    $_SESSION['error'] = 'Please select a file to upload';
                }
                header('Location: council_documents.php');
                exit;
                break;
        }
    }
}

// Get notification context for highlighting
$notificationId = $_GET['notification_id'] ?? '';
$notificationType = $_GET['notification_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Get council's documents with academic year filtering (ACTIVE YEARS ONLY)
$query = "
    SELECT d.*, 
           u.username as submitted_by_name,
           CASE 
               WHEN adviser.role = 'council_adviser' THEN 'Council Adviser'
               WHEN adviser.role = 'osas' THEN 'OSAS Personnel'
               ELSE 'Adviser'
           END as adviser_name,
           'OSAS Personnel' as osas_name,
           d.adviser_approval_at, d.adviser_rejected_at, d.osas_approved_at, d.osas_rejected_at,
           d.approved_by_adviser, d.approved_by_osas,
           d.resubmission_deadline, d.deadline_set_by, d.deadline_set_at,
           COALESCE(at_doc.status, at_council.status) as academic_year_status,
           COALESCE(at_doc.school_year, at_council.school_year) as school_year
    FROM council_documents d
    LEFT JOIN users u ON d.submitted_by = u.id
    LEFT JOIN users adviser ON d.approved_by_adviser = adviser.id
    LEFT JOIN users osas ON d.approved_by_osas = osas.id
    LEFT JOIN council c ON d.council_id = c.id
    LEFT JOIN academic_terms at_doc ON d.academic_year_id = at_doc.id
    LEFT JOIN academic_terms at_council ON c.academic_year_id = at_council.id
    WHERE d.council_id = ?
    AND COALESCE(at_doc.status, at_council.status) = 'active'
    ORDER BY d.submitted_at DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $councilId);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Council document type labels
$documentTypes = [
    'adviser_resume' => 'Adviser Resume',
    'student_profile' => 'Student Profile',
    'officers_list' => 'Officers List',
    'calendar_activities' => 'Calendar of Activities',
    'official_logo' => 'Official Logo',
    'officers_grade' => 'Officers Grade',
    'group_picture' => 'Group Picture',
    'constitution_bylaws' => 'Constitution & Bylaws',
    'members_list' => 'Members List',
    'good_moral' => 'Good Moral Certificate',
    'adviser_acceptance' => 'Adviser Acceptance',
    'accomplishment_report' => 'Accomplishment Report',
    'previous_plan_of_activities' => 'Previous Plan of Activities',
    'financial_report' => 'Financial Report'
];
// If council is NEW, hide optional legacy documents (not required)
if ($councilType === 'new') {
    unset($documentTypes['accomplishment_report']);
    unset($documentTypes['previous_plan_of_activities']);
    unset($documentTypes['financial_report']);
}

// Merge all required documents with submitted documents
// Create a map of submitted documents by document_type
$submittedDocumentsMap = [];
foreach ($documents as $doc) {
    $submittedDocumentsMap[$doc['document_type']] = $doc;
}

// Build complete list of all required documents
$allDocuments = [];
foreach ($documentTypes as $docType => $docLabel) {
    if (isset($submittedDocumentsMap[$docType])) {
        // Document has been submitted
        $allDocuments[] = $submittedDocumentsMap[$docType];
    } else {
        // Document not yet submitted - create placeholder
        $allDocuments[] = [
            'id' => null,
            'document_type' => $docType,
            'file_path' => null,
            'status' => 'not_submitted',
            'submitted_at' => null,
            'adviser_approval_at' => null,
            'adviser_rejected_at' => null,
            'osas_approved_at' => null,
            'osas_rejected_at' => null,
            'approved_by_adviser' => null,
            'approved_by_osas' => null,
            'reject_reason' => null,
            'resubmission_deadline' => null,
            'adviser_name' => null,
            'osas_name' => 'OSAS Personnel',
            'submitted_by_name' => null,
            'academic_year_status' => null,
            'school_year' => null
        ];
    }
}

// Replace $documents with the merged list
$documents = $allDocuments;

// Get messages from session
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Clear session messages
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Council Documents - Academic Council Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        /* Main heading styling */
        h2 {
            color: #212529;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        
        /* Button styling */
        .btn-primary {
            background: #343a40 !important;
            border: none !important;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.5rem 1.5rem;
            transition: all 0.3s ease;
            color: white !important;
            border-color: #343a40 !important;
        }
        
        .btn-primary:hover {
            background: #212529 !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
            color: white !important;
            border-color: #212529 !important;
        }
        
        .btn-primary:focus {
            background: #212529 !important;
            color: white !important;
            border-color: #212529 !important;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        .btn-primary:active {
            background: #212529 !important;
            color: white !important;
            border-color: #212529 !important;
        }
        
        .btn-secondary {
            background: #6c757d !important;
            border: none !important;
            font-weight: 600;
            border-radius: 8px;
            color: white !important;
            border-color: #6c757d !important;
        }
        
        .btn-secondary:hover {
            background: #5c636a !important;
            transform: translateY(-1px);
            color: white !important;
            border-color: #5c636a !important;
        }
        
        .btn-secondary:focus {
            background: #5c636a !important;
            color: white !important;
            border-color: #5c636a !important;
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25);
        }
        
        .btn-secondary:active {
            background: #5c636a !important;
            color: white !important;
            border-color: #5c636a !important;
        }
        
        /* Action buttons - KEEP ORIGINAL COLORS */
        .btn-info {
            background: linear-gradient(45deg, #20c997);
            border: none;
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #0bb5d6, #25c5e8);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
            font-weight: 500;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c02a37;
            transform: scale(1.05);
            color: white;
        }
        
        /* Enhanced card styling */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: #ffffff;
            border-left: 5px solid #495057;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .card-body {
            padding: 2rem;
            background: #fafafa;
        }
        
        /* Table styling */
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            background: white;
        }
        
        .table {
            margin-bottom: 0;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table thead th {
            background: #f8f9fa;
            color: #495057;
            font-weight: 600;
            border: none;
            padding: 1rem;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #dee2e6;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:nth-child(odd) {
            background: rgba(52, 58, 64, 0.03);
        }
        
        .table tbody tr:nth-child(even) {
            background: rgba(108, 117, 125, 0.03);
        }
        
        .table tbody tr:hover {
            background: rgba(73, 80, 87, 0.1);
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .table td {
            padding: 1rem;
            border-color: rgba(0, 0, 0, 0.05);
            vertical-align: middle;
            color: #212529;
        }
        
        /* Empty state styling */
        .table tbody tr td[colspan="6"] {
            background: #f8f9fa;
            color: #6c757d;
            font-style: italic;
            text-align: center;
            padding: 3rem;
            border-left: none !important;
        }
        
        /* Badge styling - KEEP ORIGINAL COLORS */
        .badge {
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge.bg-warning {
            background: #e6ac00 !important;
            color: white;
        }
        
        .badge.bg-success {
            background: #004085 !important;
            color: white;
        }
        
        .badge.bg-danger {
            background: #b02a37 !important;
            color: white;
        }
        
        .badge.bg-secondary {
            background: #6c757d !important;
            color: white;
        }
        
        /* Tooltip icon styling - KEEP ORIGINAL COLORS FOR STATUS RELATED */
        .bi-info-circle {
            color: #fd7e14;
            margin-left: 0.5rem;
            cursor: help;
            transition: all 0.3s ease;
        }
        
        .bi-info-circle:hover {
            color: #e8690f;
            transform: scale(1.2);
        }
        
        /* Alert styling */
        .alert-success {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            border-radius: 10px;
            border-left: 4px solid #198754;
        }
        
        .alert-danger {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            border-radius: 10px;
            border-left: 4px solid #dc3545;
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: #343a40;
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 1.5rem;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 2rem;
            background: #fafafa;
        }
        
        /* Form styling */
        .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control,
        .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
            transform: translateY(-1px);
        }
        
        .form-control:hover,
        .form-select:hover {
            border-color: #6c757d;
        }
        
        /* File input styling */
        .form-control[type="file"] {
            padding: 0.5rem 0.75rem;
        }
        
        .form-control[type="file"]::-webkit-file-upload-button {
            background: #495057;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.5rem 1rem;
            margin-right: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-control[type="file"]::-webkit-file-upload-button:hover {
            background: #343a40;
            transform: scale(1.05);
        }
        
        /* Small text styling */
        .text-muted {
            color: #6c757d !important;
            font-size: 0.875rem;
        }
        
        /* Enhanced hover effects */
        .btn-sm {
            transition: all 0.2s ease;
            border-radius: 6px;
        }
        
        .btn-sm:hover {
            transform: scale(1.05);
        }
        
        /* Document type cell styling */
        .table td:first-child {
            font-weight: 600;
            color: #495057;
        }
        
        .table tbody tr:nth-child(odd) td:first-child {
            color: #212529;
        }
        
        .table tbody tr:nth-child(even) td:first-child {
            color: #343a40;
        }
        
        /* Submission date styling */
        .table td:nth-child(5) {
            font-size: 0.875rem;
            color: #6c757d;
        }
        
        /* Actions column styling */
        .table td:last-child {
            text-align: center;
        }
        
        /* Loading spinner */
        .spinner-border {
            color: #495057;
        }
        
        /* Custom scrollbar */
        .modal-body {
            scrollbar-width: thin;
            scrollbar-color: #495057 #f8f9fa;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f8f9fa;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #495057;
            border-radius: 3px;
        }
        
        /* Animation for table rows */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .table tbody tr {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .table tbody tr:nth-child(even) {
            animation-delay: 0.1s;
        }
        
        .table tbody tr:nth-child(3n) {
            animation-delay: 0.2s;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
            }
            
            .table td,
            .table th {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .badge {
                font-size: 0.7rem;
                padding: 0.25rem 0.5rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
        }
        
        /* Status column enhancement */
        .table td:nth-child(3) {
            text-align: center;
        }
        
        /* File column enhancement */
        .table td:nth-child(2) {
            text-align: center;
        }
        
        /* Processed by column styling */
        .table td:nth-child(4) {
            font-weight: 500;
            color: #495057;
            font-size: 0.875rem;
            line-height: 1.4;
        }
        
        .table td:nth-child(4) strong {
            color: #212529;
            font-weight: 600;
        }
        
        /* Better contrast and accessibility */
        .card-body {
            background: #ffffff;
        }
        .table td.text-center, .table th.text-center {
            vertical-align: middle;
        }
        .badge {
            border-radius: 0.7em;
            font-weight: 600;
            letter-spacing: 1px;
            font-size: 1rem;
            padding: 0.6em 1.2em;
        }
        
        /* Notification highlighting */
        .notification-highlight {
            background-color: #e3f2fd !important;
            border: 2px solid #2196f3 !important;
            box-shadow: 0 0 15px rgba(33, 150, 243, 0.3) !important;
            animation: notificationPulse 2s ease-in-out;
        }
        @keyframes notificationPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.01); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Council Documents</h2>
                    <p class="page-subtitle">Upload and manage required council documents</p>
                </div>
                <div>
                    <?php if ($canSubmit): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                        <i class="bi bi-upload"></i> Upload Document
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn btn-secondary" disabled title="<?php echo htmlspecialchars($submissionStatus['message']); ?>">
                        <i class="bi bi-upload"></i> Upload Document
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!$canSubmit): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <div class="d-flex align-items-start">
                    <div class="me-3">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-2">
                            <i class="fas fa-clock me-2"></i>
                            Document Submission Not Available
                        </h5>
                        <p class="mb-0"><?php echo htmlspecialchars($submissionStatus['message']); ?></p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <!-- Academic Year Status Notice -->
            <?php if (!empty($documents)): ?>
                <?php 
                $firstDoc = $documents[0];
                if (isset($firstDoc['academic_year_status']) && $firstDoc['academic_year_status'] === 'active'): 
                ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Showing documents for academic year: <strong><?php echo htmlspecialchars($firstDoc['school_year']); ?></strong>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Document Type</th>
                                    <th>File</th>
                                    <th class="text-center">STATUS</th>
                                    <th>Processed By</th>
                                    <th>Submission Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($documents)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center">
                                            <i class="fas fa-folder-open me-2"></i>
                                            No documents uploaded yet for the current academic year
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($documents as $document): 
                                        // Check if this document should be highlighted based on notification
                                        $isHighlighted = false;
                                        if ($notificationId && $notificationType && isset($document['id'])) {
                                            // For document_rejected notifications, highlight the specific document that was rejected
                                            if ($notificationType === 'document_rejected' && $notificationId == $document['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For document_approved notifications, highlight the specific document that was approved
                                            elseif ($notificationType === 'document_approved' && $notificationId == $document['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For document_submitted notifications, highlight the specific document that was submitted
                                            elseif ($notificationType === 'document_submitted' && $notificationId == $document['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For documents_sent_to_osas notifications, highlight all documents that were sent to OSAS
                                            elseif ($notificationType === 'documents_sent_to_osas') {
                                                // Highlight all documents that have been approved by adviser but not yet by OSAS
                                                if ($document['status'] === 'approved' && !empty($document['adviser_approval_at']) && empty($document['osas_approved_at'])) {
                                                    $isHighlighted = true;
                                                }
                                            }
                                        }
                                        
                                        $isSubmitted = isset($document['id']) && $document['status'] !== 'not_submitted';
                                    ?>
                                        <tr class="<?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                                            <?php if ($isSubmitted): ?>id="document-<?php echo $document['id']; ?>"<?php endif; ?>>
                                            <td><?php echo htmlspecialchars($documentTypes[$document['document_type']] ?? $document['document_type']); ?></td>
                                            <td>
                                                <?php if ($isSubmitted && !empty($document['file_path'])): ?>
                                                    <button type="button" class="btn btn-sm btn-info" 
                                                        onclick="viewFileModal('<?php echo addslashes($document['file_path']); ?>', '<?php echo addslashes($documentTypes[$document['document_type']] ?? $document['document_type']); ?>')">
                                                        <i class="bi bi-file-earmark"></i> View
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted"><i class="bi bi-file-earmark-x"></i> Not uploaded</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php
                                                $statusText = '';
                                                $statusClass = '';
                                                if ($document['status'] === 'not_submitted') {
                                                    $statusText = 'NOT SUBMITTED';
                                                    $statusClass = 'bg-secondary';
                                                } elseif ($document['status'] === 'pending') {
                                                    $statusText = 'PENDING';
                                                    $statusClass = 'bg-warning text-dark';
                                                } elseif ($document['status'] === 'approved' && empty($document['osas_approved_at'])) {
                                                    $statusText = 'IN REVIEW';
                                                    $statusClass = 'bg-info text-white';
                                                } elseif ($document['status'] === 'approved' && !empty($document['osas_approved_at'])) {
                                                    $statusText = 'APPROVED';
                                                    $statusClass = 'bg-primary';
                                                } elseif ($document['status'] === 'rejected') {
                                                    $statusText = 'REJECTED';
                                                    $statusClass = 'bg-danger';
                                                }
                                                ?>
                                                <span class="badge <?php echo $statusClass; ?>">
                                                    <?php echo $statusText; ?>
                                                </span>
                                                <?php if ($document['status'] === 'rejected' && !empty($document['resubmission_deadline'])): ?>
                                                    <br><small class="text-muted">Deadline: <?php echo date('M d, Y g:i A', strtotime($document['resubmission_deadline'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($document['status'] === 'not_submitted') {
                                                    echo '<span class="text-muted">-</span>';
                                                } else {
                                                    $mostRecentProcessor = '';
                                                    
                                                    // Check current approval/rejection status first
                                                    if (!empty($document['osas_approved_at'])) {
                                                        $mostRecentProcessor = htmlspecialchars($document['osas_name']);
                                                    } elseif (!empty($document['osas_rejected_at'])) {
                                                        $mostRecentProcessor = htmlspecialchars($document['osas_name']);
                                                    } elseif (!empty($document['adviser_approval_at'])) {
                                                        $mostRecentProcessor = htmlspecialchars($document['adviser_name']);
                                                    } elseif (!empty($document['adviser_rejected_at'])) {
                                                        $mostRecentProcessor = htmlspecialchars($document['adviser_name']);
                                                    }
                                                    
                                                    if (empty($mostRecentProcessor)) {
                                                        echo '<span class="text-muted">No processing yet</span>';
                                                    } else {
                                                        echo $mostRecentProcessor;
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($isSubmitted && !empty($document['submitted_at'])): ?>
                                                    <?php echo date('M d, Y H:i', strtotime($document['submitted_at'])); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($document['status'] === 'rejected' && !empty($document['reject_reason'])): ?>
                                                    <button type="button" class="btn btn-sm btn-primary" onclick="openResubmitModal(<?php echo $document['id']; ?>, '<?php echo addslashes($document['reject_reason']); ?>', '<?php echo addslashes($document['file_path']); ?>', '<?php echo addslashes($document['document_type']); ?>')">
                                                        <i class="bi bi-arrow-clockwise"></i> Resubmit
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload">
                        
                        <div class="mb-3">
                            <label for="document_type" class="form-label">Document Type</label>
                            <?php
                                // Build a set of already-submitted types for this council
                                $submittedTypes = array_column($documents, 'document_type');
                                $submittedSet = array_fill_keys($submittedTypes, true);
                            ?>
                            <select class="form-select" id="document_type" name="document_type" required>
                                <option value="">Select document type</option>
                                <?php foreach ($documentTypes as $key => $label): ?>
                                    <?php if (!isset($submittedSet[$key])): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="document" class="form-label">Document File</label>
                            <input type="file" class="form-control" id="document" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png, .xls,.xlsx, .csv" required>
                            <small class="text-muted">Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX, CSV</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Resubmit Document Modal -->
    <!-- File Viewer Modal -->
    <div class="modal fade" id="fileViewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fileViewerTitle">View Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="fileViewerBody" style="min-height:500px; display:flex; align-items:center; justify-content:center; background:#f8f9fa;">
                    <!-- Content will be injected dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="resubmitDocumentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Resubmit Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="resubmitForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="resubmit">
                        <input type="hidden" name="document_id" id="resubmitDocumentId">
                        <input type="hidden" name="document_type" id="resubmitDocumentType">
                        
                        <!-- Rejection Comments Section -->
                        <div class="mb-4">
                            <h6 class="text-danger mb-2">
                                <i class="bi bi-exclamation-triangle me-2"></i>Rejection Comments
                            </h6>
                            <div class="alert alert-danger">
                                <p id="rejectionCommentsText" class="mb-0"></p>
                            </div>
                        </div>
                        
                        <!-- Original Document Section -->
                        <div class="mb-4">
                            <h6 class="mb-2">
                                <i class="bi bi-file-earmark me-2"></i>Original Document
                            </h6>
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-file-earmark-pdf display-6 text-danger"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1" id="originalFileName"></h6>
                                            <small class="text-muted">Original rejected document</small>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <a href="#" id="originalFileLink" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeOriginalFile()">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- New Document Upload Section -->
                        <div class="mb-3">
                            <label for="new_document" class="form-label">
                                <i class="bi bi-upload me-2"></i>Upload New Document
                            </label>
                            <input type="file" class="form-control" id="new_document" name="new_document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx,.csv" required>
                            <small class="text-muted">Accepted formats: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX, CSV</small>
                        </div>
                        
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise me-2"></i>Resubmit Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })
        
        // Function to open resubmit modal
        function openResubmitModal(documentId, rejectionReason, filePath, documentType) {
            // Populate the modal with data
            document.getElementById('resubmitDocumentId').value = documentId;
            document.getElementById('resubmitDocumentType').value = documentType;
            document.getElementById('rejectionCommentsText').textContent = rejectionReason;
            
            // Set original file information
            const fileName = filePath.split('/').pop(); // Get filename from path
            document.getElementById('originalFileName').textContent = fileName;
            document.getElementById('originalFileLink').href = filePath;
            
            // Show the modal
            new bootstrap.Modal(document.getElementById('resubmitDocumentModal')).show();
        }

        // Function to remove original file from display
        function removeOriginalFile() {
            const originalFileSection = document.querySelector('.card-body .d-flex.align-items-center');
            if (originalFileSection) {
                originalFileSection.innerHTML = `
                    <div class="text-center w-100">
                        <i class="bi bi-file-earmark-x display-4 text-muted"></i>
                        <p class="text-muted mt-2">Original file removed</p>
                    </div>
                `;
            }
        }

        // Function to view file in modal
        function viewFileModal(filePath, docTitle) {
            var modal = new bootstrap.Modal(document.getElementById('fileViewerModal'));
            var body = document.getElementById('fileViewerBody');
            var title = document.getElementById('fileViewerTitle');
            title.textContent = docTitle;
            // Clear previous content
            body.innerHTML = '<div class="w-100 text-center text-muted">Loading...</div>';

            // Determine file type by extension
            var ext = filePath.split('.').pop().toLowerCase();
            if (["jpg", "jpeg", "png", "gif", "bmp", "webp"].includes(ext)) {
                setTimeout(function() {
                    body.innerHTML = '<img src="' + filePath + '" alt="Document Image" style="max-width:100%; max-height:70vh; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.12);">';
                }, 300);
            } else if (ext === "pdf") {
                // Use embedded PDF viewer
                const containerId = 'pdf-viewer-' + Math.random().toString(36).substring(2);
                body.innerHTML = '<div id="' + containerId + '" class="pdf-viewer-container" style="width:100%; height:70vh;"></div>';
                const container = document.getElementById(containerId);
                const viewer = new CustomPDFViewer(container, {
                    showToolbar: true,
                    enableZoom: true,
                    enableDownload: true,
                    enablePrint: true
                });
                viewer.loadDocument(filePath);
            } else {
                setTimeout(function() {
                    body.innerHTML = '<div class="alert alert-info w-100">Cannot preview this file type. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
                }, 300);
            }
            modal.show();
        }
        
        // Notification highlighting and scrolling
        document.addEventListener('DOMContentLoaded', function() {
            const highlightedDoc = document.querySelector('.notification-highlight');
            if (highlightedDoc) {
                highlightedDoc.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                setTimeout(() => {
                    highlightedDoc.style.animation = 'notificationPulse 1s ease-in-out';
                }, 500);
                setTimeout(() => {
                    highlightedDoc.classList.remove('notification-highlight');
                }, 5000);
            }
        });
    </script>
</body>
</html> 
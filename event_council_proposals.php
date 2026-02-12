<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_president']);

// Council-only context
$councilId = getCurrentCouncilId();
if (!$councilId) {
    echo '<div class="alert alert-danger">No council found for this user.</div>';
    exit;
}

$message = '';
$error = '';

// Recognition guard (mirror events.php/awards.php)
$isRecognized = false;
$recognitionNotification = null;
$stmt = $conn->prepare("SELECT status FROM council WHERE id = ?");
$stmt->bind_param("i", $councilId);
$stmt->execute();
$result = $stmt->get_result();
if ($org = $result->fetch_assoc()) {
    $isRecognized = ($org['status'] === 'recognized');
    if (!$isRecognized) {
        $recognitionNotification = [
            'type' => 'council',
            'message' => 'This Council is not yet recognized. Please go to the Council Documents section and complete the required submissions listed there.',
            'message_html' => 'This Council is not yet recognized. Please go to the <a href="council_documents.php" class="alert-link fw-bold">Council Documents</a> section and complete the required submissions listed there.'
        ];
    }
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'] ?? 'Your council is not recognized.';
        header("Location: event_council_proposals.php");
        exit;
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'submit_proposal':
                $title = $_POST['title'] ?? '';
                $venue = $_POST['venue'] ?? '';
                
				if (!empty($title) && !empty($venue)) {
					// Resolve current active semester
					$semRes = $conn->query("SELECT id FROM academic_semesters WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
					$activeSemesterId = null;
					if ($semRes && ($semRow = $semRes->fetch_assoc())) {
						$activeSemesterId = (int)$semRow['id'];
					}

					if ($activeSemesterId === null) {
						$_SESSION['error'] = 'No active semester is currently set. Please try again later.';
						header("Location: event_council_proposals.php");
						exit;
					}

					// VALIDATE ALL FILES FIRST (before inserting proposal)
					if (!empty($_FILES['proposal_files']['name'][0])) {
						$upload_dir = './uploads/events/proposals/';
						if (!file_exists($upload_dir)) {
							mkdir($upload_dir, 0777, true);
						}
						
						// Pre-validate all files before inserting proposal
						foreach ($_FILES['proposal_files']['tmp_name'] as $key => $tmp_name) {
							$file_name = $_FILES['proposal_files']['name'][$key];
							$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
							$file_size = $_FILES['proposal_files']['size'][$key] ?? 0;
							
							if (in_array($file_ext, ['pdf', 'doc', 'docx'])) {
								// Additional MIME type validation
								$finfo = finfo_open(FILEINFO_MIME_TYPE);
								$mime = $finfo ? finfo_file($finfo, $tmp_name) : '';
								if ($finfo) { finfo_close($finfo); }
								
								$allowed_mimes = [
									'application/pdf',
									'application/msword',
									'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
								];
								
								// Validate file size (10MB max) - MUST BE CHECKED FIRST
								if ($file_size > 10 * 1024 * 1024) {
									$_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
									header("Location: event_council_proposals.php");
									exit;
								}
								
								// Validate MIME type
								if (!in_array($mime, $allowed_mimes)) {
									$_SESSION['error'] = 'Invalid file type detected';
									header("Location: event_council_proposals.php");
									exit;
								}
							} else {
								// Invalid file extension
								$_SESSION['error'] = 'Invalid file type. Please upload PDF, DOC, or DOCX files.';
								header("Location: event_council_proposals.php");
								exit;
							}
						}
					}

					// Only insert proposal if all files passed validation
					$stmt = $conn->prepare("INSERT INTO event_approvals (council_id, semester_id, title, venue) VALUES (?, ?, ?, ?)");
					$stmt->bind_param("iiss", $councilId, $activeSemesterId, $title, $venue);
                    
                    if ($stmt->execute()) {
                        $approvalId = $stmt->insert_id;
                        
                        // Notify council adviser about new event proposal submission
                        require_once 'includes/notification_helper.php';
                        notifyCouncilEventSubmission($councilId, $title, $approvalId);
                        
                        // Handle document uploads with proper validation
                        if (!empty($_FILES['proposal_files']['name'][0])) {
                            $upload_dir = './uploads/events/proposals/';
                            if (!file_exists($upload_dir)) {
                                mkdir($upload_dir, 0777, true);
                            }
                            
                            $success = true;
                            $uploadErrors = [];
                            $allowed_extensions = ['pdf', 'doc', 'docx'];
                            $allowed_mimes = [
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                            ];
                            
                            foreach ($_FILES['proposal_files']['tmp_name'] as $key => $tmp_name) {
                                // Skip if no file was uploaded
                                if (empty($_FILES['proposal_files']['name'][$key]) || $_FILES['proposal_files']['error'][$key] !== UPLOAD_ERR_OK) {
                                    continue;
                                }
                                
                                $file_name = $_FILES['proposal_files']['name'][$key];
                                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                $file_size = $_FILES['proposal_files']['size'][$key] ?? 0;
                                
                                // Validate file size (10MB max)
                                if ($file_size > 10 * 1024 * 1024) {
                                    $uploadErrors[] = $file_name . ': File size exceeds 10MB limit';
                                    $success = false;
                                    continue;
                                }
                                
                                // Validate file extension
                                if (!in_array($file_ext, $allowed_extensions)) {
                                    $uploadErrors[] = $file_name . ': Invalid file type. Only PDF, DOC, and DOCX files are allowed';
                                    $success = false;
                                    continue;
                                }
                                
                                // Validate MIME type
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $mime = $finfo ? finfo_file($finfo, $tmp_name) : '';
                                if ($finfo) { finfo_close($finfo); }
                                
                                if (!in_array($mime, $allowed_mimes)) {
                                    $uploadErrors[] = $file_name . ': Invalid file type detected';
                                    $success = false;
                                    continue;
                                }
                                
                                // Generate unique filename
                                $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                                $counter = 1;
                                $new_name = $original_name . '.' . $file_ext;
                                $upload_path = $upload_dir . $new_name;
                                
                                while (file_exists($upload_path)) {
                                    $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                    $upload_path = $upload_dir . $new_name;
                                    $counter++;
                                }
                                
                                // Move uploaded file
                                if (move_uploaded_file($tmp_name, $upload_path)) {
                                    $document_type = $_POST['document_types'][$key] ?? 'other';
                                    $stmt = $conn->prepare("INSERT INTO event_documents (event_approval_id, document_type, file_path, submitted_by) VALUES (?, ?, ?, ?)");
                                    $stmt->bind_param("issi", $approvalId, $document_type, $upload_path, $_SESSION['user_id']);
                                    if (!$stmt->execute()) {
                                        $uploadErrors[] = $file_name . ': Database error - ' . $stmt->error;
                                        $success = false;
                                        // Delete uploaded file if database insert fails
                                        if (file_exists($upload_path)) {
                                            unlink($upload_path);
                                        }
                                    }
                                    $stmt->close();
                                } else {
                                    $uploadErrors[] = $file_name . ': Failed to upload file';
                                    $success = false;
                                }
                            }
                            
                            if (!$success) {
                                $errorMsg = 'Proposal submitted but some documents failed to upload.';
                                if (!empty($uploadErrors)) {
                                    $errorMsg .= ' Errors: ' . implode('; ', $uploadErrors);
                                }
                                $_SESSION['error'] = $errorMsg;
                            } else {
                                $_SESSION['message'] = 'Event proposal submitted successfully with documents!';
                            }
                        } else {
                            $_SESSION['message'] = 'Event proposal submitted successfully!';
                        }
                        
                        header("Location: event_council_proposals.php");
                        exit;
                    } else {
                        $_SESSION['error'] = 'Error submitting proposal: ' . $stmt->error;
                        header("Location: event_council_proposals.php");
                        exit;
                    }
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header("Location: event_council_proposals.php");
                    exit;
                }
                break;

            case 'resubmit_document':
                $documentId = $_POST['document_id'] ?? 0;
                $documentType = $_POST['document_type'] ?? '';
                
                if (!empty($documentType) && isset($_FILES['new_document']) && !empty($_FILES['new_document']['name'])) {
                    $upload_dir = './uploads/events/proposals/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_name = $_FILES['new_document']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $file_size = $_FILES['new_document']['size'] ?? 0;
                    
                    // Validate file extension
                    if (!in_array($file_ext, ['pdf', 'doc', 'docx'])) {
                        $errorMsg = 'Invalid file type. Only PDF, DOC, and DOCX files are allowed.';
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'error' => $errorMsg]);
                            exit;
                        } else {
                            $_SESSION['error'] = $errorMsg;
                            header("Location: event_council_proposals.php");
                            exit;
                        }
                    }
                    
                    // Validate file size (10MB max)
                    if ($file_size > 10 * 1024 * 1024) {
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'error' => 'File size exceeds 10MB limit. Please upload a smaller file.']);
                            exit;
                        } else {
                            $_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
                            header("Location: event_council_proposals.php");
                            exit;
                        }
                    }
                    
                    // Validate MIME type
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_file($finfo, $_FILES['new_document']['tmp_name']) : '';
                    if ($finfo) { finfo_close($finfo); }
                    
                    $allowed_mimes = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ];
                    
                    if (!in_array($mime, $allowed_mimes)) {
                        $errorMsg = 'Invalid file type detected. Please upload a valid document file.';
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'error' => $errorMsg]);
                            exit;
                        } else {
                            $_SESSION['error'] = $errorMsg;
                            header("Location: event_council_proposals.php");
                            exit;
                        }
                    }
                    
                    if (in_array($file_ext, ['pdf', 'doc', 'docx'])) {
                        
                        $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                        $counter = 1;
                        $new_name = $original_name . '.' . $file_ext;
                        $upload_path = $upload_dir . $new_name;
                        
                        while (file_exists($upload_path)) {
                            $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                            $upload_path = $upload_dir . $new_name;
                            $counter++;
                        }
                        
                        if (move_uploaded_file($_FILES['new_document']['tmp_name'], $upload_path)) {
                            // Get the old file path before updating
                            $stmt = $conn->prepare("SELECT file_path FROM event_documents WHERE id = ?");
                            $stmt->bind_param("i", $documentId);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $oldDocument = $result->fetch_assoc();
                            $oldFilePath = $oldDocument['file_path'] ?? null;
                            
                            // Update existing document entry
                            $stmt = $conn->prepare("UPDATE event_documents SET file_path = ?, status = 'pending', submitted_at = NOW(), adviser_approved_at = NULL, adviser_rejected_at = NULL, osas_approved_at = NULL, osas_rejected_at = NULL, approved_by_adviser = NULL, approved_by_osas = NULL, rejection_reason = NULL WHERE id = ?");
                            $stmt->bind_param("si", $upload_path, $documentId);
                            
                            if ($stmt->execute()) {
                                // Delete the old file if it exists
                                if ($oldFilePath && file_exists($oldFilePath)) {
                                    unlink($oldFilePath);
                                }
                                
                                // AJAX success response
                                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                    header('Content-Type: application/json');
                                    echo json_encode(['success' => true, 'message' => 'Document resubmitted successfully!']);
                                    exit;
                                } else {
                                    $_SESSION['message'] = 'Document resubmitted successfully!';
                                    header("Location: event_council_proposals.php");
                                    exit;
                                }
                            } else {
                                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                    header('Content-Type: application/json');
                                    echo json_encode(['success' => false, 'error' => 'Error resubmitting document: ' . $stmt->error]);
                                    exit;
                                } else {
                                    $_SESSION['error'] = 'Error resubmitting document: ' . $stmt->error;
                                    header("Location: event_council_proposals.php");
                                    exit;
                                }
                            }
                        } else {
                            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                header('Content-Type: application/json');
                                echo json_encode(['success' => false, 'error' => 'Error moving uploaded file']);
                                exit;
                            } else {
                                $_SESSION['error'] = 'Error moving uploaded file';
                                header("Location: event_council_proposals.php");
                                exit;
                            }
                        }
                    } else {
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'error' => 'Invalid file type. Please upload PDF, DOC, or DOCX files.']);
                            exit;
                        } else {
                            $_SESSION['error'] = 'Invalid file type. Please upload PDF, DOC, or DOCX files.';
                            header("Location: event_council_proposals.php");
                            exit;
                        }
                    }
                } else {
                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'error' => 'Please select a file to upload']);
                        exit;
                    } else {
                        $_SESSION['error'] = 'Please select a file to upload';
                        header("Location: event_council_proposals.php");
                        exit;
                    }
                }
                break;
        }
    }
}

// Get notification context for highlighting
$notificationId = $_GET['notification_id'] ?? '';
$notificationType = $_GET['notification_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Pagination setup
$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Count total proposals for pagination
$count_query = "SELECT COUNT(*) FROM event_approvals a WHERE a.council_id = ?";
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param("i", $councilId);
$count_stmt->execute();
$count_stmt->bind_result($total_proposals);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_proposals / $per_page);

// Get event proposals with approval status for council only
$query = "
    SELECT a.*, 
           (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = a.id) as doc_count,
           (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = a.id AND osas_approved_at IS NOT NULL) as approved_docs,
           (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = a.id AND (osas_rejected_at IS NOT NULL OR adviser_rejected_at IS NOT NULL)) as rejected_docs,
           (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = a.id AND adviser_approved_at IS NULL AND adviser_rejected_at IS NULL AND osas_approved_at IS NULL AND osas_rejected_at IS NULL) as pending_docs,
           (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = a.id AND adviser_approved_at IS NOT NULL AND osas_approved_at IS NULL AND osas_rejected_at IS NULL) as sent_docs
    FROM event_approvals a
    WHERE a.council_id = ?
";

// Don't apply status filter - show all events but highlight the relevant ones
// The highlighting logic will handle showing which events match the notification

$query .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param("iii", $councilId, $per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $proposals = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $proposals = [];
}

// Document type labels
$documentTypes = [
    'activity_proposal' => 'Activity Proposal',
    'resolution_budget_approval' => 'Resolution for Budget Approval',
    'letter_venue_equipment' => 'Letters for Venue/Equipment',
    'cv_speakers' => 'CV of Speakers',
];

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Proposals - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        /* Main heading styling */
        h2 {
            color: #212529;
            font-weight: 600;
        }
        
        /* Button styling */
        /* Unified light main action button */
        .main-action,
        .main-action:link,
        .main-action:visited,
        .main-action:focus,
        .main-action:active,
        .main-action:hover {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #495057 !important;
            text-decoration: none !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .main-action:hover {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }

        .main-action:focus {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
        }

        .main-action:active {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
        }
        
        .btn-success {
            background: linear-gradient(45deg, #343a40, #495057);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.5rem 1.5rem;
            color: white;
        }
        
        .btn-success:hover {
            background: linear-gradient(45deg, #212529, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
            color: white;
        }
        
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
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-left: 4px solid transparent;
        }
        
        .card:nth-child(odd) {
            border-left-color: #495057; /* Dark Gray */
        }
        
        .card:nth-child(even) {
            border-left-color: #6c757d; /* Medium Gray */
        }
        
        .card-header.bg-primary {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }
        
        .card-title {
            color: #212529;
            font-weight: 600;
        }
        
        /* Table styling */
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table-light th {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(108, 117, 125, 0.1) 100%);
            color: #495057;
            font-weight: 600;
            border: none;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background: rgba(52, 58, 64, 0.05);
        }
        
        .table-striped tbody tr:nth-of-type(even) {
            background: rgba(108, 117, 125, 0.05);
        }
        
        .table-hover tbody tr:hover {
            background: rgba(73, 80, 87, 0.1);
        }
        
        /* Action buttons - KEEP ORIGINAL COLORS */
        .btn-info {
            background: linear-gradient(45deg, #20c997);
            border: none;
            color: white;
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #0bb5d6, #25c5e8);
            color: white;
        }
        
        .btn-warning {
            background: linear-gradient(45deg, #fd7e14, #ff922b);
            border: none;
            color: white;
        }
        
        .btn-warning:hover {
            background: linear-gradient(45deg, #e8690f, #e8690f);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #e55563);
            border: none;
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #c02a37, #d63447);
        }
        
        /* Status badges - matching organization documents design */
        .badge {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
        }
        
        .badge.bg-warning {
            background: linear-gradient(45deg, #ffc107, #ffcd39) !important;
            color: #000 !important;
        }
        
        .badge.bg-primary {
            background: #0056b3 !important;
            color: white !important;
        }
        
        .badge.bg-success {
            background: #0056b3 !important;
            color: white !important;
        }
        
        .badge.bg-danger {
            background: linear-gradient(45deg, #dc3545, #e55563) !important;
            color: white !important;
        }
        
        .badge.bg-info {
            background: linear-gradient(45deg, #0dcaf0, #31d2f2) !important;
            color: white;
        }
        
        .badge.bg-secondary {
            background: #e6b800 !important;
            color: white;
        }
        
        /* Alert styling */
        .alert-success {
            background: linear-gradient(90deg, rgba(73, 80, 87, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border: 1px solid rgba(73, 80, 87, 0.3);
            color: #495057;
            border-radius: 8px;
        }
        
        .alert-danger {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border: 1px solid rgba(52, 58, 64, 0.3);
            color: #495057;
            border-radius: 8px;
        }
        
        .alert-info {
            background: linear-gradient(90deg, rgba(13, 202, 240, 0.1) 0%, rgba(13, 202, 240, 0.05) 100%) !important;
            border: 1px solid rgba(13, 202, 240, 0.3) !important;
            color: #0c5460 !important;
            border-radius: 8px !important;
        }
        
        /* Modal styling */
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border-radius: 0;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        /* Fix modal z-index layering issues */
        .modal {
            z-index: 1055 !important;
        }
        
        .modal-backdrop {
            z-index: 1054 !important;
        }
        
        /* Ensure resubmit modal appears above view event modal */
        #resubmitDocumentModal {
            z-index: 1065 !important;
        }
        
        #resubmitDocumentModal .modal-backdrop {
            z-index: 1064 !important;
        }
        
        /* File viewer modal should be on top */
        #fileViewerModal {
            z-index: 1075 !important;
        }
        
        #fileViewerModal .modal-backdrop {
            z-index: 1074 !important;
        }
        
        /* View event modal base z-index */
        #viewEventModal {
            z-index: 1055 !important;
        }
        
        /* Submit proposal modal base z-index */
        #submitProposalModal {
            z-index: 1055 !important;
        }
        
        /* Prevent modal backdrop from interfering with nested modals */
        .modal-backdrop + .modal-backdrop {
            z-index: 1064 !important;
        }
        
        /* Ensure modals are clickable and not grayed out */
        .modal.show {
            pointer-events: auto !important;
        }
        
        .modal.show .modal-content {
            pointer-events: auto !important;
        }
        
        /* Fix for nested modal interactions */
        .modal-dialog {
            pointer-events: auto !important;
        }
        
        /* Ensure form elements in modals are interactive */
        .modal input,
        .modal textarea,
        .modal select,
        .modal button {
            pointer-events: auto !important;
        }
        
        /* Additional modal styling fixes */
        .modal.show {
            display: block !important;
            background-color: rgba(0, 0, 0, 0.5) !important;
        }
        
        /* Ensure modal content is always visible and interactive */
        .modal-dialog {
            pointer-events: auto !important;
            z-index: inherit !important;
        }
        
        /* Fix for nested modal backdrop issues */
        .modal-backdrop.show {
            opacity: 0.5 !important;
        }
        
        /* Ensure buttons in modals are always clickable */
        .modal .btn {
            position: relative;
            z-index: 1;
        }
        
        /* Fix for modal stacking order */
        .modal[style*="z-index"] {
            z-index: inherit !important;
        }
        
        /* Ensure proper modal backdrop cleanup */
        .modal-backdrop {
            pointer-events: auto !important;
        }
        
        /* Fix for multiple modal backdrops */
        .modal-backdrop + .modal-backdrop {
            display: none !important;
        }
        
        /* Ensure modal content is restored properly */
        .modal.fade:not(.show) {
            pointer-events: none !important;
        }
        
        .modal.fade.show {
            pointer-events: auto !important;
        }
        
        /* Form styling */
        .form-label {
            color: #495057;
            font-weight: 500;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 6px;
        }
        
        .btn-secondary:hover {
            background-color: #5c636a;
            border-color: #565e64;
        }
        
        /* Filter styling */
        .form-select-sm {
            border-radius: 6px;
            border-color: #dee2e6;
        }
        
        .form-select-sm:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        /* Participant and document entry styling */
        .participant-entry,
        .document-entry {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 8px;
            padding: 0.75rem;
            border-left: 3px solid #495057;
        }
        
        .participant-entry:nth-child(even),
        .document-entry:nth-child(even) {
            border-left-color: #6c757d;
        }
        
        /* Spinner styling */
        .spinner-border {
            color: #495057;
        }
        
        /* Enhanced hover effects */
        .btn-group .btn {
            transition: all 0.2s ease;
        }
        
        .btn-group .btn:hover {
            transform: scale(1.05);
        }
        
        /* Table responsive wrapper */
        .table-responsive {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        /* Custom scrollbar for modal content */
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
        
        /* Text muted styling */
        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }
        
        /* Enhanced user-friendly features */
        .card-body {
            background: rgba(255, 255, 255, 0.9);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }
        
        /* Better contrast for readability */
        .table tbody td {
            color: #212529;
        }
        
        /* Improved visual hierarchy */
        .modal-body {
            background: #fafafa;
        }
        
        /* Enhanced form styling */
        .form-control,
        .form-select {
            border: 1px solid #dee2e6;
            transition: all 0.2s ease;
        }
        
        .form-control:hover,
        .form-select:hover {
            border-color: #6c757d;
        }
        
        /* Modal table styling for consistent alignment */
        .modal-table {
            table-layout: fixed;
        }
        
        .modal-table th,
        .modal-table td {
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
        }
        
        .modal-table .text-truncate {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .modal-table .btn-group {
            white-space: nowrap;
        }
        
        .modal-table .btn-group .btn {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Ensure status badges are properly aligned */
        .modal-table .badge {
            display: inline-block;
            white-space: nowrap;
        }
        
        /* Fix for status column with additional text */
        .modal-table .d-flex.flex-column {
            align-items: center;
            justify-content: center;
        }
        
        /* Ensure consistent row heights */
        .modal-table tbody tr {
            height: 60px;
        }
        
        .modal-table tbody tr td {
            padding: 0.75rem 0.5rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Event Proposals</h2>
                    <p class="page-subtitle">Submit and track event proposals for approval</p>
                </div>
                <div>
                    <button type="button" class="btn btn-light main-action" data-bs-toggle="modal" data-bs-target="#submitProposalModal" <?php echo !$isRecognized ? 'disabled' : ''; ?> >
                        <i class="bi bi-file-earmark-text"></i> Submit Proposal
                    </button>
                </div>
            </div>
            <?php if (!$isRecognized && $recognitionNotification): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start">
                        <div class="me-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="alert-heading mb-2">
                                <i class="fas fa-bell me-2"></i>
                                Recognition Required
                            </h5>
                            <p class="mb-0">
                                <?php echo isset($recognitionNotification['message_html']) ? $recognitionNotification['message_html'] : htmlspecialchars($recognitionNotification['message']); ?>
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            


            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Event Proposals</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Venue</th>
                                    <th class="text-center">Documents</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Date Submitted</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($proposals)): ?>
                                    <tr><td colspan="6" class="text-center">No event proposals found</td></tr>
                                <?php else: ?>
                                    <?php foreach ($proposals as $proposal): 
                                        // Check if this proposal should be highlighted based on notification
                                        $isHighlighted = false;
                                        if ($notificationId && $notificationType) {
                                            // For event_approved notifications, highlight the specific event that was approved
                                            if ($notificationType === 'event_approved' && $notificationId == $proposal['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For event_rejected notifications, highlight the specific event that was rejected
                                            elseif ($notificationType === 'event_rejected' && $notificationId == $proposal['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For event_submitted notifications, highlight the specific event that was submitted
                                            elseif ($notificationType === 'event_submitted' && $notificationId == $proposal['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For event_document_approved notifications, highlight the specific event that had a document approved
                                            elseif ($notificationType === 'event_document_approved' && $notificationId == $proposal['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For event_document_rejected notifications, highlight the specific event that had a document rejected
                                            elseif ($notificationType === 'event_document_rejected' && $notificationId == $proposal['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For event_documents_sent_to_osas notifications, highlight events that were sent to OSAS
                                            elseif ($notificationType === 'event_documents_sent_to_osas') {
                                                // Highlight events that have been approved by adviser but not yet by OSAS
                                                if ($proposal['sent_docs'] == $proposal['doc_count'] && $proposal['doc_count'] > 0) {
                                                    $isHighlighted = true;
                                                }
                                            }
                                        }
                                    ?>
                                    <tr class="<?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                                        id="event-<?php echo $proposal['id']; ?>">
                                        <td><?php echo htmlspecialchars($proposal['title']); ?></td>
                                        <td><?php echo htmlspecialchars($proposal['venue']); ?></td>
                                        <td class="text-center"><?php echo $proposal['approved_docs']; ?>/<?php echo $proposal['doc_count']; ?> approved</td>
                                        <td class="text-center">
                                            <?php 
                                            require_once 'includes/status_helper.php';
                                            $status = getPresidentEventStatus(
                                                $proposal['doc_count'],
                                                $proposal['approved_docs'],
                                                $proposal['rejected_docs'],
                                                $proposal['sent_docs'],
                                                $proposal['pending_docs']
                                            );
                                            echo getPresidentStatusBadge($status);
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <?php 
                                            if (!empty($proposal['created_at'])) {
                                                $submittedDate = new DateTime($proposal['created_at']);
                                                echo $submittedDate->format('M d, Y');
                                            } else {
                                                echo '<span class="text-muted">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewEventModal" data-approval-id="<?php echo $proposal['id']; ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <nav aria-label="Event proposals pagination" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $notificationId ? '&notification_id=' . $notificationId : ''; ?><?php echo $notificationType ? '&notification_type=' . $notificationType : ''; ?>">Previous</a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">Previous</span>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=1<?php echo $notificationId ? '&notification_id=' . $notificationId : ''; ?><?php echo $notificationType ? '&notification_type=' . $notificationType : ''; ?>">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $notificationId ? '&notification_id=' . $notificationId : ''; ?><?php echo $notificationType ? '&notification_type=' . $notificationType : ''; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $notificationId ? '&notification_id=' . $notificationId : ''; ?><?php echo $notificationType ? '&notification_type=' . $notificationType : ''; ?>"><?php echo $total_pages; ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $notificationId ? '&notification_id=' . $notificationId : ''; ?><?php echo $notificationType ? '&notification_type=' . $notificationType : ''; ?>">Next</a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link">Next</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    
                    <div class="text-center text-muted mb-3">
                        <small>Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_proposals; ?> total proposals)</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Submit Proposal Modal -->
    <div class="modal fade" id="submitProposalModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Event Proposal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="submit_proposal">
                        
                        <div class="mb-3">
                            <label for="proposal_title" class="form-label">Event Title</label>
                            <input type="text" class="form-control" id="proposal_title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="proposal_venue" class="form-label">Proposed Venue</label>
                            <input type="text" class="form-control" id="proposal_venue" name="venue" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Required Documents</label>
                            <div id="documents-container">
                                <div class="document-entry mb-2">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <select class="form-select" name="document_types[]" required>
                                                <option value="">Select document type</option>
                                                <?php foreach ($documentTypes as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <input type="file" class="form-control" name="proposal_files[]" accept=".pdf,.doc,.docx" required>
                                            <small class="text-muted">Accepted formats: PDF, DOC, DOCX</small>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger remove-document" style="display: none;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary mt-2" id="add-document">
                                <i class="bi bi-plus-circle"></i> Add Document
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Proposal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <!-- View Event Modal -->
    <div class="modal fade" id="viewEventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Event Proposal Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewEventContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>



    <!-- Resubmit Document Modal -->
    <div class="modal fade" id="resubmitDocumentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Resubmit Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="resubmitForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="resubmit_document">
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
                            <input type="file" class="form-control" id="new_document" name="new_document" accept=".pdf,.doc,.docx" required>
                            <small class="text-muted">Accepted formats: PDF, DOC, DOCX</small>
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
        // Expose a global document viewer function for injected content
        window.viewEventDoc = function(filePath, title) {
            const existingModal = document.getElementById('fileViewerModal');
            let modalEl = existingModal;
            if (!modalEl) {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = `
                <div class="modal fade" id="fileViewerModal" tabindex="-1">
                    <div class="modal-dialog modal-xl modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="fileViewerTitle">View Document</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="fileViewerBody" style="min-height:500px; display:flex; align-items:center; justify-content:center; background:#f8f9fa;"></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>`;
                document.body.appendChild(wrapper.firstElementChild);
                modalEl = document.getElementById('fileViewerModal');
            }

            const body = document.getElementById('fileViewerBody');
            const heading = document.getElementById('fileViewerTitle');
            if (heading) heading.textContent = title || 'View Document';
            if (body) body.innerHTML = '<div class="w-100 text-center text-muted">Loading...</div>';

            const ext = (filePath.split('.').pop() || '').toLowerCase();
            if (["jpg","jpeg","png","gif","bmp","webp"].includes(ext)) {
                setTimeout(function(){
                    body.innerHTML = '<img src="' + filePath + '" alt="Document Image" style="max-width:100%; max-height:70vh; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.12);">';
                }, 150);
            } else if (ext === 'pdf') {
                const containerId = 'pdf-viewer-' + Math.random().toString(36).substring(2);
                body.innerHTML = '<div id="' + containerId + '" class="pdf-viewer-container" style="width:100%; height:70vh;"></div>';
                const container = document.getElementById(containerId);
                if (typeof CustomPDFViewer !== 'undefined') {
                    const viewer = new CustomPDFViewer(container, { showToolbar: true, enableZoom: true, enableDownload: true, enablePrint: true });
                    viewer.loadDocument(filePath);
                } else {
                    // Lazy load assets if not yet available
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = 'assets/css/pdf-viewer.css';
                    document.head.appendChild(link);
                    const script = document.createElement('script');
                    script.src = 'assets/js/pdf-viewer.js';
                    script.onload = function(){
                        const viewer = new CustomPDFViewer(container, { showToolbar: true, enableZoom: true, enableDownload: true, enablePrint: true });
                        viewer.loadDocument(filePath);
                    };
                    document.body.appendChild(script);
                }
            } else {
                setTimeout(function(){
                    body.innerHTML = '<div class="alert alert-info w-100">Cannot preview this file type. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
                }, 150);
            }

            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        };
        // Handle view event modal
        document.getElementById('viewEventModal').addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const approvalId = button.getAttribute('data-approval-id');
            
            // Store the current approval ID for real-time updates
            this.setAttribute('data-current-approval-id', approvalId);
            
            document.getElementById('viewEventContent').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            
            fetch(`get_event_details.php?approval_id=${approvalId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('viewEventContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('viewEventContent').innerHTML = '<div class="alert alert-danger">Error loading event details. Please try again.</div>';
                });
            
            // Start auto-refresh for this modal
            startModalAutoRefresh(approvalId);
        });
        
        // Auto-refresh modal content every 5 seconds
        let modalRefreshInterval;
        function startModalAutoRefresh(approvalId) {
            // Clear any existing interval
            if (modalRefreshInterval) {
                clearInterval(modalRefreshInterval);
            }
            
            // Set up new interval
            modalRefreshInterval = setInterval(() => {
                const viewEventModal = document.getElementById('viewEventModal');
                if (viewEventModal.classList.contains('show')) {
                    fetch(`get_event_details.php?approval_id=${approvalId}`)
                        .then(response => response.text())
                        .then(html => {
                            document.getElementById('viewEventContent').innerHTML = html;
                        })
                        .catch(error => {
                            console.error('Error auto-refreshing modal content:', error);
                        });
                } else {
                    // Modal is closed, stop auto-refresh
                    clearInterval(modalRefreshInterval);
                }
            }, 5000); // Refresh every 5 seconds
        }



        // Handle documents
        let documentCount = 1;
        document.getElementById('add-document').addEventListener('click', function() {
            const container = document.getElementById('documents-container');
            const newEntry = document.createElement('div');
            newEntry.className = 'document-entry mb-2';
            newEntry.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <select class="form-select" name="document_types[]" required>
                            <option value="">Select document type</option>
                            <?php foreach ($documentTypes as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <input type="file" class="form-control" name="proposal_files[]" accept=".pdf,.doc,.docx" required>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-danger remove-document">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newEntry);
            documentCount++;

            if (documentCount > 1) {
                document.querySelector('.document-entry:first-child .remove-document').style.display = 'block';
            }
        });

        document.getElementById('documents-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-document')) {
                const entry = e.target.closest('.document-entry');
                entry.remove();
                documentCount--;

                if (documentCount === 1) {
                    document.querySelector('.document-entry:first-child .remove-document').style.display = 'none';
                }
            }
        });
        
        // Handle resubmit button clicks (event delegation for dynamically loaded content)
        document.addEventListener('click', function(e) {
            if (e.target.closest('.resubmit-btn')) {
                const btn = e.target.closest('.resubmit-btn');
                const documentId = btn.getAttribute('data-document-id');
                const rejectionReason = btn.getAttribute('data-rejection-reason');
                const filePath = btn.getAttribute('data-file-path');
                const documentType = btn.getAttribute('data-document-type');
                
                if (typeof window.openResubmitModal === 'function') {
                    window.openResubmitModal(documentId, rejectionReason, filePath, documentType);
                }
            }
        });

        // Function to open resubmit modal (make it globally accessible)
        window.openResubmitModal = function(documentId, rejectionReason, filePath, documentType) {
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

        // Cache original modal content to restore between submissions
        const resubmitModal = document.getElementById('resubmitDocumentModal');
        const resubmitModalBody = resubmitModal.querySelector('.modal-body');
        const resubmitModalFooter = resubmitModal.querySelector('.modal-footer');
        const originalResubmitBodyHTML = resubmitModalBody.innerHTML;
        const originalResubmitFooterHTML = resubmitModalFooter.innerHTML;

        // Restore original content whenever modal is fully hidden
        resubmitModal.addEventListener('hidden.bs.modal', function() {
            resubmitModalBody.innerHTML = originalResubmitBodyHTML;
            resubmitModalFooter.innerHTML = originalResubmitFooterHTML;
        });

        // Handle resubmit form submission via AJAX (delegated to survive DOM resets)
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.id !== 'resubmitForm') return;
            e.preventDefault();

            const formData = new FormData(form);
            const submitButton = form.querySelector('button[type="submit"]');
            const originalText = submitButton.innerHTML;

            // Disable submit button and show loading
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Resubmitting...';

            fetch('event_council_proposals.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                const ct = response.headers.get('content-type') || '';
                if (ct.includes('application/json')) return response.json();
                return response.text().then(t => ({ success: t.includes('successfully'), raw: t }));
            })
            .then(payload => {
                const ok = payload && payload.success === true;
                if (ok) {
                    // Temporary success message, then auto-close
                    const modalBody = document.querySelector('#resubmitDocumentModal .modal-body');
                    modalBody.innerHTML = `
                        <div class="text-center">
                            <i class="bi bi-check-circle text-success display-4"></i>
                            <h5 class="mt-3">Document Resubmitted Successfully!</h5>
                            <p class="text-muted">The document has been resubmitted and is now pending review.</p>
                        </div>
                    `;
                    // Replace footer with only a Close button
                    const modalFooter = document.querySelector('#resubmitDocumentModal .modal-footer');
                    if (modalFooter) {
                        modalFooter.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                    }

                    // Refresh the view event modal content to show updated status
                    const viewEventModal = document.getElementById('viewEventModal');
                    if (viewEventModal.classList.contains('show')) {
                        const currentApprovalId = document.querySelector('#viewEventModal').getAttribute('data-current-approval-id');
                        if (currentApprovalId) {
                            fetch(`get_event_details.php?approval_id=${currentApprovalId}`)
                                .then(response => response.text())
                                .then(html => {
                                    document.getElementById('viewEventContent').innerHTML = html;
                                })
                                .catch(error => {
                                    console.error('Error refreshing modal content:', error);
                                });
                        }
                    }

                    // Refresh the main table content without page reload
                    setTimeout(() => {
                        fetch('event_council_proposals.php')
                            .then(response => response.text())
                            .then(html => {
                                // Create a temporary div to parse the HTML
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = html;

                                // Extract the table content
                                const newTableContent = tempDiv.querySelector('.table-responsive');
                                const currentTableContent = document.querySelector('.table-responsive');

                                if (newTableContent && currentTableContent) {
                                    currentTableContent.innerHTML = newTableContent.innerHTML;
                                }
                            })
                            .catch(error => {
                                console.error('Error refreshing table content:', error);
                            });
                    }, 800); // Refresh table after ~1 second

                    // Re-enable button and close modal after short delay
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                    setTimeout(() => {
                        const bsModal = bootstrap.Modal.getInstance(resubmitModal) || new bootstrap.Modal(resubmitModal);
                        bsModal.hide();
                    }, 1000);
                } else {
                    // Show error message (no buttons, auto-close)
                    const modalBody = document.querySelector('#resubmitDocumentModal .modal-body');
                    const errorMessage = payload && payload.error ? payload.error : 'Error resubmitting document. Please try again.';
                    modalBody.innerHTML = `
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            ${errorMessage}
                        </div>
                    `;

                    // Hide footer buttons
                    const modalFooter = document.querySelector('#resubmitDocumentModal .modal-footer');
                    if (modalFooter) {
                        modalFooter.innerHTML = '';
                    }

                    // Reset submit button
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;

                    // Auto-close modal after 3 seconds
                    setTimeout(() => {
                        const bsModal = bootstrap.Modal.getInstance(resubmitModal) || new bootstrap.Modal(resubmitModal);
                        bsModal.hide();
                        // Restore original content after modal is hidden
                        setTimeout(() => {
                            resubmitModalBody.innerHTML = originalResubmitBodyHTML;
                            resubmitModalFooter.innerHTML = originalResubmitFooterHTML;
                        }, 300);
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const modalBody = document.querySelector('#resubmitDocumentModal .modal-body');
                modalBody.innerHTML = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Error resubmitting document. Please try again.
                    </div>
                `;

                // Hide footer buttons
                const modalFooter = document.querySelector('#resubmitDocumentModal .modal-footer');
                if (modalFooter) {
                    modalFooter.innerHTML = '';
                }

                // Reset submit button
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;

                // Auto-close modal after 3 seconds
                setTimeout(() => {
                    const bsModal = bootstrap.Modal.getInstance(resubmitModal) || new bootstrap.Modal(resubmitModal);
                    bsModal.hide();
                    // Restore original content after modal is hidden
                    setTimeout(() => {
                        resubmitModalBody.innerHTML = originalResubmitBodyHTML;
                        resubmitModalFooter.innerHTML = originalResubmitFooterHTML;
                    }, 300);
                }, 3000);
            });
        });
        
        // Notification highlighting and scrolling
        document.addEventListener('DOMContentLoaded', function() {
            const highlightedEvent = document.querySelector('.notification-highlight');
            if (highlightedEvent) {
                highlightedEvent.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                setTimeout(() => {
                    highlightedEvent.style.animation = 'notificationPulse 1s ease-in-out';
                }, 500);
                setTimeout(() => {
                    highlightedEvent.classList.remove('notification-highlight');
                }, 5000);
            }
        });
    </script>
    
    <style>
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
</body>
</html> 
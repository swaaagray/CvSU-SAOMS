<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator', 'osas', 'org_adviser', 'org_president', 'council_president']);


$message = '';
$error = '';

// Get filter parameters
$documentType = $_GET['document_type'] ?? '';
$council = $_GET['council'] ?? '';
$status = $_GET['status'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// Pagination parameters
$perPage = isset($_GET['per_page']) && (int)$_GET['per_page'] > 0 ? min(50, (int)$_GET['per_page']) : 10;
$pageCouncil = isset($_GET['page_council']) ? max(1, (int)$_GET['page_council']) : 1;
$pageEvent = isset($_GET['page_event']) ? max(1, (int)$_GET['page_event']) : 1;

// Get notification context for tab selection and highlighting
$notificationTab = $_GET['tab'] ?? '';
$notificationId = $_GET['notification_id'] ?? '';
$notificationType = $_GET['notification_type'] ?? '';

// Document type labels - All document types
$documentTypes = [
    'adviser_resume' => 'Resume / CV of Adviser',
    'student_profile' => 'Bio Data / Student Profile',
    'officers_list' => 'List of Officers',
    'calendar_activities' => 'Calendar of Activities',
    'official_logo' => 'Official Logo',
    'officers_grade' => 'Grade Certificate of Officers',
    'group_picture' => 'Group Picture with Captions',
    'constitution_bylaws' => 'Constitution and By-Laws',
    'members_list' => 'Updated List of Members',
    'good_moral' => 'Good Moral Certification',
    'adviser_acceptance' => 'Acceptance Letter of Adviser',
    'budget_resolution' => 'Organization Budget Approval Resolution',
    'activity_proposal' => 'Activity Proposal',
    'resolution_budget_approval' => 'Resolution for Budget Approval',
    'letter_venue_equipment' => 'Letters for Venue/Equipment',
    'cv_speakers' => 'CV of Speakers',
    'accomplishment_report' => 'Accomplishment Report',
    'previous_plan_of_activities' => 'Previous Plan of Activities',
    'financial_report' => 'Financial Report'
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'approve_org_doc':
                $docId = $_POST['doc_id'] ?? 0;
                $response = ['success' => false, 'message' => ''];
                
                if ($docId) {
                    $stmt = $conn->prepare("UPDATE council_documents SET status = 'approved', osas_approved_at = NOW(), approved_by_osas = ? WHERE id = ?");
                    $stmt->bind_param("ii", $_SESSION['user_id'], $docId);
                    if ($stmt->execute()) {
                        // Get document details for notification
                        $docStmt = $conn->prepare("SELECT council_id, document_type FROM council_documents WHERE id = ?");
                        $docStmt->bind_param("i", $docId);
                        $docStmt->execute();
                        $docDetails = $docStmt->get_result()->fetch_assoc();
                        $docStmt->close();
                        
                        // Create notification for document approval
                        if ($docDetails) {
                            require_once 'includes/notification_helper.php';
                            notifyCouncilDocumentAction($docDetails['council_id'], 'approved', $docDetails['document_type'], $docId);
                        }
                        
                        // Recalculate council recognition status based on required documents for council type
                        if ($docDetails && !empty($docDetails['council_id'])) {
                            $councilId = $docDetails['council_id'];

                            // Determine council type
                            $councilType = 'new';
                            $typeStmt = $conn->prepare("SELECT type FROM council WHERE id = ?");
                            if ($typeStmt) {
                                $typeStmt->bind_param("i", $councilId);
                                if ($typeStmt->execute()) {
                                    $typeRow = $typeStmt->get_result()->fetch_assoc();
                                    if (!empty($typeRow['type'])) {
                                        $councilType = $typeRow['type'];
                                    }
                                }
                                $typeStmt->close();
                            }

                            // Build required document list based on type
                            $requiredDocuments = [
                                'adviser_resume',
                                'student_profile',
                                'officers_list',
                                'calendar_activities',
                                'official_logo',
                                'officers_grade',
                                'group_picture',
                                'constitution_bylaws',
                                'members_list',
                                'good_moral',
                                'adviser_acceptance'
                            ];
                            if ($councilType === 'old') {
                                $requiredDocuments = array_merge($requiredDocuments, [
                                    'accomplishment_report',
                                    'previous_plan_of_activities',
                                    'financial_report'
                                ]);
                            }

                            // Count approved documents among required list
                            $placeholders = implode(',', array_fill(0, count($requiredDocuments), '?'));
                            $types = str_repeat('s', count($requiredDocuments)) . 'i';
                            $query = "SELECT COUNT(*) AS approved_count FROM council_documents WHERE council_id = ? AND document_type IN ($placeholders) AND status = 'approved'";
                            // Bind council_id first then required documents
                            $stmt = $conn->prepare(str_replace('council_id = ?', 'council_id = ?',$query));
                            if ($stmt) {
                                // Build params dynamically
                                $bindParams = [];
                                $bindTypes = 'i' . str_repeat('s', count($requiredDocuments));
                                $bindValues = array_merge([$councilId], $requiredDocuments);
                                $bindParams[] = & $bindTypes;
                                foreach ($bindValues as $k => $v) {
                                    $bindParams[] = & $bindValues[$k];
                                }
                                call_user_func_array([$stmt, 'bind_param'], $bindParams);
                                $stmt->execute();
                                $approvedCount = (int)($stmt->get_result()->fetch_assoc()['approved_count'] ?? 0);
                                $stmt->close();
                            } else {
                                $approvedCount = 0;
                            }

                            if ($approvedCount === count($requiredDocuments)) {
                                $stmt = $conn->prepare("UPDATE council SET status = 'recognized' WHERE id = ?");
                                $stmt->bind_param("i", $councilId);
                                $stmt->execute();
                            } else {
                                $stmt = $conn->prepare("UPDATE council SET status = 'unrecognized' WHERE id = ?");
                                $stmt->bind_param("i", $councilId);
                                $stmt->execute();
                            }
                        }
                        $response = ['success' => true, 'message' => 'Document approved successfully!'];
                    } else {
                        $response = ['success' => false, 'message' => 'Error approving document'];
                    }
                }
                
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
                break;

            case 'reject_org_doc':
                $docId = $_POST['doc_id'] ?? 0;
                $reason = $_POST['reason'] ?? '';
                $deadline = $_POST['deadline'] ?? '';
                $response = ['success' => false, 'message' => ''];
                
                if ($docId && !empty($reason)) {
                    // Prepare the SQL with optional deadline
                    if (!empty($deadline)) {
                        $stmt = $conn->prepare("UPDATE council_documents SET status = 'rejected', osas_rejected_at = NOW(), approved_by_osas = ?, reject_reason = ?, resubmission_deadline = ?, deadline_set_by = ?, deadline_set_at = NOW() WHERE id = ?");
                        $stmt->bind_param("issii", $_SESSION['user_id'], $reason, $deadline, $_SESSION['user_id'], $docId);
                    } else {
                        $stmt = $conn->prepare("UPDATE council_documents SET status = 'rejected', osas_rejected_at = NOW(), approved_by_osas = ?, reject_reason = ? WHERE id = ?");
                        $stmt->bind_param("isi", $_SESSION['user_id'], $reason, $docId);
                    }
                    
                    if ($stmt->execute()) {
                        // Get document details for notification
                        $docStmt = $conn->prepare("SELECT council_id, document_type FROM council_documents WHERE id = ?");
                        $docStmt->bind_param("i", $docId);
                        $docStmt->execute();
                        $docDetails = $docStmt->get_result()->fetch_assoc();
                        $docStmt->close();
                        
                        // Create notification for document rejection
                        if ($docDetails) {
                            require_once 'includes/notification_helper.php';
                            notifyCouncilDocumentAction($docDetails['council_id'], 'rejected', $docDetails['document_type'], $docId);
                        }
                        
                        // Any rejection guarantees the council remains UNRECOGNIZED
                        if (!empty($docDetails['council_id'])) {
                            $councilId = (int)$docDetails['council_id'];
                            $stmt = $conn->prepare("UPDATE council SET status = 'unrecognized' WHERE id = ?");
                            $stmt->bind_param("i", $councilId);
                            $stmt->execute();
                        }
                        $response = ['success' => true, 'message' => 'Document rejected successfully!' . (!empty($deadline) ? ' Deadline set for resubmission.' : '')];
                    } else {
                        $response = ['success' => false, 'message' => 'Error rejecting document'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Document ID and reason are required'];
                }
                
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
                break;

            case 'approve_event_doc':
                $docId = $_POST['doc_id'] ?? 0;
                $response = ['success' => false, 'message' => ''];
                
                if ($docId) {
                    $stmt = $conn->prepare("UPDATE event_documents SET status = 'approved', osas_approved_at = NOW(), approved_by_osas = ? WHERE id = ?");
                    $stmt->bind_param("ii", $_SESSION['user_id'], $docId);
                    if ($stmt->execute()) {
                        // Get event details for notification
                        $eventStmt = $conn->prepare("
                            SELECT ea.council_id, ea.title, ea.id as event_approval_id
                            FROM event_documents ed 
                            JOIN event_approvals ea ON ed.event_approval_id = ea.id 
                            WHERE ed.id = ?
                        ");
                        $eventStmt->bind_param("i", $docId);
                        $eventStmt->execute();
                        $eventDetails = $eventStmt->get_result()->fetch_assoc();
                        $eventStmt->close();
                        
                        // Create notification for event document approval
                        if ($eventDetails) {
                            require_once 'includes/notification_helper.php';
                            notifyCouncilEventAction($eventDetails['council_id'], 'approved', $eventDetails['title'], $eventDetails['event_approval_id']);
                        }
                        
                        $response = ['success' => true, 'message' => 'Document approved successfully!'];
                    } else {
                        $response = ['success' => false, 'message' => 'Error approving document'];
                    }
                }
                
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
                break;

            case 'reject_event_doc':
                $docId = $_POST['doc_id'] ?? 0;
                $reason = $_POST['reason'] ?? '';
                $deadline = $_POST['deadline'] ?? '';
                $response = ['success' => false, 'message' => ''];
                
                if ($docId && !empty($reason)) {
                    // Prepare the SQL with optional deadline
                    if (!empty($deadline)) {
                        $stmt = $conn->prepare("UPDATE event_documents SET status = 'rejected', osas_rejected_at = NOW(), approved_by_osas = ?, rejection_reason = ?, resubmission_deadline = ?, deadline_set_by = ?, deadline_set_at = NOW() WHERE id = ?");
                        $stmt->bind_param("issii", $_SESSION['user_id'], $reason, $deadline, $_SESSION['user_id'], $docId);
                    } else {
                        $stmt = $conn->prepare("UPDATE event_documents SET status = 'rejected', osas_rejected_at = NOW(), approved_by_osas = ?, rejection_reason = ? WHERE id = ?");
                        $stmt->bind_param("isi", $_SESSION['user_id'], $reason, $docId);
                    }
                    
                    if ($stmt->execute()) {
                        // Get event details and document type for notification
                        $eventStmt = $conn->prepare("
                            SELECT ea.council_id, ea.title, ea.id as event_approval_id, ed.document_type
                            FROM event_documents ed 
                            JOIN event_approvals ea ON ed.event_approval_id = ea.id 
                            WHERE ed.id = ?
                        ");
                        $eventStmt->bind_param("i", $docId);
                        $eventStmt->execute();
                        $eventDetails = $eventStmt->get_result()->fetch_assoc();
                        $eventStmt->close();
                        
                        // Create notification for event document rejection
                        if ($eventDetails) {
                            require_once 'includes/notification_helper.php';
                            notifyOSASCouncilEventDocumentAction($eventDetails['council_id'], $eventDetails['document_type'], $docId, $eventDetails['title']);
                        }
                        
                        $response = ['success' => true, 'message' => 'Document rejected successfully!' . (!empty($deadline) ? ' Deadline set for resubmission.' : '')];
                    } else {
                        $response = ['success' => false, 'message' => 'Error rejecting document'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'Document ID and reason are required'];
                }
                
                // Return JSON response for AJAX
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
                break;
        }
    }
}

// Get councils for filter
$councils = $conn->query("SELECT id, council_name FROM council ORDER BY council_name")->fetch_all(MYSQLI_ASSOC);

// Build council documents query - show documents that need OSAS attention
$wherePending = "d.adviser_approval_at IS NOT NULL AND d.osas_approved_at IS NULL AND d.osas_rejected_at IS NULL";
$councilDocsQueryBase = "
    SELECT d.*, c.council_name as council_name, u.username as submitted_by,
           d.adviser_approval_at, d.osas_approved_at, d.osas_rejected_at,
           d.resubmission_deadline, d.deadline_set_by, d.deadline_set_at
    FROM council_documents d
    JOIN council c ON d.council_id = c.id
    LEFT JOIN academic_terms at_doc ON d.academic_year_id = at_doc.id
    LEFT JOIN academic_terms at_council ON c.academic_year_id = at_council.id
    JOIN users u ON d.submitted_by = u.id
    WHERE d.adviser_approval_at IS NOT NULL
      AND COALESCE(at_doc.status, at_council.status) = 'active'
";

$params = [];
$types = "";

if ($documentType) {
    // Check if it's a council document type
    if (isset($documentTypes[$documentType])) {
        $councilDocsQueryBase .= " AND d.document_type = ?";
        $params[] = $documentType;
        $types .= "s";
    }
}

if ($council) {
    $councilDocsQueryBase .= " AND d.council_id = ?";
    $params[] = $council;
    $types .= "i";
}

// For NEW councils, hide year-2+ documents on OSAS side during the active academic year
// Only show the 11 required documents; the 3 additional appear when council becomes 'old'
$councilDocsQueryBase .= " AND (c.type <> 'new' OR d.document_type NOT IN ('accomplishment_report','previous_plan_of_activities','financial_report'))";

// Apply OSAS status filter within adviser-approved set
if ($status === 'pending') {
    $councilDocsQueryBase .= " AND d.osas_approved_at IS NULL AND d.osas_rejected_at IS NULL";
} elseif ($status === 'approved') {
    $councilDocsQueryBase .= " AND d.osas_approved_at IS NOT NULL";
} elseif ($status === 'rejected') {
    $councilDocsQueryBase .= " AND d.osas_rejected_at IS NULL";
}

// Don't apply status filter - show all documents but highlight the relevant ones
// The highlighting logic will handle showing which documents match the notification

if ($startDate) {
    $councilDocsQueryBase .= " AND d.submitted_at >= ?";
    $params[] = $startDate . " 00:00:00";
    $types .= "s";
}

if ($endDate) {
    $councilDocsQueryBase .= " AND d.submitted_at <= ?";
    $params[] = $endDate . " 23:59:59";
    $types .= "s";
}

// Count for pagination (avoid duplicate column names in derived table)
$councilCountQuery = "SELECT COUNT(*) as total " . substr($councilDocsQueryBase, strpos($councilDocsQueryBase, 'FROM'));
$stmt = $conn->prepare($councilCountQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$councilTotal = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$councilTotalPages = max(1, (int)ceil($councilTotal / $perPage));
$councilOffset = ($pageCouncil - 1) * $perPage;

// Fetch paginated
$councilDocsQuery = $councilDocsQueryBase . " ORDER BY d.submitted_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($councilDocsQuery);
if (!empty($params)) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$perPage, $councilOffset]));
} else {
    $stmt->bind_param("ii", $perPage, $councilOffset);
}
$stmt->execute();
$councilDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build council event documents query - group by event
$eventDocsQueryBase = "
    SELECT ea.id as event_approval_id, ea.title as event_title, c.council_name as council_name,
           COUNT(d.id) as total_docs,
           COUNT(CASE WHEN d.osas_approved_at IS NULL AND d.osas_rejected_at IS NULL AND d.adviser_approved_at IS NOT NULL THEN 1 END) as pending_docs,
           COUNT(CASE WHEN d.osas_approved_at IS NOT NULL THEN 1 END) as approved_docs,
           COUNT(CASE WHEN d.osas_rejected_at IS NOT NULL THEN 1 END) as rejected_docs,
           COUNT(CASE WHEN d.resubmission_deadline IS NOT NULL THEN 1 END) as docs_with_deadline,
           ea.created_at as event_date
    FROM event_approvals ea
    JOIN council c ON ea.council_id = c.id
    LEFT JOIN event_documents d ON ea.id = d.event_approval_id
    WHERE ea.council_id IS NOT NULL
";

$params = [];
$types = "";

if ($documentType) {
    // Check if it's a council event document type
    if (isset($documentTypes[$documentType])) {
        $eventDocsQueryBase .= " AND d.document_type = ?";
        $params[] = $documentType;
        $types .= "s";
    }
}

if ($council) {
    $eventDocsQueryBase .= " AND ea.council_id = ?";
    $params[] = $council;
    $types .= "i";
}

// OSAS Visibility Rule: Events only appear when ALL documents are approved by adviser
// AND no documents have been rejected by OSAS without being resubmitted
// This prevents mixed states where resubmitted and rejected documents appear together
// Events must be completely clean (all adviser-approved, no OSAS rejections) to be visible

if ($startDate) {
    $eventDocsQueryBase .= " AND ea.created_at >= ?";
    $params[] = $startDate . " 00:00:00";
    $types .= "s";
}

if ($endDate) {
    $eventDocsQueryBase .= " AND ea.created_at <= ?";
    $params[] = $endDate . " 23:59:59";
    $types .= "s";
}

$eventGroupSection = " GROUP BY ea.id, ea.title, c.council_name, ea.created_at 
                    HAVING total_docs > 0 AND (
                        COUNT(CASE WHEN d.adviser_approved_at IS NOT NULL THEN 1 END) = total_docs AND
                        COUNT(CASE WHEN d.osas_rejected_at IS NOT NULL THEN 1 END) = 0
                    )";

// Count total event groups (avoid duplicate column names by reusing FROM and grouping)
$eventCountQuery = $eventDocsQueryBase . $eventGroupSection;
$eventCountQuery = "SELECT COUNT(*) as total FROM (" . $eventCountQuery . ") t";
$stmt = $conn->prepare($eventCountQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$eventTotal = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$eventTotalPages = max(1, (int)ceil($eventTotal / $perPage));
$eventOffset = ($pageEvent - 1) * $perPage;

// Fetch paginated event groups
$eventDocsQuery = $eventDocsQueryBase . $eventGroupSection . " ORDER BY ea.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($eventDocsQuery);
if (!empty($params)) {
    $stmt->bind_param($types . "ii", ...array_merge($params, [$perPage, $eventOffset]));
} else {
    $stmt->bind_param("ii", $perPage, $eventOffset);
}
$stmt->execute();
$eventDocs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSAS Council Documents - OSAS Portal</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        /* Main styling */
        main {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: calc(100vh - 56px);
        }
        
        /* Page header styling */
        main h2 {
            color: #343a40;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        
        /* Page title and subtitle styling */
        .page-title {
            color: #333333 !important;
            font-weight: 700;
            font-size: 2.00rem;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        
        .page-subtitle {
            color: #666666 !important;
            font-weight: 400;
            font-size: 1rem;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.5;
            margin-bottom: 0;
        }
        
        /* Page header spacing */
        .page-header {
            margin-bottom: 1.5rem !important;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-subtitle {
                font-size: 0.95rem;
            }
        }
        
        /* Enhanced card styling */
        main .card {
            border: none !important;
            outline: none !important;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        main .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Filter card styling */
        main .card:first-of-type {
            border: none !important;
            border-left: none !important;
            outline: none !important;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        /* Enhanced filter form styling */
        main .card:first-of-type .form-label {
            color: #495057;
            font-weight: 700;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.75rem;
        }
        
        main .card:first-of-type .form-control,
        main .card:first-of-type .form-select {
            border: 2px solid #dee2e6;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            background-color: #ffffff;
            transition: all 0.3s ease;
        }
        
        main .card:first-of-type .form-control:focus,
        main .card:first-of-type .form-select:focus {
            border-color: #6c757d;
            box-shadow: 0 0 0 0.25rem rgba(108, 117, 125, 0.25);
            background-color: #ffffff;
            outline: none !important;
        }
        
        main .card:first-of-type .btn-primary {
            background: linear-gradient(45deg, #6c757d, #495057);
            border: none !important;
            outline: none !important;
            font-weight: 700;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }
        
        main .card:first-of-type .btn-primary:hover {
            background: linear-gradient(45deg, #495057, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
            border: none !important;
            outline: none !important;
        }
        
        main .card:first-of-type .btn-primary:focus,
        main .card:first-of-type .btn-primary:active {
            border: none !important;
            outline: none !important;
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4) !important;
        }
        
        main .card:first-of-type .btn-secondary {
            background: linear-gradient(45deg, #adb5bd, #6c757d);
            border: none !important;
            outline: none !important;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }
        
        main .card:first-of-type .btn-secondary:focus,
        main .card:first-of-type .btn-secondary:active {
            border: none !important;
            outline: none !important;
        }
        
        /* Enhanced form field styling */
        main .card:first-of-type .form-select option {
            padding: 0.5rem;
            font-size: 0.95rem;
        }
        
        main .card:first-of-type .form-select optgroup {
            font-weight: 700;
            color: #495057;
            background-color: #f8f9fa;
        }
        
        /* Filter form responsive adjustments */
        @media (max-width: 768px) {
            main .card:first-of-type .btn-primary,
            main .card:first-of-type .btn-secondary {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            main .card:first-of-type .btn-primary {
                margin-right: 0 !important;
            }
        }
        
        /* Council documents card */
        main .card:nth-of-type(2) {
            border-left: 5px solid #495057;
        }
        
        /* Council event documents card */
        main .card:nth-of-type(3) {
            border-left: 5px solid #343a40;
        }
        
        main .card-body {
            padding: 2rem;
        }
        
        /* Card header styling */
        main .card-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem 2rem;
        }
        
        main .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Form styling */
        main .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        main .form-control,
        main .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        main .form-control:focus,
        main .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        /* Button styling */
        main .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;

        }
        
        main .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
        }
        
        main .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        main .btn-secondary:hover {
            background: linear-gradient(45deg, #5a6268, #5a6268);
            transform: translateY(-2px);
        }
        
        main .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
        }
        
        /* Enhanced table styling */
        main .table-responsive {
            border-radius: 12px;
            overflow-x: auto !important; /* Explicit horizontal scroll */
            overflow-y: visible;
            -webkit-overflow-scrolling: touch; /* Smooth iOS scrolling */
            scrollbar-width: thin;
            scrollbar-color: #495057 #f8f9fa;
            position: relative;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            /* Add visual indicator for scrollable content */
            background: linear-gradient(to right, white 0%, white 95%, rgba(255,255,255,0.8) 100%);
        }
        
        main .table-responsive::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 30px;
            height: 100%;
            background: linear-gradient(to right, transparent, rgba(0,0,0,0.05));
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        /* Show indicator when content is scrollable */
        main .table-responsive.scrollable::after {
            opacity: 1;
        }
        
        main .table {
            margin-bottom: 0;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            font-size: 0.85rem; /* Smaller font for better fit */
            min-width: 800px; /* Ensure horizontal scroll on mobile */
        }
        
        main .table thead th {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            font-weight: 600;
            border: none;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.75rem 0.5rem;
            position: relative;
            white-space: nowrap;
        }
        
        main .table thead th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #495057 0%, #6c757d 100%);
        }
        
        main .table tbody tr {
            transition: all 0.3s ease;
            border: none;
        }
        
        /* Council documents table - gray theme */
        main .card:nth-of-type(2) .table tbody tr:nth-child(odd) {
            background: rgba(52, 58, 64, 0.03);
            border-left: 3px solid rgba(52, 58, 64, 0.2);
        }
        
        main .card:nth-of-type(2) .table tbody tr:nth-child(even) {
            background: rgba(108, 117, 125, 0.03);
            border-left: 3px solid rgba(108, 117, 125, 0.2);
        }
        
        main .card:nth-of-type(2) .table tbody tr:hover {
            background: rgba(73, 80, 87, 0.1) !important;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(73, 80, 87, 0.2);
            border-left-color: #495057 !important;
        }
        
        /* Council event documents table - darker gray theme */
        main .card:nth-of-type(3) .table tbody tr:nth-child(odd) {
            background: rgba(33, 37, 41, 0.03);
            border-left: 3px solid rgba(33, 37, 41, 0.2);
        }
        
        main .card:nth-of-type(3) .table tbody tr:nth-child(even) {
            background: rgba(52, 58, 64, 0.03);
            border-left: 3px solid rgba(52, 58, 64, 0.2);
        }
        
        main .card:nth-of-type(3) .table tbody tr:hover {
            background: rgba(52, 58, 64, 0.1) !important;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(52, 58, 64, 0.2);
            border-left-color: #343a40 !important;
        }
        
        /* Button styling in tables - solid colors for action buttons */
        main .btn-info {
            background: #198754;
            border: none;
            color: white;
        }
        
        main .btn-info:hover {
            background: #157347;
            color: white;
        }
        
        main .btn-success {
            background: #0056b3;
            border: none;
        }
        
        main .btn-success:hover {
            background: #004085;
        }
        
        main .btn-danger {
            background: #dc3545;
            border: none;
        }
        
        main .btn-danger:hover {
            background: #bb2d3b;
        }
        
        /* Responsive column widths for Council Documents table */
        main .card:nth-of-type(2) .table th:nth-child(1), /* Council */
        main .card:nth-of-type(2) .table td:nth-child(1) {
            width: 20%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.3;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(2), /* Document Type */
        main .card:nth-of-type(2) .table td:nth-child(2) {
            width: 25%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.2;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            hyphens: auto;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(3), /* Submitted By */
        main .card:nth-of-type(2) .table td:nth-child(3) {
            width: 15%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(4), /* Date Submitted */
        main .card:nth-of-type(2) .table td:nth-child(4) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(5), /* Status */
        main .card:nth-of-type(2) .table td:nth-child(5) {
            width: 10%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(6), /* Actions */
        main .card:nth-of-type(2) .table td:nth-child(6) {
            width: 28%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
        }
        
        /* Responsive column widths for Council Event Documents table */
        main .card:nth-of-type(3) .table th:nth-child(1), /* Event Title */
        main .card:nth-of-type(3) .table td:nth-child(1) {
            width: 25%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.3;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            hyphens: auto;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(3) .table th:nth-child(2), /* Council */
        main .card:nth-of-type(3) .table td:nth-child(2) {
            width: 20%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            vertical-align: top;
            line-height: 1.3;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(3) .table th:nth-child(3), /* Documents */
        main .card:nth-of-type(3) .table td:nth-child(3) {
            width: 15%;
            text-align: center;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(3) .table th:nth-child(4), /* Date Submitted */
        main .card:nth-of-type(3) .table td:nth-child(4) {
            width: 15%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(3) .table th:nth-child(5), /* Status */
        main .card:nth-of-type(3) .table td:nth-child(5) {
            width: 15%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(3) .table th:nth-child(6), /* Actions */
        main .card:nth-of-type(3) .table td:nth-child(6) {
            width: 10%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
        }
        
        /* Compact action buttons */
        main .table td:last-child {
            white-space: nowrap;
            vertical-align: middle;
        }
        
        main .table td:last-child .btn {
            margin-right: 0.2rem;
            margin-bottom: 0.2rem;
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            border-radius: 4px;
        }
        
        main .table td:last-child .btn:last-child {
            margin-right: 0;
        }
        
        /* Make buttons more compact */
        main .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.2;
        }
        
        /* General table cell styling */
        main .table td {
            border-color: rgba(0, 0, 0, 0.05);
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }
        
        /* Compact container for better space usage */
        main .container-fluid {
            padding: 1.5rem;
            max-width: 100%;
        }
        
        /* Responsive card spacing */
        main .card {
            margin-bottom: 1.5rem;
        }
        
        main .card-body {
            padding: 1.5rem;
        }
        
        /* Alert styling */
        main .alert-success {
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.1), rgba(134, 142, 150, 0.05));
            border: 2px solid rgba(108, 117, 125, 0.2);
            border-radius: 12px;
            color: #495057;
        }
        
        main .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border: 2px solid rgba(220, 53, 69, 0.2);
            border-radius: 12px;
            color: #842029;
        }
        
        /* Pagination styling aligned with theme */
        .pagination .page-link {
            color: #495057;
            border: 1px solid #dee2e6;
            background: #ffffff;
            font-weight: 600;
        }
        .pagination .page-link:hover {
            color: #ffffff;
            background: linear-gradient(45deg, #495057, #6c757d);
            border-color: #495057;
        }
        .pagination .page-item.active .page-link {
            color: #ffffff;
            background: linear-gradient(45deg, #343a40, #495057);
            border-color: #343a40;
            box-shadow: 0 2px 8px rgba(52, 58, 64, 0.3);
        }
        .pagination .page-item.disabled .page-link {
            color: #adb5bd;
            background: #f8f9fa;
            border-color: #dee2e6;
        }

        /* Modal alert styling */
        #eventDocsModal .alert {
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        #eventDocsModal .alert-success {
            background: linear-gradient(135deg, rgba(25, 135, 84, 0.1), rgba(25, 135, 84, 0.05));
            border: 2px solid rgba(25, 135, 84, 0.3);
            color: #0f5132;
        }
        
        #eventDocsModal .alert-danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border: 2px solid rgba(220, 53, 69, 0.3);
            color: #842029;
        }
        
        /* Text muted styling */
        main .text-muted {
            color: #6c757d !important;
            font-style: italic;
            text-align: center;
            padding: 2rem;
            background: rgba(108, 117, 125, 0.05);
            border-radius: 8px;
            border: 2px dashed rgba(108, 117, 125, 0.2);
        }
        
        /* Modal styling */
        main .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        main .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border: none;
        }
        
        main .modal-title {
            font-weight: 600;
        }
        
        main .modal-body {
            padding: 2rem;
        }
        
        main .modal-footer {
            border: none;
            padding: 1rem 2rem 2rem;
        }
        
        /* Fix modal z-index layering issues */
        .modal {
            z-index: 1055 !important;
        }
        
        .modal-backdrop {
            z-index: 1054 !important;
        }
        
        /* Ensure rejection and approval modals appear above event documents modal */
        #rejectOrgDocModal,
        #approveOrgDocModal,
        #rejectEventDocModal,
        #approveEventDocModal {
            z-index: 1065 !important;
        }
        
        #rejectOrgDocModal .modal-backdrop,
        #approveOrgDocModal .modal-backdrop,
        #rejectEventDocModal .modal-backdrop,
        #approveEventDocModal .modal-backdrop {
            z-index: 1064 !important;
        }
        
        /* File viewer modal should be on top */
        #fileViewerModal {
            z-index: 1075 !important;
        }
        
        #fileViewerModal .modal-backdrop {
            z-index: 1074 !important;
        }
        
        /* Event documents modal base z-index */
        #eventDocsModal {
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
        
        main .table tbody tr {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        main .table tbody tr:nth-child(even) {
            animation-delay: 0.1s;
        }
        
        main .table tbody tr:nth-child(3n) {
            animation-delay: 0.2s;
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            main .container-fluid {
                padding: 1rem;
            }
            
            main .card-body {
                padding: 1rem;
            }
            
            main h2 {
                font-size: 1.5rem;
            }
            
            main .table {
                font-size: 0.75rem;
            }
            
            main .table thead th,
            main .table td {
                padding: 0.5rem 0.3rem;
            }
            
            /* Adjust button sizes for mobile */
            main .btn-sm {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }
            
            /* Stack action buttons vertically on mobile */
            main .table td:last-child .btn {
                display: block;
                width: 100%;
                margin: 0.1rem 0;
                text-align: center;
            }
            
            /* Reduce column widths for mobile */
            main .card:nth-of-type(2) .table th:nth-child(1),
            main .card:nth-of-type(2) .table td:nth-child(1) {
                width: 25%;
            }
            
            main .card:nth-of-type(2) .table th:nth-child(2),
            main .card:nth-of-type(2) .table td:nth-child(2) {
                width: 30%;
            }
            
            main .card:nth-of-type(2) .table th:nth-child(3),
            main .card:nth-of-type(2) .table td:nth-child(3) {
                width: 15%;
            }
            
            main .card:nth-of-type(2) .table th:nth-child(4),
            main .card:nth-of-type(2) .table td:nth-child(4) {
                width: 15%;
            }
            
            main .card:nth-of-type(2) .table th:nth-child(5),
            main .card:nth-of-type(2) .table td:nth-child(5) {
                width: 15%;
            }
            
            /* Council event documents table mobile adjustments */
            main .card:nth-of-type(3) .table th:nth-child(1),
            main .card:nth-of-type(3) .table td:nth-child(1) {
                width: 30%;
            }
            
            main .card:nth-of-type(3) .table th:nth-child(2),
            main .card:nth-of-type(3) .table td:nth-child(2) {
                width: 25%;
            }
            
            main .card:nth-of-type(3) .table th:nth-child(3),
            main .card:nth-of-type(3) .table td:nth-child(3) {
                width: 20%;
            }
            
            main .card:nth-of-type(3) .table th:nth-child(4),
            main .card:nth-of-type(3) .table td:nth-child(4) {
                width: 15%;
            }
            
            main .card:nth-of-type(3) .table th:nth-child(5),
            main .card:nth-of-type(3) .table td:nth-child(5) {
                width: 10%;
            }
        }
        
        /* Extra small devices */
        @media (max-width: 576px) {
            main .container-fluid {
                padding: 0.5rem;
            }
            
            main .table {
                font-size: 0.7rem;
            }
            
            main .table thead th,
            main .table td {
                padding: 0.4rem 0.2rem;
            }
            
            /* Hide less important columns on very small screens */
            main .card:nth-of-type(2) .table th:nth-child(3),
            main .card:nth-of-type(2) .table td:nth-child(3) {
                display: none;
            }
            
            main .card:nth-of-type(3) .table th:nth-child(4),
            main .card:nth-of-type(3) .table td:nth-child(4) {
                display: none;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="page-header">
                <h2 class="page-title">OSAS Council Documents</h2>
                <p class="page-subtitle">Manage and review council documents</p>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title h5 mb-0">
                        Document Filters
                    </h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="document_type" class="form-label">Document Type</label>
                            <select class="form-select" id="document_type" name="document_type">
                                <option value="">All Types</option>
                                <?php foreach ($documentTypes as $key => $label): ?>
                                    <option value="<?php echo $key; ?>" <?php echo $documentType === $key ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="council" class="form-label">Council</label>
                            <select class="form-select" id="council" name="council">
                                <option value="">All Councils</option>
                                <?php foreach ($councils as $councilItem): ?>
                                    <option value="<?php echo $councilItem['id']; ?>" <?php echo $council == $councilItem['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($councilItem['council_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="" <?php echo $status === '' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search me-2"></i>Apply Filters
                            </button>
                            <a href="osas_council_documents.php" class="btn btn-secondary">
                                <i class="fas fa-refresh me-2"></i>Reset Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Council Documents -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title h5 mb-0">Council Documents</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($councilDocs)): ?>
                        <p class="text-muted">No council documents approved by advisers found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Council</th>
                                        <th>Document Type</th>
                                        <th>Submitted By</th>
                                        <th>Date Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($councilDocs as $doc): 
                                        // Check if this document should be highlighted based on notification
                                        $isHighlighted = false;
                                        if ($notificationId && $notificationType) {
                                            // For council_documents_for_review notifications, highlight the specific document that was sent for review
                                            if ($notificationType === 'council_documents_for_review' && $notificationId == $doc['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For council_document_approved notifications, highlight the specific document that was approved
                                            elseif ($notificationType === 'council_document_approved' && $notificationId == $doc['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For council_document_rejected notifications, highlight the specific document that was rejected
                                            elseif ($notificationType === 'council_document_rejected' && $notificationId == $doc['id']) {
                                                $isHighlighted = true;
                                            }
                                            // For council document_submitted notifications, highlight the newly submitted document
                                            elseif ($notificationType === 'document_submitted' && $notificationId == $doc['id']) {
                                                $isHighlighted = true;
                                            }
                                        }
                                        
                                        // Get the appropriate document type label
                                        $docTypeLabel = '';
                                        if (isset($documentTypes[$doc['document_type']])) {
                                            $docTypeLabel = $documentTypes[$doc['document_type']];
                                        } else {
                                            $docTypeLabel = 'Unknown Document Type';
                                        }
                                    ?>
                                        <tr class="<?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                                            id="document-<?php echo $doc['id']; ?>"
                                            data-doc-id="<?php echo $doc['id']; ?>"
                                            data-file-path="<?php echo htmlspecialchars($doc['file_path']); ?>"
                                            data-doc-title="<?php echo addslashes($docTypeLabel); ?>">
                                            <td><?php echo htmlspecialchars($doc['council_name']); ?></td>
                                            <td><?php echo $docTypeLabel; ?></td>
                                            <td><?php echo htmlspecialchars($doc['submitted_by']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($doc['submitted_at'])); ?></td>
                                            <td>
                                                <?php if (!empty($doc['osas_approved_at'])): ?>
                                                    <span class="badge bg-success">Approved</span>
                                                <?php elseif (!empty($doc['osas_rejected_at'])): ?>
                                                    <span class="badge bg-danger">Rejected</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Pending Review</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-info" 
                                                    onclick="viewFileModal('./<?php echo addslashes($doc['file_path']); ?>', '<?php echo addslashes($docTypeLabel); ?>')">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                                <?php if (empty($doc['osas_approved_at']) && empty($doc['osas_rejected_at'])): ?>
                                                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveOrgDocModal" data-doc-id="<?php echo $doc['id']; ?>">
                                                        <i class="bi bi-check-circle"></i> Approve
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectOrgDocModal" data-doc-id="<?php echo $doc['id']; ?>">
                                                        <i class="bi bi-x-circle"></i> Reject
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($councilTotalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm">
                                <?php 
                                $qs = $_GET; 
                                for ($p = 1; $p <= $councilTotalPages; $p++): 
                                    $qs['page_council'] = $p;
                                    $url = 'osas_council_documents.php?' . http_build_query($qs);
                                ?>
                                    <li class="page-item <?php echo $p == $pageCouncil ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $url; ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Council Event Documents -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title h5 mb-0">Council Event Documents</h3>
                </div>
                <div class="card-body">
                    <?php if (empty($eventDocs)): ?>
                        <p class="text-muted">No pending council event documents.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Event Title</th>
                                        <th>Council</th>
                                        <th>Total Documents</th>
                                        <th>Date Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($eventDocs as $event): 
                                        // Check if this event should be highlighted based on notification
                                        $isHighlighted = false;
                                        if ($notificationId && $notificationType) {
                                            // For council_event_documents_for_review notifications, highlight the specific event that was sent for review
                                            if ($notificationType === 'council_event_documents_for_review' && $notificationId == $event['event_approval_id']) {
                                                $isHighlighted = true;
                                            }
                                            // For council_event_approved notifications, highlight the specific event that was approved
                                            elseif ($notificationType === 'council_event_approved' && $notificationId == $event['event_approval_id']) {
                                                $isHighlighted = true;
                                            }
                                            // For council_event_rejected notifications, highlight the specific event that was rejected
                                            elseif ($notificationType === 'council_event_rejected' && $notificationId == $event['event_approval_id']) {
                                                $isHighlighted = true;
                                            }
                                        }
                                    ?>
                                        <tr class="<?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                                            id="event-<?php echo $event['event_approval_id']; ?>"
                                            data-event-id="<?php echo $event['event_approval_id']; ?>">
                                            <td><?php echo htmlspecialchars($event['event_title']); ?></td>
                                            <td><?php echo htmlspecialchars($event['council_name']); ?></td>
                                            <td><?php echo $event['total_docs']; ?> documents</td>
                                            <td><?php echo date('M d, Y', strtotime($event['event_date'])); ?></td>
                                            <td>
                                                <?php 
                                                require_once 'includes/status_helper.php';
                                                $status = getOsasEventStatus(
                                                    $event['total_docs'],
                                                    $event['approved_docs'],
                                                    $event['rejected_docs']
                                                );
                                                echo getOsasStatusBadge($status);
                                                ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#eventDocsModal" data-event-id="<?php echo $event['event_approval_id']; ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($eventTotalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination pagination-sm">
                                <?php 
                                $qs = $_GET; 
                                for ($p = 1; $p <= $eventTotalPages; $p++): 
                                    $qs['page_event'] = $p;
                                    $url = 'osas_council_documents.php?' . http_build_query($qs);
                                ?>
                                    <li class="page-item <?php echo $p == $pageEvent ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo $url; ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Approve Council Document Modal -->
    <div class="modal fade" id="approveOrgDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Council Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to approve this document?</p>
                        <input type="hidden" name="action" value="approve_org_doc">
                        <input type="hidden" name="doc_id" id="approveOrgDocId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reject Council Document Modal -->
    <div class="modal fade" id="rejectOrgDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Reject Council Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejectOrgReason" class="form-label">
                                Rejection Reason <span class="text-danger">*</span>
                            </label>
                            <textarea class="form-control" id="rejectOrgReason" name="reason" rows="4" required placeholder="Please provide a reason for rejecting this document..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="rejectOrgDeadline" class="form-label">
                                Resubmission Deadline (Optional)
                            </label>
                            <input type="datetime-local" class="form-control" id="rejectOrgDeadline" name="deadline" 
                                   min="<?php echo date('Y-m-d\TH:i'); ?>" 
                                   placeholder="Set deadline for resubmission">
                            <small class="form-text text-muted">Leave empty if no deadline is required</small>
                        </div>
                        <input type="hidden" name="action" value="reject_org_doc">
                        <input type="hidden" name="doc_id" id="rejectOrgDocId">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Approve Council Event Document Modal -->
    <div class="modal fade" id="approveEventDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Approve Council Event Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve this document?</p>
                    <input type="hidden" id="approveEventDocId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="handleEventDocApproval()">Approve</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject Event Document Modal -->
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
    <div class="modal fade" id="rejectEventDocModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Council Event Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="rejectEventReason" class="form-label">
                            Rejection Reason <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control" id="rejectEventReason" rows="4" placeholder="Please provide a reason for rejecting this document..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="rejectEventDeadline" class="form-label">
                            Resubmission Deadline (Optional)
                        </label>
                        <input type="datetime-local" class="form-control" id="rejectEventDeadline" 
                               min="<?php echo date('Y-m-d\TH:i'); ?>" 
                               placeholder="Set deadline for resubmission">
                        <small class="form-text text-muted">Leave empty if no deadline is required</small>
                    </div>
                    <input type="hidden" id="rejectEventDocId">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="handleEventDocRejection()">Confirm Rejection</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Council Event Documents Modal -->
    <div class="modal fade" id="eventDocsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="eventDocsModalTitle">Council Event Documents</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="eventDocsModalBody">
                    <!-- Documents will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        // Handle modal data with event delegation for dynamically loaded content
        document.addEventListener('DOMContentLoaded', function() {
            const modals = ['approveOrgDoc', 'rejectOrgDoc', 'approveEventDoc', 'rejectEventDoc'];
            modals.forEach(modal => {
                const modalElement = document.getElementById(modal + 'Modal');
                if (modalElement) {
                    modalElement.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;
                        const docId = button.getAttribute('data-doc-id');
                        document.getElementById(modal + 'Id').value = docId;
                    });
                }
            });
            
                                            // Add event delegation for dynamically loaded approval/rejection buttons
            document.addEventListener('click', function(event) {
                if (event.target.matches('[data-bs-target="#approveEventDocModal"]') || 
                    event.target.closest('[data-bs-target="#approveEventDocModal"]')) {
                    const button = event.target.matches('[data-bs-target="#approveEventDocModal"]') ? 
                        event.target : event.target.closest('[data-bs-target="#approveEventDocModal"]');
                    const docId = button.getAttribute('data-doc-id');
                    document.getElementById('approveEventDocId').value = docId;
                }
                
                if (event.target.matches('[data-bs-target="#rejectEventDocModal"]') || 
                    event.target.closest('[data-bs-target="#rejectEventDocModal"]')) {
                    const button = event.target.matches('[data-bs-target="#rejectEventDocModal"]') ? 
                        event.target : event.target.closest('[data-bs-target="#rejectEventDocModal"]');
                    const docId = button.getAttribute('data-doc-id');
                    document.getElementById('rejectEventDocId').value = docId;
                }
            });
        });

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

        // Handle event documents modal
        document.getElementById('eventDocsModal').addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const eventId = button.getAttribute('data-event-id');
            
            document.getElementById('eventDocsModalTitle').textContent = 'Council Event Documents';
            document.getElementById('eventDocsModalBody').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            
            fetch(`get_osas_event_details.php?approval_id=${eventId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('eventDocsModalBody').innerHTML = html;
                    // Set the event ID attribute for future updates
                    document.getElementById('eventDocsModalBody').setAttribute('data-event-id', eventId);
                    
                    // Ensure all buttons in the modal are properly initialized
                    initializeModalButtons();
                })
                .catch(error => {
                    document.getElementById('eventDocsModalBody').innerHTML = '<div class="alert alert-danger">Error loading event details. Please try again.</div>';
                });
        });
        
        // Function to initialize modal buttons and ensure they work properly
        function initializeModalButtons() {
            const modalBody = document.getElementById('eventDocsModalBody');
            
            // Initialize approval buttons
            const approveButtons = modalBody.querySelectorAll('[data-bs-target="#approveEventDocModal"]');
            approveButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const docId = this.getAttribute('data-doc-id');
                    document.getElementById('approveEventDocId').value = docId;
                    
                    // Show the approval modal
                    const approvalModal = new bootstrap.Modal(document.getElementById('approveEventDocModal'));
                    approvalModal.show();
                });
            });
            
            // Initialize rejection buttons
            const rejectButtons = modalBody.querySelectorAll('[data-bs-target="#rejectEventDocModal"]');
            rejectButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const docId = this.getAttribute('data-doc-id');
                    document.getElementById('rejectEventDocId').value = docId;
                    
                    // Show the rejection modal
                    const rejectionModal = new bootstrap.Modal(document.getElementById('rejectEventDocModal'));
                    rejectionModal.show();
                });
            });
        }
        
        // Function to clean up modal backdrops (prevents gray screen issue)
        function cleanupModalBackdrops() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            if (backdrops.length > 1) {
                // Remove extra backdrops, keep only the first one
                for (let i = 1; i < backdrops.length; i++) {
                    backdrops[i].remove();
                }
            }
            
            // Ensure body doesn't have modal-open class when no modals are open
            const openModals = document.querySelectorAll('.modal.show');
            if (openModals.length === 0) {
                document.body.classList.remove('modal-open');
                document.body.style.paddingRight = '';
            }
        }
        
        // Ensure event documents modal stays open when approval/rejection modals are opened
        document.addEventListener('DOMContentLoaded', function() {
            const eventDocsModal = document.getElementById('eventDocsModal');
            if (eventDocsModal) {
                // Store the event documents modal instance
                let eventDocsModalInstance = null;
                
                // Initialize the event documents modal
                eventDocsModal.addEventListener('shown.bs.modal', function() {
                    eventDocsModalInstance = bootstrap.Modal.getInstance(eventDocsModal);
                });
                
                // Prevent the event documents modal from closing when other modals are opened
                eventDocsModal.addEventListener('hide.bs.modal', function(event) {
                    // Check if this is being triggered by opening an approval/rejection modal
                    const activeElement = document.activeElement;
                    if (activeElement && (activeElement.getAttribute('data-bs-target') === '#approveEventDocModal' || 
                                         activeElement.getAttribute('data-bs-target') === '#rejectEventDocModal')) {
                        event.preventDefault();
                        return false;
                    }
                });
                
                // Handle approval/rejection modal events to keep event docs modal open
                const approvalRejectionModals = ['#approveEventDocModal', '#rejectEventDocModal'];
                approvalRejectionModals.forEach(modalSelector => {
                    const modal = document.querySelector(modalSelector);
                    if (modal) {
                        modal.addEventListener('show.bs.modal', function() {
                            // Ensure event docs modal stays open
                            if (eventDocsModalInstance && eventDocsModal.classList.contains('show')) {
                                // Temporarily disable the backdrop of event docs modal
                                eventDocsModal.style.zIndex = '1054';
                            }
                        });
                        
                        modal.addEventListener('hidden.bs.modal', function() {
                            // Restore event docs modal z-index
                            if (eventDocsModalInstance && eventDocsModal.classList.contains('show')) {
                                eventDocsModal.style.zIndex = '1055';
                            }
                        });
                    }
                });
            }
        });
        
        // Notification tab handling
        document.addEventListener('DOMContentLoaded', function() {
            const notificationTab = '<?php echo $notificationTab; ?>';
            if (notificationTab) {
                let targetSection;
                if (notificationTab === 'council_documents') {
                    targetSection = document.querySelector('.card:has(h3:contains("Council Documents"))');
                } else if (notificationTab === 'council_event_documents') {
                    targetSection = document.querySelector('.card:has(h3:contains("Council Event Documents"))');
                }
                
                if (targetSection) {
                    // Scroll to the target section
                    targetSection.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'start' 
                    });
                    
                    // Add a subtle highlight effect
                    targetSection.style.border = '2px solid #2196f3';
                    targetSection.style.boxShadow = '0 0 15px rgba(33, 150, 243, 0.3)';
                    
                    // Remove highlight after 3 seconds
                    setTimeout(() => {
                        targetSection.style.border = '';
                        targetSection.style.boxShadow = '';
                    }, 3000);
                }
            }
        });
        
        // Notification highlighting and scrolling
        document.addEventListener('DOMContentLoaded', function() {
            const highlightedItems = document.querySelectorAll('.notification-highlight');
            if (highlightedItems.length > 0) {
                // Scroll to the first highlighted item
                highlightedItems[0].scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                
                // Add flash effect to all highlighted items
                highlightedItems.forEach(item => {
                    setTimeout(() => {
                        item.style.animation = 'notificationPulse 1s ease-in-out';
                    }, 500);
                    
                    // Remove highlight after 5 seconds
                    setTimeout(() => {
                        item.classList.remove('notification-highlight');
                    }, 5000);
                });
            }
        });

        // AJAX form submission handlers
        function submitOrgDocAction(action, docId, reason = '', deadline = '') {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('doc_id', docId);
            if (reason) {
                formData.append('reason', reason);
            }
            if (deadline) {
                formData.append('deadline', deadline);
            }

            // Determine which modal is being used
            const isApprovalForm = action === 'approve_org_doc';
            const modal = isApprovalForm ? document.getElementById('approveOrgDocModal') : document.getElementById('rejectOrgDocModal');
            const submitButton = modal ? modal.querySelector('button[type="submit"], button[onclick*="submitOrgDocAction"]') : null;
            let originalText = '';
            
            if (submitButton) {
                originalText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (action === 'approve_org_doc' ? 'Approving...' : 'Rejecting...');
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message within the modal
                    const modal = isApprovalForm ? document.getElementById('approveOrgDocModal') : document.getElementById('rejectOrgDocModal');
                    const body = modal.querySelector('.modal-body');
                    const footer = modal.querySelector('.modal-footer');
                    
                    body.innerHTML = '<div class="text-center"><i class="bi bi-check-circle text-success display-4"></i><h5 class="mt-3">Document ' + (isApprovalForm ? 'Approved' : 'Rejected') + ' Successfully!</h5><p class="text-muted">Status updated.</p></div>';
                    footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                    
                    // Update the specific row or refresh the table
                    updateDocumentRow(docId, action);
                    
                    // Auto-hide modal after 1.5 seconds
                    setTimeout(() => {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    }, 1500);
                } else {
                    // Show error message within the modal
                    const modal = isApprovalForm ? document.getElementById('approveOrgDocModal') : document.getElementById('rejectOrgDocModal');
                    const body = modal.querySelector('.modal-body');
                    body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + data.message + '</div>';
                }
            })
            .catch(error => {
                // Show error message within the modal
                const modal = isApprovalForm ? document.getElementById('approveOrgDocModal') : document.getElementById('rejectOrgDocModal');
                const body = modal.querySelector('.modal-body');
                body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Network error occurred. Please check your connection and try again.</div>';
                console.error('Error:', error);
            })
            .finally(() => {
                // Reset button state
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }
            });
        }

        function submitEventDocAction(action, docId, reason = '', deadline = '') {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('doc_id', docId);
            if (reason) {
                formData.append('reason', reason);
            }
            if (deadline) {
                formData.append('deadline', deadline);
            }

            // Determine which modal is being used
            const isApprovalForm = action === 'approve_event_doc';
            const modal = isApprovalForm ? document.getElementById('approveEventDocModal') : document.getElementById('rejectEventDocModal');
            const submitButton = modal ? modal.querySelector('button[onclick*="handleEventDoc"]') : null;
            let originalText = '';
            
            if (submitButton) {
                originalText = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (action === 'approve_event_doc' ? 'Approving...' : 'Rejecting...');
            }

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message within the approval/rejection modal
                    const isApprovalForm = action === 'approve_event_doc';
                    const modal = isApprovalForm ? document.getElementById('approveEventDocModal') : document.getElementById('rejectEventDocModal');
                    const body = modal.querySelector('.modal-body');
                    const footer = modal.querySelector('.modal-footer');
                    
                    body.innerHTML = '<div class="text-center"><i class="bi bi-check-circle text-success display-4"></i><h5 class="mt-3">Document ' + (isApprovalForm ? 'Approved' : 'Rejected') + ' Successfully!</h5><p class="text-muted">You can continue reviewing other documents.</p></div>';
                    footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                    
                    // Update the specific document row in the modal
                    updateEventDocumentRow(docId, action);
                    
                    // Auto-hide modal after 1.5 seconds
                    setTimeout(() => {
                        const modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    }, 1500);
                    
                    // Update the main event documents table
                    updateMainEventTable();
                    
                    // Keep the event documents modal open - DO NOT close it
                    // This allows users to continue approving/rejecting other documents
                } else {
                    // Show error message within the modal
                    const isApprovalForm = action === 'approve_event_doc';
                    const modal = isApprovalForm ? document.getElementById('approveEventDocModal') : document.getElementById('rejectEventDocModal');
                    const body = modal.querySelector('.modal-body');
                    body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>' + data.message + '</div>';
                }
            })
            .catch(error => {
                // Show error message within the modal
                const isApprovalForm = action === 'approve_event_doc';
                const modal = isApprovalForm ? document.getElementById('approveEventDocModal') : document.getElementById('rejectEventDocModal');
                const body = modal.querySelector('.modal-body');
                body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Network error occurred. Please check your connection and try again.</div>';
                console.error('Error:', error);
            })
            .finally(() => {
                // Reset button state
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }
            });
        }

        function updateDocumentRow(docId, action) {
            const row = document.querySelector(`tr[data-doc-id="${docId}"]`);
            if (row) {
                const statusCell = row.querySelector('td:nth-child(5)');
                const actionsCell = row.querySelector('td:nth-child(6)');
                const filePath = row.getAttribute('data-file-path');
                const docTitle = row.getAttribute('data-doc-title');
                
                if (action === 'approve_org_doc') {
                    statusCell.innerHTML = '<span class="badge bg-success">Approved</span>';
                    actionsCell.innerHTML = `<button type="button" class="btn btn-sm btn-info" onclick="viewFileModal('./${filePath}', '${docTitle}')"><i class="bi bi-eye"></i> View</button>`;
                } else if (action === 'reject_org_doc') {
                    statusCell.innerHTML = '<span class="badge bg-danger">Rejected</span>';
                    actionsCell.innerHTML = `<button type="button" class="btn btn-sm btn-info" onclick="viewFileModal('./${filePath}', '${docTitle}')"><i class="bi bi-eye"></i> View</button>`;
                }
                
                // Add a subtle highlight effect
                row.style.backgroundColor = '#d4edda';
                setTimeout(() => {
                    row.style.backgroundColor = '';
                }, 2000);
            }
        }

        function showAlert(type, message) {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Insert at the top of the main container
            const container = document.querySelector('.container-fluid');
            container.insertBefore(alertDiv, container.firstChild);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        function showModalAlert(type, message) {
            // Remove existing alerts in modal
            const modalBody = document.querySelector('#eventDocsModal .modal-body');
            const existingAlerts = modalBody.querySelectorAll('.alert');
            existingAlerts.forEach(alert => alert.remove());
            
            // Create new alert
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
            alertDiv.innerHTML = `
                <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            // Insert at the top of the modal body
            modalBody.insertBefore(alertDiv, modalBody.firstChild);
            
            // Auto-dismiss after 2 seconds for success messages (since modal stays open)
            const dismissTime = type === 'success' ? 2000 : 3000;
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, dismissTime);
        }

        function updateEventDocumentRow(docId, action) {
            const modalBody = document.querySelector('#eventDocsModal .modal-body');
            const row = modalBody.querySelector(`tr[data-doc-id="${docId}"]`);
            if (row) {
                const statusCell = row.querySelector('td:nth-child(2)');
                const actionsCell = row.querySelector('td:nth-child(4)');
                const filePath = row.getAttribute('data-file-path');
                const docTitle = row.getAttribute('data-doc-title');
                
                if (action === 'approve_event_doc') {
                    statusCell.innerHTML = '<span class="badge bg-success">APPROVED</span>';
                    // Remove approve/reject buttons, keep only view button
                    actionsCell.innerHTML = `<button type="button" class="btn btn-sm btn-info" onclick="viewFileModal('./${filePath}', '${docTitle}')"><i class="bi bi-eye"></i> View</button>`;
                } else if (action === 'reject_event_doc') {
                    statusCell.innerHTML = '<span class="badge bg-danger text-white">REJECTED</span>';
                    // Remove approve/reject buttons, keep only view button
                    actionsCell.innerHTML = `<button type="button" class="btn btn-sm btn-info" onclick="viewFileModal('./${filePath}', '${docTitle}')"><i class="bi bi-eye"></i> View</button>`;
                }
                
                // Add a subtle highlight effect to show the change
                row.style.backgroundColor = '#d4edda';
                row.style.borderLeft = '4px solid #198754';
                setTimeout(() => {
                    row.style.backgroundColor = '';
                    row.style.borderLeft = '';
                }, 3000);
                
                // Also update the main event documents table to reflect the change
                updateMainEventTableRow(row.closest('.modal-body').getAttribute('data-event-id'));
            }
        }

        function updateMainEventTable() {
            // Get the current event ID from the modal body
            const modalBody = document.getElementById('eventDocsModalBody');
            const eventId = modalBody.getAttribute('data-event-id');
            
            if (eventId) {
                // Update the main event documents table row without reloading the modal
                updateMainEventTableRow(eventId);
                
                // Optionally, we could also update the document counts in the main table
                // but for now, just highlight the row to show it was updated
            }
        }

        function updateMainEventTableRow(eventId) {
            // Get the main table row for this event
            const mainRow = document.querySelector(`tr[data-event-id="${eventId}"]`);
            if (mainRow) {
                // Add a subtle highlight effect to show it was updated
                mainRow.style.backgroundColor = '#d4edda';
                setTimeout(() => {
                    mainRow.style.backgroundColor = '';
                }, 2000);
                
                // Update the status badge if needed
                const statusCell = mainRow.querySelector('td:nth-child(5)');
                if (statusCell) {
                    // Check if all documents are now approved
                    const modalBody = document.querySelector('#eventDocsModal .modal-body');
                    const approvedDocs = modalBody.querySelectorAll('tr[data-doc-id] .badge.bg-success').length;
                    const totalDocs = modalBody.querySelectorAll('tr[data-doc-id]').length;
                    
                    if (approvedDocs === totalDocs && totalDocs > 0) {
                        statusCell.innerHTML = '<span class="badge bg-success">Approved</span>';
                    } else if (approvedDocs > 0 || totalDocs > 0) {
                        statusCell.innerHTML = '<span class="badge bg-warning text-dark">Pending Review</span>';
                    }
                }
            }
        }

        // JavaScript functions to handle event document approval and rejection
        function handleEventDocApproval() {
            const docId = document.getElementById('approveEventDocId').value;
            if (docId) {
                submitEventDocAction('approve_event_doc', docId);
            }
        }

        function handleEventDocRejection() {
            const docId = document.getElementById('rejectEventDocId').value;
            const reason = document.getElementById('rejectEventReason').value;
            const deadline = document.getElementById('rejectEventDeadline').value;
            
            if (!reason.trim()) {
                showModalAlert('danger', 'Please provide a rejection reason.');
                return;
            }
            submitEventDocAction('reject_event_doc', docId, reason, deadline);
        }

        // Update modal event handlers to use AJAX
        document.addEventListener('DOMContentLoaded', function() {
            // Council document approval
            const approveOrgForm = document.querySelector('#approveOrgDocModal form');
            if (approveOrgForm) {
                approveOrgForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const docId = document.getElementById('approveOrgDocId').value;
                    submitOrgDocAction('approve_org_doc', docId);
                });
            }

            // Council document rejection
            const rejectOrgForm = document.querySelector('#rejectOrgDocModal form');
            if (rejectOrgForm) {
                rejectOrgForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const docId = document.getElementById('rejectOrgDocId').value;
                    const reason = document.getElementById('rejectOrgReason').value;
                    const deadline = document.getElementById('rejectOrgDeadline').value;
                    if (!reason.trim()) {
                        showAlert('danger', 'Please provide a rejection reason.');
                        return;
                    }
                    submitOrgDocAction('reject_org_doc', docId, reason, deadline);
                });
            }

            // Cache and restore approve/reject modals to allow continuous actions (matching adviser-side behavior)
            const approveEventModal = document.getElementById('approveEventDocModal');
            const rejectEventModal = document.getElementById('rejectEventDocModal');
            const approveOrgModal = document.getElementById('approveOrgDocModal');
            const rejectOrgModal = document.getElementById('rejectOrgDocModal');
            
            // Store original content for restoration
            let approveEventBodyOriginal, approveEventFooterOriginal;
            let rejectEventBodyOriginal, rejectEventFooterOriginal;
            let approveOrgBodyOriginal, approveOrgFooterOriginal;
            let rejectOrgBodyOriginal, rejectOrgFooterOriginal;
            
            // Initialize original content when modals are first shown
            if (approveEventModal) {
                approveEventModal.addEventListener('shown.bs.modal', function() {
                    if (!approveEventBodyOriginal) {
                        approveEventBodyOriginal = this.querySelector('.modal-body').innerHTML;
                        approveEventFooterOriginal = this.querySelector('.modal-footer').innerHTML;
                    }
                });
            }
            
            if (rejectEventModal) {
                rejectEventModal.addEventListener('shown.bs.modal', function() {
                    if (!rejectEventBodyOriginal) {
                        rejectEventBodyOriginal = this.querySelector('.modal-body').innerHTML;
                        rejectEventFooterOriginal = this.querySelector('.modal-footer').innerHTML;
                    }
                });
            }
            
            if (approveOrgModal) {
                approveOrgBodyOriginal = approveOrgModal.querySelector('.modal-body').innerHTML;
                approveOrgFooterOriginal = approveOrgModal.querySelector('.modal-footer').innerHTML;
            }
            
            if (rejectOrgModal) {
                rejectOrgBodyOriginal = rejectOrgModal.querySelector('.modal-body').innerHTML;
                rejectOrgFooterOriginal = rejectOrgModal.querySelector('.modal-footer').innerHTML;
            }
            
            // Restore modal content when hidden (prevents gray screen issue)
            if (approveEventModal) {
                approveEventModal.addEventListener('hidden.bs.modal', function() {
                    if (approveEventBodyOriginal && approveEventFooterOriginal) {
                        this.querySelector('.modal-body').innerHTML = approveEventBodyOriginal;
                        this.querySelector('.modal-footer').innerHTML = approveEventFooterOriginal;
                    }
                    // Clear hidden input fields
                    const hiddenInputs = this.querySelectorAll('input[type="hidden"]');
                    hiddenInputs.forEach(input => {
                        input.value = '';
                    });
                    
                    // Clean up any lingering modal backdrops
                    setTimeout(cleanupModalBackdrops, 100);
                });
            }
            
            if (rejectEventModal) {
                rejectEventModal.addEventListener('hidden.bs.modal', function() {
                    if (rejectEventBodyOriginal && rejectEventFooterOriginal) {
                        this.querySelector('.modal-body').innerHTML = rejectEventBodyOriginal;
                        this.querySelector('.modal-footer').innerHTML = rejectEventFooterOriginal;
                    }
                    // Clear hidden input fields and textarea
                    const hiddenInputs = this.querySelectorAll('input[type="hidden"]');
                    hiddenInputs.forEach(input => {
                        input.value = '';
                    });
                    
                    // Clear rejection reason textarea
                    const textarea = this.querySelector('textarea');
                    if (textarea) {
                        textarea.value = '';
                    }
                    
                    // Clean up any lingering modal backdrops
                    setTimeout(cleanupModalBackdrops, 100);
                });
            }
            
            if (approveOrgModal) {
                approveOrgModal.addEventListener('hidden.bs.modal', function() {
                    if (approveOrgBodyOriginal && approveOrgFooterOriginal) {
                        this.querySelector('.modal-body').innerHTML = approveOrgBodyOriginal;
                        this.querySelector('.modal-footer').innerHTML = approveOrgFooterOriginal;
                    }
                    // Clear hidden input fields
                    const hiddenInputs = this.querySelectorAll('input[type="hidden"]');
                    hiddenInputs.forEach(input => {
                        input.value = '';
                    });
                    
                    // Clean up any lingering modal backdrops
                    setTimeout(cleanupModalBackdrops, 100);
                });
            }
            
            if (rejectOrgModal) {
                rejectOrgModal.addEventListener('hidden.bs.modal', function() {
                    if (rejectOrgBodyOriginal && rejectOrgFooterOriginal) {
                        this.querySelector('.modal-body').innerHTML = rejectOrgBodyOriginal;
                        this.querySelector('.modal-footer').innerHTML = rejectOrgFooterOriginal;
                    }
                    // Clear hidden input fields and textarea
                    const hiddenInputs = this.querySelectorAll('input[type="hidden"]');
                    hiddenInputs.forEach(input => {
                        input.value = '';
                    });
                    
                    // Clear rejection reason textarea
                    const textarea = this.querySelector('textarea');
                    if (textarea) {
                        textarea.value = '';
                    }
                    
                    // Clean up any lingering modal backdrops
                    setTimeout(cleanupModalBackdrops, 100);
                });
            }
        });
        
        // Detect scrollable tables and add visual indicators
        document.addEventListener('DOMContentLoaded', function() {
            const tableResponsives = document.querySelectorAll('main .table-responsive');
            tableResponsives.forEach(function(tableWrapper) {
                const table = tableWrapper.querySelector('.table');
                if (table && table.scrollWidth > tableWrapper.clientWidth) {
                    tableWrapper.classList.add('scrollable');
                    
                    // Add scroll event listener to show/hide gradient indicator
                    tableWrapper.addEventListener('scroll', function() {
                        const isAtEnd = this.scrollWidth - this.scrollLeft <= this.clientWidth + 10;
                        if (isAtEnd) {
                            this.classList.remove('scrollable');
                        } else {
                            this.classList.add('scrollable');
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
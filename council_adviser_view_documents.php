<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

$councilId = getCurrentCouncilId();

if (!$councilId) {
    echo '<div class="alert alert-danger">No council found for this adviser.</div>';
    exit;
}

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $documentId = $_POST['document_id'] ?? 0;
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        $response = ['success' => false, 'message' => ''];
        
        switch ($_POST['action']) {
            case 'approve':
                // Get document type first
                $stmt = $conn->prepare("SELECT document_type FROM council_documents WHERE id = ? AND council_id = ?");
                $stmt->bind_param("ii", $documentId, $councilId);
                $stmt->execute();
                $result = $stmt->get_result();
                $document = $result->fetch_assoc();
                
                if ($document) {
                    // Update document status to approved by adviser
                    $stmt = $conn->prepare("UPDATE council_documents SET status = 'approved', adviser_approval_at = NOW(), approved_by_adviser = ? WHERE id = ? AND council_id = ?");
                    $stmt->bind_param("iii", $_SESSION['user_id'], $documentId, $councilId);
                    
                    if ($stmt->execute()) {
                        // Create notification for president
                        require_once 'includes/notification_helper.php';
                        notifyCouncilAdviserDocumentAction($councilId, 'approved', $document['document_type'], $documentId);
                        
                        // Notify OSAS immediately when document is sent to them
                        notifyIndividualCouncilDocumentSentToOSAS($councilId, $document['document_type'], $documentId);
                        
                        // Check if all required documents are approved by adviser
                        $allApproved = $conn->query("
                            SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN adviser_approval_at IS NOT NULL THEN 1 ELSE 0 END) as approved
                            FROM council_documents 
                            WHERE council_id = $councilId AND status != 'rejected'
                        ")->fetch_assoc();
                        
                        // If all documents are approved, also send bulk notification
                        if ($allApproved['total'] > 0 && $allApproved['approved'] == $allApproved['total']) {
                            notifyCouncilDocumentsSentToOSAS($councilId);
                        }
                        
                        $response['success'] = true;
                        $response['message'] = 'Document approved successfully!';
                        $response['documentId'] = $documentId;
                    } else {
                        $response['message'] = 'Error approving document';
                    }
                } else {
                    $response['message'] = 'Document not found';
                }
                break;
                
            case 'reject':
                if (empty($rejectionReason)) {
                    $response['message'] = 'Please provide a rejection reason';
                } else {
                    // Get document type first
                    $stmt = $conn->prepare("SELECT document_type FROM council_documents WHERE id = ? AND council_id = ?");
                    $stmt->bind_param("ii", $documentId, $councilId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $document = $result->fetch_assoc();
                    
                    if ($document) {
                        // Update document status to rejected by adviser
                        $stmt = $conn->prepare("UPDATE council_documents SET status = 'rejected', adviser_rejected_at = NOW(), approved_by_adviser = NULL, reject_reason = ? WHERE id = ? AND council_id = ?");
                        $stmt->bind_param("sii", $rejectionReason, $documentId, $councilId);
                        
                        if ($stmt->execute()) {
                            // Create notification for president
                            require_once 'includes/notification_helper.php';
                            notifyCouncilAdviserDocumentAction($councilId, 'rejected', $document['document_type'], $documentId);
                            
                            $response['success'] = true;
                            $response['message'] = 'Document rejected successfully!';
                            $response['documentId'] = $documentId;
                            $response['rejectionReason'] = $rejectionReason;
                        } else {
                            $response['message'] = 'Error rejecting document';
                        }
                    } else {
                        $response['message'] = 'Document not found';
                    }
                }
                break;
        }
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            // Clear any previous output
            if (ob_get_level()) {
                ob_clean();
            }
            header('Content-Type: application/json');
            $json = json_encode($response);
            if ($json === false) {
                echo json_encode(['success' => false, 'message' => 'JSON encoding error']);
            } else {
                echo $json;
            }
            exit;
        } else {
            // Handle regular form submission
            if ($response['success']) {
                $_SESSION['message'] = $response['message'];
            } else {
                $_SESSION['error'] = $response['message'];
            }
            header('Location: council_adviser_view_documents.php');
            exit;
        }
    }
}

// Get filter parameters
$documentType = $_GET['document_type'] ?? '';
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Get notification context for highlighting
$notificationId = $_GET['notification_id'] ?? '';
$notificationType = $_GET['notification_type'] ?? '';

// Get council details
$stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
$stmt->bind_param("i", $councilId);
$stmt->execute();
$council = $stmt->get_result()->fetch_assoc();

// Build documents query - show only documents linked to ACTIVE academic terms
// Hide adviser-rejected documents (sent back to student) but show OSAS-rejected documents
$query = "
    SELECT d.*, u.username as submitted_by_name, u.email as submitted_by_email,
           d.reject_reason, d.adviser_approval_at, d.adviser_rejected_at, d.osas_approved_at, d.osas_rejected_at,
           d.resubmission_deadline, d.deadline_set_by, d.deadline_set_at
    FROM council_documents d
    LEFT JOIN users u ON d.submitted_by = u.id
    LEFT JOIN council c ON d.council_id = c.id
    LEFT JOIN academic_terms at_doc ON d.academic_year_id = at_doc.id
    LEFT JOIN academic_terms at_council ON c.academic_year_id = at_council.id
    WHERE d.council_id = ?
      AND COALESCE(at_doc.status, at_council.status) = 'active'
      AND (d.status != 'rejected' OR d.osas_rejected_at IS NOT NULL)
";

$params = [$councilId];
$types = "i";

if ($documentType) {
    $query .= " AND d.document_type = ?";
    $params[] = $documentType;
    $types .= "s";
}

// Don't apply status filter - show all documents but highlight the relevant ones
// The highlighting logic will handle showing which documents match the notification

if ($dateFrom) {
    $query .= " AND DATE(d.submitted_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo) {
    $query .= " AND DATE(d.submitted_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$query .= " ORDER BY d.submitted_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Document type labels
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
    'budget_resolution' => 'Budget Resolution',
    'activity_permit' => 'Activity Permit',
    'accomplishment_report' => 'Accomplishment Report',
    'previous_plan_of_activities' => 'Previous Plan of Activities',
    'financial_report' => 'Financial Report'
];

// Get document statistics
$totalDocuments = count($documents);
$pendingCount = count(array_filter($documents, fn($d) => $d['status'] === 'pending'));
$approvedCount = count(array_filter($documents, fn($d) => $d['status'] === 'approved' && $d['adviser_approval_at'] && empty($d['osas_approved_at'])));
$rejectedCount = count(array_filter($documents, fn($d) => $d['status'] === 'rejected' && !empty($d['osas_rejected_at'])));
// Calculate fully approved count
$fullyApprovedCount = count(array_filter($documents, fn($d) => $d['status'] === 'approved' && !empty($d['osas_approved_at'])));

// Get messages from session
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Progress - Organization Adviser</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        /* Main styling */
        main {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: calc(100vh - 56px);
        }
        
        /* Page header styling */
        .page-header h2 {
            color: #343a40;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }
        
        /* Enhanced card styling */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Statistics cards */
        .stats-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            background: #fff;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-bottom: 1.5rem;
            border-left: 12px solid #e5e7eb; /* default gray, will be overridden */
        }
        .stats-card:hover {
            transform: translateY(-4px) scale(1.03);
            box-shadow: 0 8px 32px rgba(0,0,0,0.13);
        }
        .stats-card.total    { border-left-color: #2563eb; }
        .stats-card.pending  { border-left-color: #fbbf24; }
        .stats-card.sent     { border-left-color: #0ea5e9; }
        .stats-card.approved { border-left-color: #22c55e; }
        .stats-card.rejected { border-left-color: #ef4444; }
        .stats-card .display-4 { font-size: 3rem; margin-bottom: 0.5rem; }
        .stats-card h3 { font-size: 2.2rem; font-weight: 700; margin-bottom: 0.2rem; }
        .stats-card p { font-size: 1.1rem; font-weight: 500; margin-bottom: 0; }
        
        /* Filter section styling */
        .filter-section {
            border-left: 5px solid #6c757d;
        }
        
        .filter-section .card-header {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }
        
        /* Document cards */
        .document-card {
            border-left: 5px solid #495057;
            margin-bottom: 1rem;
        }
        
        .document-card.pending {
            border-left-color: #ffc107;
        }
        
        .document-card.approved {
            border-left-color: #0056b3;
        }
        
        .document-card.rejected {
            border-left-color: #dc3545;
        }
        
        /* Notification highlighting */
        .notification-highlight {
            border: 3px solid #2196f3 !important;
            box-shadow: 0 0 20px rgba(33, 150, 243, 0.3) !important;
            animation: notificationPulse 2s ease-in-out;
        }
        
        @keyframes notificationPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        /* Status badges */
        .status-badge {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
        }
        
        .status-pending {
            background: linear-gradient(45deg, #ffc107, #ffcd39);
            color: #000;
        }
        
        .status-approved {
            background: #0056b3;
            color: white;
        }
        
        .status-rejected {
            background: linear-gradient(45deg, #dc3545, #e55563);
            color: white;
        }
        
        /* Form styling */
        .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control,
        .form-select {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        /* Button styling */
        .btn-primary {
            background: linear-gradient(45deg, #495057, #6c757d);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #343a40, #495057);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3);
        }
        
        /* View File button - solid green */
        .btn-outline-primary {
            background: #157347;
            border: 2px solid #157347;
            color: white;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: #0f5132;
            border-color: #0f5132;
            color: white;
            transform: translateY(-1px);
        }
        
        /* View File button specific styling */
        .btn-view-file {
            background: #157347 !important;
            border: 2px solid #157347 !important;
            color: white !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-view-file:hover {
            background: #0f5132 !important;
            border-color: #0f5132 !important;
            color: white !important;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #0056b3;
            border: none;
            font-weight: 600;
        }
        
        .btn-success:hover {
            background: #004085;
            transform: scale(1.05);
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #e55563);
            border: none;
            font-weight: 600;
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #c02a37, #d63447);
            transform: scale(1.05);
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            font-weight: 600;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5a6268, #5a6268);
        }
        
        /* Modal styling */
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border: none;
        }
        
        .modal-footer {
            border: none;
        }
        
        /* File link styling */
        .file-link {
            color: #495057;
            text-decoration: none;
            font-weight: 500;
        }
        
        .file-link:hover {
            color: #343a40;
            text-decoration: underline;
        }
        
        /* Alert styling */
        .alert {
            border: none;
            border-radius: 10px;
            font-weight: 500;
        }
        
        .alert-success {
            background: linear-gradient(45deg, #d1e7dd, #badbcc);
            color: #0f5132;
        }
        
        .alert-danger {
            background: linear-gradient(45deg, #f8d7da, #f1aeb5);
            color: #721c24;
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

<?php include 'includes/navbar.php'; ?>

<main>
    <div class="container-fluid py-2">
        <!-- Page Header -->
        <div class="page-header">
            <h2 class="page-title"><i class="bi bi-file-earmark-check me-3"></i>Document Progress</h2>
            <p class="page-subtitle">View document submissions and their status for <strong><?php echo htmlspecialchars($council['council_name']); ?></strong></p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        


        <!-- Statistics Cards -->
        <div class="row flex-nowrap g-3 mb-4" style="overflow-x:auto;">
    <div class="col">
        <div class="card stats-card total h-100">
            <div class="card-body text-center">
                <i class="bi bi-files display-4" style="color:#2563eb"></i>
                <h3><?php echo $totalDocuments; ?></h3>
                <p>Total Documents</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stats-card pending h-100">
            <div class="card-body text-center">
                <i class="bi bi-clock display-4" style="color:#fbbf24"></i>
                <h3><?php echo $pendingCount; ?></h3>
                <p>Pending Review</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stats-card sent h-100">
            <div class="card-body text-center">
                <i class="bi bi-check2-circle display-4" style="color:#0ea5e9"></i>
                <h3><?php echo $approvedCount; ?></h3>
                <p>Sent to OSAS</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stats-card approved h-100">
            <div class="card-body text-center">
                <i class="bi bi-patch-check display-4" style="color:#22c55e"></i>
                <h3><?php echo $fullyApprovedCount; ?></h3>
                <p>Approved</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card stats-card rejected h-100">
            <div class="card-body text-center">
                <i class="bi bi-x-circle display-4" style="color:#ef4444"></i>
                <h3><?php echo $rejectedCount; ?></h3>
                <p>Rejected</p>
            </div>
        </div>
    </div>
</div>

        <!-- Filter Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card filter-section">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-funnel me-2"></i>Filter Documents
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label for="document_type" class="form-label">Document Type</label>
                                    <select class="form-select" id="document_type" name="document_type">
                                        <option value="">All Types</option>
                                        <?php foreach ($documentTypes as $key => $label): ?>
                                            <option value="<?php echo $key; ?>" <?php echo $documentType === $key ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                        <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent to OSAS</option>
                                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="date_from" class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="date_to" class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-2"></i>Apply Filters
                                </button>
                                <a href="council_adviser_view_documents.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documents List -->
        <div class="row">
            <div class="col-12">
                <?php if (empty($documents)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-file-earmark display-1 text-muted mb-3"></i>
                            <h4 class="text-muted">No Documents Found</h4>
                            <p class="text-muted">No documents match the current filters or no documents have been submitted yet.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($documents as $document): ?>
                        <?php 
                        // Check if this document should be highlighted based on notification
                        $isHighlighted = false;
                        if ($notificationId && $notificationType) {
                            // For document_submitted notifications, highlight the specific document that was submitted
                            if ($notificationType === 'document_submitted' && $notificationId == $document['id']) {
                                $isHighlighted = true;
                            }
                            // For document_approved notifications, highlight the specific document that was approved
                            elseif ($notificationType === 'document_approved' && $notificationId == $document['id']) {
                                $isHighlighted = true;
                            }
                            // For document_rejected notifications, highlight the specific document that was rejected
                            elseif ($notificationType === 'document_rejected' && $notificationId == $document['id']) {
                                $isHighlighted = true;
                            }
                        }
                        ?>
                        <div class="card document-card <?php echo $document['status']; ?> <?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                             id="document-<?php echo $document['id']; ?>">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-3">
                                        <h5 class="mb-2">
                                            <i class="bi bi-file-earmark-text me-2"></i>
                                            <?php echo htmlspecialchars($documentTypes[$document['document_type']] ?? 'Unknown Type'); ?>
                                        </h5>
                                        <span class="status-badge status-<?php echo $document['status']; ?>">
                                            <?php 
                                            // For adviser view, show appropriate status based on approval stages
                                            if ($document['status'] === 'approved' && $document['adviser_approval_at'] && !empty($document['osas_approved_at'])) {
                                                echo 'Approved';
                                            } elseif ($document['status'] === 'approved' && $document['adviser_approval_at']) {
                                                echo 'Sent to OSAS';
                                            } elseif ($document['status'] === 'rejected' && !empty($document['osas_rejected_at'])) {
                                                echo 'Rejected by OSAS';
                                            } else {
                                                echo ucfirst($document['status']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1"><strong>Submitted by:</strong></p>
                                        <p class="mb-0 text-muted"><?php echo htmlspecialchars($document['submitted_by_name'] ?? 'Unknown'); ?></p>
                                        <small class="text-muted"><?php echo htmlspecialchars($document['submitted_by_email'] ?? ''); ?></small>
                                    </div>
                                    <div class="col-md-2">
                                        <p class="mb-1"><strong>Submitted:</strong></p>
                                        <p class="mb-0 text-muted"><?php echo date('M d, Y', strtotime($document['submitted_at'])); ?></p>
                                        <small class="text-muted"><?php echo date('g:i A', strtotime($document['submitted_at'])); ?></small>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="button" 
                                                class="btn btn-sm btn-view-file" 
                                                onclick="viewFileModal('<?php echo addslashes($document['file_path']); ?>', '<?php echo addslashes($documentTypes[$document['document_type']] ?? 'Document'); ?>')">
                                            <i class="bi bi-eye me-1"></i>View File
                                        </button>
                                    </div>
                                    <div class="col-md-2">
                                        <?php if ($document['status'] === 'pending'): ?>
                                            <div class="btn-group" role="group">
                                                <button type="button" 
                                                        class="btn btn-sm btn-success" 
                                                        onclick="approveDocument(<?php echo $document['id']; ?>)">
                                                    <i class="bi bi-check-circle me-1"></i>Approve
                                                </button>
                                                <button type="button" 
                                                        class="btn btn-sm btn-danger" 
                                                        onclick="rejectDocument(<?php echo $document['id']; ?>)">
                                                    <i class="bi bi-x-circle me-1"></i>Reject
                                                </button>
                                            </div>
                                        <?php elseif ($document['status'] === 'approved' && $document['adviser_approval_at'] && !empty($document['osas_approved_at'])): ?>
                                            <span class="badge bg-success">Approved</span>
                                            <small class="d-block text-muted">OSAS approved on <?php echo date('M d, Y', strtotime($document['osas_approved_at'])); ?></small>
                                        <?php elseif ($document['status'] === 'approved' && $document['adviser_approval_at']): ?>
                                            <span class="badge bg-success">Sent to OSAS</span>
                                            <small class="d-block text-muted">Approved on <?php echo date('M d, Y', strtotime($document['adviser_approval_at'])); ?></small>
                                        <?php elseif ($document['status'] === 'rejected' && $document['adviser_rejected_at']): ?>
                                            <span class="badge bg-danger">Rejected by Adviser</span>
                                            <small class="d-block text-muted"><?php echo date('M d, Y', strtotime($document['adviser_rejected_at'])); ?></small>
                                            <?php if ($document['reject_reason']): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger mt-1" 
                                                        onclick="showRejectionReason('<?php echo htmlspecialchars($document['reject_reason']); ?>')">
                                                    <i class="bi bi-info-circle me-1"></i>View Reason
                                                </button>
                                            <?php endif; ?>
                                        <?php elseif ($document['status'] === 'rejected' && !empty($document['osas_rejected_at'])): ?>
                                            <span class="badge bg-danger">Rejected by OSAS</span>
                                            <small class="d-block text-muted"><?php echo date('M d, Y', strtotime($document['osas_rejected_at'])); ?></small>
                                            <?php if ($document['reject_reason']): ?>
                                                <button type="button" 
                                                        class="btn btn-sm btn-outline-danger mt-1" 
                                                        onclick="showRejectionReason('<?php echo htmlspecialchars($document['reject_reason']); ?>')">
                                                    <i class="bi bi-info-circle me-1"></i>View Reason
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($document['resubmission_deadline'])): ?>
                                                <small class="d-block text-warning mt-1">
                                                    <i class="bi bi-clock me-1"></i>Deadline: <?php echo date('M d, Y g:i A', strtotime($document['resubmission_deadline'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<!-- Rejection Reason Modal -->
<!-- File Viewer Modal -->
<div class="modal fade" id="fileViewerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="fileViewerTitle">View Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="fileViewerBody" style="display:flex; align-items:center; justify-content:center; background:#f8f9fa;">
                <!-- Content will be injected dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="rejectionReasonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rejection Reason</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="rejectionReasonText"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Approval Confirmation Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Approval</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to approve this document? This action will send the document to OSAS for final approval.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="document_id" id="approveDocumentId">
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Rejection Modal -->
<div class="modal fade" id="rejectionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="rejectionForm">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="document_id" id="rejectDocumentId">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" required placeholder="Please provide a reason for rejecting this document..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="rejectionForm" class="btn btn-danger">Confirm Rejection</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/pdf-viewer.js"></script>
<script>
    // Function to show rejection reason
    function showRejectionReason(reason) {
        document.getElementById('rejectionReasonText').textContent = reason;
        new bootstrap.Modal(document.getElementById('rejectionReasonModal')).show();
    }
    
    // Function to approve document
    function approveDocument(documentId) {
        document.getElementById('approveDocumentId').value = documentId;
        new bootstrap.Modal(document.getElementById('approvalModal')).show();
    }
    
    // Function to reject document
    function rejectDocument(documentId) {
        document.getElementById('rejectDocumentId').value = documentId;
        document.getElementById('rejection_reason').value = '';
        new bootstrap.Modal(document.getElementById('rejectionModal')).show();
    }

    // Handle form submissions with loading effects
    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (!form) return;
        
        const isApprovalForm = form.querySelector('input[name="action"][value="approve"]') !== null;
        const isRejectionForm = form.querySelector('input[name="action"][value="reject"]') !== null;
        
        if (!isApprovalForm && !isRejectionForm) return;
        
        e.preventDefault();
        
        // Client-side validation for rejection
        if (isRejectionForm) {
            const rejectionReason = form.querySelector('textarea[name="rejection_reason"]');
            if (!rejectionReason || !rejectionReason.value.trim()) {
                // Show validation error in modal
                const modal = document.getElementById('rejectionModal');
                const body = modal.querySelector('.modal-body');
                const existingAlert = body.querySelector('.alert-danger');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                alertDiv.style.background = '#f8d7da';
                alertDiv.style.border = '1px solid #f5c6cb';
                alertDiv.style.color = '#721c24';
                alertDiv.innerHTML = `
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Rejection reason required.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                body.insertBefore(alertDiv, body.firstChild);
                
                // Focus on the textarea
                rejectionReason.focus();
                return;
            }
        }
        
        const formData = new FormData(form);
        const submitButton = form.querySelector('button[type="submit"]');
        const originalText = submitButton.innerHTML;
        
        // Disable submit button and show loading
        submitButton.disabled = true;
        submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (isApprovalForm ? 'Approving...' : 'Rejecting...');
        
        fetch('council_adviser_view_documents.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                // Show success message
                const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
                const body = modal.querySelector('.modal-body');
                const footer = modal.querySelector('.modal-footer');
                
                body.innerHTML = '<div class="text-center"><i class="bi bi-check-circle text-success display-4"></i><h5 class="mt-3">Document ' + (isApprovalForm ? 'Approved' : 'Rejected') + ' Successfully!</h5><p class="text-muted">Status updated.</p></div>';
                footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                
                // Reset button
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
                
                // Auto-hide modal after 1.5 seconds
                setTimeout(() => {
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                    // Reset modal content for next use
                    resetModal(modal, isApprovalForm);
                }, 1500);
                
                // Update the document status in the UI without page reload
                updateDocumentStatus(data.documentId, isApprovalForm ? 'approved' : 'rejected', data.rejectionReason);
                
                // Update statistics
                updateStatistics();
            } else {
                // Show error message
                const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
                const body = modal.querySelector('.modal-body');
                
                body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error: ' + data.message + '</div>';
                
                // Reset button
                submitButton.disabled = false;
                submitButton.innerHTML = originalText;
            }
        })
        .catch(error => {
            // Show error message
            const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
            const body = modal.querySelector('.modal-body');
            
            body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error: ' + error.message + '</div>';
            
            // Reset button
            submitButton.disabled = false;
            submitButton.innerHTML = originalText;
        });
    });

    // Handle button clicks for forms with external submit buttons
    document.addEventListener('click', function(e) {
        if (e.target && e.target.type === 'submit' && e.target.form) {
            const form = e.target.form;
            const isApprovalForm = form.querySelector('input[name="action"][value="approve"]') !== null;
            const isRejectionForm = form.querySelector('input[name="action"][value="reject"]') !== null;
            
            if (isApprovalForm || isRejectionForm) {
                e.preventDefault();
                
                // Client-side validation for rejection
                if (isRejectionForm) {
                    const rejectionReason = form.querySelector('textarea[name="rejection_reason"]');
                    if (!rejectionReason || !rejectionReason.value.trim()) {
                        // Show validation error in modal
                        const modal = document.getElementById('rejectionModal');
                        const body = modal.querySelector('.modal-body');
                        const existingAlert = body.querySelector('.alert-danger');
                        if (existingAlert) {
                            existingAlert.remove();
                        }
                        
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-danger alert-dismissible fade show';
                        alertDiv.style.background = '#f8d7da';
                        alertDiv.style.border = '1px solid #f5c6cb';
                        alertDiv.style.color = '#721c24';
                        alertDiv.innerHTML = `
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            Rejection reason required.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        body.insertBefore(alertDiv, body.firstChild);
                        
                        // Focus on the textarea
                        rejectionReason.focus();
                        return;
                    }
                }
                
                const formData = new FormData(form);
                const submitButton = e.target;
                const originalText = submitButton.innerHTML;
                
                // Disable submit button and show loading
                submitButton.disabled = true;
                submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (isApprovalForm ? 'Approving...' : 'Rejecting...');
                
                fetch('council_adviser_view_documents.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
                    if (data.success) {
                        // Show success message
                        const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
                        const body = modal.querySelector('.modal-body');
                        const footer = modal.querySelector('.modal-footer');
                        
                        body.innerHTML = '<div class="text-center"><i class="bi bi-check-circle text-success display-4"></i><h5 class="mt-3">Document ' + (isApprovalForm ? 'Approved' : 'Rejected') + ' Successfully!</h5><p class="text-muted">Status updated.</p></div>';
                        footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                        
                        // Reset button
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                        
                        // Auto-hide modal after 1.5 seconds
                        setTimeout(() => {
                            const modalInstance = bootstrap.Modal.getInstance(modal);
                            if (modalInstance) {
                                modalInstance.hide();
                            }
                            // Reset modal content for next use
                            resetModal(modal, isApprovalForm);
                        }, 1500);
                        
                        // Update the document status in the UI without page reload
                        updateDocumentStatus(data.documentId, isApprovalForm ? 'approved' : 'rejected', data.rejectionReason);
                        
                        // Update statistics
                        updateStatistics();
                    } else {
                        // Show error message
                        const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
                        const body = modal.querySelector('.modal-body');
                        
                        body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error: ' + data.message + '</div>';
                        
                        // Reset button
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    }
                })
                .catch(error => {
                    // Show error message
                    const modal = isApprovalForm ? document.getElementById('approvalModal') : document.getElementById('rejectionModal');
                    const body = modal.querySelector('.modal-body');
                    
                    body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error: ' + error.message + '</div>';
                    
                    // Reset button
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                });
            }
        }
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
    
    // Function to update document status in the UI
    function updateDocumentStatus(documentId, newStatus, rejectionReason = null) {
        const documentCard = document.getElementById('document-' + documentId);
        if (!documentCard) return;
        
        // Update the card class
        documentCard.className = documentCard.className.replace(/document-card \w+/, 'document-card ' + newStatus);
        
        // Update status badge
        const statusBadge = documentCard.querySelector('.status-badge');
        if (statusBadge) {
            statusBadge.className = 'status-badge status-' + newStatus;
            if (newStatus === 'approved') {
                statusBadge.textContent = 'Sent to OSAS';
            } else if (newStatus === 'rejected') {
                statusBadge.textContent = 'Rejected by Adviser';
            }
        }
        
        // Update action buttons area
        const actionArea = documentCard.querySelector('.col-md-2:last-child');
        if (actionArea) {
            if (newStatus === 'approved') {
                actionArea.innerHTML = `
                    <span class="badge bg-success">Sent to OSAS</span>
                    <small class="d-block text-muted">Approved on ${new Date().toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</small>
                `;
            } else if (newStatus === 'rejected') {
                // For rejected documents, hide the card with animation since they should be sent back to student
                documentCard.style.transition = 'all 0.5s ease';
                documentCard.style.opacity = '0';
                documentCard.style.transform = 'translateX(-100%)';
                
                setTimeout(() => {
                    documentCard.remove();
                    // Update statistics after removing the document
                    updateStatistics();
                }, 500);
                
                return; // Exit early since we're removing the card
            }
        }
        
        // Add a subtle animation to show the change
        documentCard.style.transition = 'all 0.3s ease';
        documentCard.style.transform = 'scale(1.02)';
        setTimeout(() => {
            documentCard.style.transform = 'scale(1)';
        }, 300);
    }
    
    // Function to update statistics
    function updateStatistics() {
        // Count documents by status
        const documents = document.querySelectorAll('.document-card');
        let totalCount = documents.length;
        let pendingCount = 0;
        let sentCount = 0;
        let approvedCount = 0;
        let rejectedCount = 0;
        
        documents.forEach(doc => {
            const statusBadge = doc.querySelector('.status-badge');
            if (statusBadge) {
                const statusText = statusBadge.textContent.toLowerCase();
                if (statusText.includes('pending')) {
                    pendingCount++;
                } else if (statusText.includes('sent to osas')) {
                    sentCount++;
                } else if (statusText.includes('approved')) {
                    approvedCount++;
                } else if (statusText.includes('rejected')) {
                    rejectedCount++;
                }
            }
        });
        
        // Update statistics cards with smooth transitions
        const statsCards = document.querySelectorAll('.stats-card');
        const statsData = [totalCount, pendingCount, sentCount, approvedCount, rejectedCount];
        
        statsCards.forEach((card, index) => {
            const numberElement = card.querySelector('h3');
            if (numberElement && statsData[index] !== undefined) {
                // Animate number change
                const currentValue = parseInt(numberElement.textContent) || 0;
                const targetValue = statsData[index];
                
                if (currentValue !== targetValue) {
                    // Add animation to updated stats
                    card.style.transition = 'transform 0.3s ease';
                    card.style.transform = 'scale(1.05)';
                    
                    // Animate the number change
                    animateNumber(numberElement, currentValue, targetValue, 300);
                    
                    setTimeout(() => {
                        card.style.transform = 'scale(1)';
                    }, 300);
                }
            }
        });
    }
    
    // Function to animate number changes
    function animateNumber(element, start, end, duration) {
        const startTime = performance.now();
        const difference = end - start;
        
        function updateNumber(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Use easing function for smooth animation
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const currentValue = Math.round(start + (difference * easeOut));
            
            element.textContent = currentValue;
            
            if (progress < 1) {
                requestAnimationFrame(updateNumber);
            }
        }
        
        requestAnimationFrame(updateNumber);
    }
    
    // Function to reset modal content for next use
    function resetModal(modal, isApprovalForm) {
        const body = modal.querySelector('.modal-body');
        const footer = modal.querySelector('.modal-footer');
        
        if (isApprovalForm) {
            // Reset approval modal
            body.innerHTML = '<p>Are you sure you want to approve this document? This action will send the document to OSAS for final approval.</p>';
            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="document_id" id="approveDocumentId">
                    <button type="submit" class="btn btn-success">Confirm Approval</button>
                </form>
            `;
        } else {
            // Reset rejection modal
            body.innerHTML = `
                <form method="POST" id="rejectionForm">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="document_id" id="rejectDocumentId">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" required placeholder="Please provide a reason for rejecting this document..."></textarea>
                    </div>
                </form>
            `;
            footer.innerHTML = `
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="rejectionForm" class="btn btn-danger">Confirm Rejection</button>
            `;
        }
    }
    
    // Notification highlighting functionality
    document.addEventListener('DOMContentLoaded', function() {
        // Check if there's a highlighted document from notification
        const highlightedDoc = document.querySelector('.notification-highlight');
        if (highlightedDoc) {
            // Scroll to the highlighted document
            highlightedDoc.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
            });
            
            // Add a subtle flash effect
            setTimeout(() => {
                highlightedDoc.style.animation = 'notificationPulse 1s ease-in-out';
            }, 500);
            
            // Remove highlight after 5 seconds
            setTimeout(() => {
                highlightedDoc.classList.remove('notification-highlight');
            }, 5000);
        }
    });
</script>

</body>
</html> 
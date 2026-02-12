<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

$councilId = getCurrentCouncilId();

if (!$councilId) {
    echo '<div class="alert danger">No council found for this adviser.</div>';
    exit;
}

// Get council details
$stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
$stmt->bind_param("i", $councilId);
$stmt->execute();
$council = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $rejectionReason = $_POST['rejection_reason'] ?? '';
        switch ($_POST['action']) {
            case 'approve':
                // Get document type and council info first
                $stmt = $conn->prepare("
                    SELECT ed.document_type, ed.event_approval_id, ea.council_id, ea.title 
                    FROM event_documents ed 
                    JOIN event_approvals ea ON ed.event_approval_id = ea.id 
                    WHERE ed.id = ?
                ");
                $stmt->bind_param("i", $documentId);
                $stmt->execute();
                $result = $stmt->get_result();
                $document = $result->fetch_assoc();
                
                if ($document) {
                    // Update document status to sent to OSAS by adviser
                    $stmt = $conn->prepare("UPDATE event_documents SET status = 'sent_to_osas', adviser_approved_at = NOW(), approved_by_adviser = ? WHERE id = ?");
                    $stmt->bind_param("ii", $_SESSION['user_id'], $documentId);
                    
                    if ($stmt->execute()) {
                        // Create notification for president
                        require_once 'includes/notification_helper.php';
                        notifyCouncilAdviserEventAction($document['council_id'], 'approved', $document['document_type'], $documentId);
                        
                        // Check if all event documents are approved by adviser
                        $stmt = $conn->prepare("
                            SELECT COUNT(*) as total, 
                                   SUM(CASE WHEN adviser_approved_at IS NOT NULL THEN 1 ELSE 0 END) as approved
                            FROM event_documents 
                            WHERE event_approval_id = ? AND status != 'rejected'
                        ");
                        $stmt->bind_param("i", $document['event_approval_id']);
                        $stmt->execute();
                        $allApproved = $stmt->get_result()->fetch_assoc();
                        
                        // If all event documents are approved, notify OSAS
                        if ($allApproved['total'] > 0 && $allApproved['approved'] == $allApproved['total']) {
                            // Get event details for notification
                            $stmt = $conn->prepare("
                                SELECT title, id as event_approval_id
                                FROM event_approvals 
                                WHERE id = ?
                            ");
                            $stmt->bind_param("i", $document['event_approval_id']);
                            $stmt->execute();
                            $eventDetails = $stmt->get_result()->fetch_assoc();
                            
                            if ($eventDetails) {
                                notifyCouncilEventDocumentsSentToOSAS($document['council_id'], $eventDetails['title'], $eventDetails['event_approval_id']);
                            }
                        }
                        
                        // Check if this is an AJAX request
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            echo 'Event document approved successfully! Document has been sent to OSAS for final approval.';
                            exit;
                        } else {
                            $_SESSION['message'] = 'Event document approved successfully! Document has been sent to OSAS for final approval.';
                        }
                    } else {
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            echo 'Error approving document';
                            exit;
                        } else {
                            $_SESSION['error'] = 'Error approving document';
                        }
                    }
                } else {
                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                        echo 'Document not found';
                        exit;
                    } else {
                        $_SESSION['error'] = 'Document not found';
                    }
                }
                break;
            case 'reject':
                if (empty($rejectionReason)) {
                    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                        echo 'Please provide a rejection reason';
                        exit;
                    } else {
                        $_SESSION['error'] = 'Please provide a rejection reason';
                    }
                } else {
                    // Get document type and council info first
                    $stmt = $conn->prepare("
                        SELECT ed.document_type, ed.event_approval_id, ea.council_id, ea.title 
                        FROM event_documents ed 
                        JOIN event_approvals ea ON ed.event_approval_id = ea.id 
                        WHERE ed.id = ?
                    ");
                    $stmt->bind_param("i", $documentId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $document = $result->fetch_assoc();
                    
                    if ($document) {
                        // Update document status to rejected by adviser
                        $stmt = $conn->prepare("UPDATE event_documents SET status = 'rejected', adviser_rejected_at = NOW(), approved_by_adviser = ?, rejection_reason = ? WHERE id = ?");
                        $stmt->bind_param("isi", $_SESSION['user_id'], $rejectionReason, $documentId);
                        
                        if ($stmt->execute()) {
                            // Create notification for president
                            require_once 'includes/notification_helper.php';
                            notifyCouncilAdviserEventAction($document['council_id'], 'rejected', $document['document_type'], $documentId);
                            
                            // Check if there are any approved documents for this event
                            $stmt2 = $conn->prepare("
                                SELECT 
                                    COUNT(*) as total_docs,
                                    COUNT(CASE WHEN status = 'sent_to_osas' AND adviser_approved_at IS NOT NULL THEN 1 END) as approved_docs,
                                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_docs
                                FROM event_documents 
                                WHERE event_approval_id = (
                                    SELECT event_approval_id FROM event_documents WHERE id = ?
                                )
                            ");
                            $stmt2->bind_param("i", $documentId);
                            $stmt2->execute();
                            $result = $stmt2->get_result()->fetch_assoc();
                            
                            if ($result['rejected_docs'] == $result['total_docs'] && $result['total_docs'] > 0) {
                                // All documents are rejected, send back to president
                                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                    echo 'Event document rejected successfully! All documents are now rejected and will be sent back to president for resubmission.';
                                    exit;
                                } else {
                                    $_SESSION['message'] = 'Event document rejected successfully! All documents are now rejected and will be sent back to president for resubmission.';
                                }
                            } elseif ($result['approved_docs'] > 0) {
                                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                    echo 'Event document rejected successfully! Other approved documents remain in transfer status until all documents are approved.';
                                    exit;
                                } else {
                                    $_SESSION['message'] = 'Event document rejected successfully! Other approved documents remain in transfer status until all documents are approved.';
                                }
                            } else {
                                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                    echo 'Event document rejected successfully!';
                                    exit;
                                } else {
                                    $_SESSION['message'] = 'Event document rejected successfully!';
                                }
                            }
                        } else {
                            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                                echo 'Error rejecting document';
                                exit;
                            } else {
                                $_SESSION['error'] = 'Error rejecting document';
                            }
                        }
                    } else {
                        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
                            echo 'Document not found';
                            exit;
                        } else {
                            $_SESSION['error'] = 'Document not found';
                        }
                    }
                }
                break;
        }
        
        header('Location: council_adviser_view_event_files.php');
        exit;
    }
}

// Filters
$status = $_GET['status'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Notification context (highlighting)
$notificationId = $_GET['notification_id'] ?? '';
$notificationType = $_GET['notification_type'] ?? '';

// Get event proposals with their documents
$query = "
    SELECT ea.id as approval_id, ea.title as event_title, ea.venue as event_venue, ea.created_at as proposal_date,
           COUNT(d.id) as total_docs,
           COUNT(CASE WHEN d.adviser_approved_at IS NULL AND d.adviser_rejected_at IS NULL AND d.osas_approved_at IS NULL AND d.osas_rejected_at IS NULL THEN 1 END) as pending_docs,
           COUNT(CASE WHEN d.adviser_approved_at IS NOT NULL AND d.osas_approved_at IS NULL AND d.osas_rejected_at IS NULL THEN 1 END) as sent_docs,
           COUNT(CASE WHEN d.osas_approved_at IS NOT NULL THEN 1 END) as approved_docs,
           COUNT(CASE WHEN d.osas_rejected_at IS NOT NULL THEN 1 END) as rejected_docs
    FROM event_approvals ea
    LEFT JOIN event_documents d ON ea.id = d.event_approval_id
    WHERE ea.council_id = ? AND ea.council_id IS NOT NULL
";

$params = [$councilId];
$types = "i";

// Add date filters
if ($dateFrom) {
    $query .= " AND DATE(ea.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= "s";
}

if ($dateTo) {
    $query .= " AND DATE(ea.created_at) <= ?";
    $params[] = $dateTo;
    $types .= "s";
}

$query .= " GROUP BY ea.id, ea.title, ea.venue, ea.created_at 
           HAVING total_docs > 0 
           ORDER BY ea.created_at DESC";

// Don't apply status filter - show all events but highlight the relevant ones
// The highlighting logic will handle showing which events match the notification

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$eventProposals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get event statistics (counting events, not documents)
$totalProposals = count($eventProposals);

// Count events by their overall status
$pendingEvents = 0;
$sentToOsasEvents = 0;
$approvedByOsasEvents = 0;
$rejectedByOsasEvents = 0;

require_once 'includes/status_helper.php';
foreach ($eventProposals as $proposal) {
    // Determine overall status for each event using standardized logic
    $overallStatus = getAdviserEventStatus(
        $proposal['total_docs'],
        $proposal['approved_docs'],
        $proposal['rejected_docs'],
        $proposal['sent_docs'],
        $proposal['pending_docs']
    );
    
    // Count events based on their overall status
    switch ($overallStatus) {
        case 'pending':
            $pendingEvents++;
            break;
        case 'sent_to_osas':
            $sentToOsasEvents++;
            break;
        case 'approved':
            $approvedByOsasEvents++;
            break;
        case 'rejected':
            $rejectedByOsasEvents++;
            break;
    }
}

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Files - Council Adviser</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        main { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); min-height: calc(100vh - 56px); }
        .page-header h2 { color: #343a40; font-weight: 700; margin-bottom: 0.5rem; }
        .page-header p { color: #6c757d; font-size: 1.1rem; margin-bottom: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); transition: all 0.3s ease; overflow: hidden; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .stats-card { border: none; border-radius: 18px; box-shadow: 0 4px 24px rgba(0,0,0,0.07); background: #fff; transition: transform 0.2s, box-shadow 0.2s; margin-bottom: 1.5rem; border-left: 12px solid #e5e7eb; }
        .stats-card:hover { transform: translateY(-4px) scale(1.03); box-shadow: 0 8px 32px rgba(0,0,0,0.13); }
        .stats-card.total{ border-left-color:#2563eb;} .stats-card.pending{ border-left-color:#fbbf24;} .stats-card.sent{ border-left-color:#0ea5e9;} .stats-card.approved{ border-left-color:#22c55e;} .stats-card.rejected{ border-left-color:#ef4444;}
        
        .stats-card .display-4 { font-size: 3rem; margin-bottom: 0.5rem; }
        .stats-card h3 { font-size: 2.2rem; font-weight: 700; margin-bottom: 0.2rem; }
        .stats-card p { font-size: 1.1rem; font-weight: 500; margin-bottom: 0; }
        .filter-section{ border-left:5px solid #6c757d; }
        .filter-section .card-header{ background: linear-gradient(90deg, #343a40, #495057) !important; color:white; border:none; font-weight:600; padding:1rem 1.5rem; }
        .form-label{ color:#495057; font-weight:600; margin-bottom:0.5rem; }
        .form-control,.form-select{ border:2px solid #e9ecef; border-radius:8px; transition:all 0.3s ease; font-size:0.95rem; }
        .form-control:focus,.form-select:focus{ border-color:#495057; box-shadow:0 0 0 0.2rem rgba(73,80,87,0.25); }
        .btn-primary{ background: linear-gradient(45deg, #495057, #6c757d); border:none; font-weight:600; border-radius:8px; transition: all 0.3s ease; }
        .btn-primary:hover{ background: linear-gradient(45deg, #343a40, #495057); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3); }
        .btn-secondary{ background: linear-gradient(45deg, #6c757d, #868e96); border:none; font-weight:600; }
        .btn-secondary:hover{ background: linear-gradient(45deg, #5a6268, #5a6268); }
        
        /* Table styling - matching organization documents design */
        .table-container {
            background: #fff;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 1rem 0.75rem;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
        }
        
        /* Status badges - matching organization documents */
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
        
        /* Button styling - matching organization documents */
        .btn-info {
            background: #157347 !important;
            border: 2px solid #157347 !important;
            color: white !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-info:hover {
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
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-top: 1px solid #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Status badges */
        .badge {
            font-size: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 20px;
        }
        
        /* Button styling */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border: none;
            padding: 1.5rem;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
            background-size: 1.2em;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        
        .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-title {
            color: white;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 1.5rem;
            background: #fff;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1.5rem;
            background: #fff;
        }
        
        /* Fix modal z-index layering issues */
        .modal {
            z-index: 1055 !important;
        }
        
        .modal-backdrop {
            z-index: 1054 !important;
        }
        
        /* Ensure approval and rejection modals appear above view event modal */
        #approveDocumentModal,
        #rejectDocumentModal {
            z-index: 1065 !important;
        }
        
        #approveDocumentModal .modal-backdrop,
        #rejectDocumentModal .modal-backdrop {
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
        
        /* View document modal base z-index */
        #viewDocumentModal {
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
        
        /* File viewer styling */
        .file-viewer {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .file-viewer iframe {
            width: 100%;
            height: 400px;
            border: none;
            border-radius: 8px;
        }
        .notification-highlight{ background-color:#e3f2fd !important; border:2px solid #2196f3 !important; box-shadow:0 0 15px rgba(33,150,243,0.3) !important; animation: notificationPulse 2s ease-in-out; }
        @keyframes notificationPulse{0%{transform:scale(1);}50%{transform:scale(1.01);}100%{transform:scale(1);}}
        
        /* Modal styling - matching OSAS side exactly */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            border-bottom: 1px solid #e9ecef;
            padding: 1.5rem;
            background: #fff;
        }
        
        .modal-header .btn-close {
            background-size: 1.2em;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        
        .modal-header .btn-close:hover {
            opacity: 1;
        }
        
        .modal-title {
            color: #343a40;
            font-weight: 700;
            font-size: 1.25rem;
            margin: 0;
        }
        
        .modal-body {
            padding: 1.5rem;
            background: #fff;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1.5rem;
            background: #fff;
        }
        
        /* Button styling in modals - matching OSAS exactly */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all 0.2s;
            border: none;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .btn-success {
            background: #198754;
            color: white;
        }
        
        .btn-success:hover {
            background: #157347;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c02a37;
        }
        
        .btn-info {
            background: #0dcaf0;
            color: white;
        }
        
        .btn-info:hover {
            background: #0aa2c0;
        }
        
        /* Table styling in modals - matching OSAS exactly */
        .table {
            margin-bottom: 0;
            background: #fff;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 1rem 0.75rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-top: 1px solid #e9ecef;
            white-space: nowrap;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* Ensure modal table content doesn't wrap */
        .modal .table td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        /* Allow specific columns to wrap if needed */
        .modal .table td:first-child {
            white-space: normal;
            max-width: none;
        }
        
        .table-light {
            background-color: #f8f9fa;
        }
        
        /* Status badges in modals - matching OSAS exactly */
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
        
        .badge.bg-success {
            background: #198754 !important;
            color: white !important;
        }
        
        .badge.bg-danger {
            background: #dc3545 !important;
            color: white !important;
        }
        
        .badge.bg-primary {
            background: #0d6efd !important;
            color: white !important;
        }
        
        /* Form elements in modals - matching OSAS exactly */
        .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            padding: 0.75rem;
        }
        
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        
        .text-danger {
            color: #dc3545 !important;
        }
        
        /* Alert styling in modals - matching OSAS exactly */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background-color: #d1e7dd;
            color: #0f5132;
            border-left: 4px solid #198754;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #0dcaf0;
        }
        
        .alert-dismissible .btn-close {
            padding: 1.25rem;
        }
        
        /* Event proposal details styling - matching OSAS exactly */
        .bg-light {
            background-color: #f8f9fa !important;
        }
        
        .rounded {
            border-radius: 8px !important;
        }
        
        .p-3 {
            padding: 1rem !important;
        }
        
        .mb-4 {
            margin-bottom: 1.5rem !important;
        }
        
        .mb-3 {
            margin-bottom: 1rem !important;
        }
        
        .mb-2 {
            margin-bottom: 0.5rem !important;
        }
        
        .mb-0 {
            margin-bottom: 0 !important;
        }
        
        .fw-bold {
            font-weight: 700 !important;
        }
        
        .text-dark {
            color: #343a40 !important;
        }
        
        /* Responsive modal adjustments */
        @media (max-width: 768px) {
            .modal-dialog {
                margin: 0.5rem;
            }
            
            .modal-header,
            .modal-body,
            .modal-footer {
                padding: 1rem;
            }
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
    <div class="container-fluid py-2">
        <div class="page-header">
            <h2 class="page-title"><i class="bi bi-calendar-event me-3"></i>Event Files</h2>
            <p class="page-subtitle">Review and manage event proposal documents for <strong><?php echo htmlspecialchars($council['council_name']); ?></strong></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row flex-nowrap g-3 mb-4" style="overflow-x:auto;">
            <div class="col">
                <div class="card stats-card total h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar-event display-4" style="color:#6c757d"></i>
                        <h3><?php echo $totalProposals; ?></h3>
                        <p>Total Events</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stats-card pending h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-clock display-4" style="color:#fbbf24"></i>
                        <h3><?php echo $pendingEvents; ?></h3>
                        <p>Pending Review</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stats-card sent h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-check2-circle display-4" style="color:#0ea5e9"></i>
                        <h3><?php echo $sentToOsasEvents; ?></h3>
                        <p>Sent to OSAS</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stats-card approved h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-patch-check display-4" style="color:#22c55e"></i>
                        <h3><?php echo $approvedByOsasEvents; ?></h3>
                        <p>Approved</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stats-card rejected h-100">
                    <div class="card-body text-center">
                        <i class="bi bi-x-circle display-4" style="color:#ef4444"></i>
                        <h3><?php echo $rejectedByOsasEvents; ?></h3>
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
                            <i class="bi bi-funnel me-2"></i>Filter Event Proposals
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="">All Status</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="sent_to_osas" <?php echo $status === 'sent_to_osas' ? 'selected' : ''; ?>>Sent to OSAS</option>
                                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved by OSAS</option>
                                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="date_from" class="form-label">Date From</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="date_to" class="form-label">Date To</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search me-2"></i>Apply Filters
                                </button>
                                <a href="council_adviser_view_event_files.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Event Proposals Table -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><i class="bi bi-calendar-event me-2"></i>Event Proposals</h5>
                    </div>
                    <div class="card-body">
                
                <?php if (empty($eventProposals)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted mt-3">No event proposals found</h5>
                        <p class="text-muted">No event proposals match your current filters.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Event Title</th>
                                    <th>Venue</th>
                                    <th class="text-center">Documents</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($eventProposals as $proposal): ?>
                                    <?php 
                                    // Check if this event should be highlighted based on notification
                                    $isHighlighted = false;
                                    if ($notificationId && $notificationType) {
                                        // For event_submitted notifications, highlight the specific event that was submitted
                                        if ($notificationType === 'event_submitted' && $notificationId == $proposal['approval_id']) {
                                            $isHighlighted = true;
                                        }
                                        // For event_approved notifications, highlight the specific event that was approved
                                        elseif ($notificationType === 'event_approved' && $notificationId == $proposal['approval_id']) {
                                            $isHighlighted = true;
                                        }
                                        // For event_rejected notifications, highlight the specific event that was rejected
                                        elseif ($notificationType === 'event_rejected' && $notificationId == $proposal['approval_id']) {
                                            $isHighlighted = true;
                                        }
                                    }
                                    ?>
                                    <tr class="<?php echo $isHighlighted ? 'notification-highlight' : ''; ?>" 
                                        id="event-<?php echo $proposal['approval_id']; ?>">
                                        <td><?php echo htmlspecialchars($proposal['event_title']); ?></td>
                                        <td><?php echo htmlspecialchars($proposal['event_venue']); ?></td>
                                        <td class="text-center"><?php echo $proposal['approved_docs']; ?>/<?php echo $proposal['total_docs']; ?> approved</td>
                                        <td class="text-center">
                                            <?php 
                                            require_once 'includes/status_helper.php';
                                            $status = getAdviserEventStatus(
                                                $proposal['total_docs'],
                                                $proposal['approved_docs'],
                                                $proposal['rejected_docs'],
                                                $proposal['sent_docs'],
                                                $proposal['pending_docs']
                                            );
                                            echo getAdviserStatusBadge($status);
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewEventModal" data-approval-id="<?php echo $proposal['approval_id']; ?>">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- View Event Modal -->
<div class="modal fade" id="viewEventModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
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

<!-- Approve Document Modal -->
<div class="modal fade" id="approveDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Approve Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="document_id" id="approveDocumentId">
                    <p>Are you sure you want to approve this document? It will be sent to OSAS for final approval.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Document Modal -->
<div class="modal fade" id="rejectDocumentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reject Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="document_id" id="rejectDocumentId">
                    <div class="mb-3">
                        <label for="rejection_reason" class="form-label">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" required 
                            placeholder="Please provide a detailed reason for rejecting this document..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Document</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Document Modal -->
<div class="modal fade" id="viewDocumentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="viewDocumentTitle">View Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewDocumentContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global embedded viewer to open PDFs/images inside a modal, matching other pages
window.viewEventDoc = function(filePath, title){
    let modal = document.getElementById('fileViewerModal');
    if (!modal) {
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
        modal = document.getElementById('fileViewerModal');
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

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
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

        // Function to view document
        function viewDocument(filePath, documentTitle) {
            var modal = new bootstrap.Modal(document.getElementById('viewDocumentModal'));
            var body = document.getElementById('viewDocumentContent');
            var title = document.getElementById('viewDocumentTitle');
            title.textContent = documentTitle;
            
            // Clear previous content
            body.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            
            // Determine file type by extension
            var ext = filePath.split('.').pop().toLowerCase();
            var content = '';
            
            if (["jpg", "jpeg", "png", "gif", "bmp", "webp"].includes(ext)) {
                content = '<img src="' + filePath + '" alt="Document Image" style="max-width:100%; max-height:70vh; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.12);">';
            } else if (ext === "pdf") {
                content = '<iframe src="' + filePath + '" style="width:100%; height:70vh; border:none; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.12); background:#fff;"></iframe>';
            } else {
                content = '<div class="alert alert-info">Cannot preview this file type. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
            }
            
            setTimeout(function() {
                body.innerHTML = content;
            }, 300);
            
            modal.show();
        }

        function approveDocument(id){ document.getElementById('approveDocumentId').value = id; new bootstrap.Modal(document.getElementById('approveDocumentModal')).show(); }
function rejectDocument(id){ document.getElementById('rejectDocumentId').value = id; new bootstrap.Modal(document.getElementById('rejectDocumentModal')).show(); }

// Enable continuous approve/reject actions by delegating submit and resetting modals
const approveModal = document.getElementById('approveDocumentModal');
const rejectModal = document.getElementById('rejectDocumentModal');
const approveBodyOriginal = approveModal.querySelector('.modal-body').innerHTML;
const approveFooterOriginal = approveModal.querySelector('.modal-footer').innerHTML;
const rejectBodyOriginal = rejectModal.querySelector('.modal-body').innerHTML;
const rejectFooterOriginal = rejectModal.querySelector('.modal-footer').innerHTML;

approveModal.addEventListener('hidden.bs.modal', function(){
    approveModal.querySelector('.modal-body').innerHTML = approveBodyOriginal;
    approveModal.querySelector('.modal-footer').innerHTML = approveFooterOriginal;
});
rejectModal.addEventListener('hidden.bs.modal', function(){
    rejectModal.querySelector('.modal-body').innerHTML = rejectBodyOriginal;
    rejectModal.querySelector('.modal-footer').innerHTML = rejectFooterOriginal;
});

        function refreshListsAndDetails() {
            const viewEventModal = document.getElementById('viewEventModal');
            if (viewEventModal.classList.contains('show')) {
                const currentApprovalId = document.querySelector('#viewEventModal').getAttribute('data-current-approval-id');
                if (currentApprovalId) {
                    fetch(`get_event_details.php?approval_id=${currentApprovalId}`)
                        .then(r => r.text())
                        .then(html => { document.getElementById('viewEventContent').innerHTML = html; })
                        .catch(() => {});
                }
            }
            setTimeout(() => {
                fetch('council_adviser_view_event_files.php')
                    .then(r => r.text())
                    .then(html => {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = html;
                        const newStats = tempDiv.querySelector('.row.flex-nowrap');
                        const curStats = document.querySelector('.row.flex-nowrap');
                        if (newStats && curStats) curStats.innerHTML = newStats.innerHTML;
                        const newTable = tempDiv.querySelector('.table-responsive');
                        const curTable = document.querySelector('.table-responsive');
                        if (newTable && curTable) curTable.innerHTML = newTable.innerHTML;
                    })
                    .catch(() => {});
            }, 600);
        }

document.addEventListener('submit', function(e){
    const form = e.target; if (!form) return;
    const isApprove = form.closest('#approveDocumentModal') !== null;
    const isReject = form.closest('#rejectDocumentModal') !== null;
    if (!isApprove && !isReject) return;
    e.preventDefault();

    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]');
    const originalText = submitButton.innerHTML;
    submitButton.disabled = true;
    submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + (isApprove ? 'Approving...' : 'Rejecting...');

    fetch('council_adviser_view_event_files.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: formData })
        .then(r=>r.text())
        .then(txt=>{
            const targetModal = isApprove ? approveModal : rejectModal;
            if (txt.includes('successfully')) {
                const body = targetModal.querySelector('.modal-body');
                const footer = targetModal.querySelector('.modal-footer');
                body.innerHTML = '<div class="text-center"><i class="bi bi-check-circle text-success display-4"></i><h5 class="mt-3">' + (isApprove ? 'Document Approved Successfully!' : 'Document Rejected Successfully!') + '</h5><p class="text-muted">Status updated.</p></div>';
                footer.innerHTML = '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
                submitButton.disabled = false; submitButton.innerHTML = originalText;
                
                // Auto-hide modal after 1.5 seconds
                setTimeout(() => {
                    const modalInstance = bootstrap.Modal.getInstance(targetModal);
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                }, 1500);
                refreshListsAndDetails();
            } else {
                const body = targetModal.querySelector('.modal-body');
                body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error processing request. Please try again.</div>';
                submitButton.disabled = false; submitButton.innerHTML = originalText;
            }
        })
        .catch(()=>{
            const targetModal = isApprove ? approveModal : rejectModal;
            const body = targetModal.querySelector('.modal-body');
            body.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>Error processing request. Please try again.</div>';
            submitButton.disabled = false; submitButton.innerHTML = originalText;
        });

        // Notification highlighting functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there's a highlighted event from notification
            const highlightedEvent = document.querySelector('.notification-highlight');
            if (highlightedEvent) {
                // Scroll to the highlighted event
                highlightedEvent.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                
                // Add a subtle flash effect
                setTimeout(() => {
                    highlightedEvent.style.animation = 'notificationPulse 1s ease-in-out';
                }, 500);
                
                // Remove highlight after 5 seconds
                setTimeout(() => {
                    highlightedEvent.classList.remove('notification-highlight');
                }, 5000);
            }
        });




});
</script>
</body>
</html>



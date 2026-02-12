<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

// Handle POST requests for document actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    if (isset($_POST['action']) && isset($_POST['doc_id'])) {
        $action = $_POST['action'];
        $docId = (int)$_POST['doc_id'];
        $reason = $_POST['reason'] ?? '';
        
        // Verify the document belongs to an event in this adviser's council
        $stmt = $conn->prepare("
            SELECT ed.*, e.council_id 
            FROM event_documents ed
            JOIN events e ON ed.event_id = e.id
            JOIN council c ON e.council_id = c.id
            WHERE ed.id = ? AND c.adviser_id = ?
        ");
        $stmt->bind_param("ii", $docId, getCurrentUserId());
        $stmt->execute();
        $document = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$document) {
            $response['message'] = 'Document not found or access denied.';
        } else {
            try {
                if ($action === 'approve_event_doc') {
                    $stmt = $conn->prepare("UPDATE event_documents SET status = 'sent_to_osas', adviser_approved_at = NOW() WHERE id = ?");
                    $stmt->bind_param("i", $docId);
                    $stmt->execute();
                    $stmt->close();
                    
                    $response['success'] = true;
                    $response['message'] = 'Document approved successfully!';
                } elseif ($action === 'reject_event_doc') {
                    if (empty($reason)) {
                        $response['message'] = 'Rejection reason is required.';
                    } else {
                        $stmt = $conn->prepare("UPDATE event_documents SET status = 'rejected', adviser_rejected_at = NOW(), rejection_reason = ? WHERE id = ?");
                        $stmt->bind_param("si", $reason, $docId);
                        $stmt->execute();
                        $stmt->close();
                        
                        $response['success'] = true;
                        $response['message'] = 'Document rejected successfully!';
                    }
                } else {
                    $response['message'] = 'Invalid action.';
                }
            } catch (Exception $e) {
                $response['message'] = 'An error occurred while processing the request.';
                error_log("Document action error: " . $e->getMessage());
            }
        }
    } else {
        $response['message'] = 'Missing required parameters.';
    }
    
    // Return JSON response for AJAX requests
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Find council for this adviser
$userId = getCurrentUserId();
$stmt = $conn->prepare("SELECT id, council_name FROM council WHERE adviser_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$council = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$council) {
    echo '<div class="alert alert-danger">No council found for this adviser.</div>';
    exit;
}

$councilId = (int)$council['id'];

// Filters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$searchTitle = $_GET['search_title'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($startDate) || !empty($endDate) || !empty($searchTitle);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Query events by council
$query = "
    SELECT e.*, 
           (SELECT COUNT(*) FROM event_images WHERE event_id = e.id) as image_count,
           (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
    FROM events e
    JOIN academic_semesters s ON e.semester_id = s.id
    JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE e.council_id = ? AND $statusCondition
";
$params = [$councilId];
$types = 'i';
if ($startDate) { $query .= " AND e.event_date >= ?"; $params[] = $startDate; $types .= 's'; }
if ($endDate) { 
    // Append time to make end date inclusive of the entire day
    $endDateWithTime = $endDate . ' 23:59:59';
    $query .= " AND e.event_date <= ?"; 
    $params[] = $endDateWithTime; 
    $types .= 's'; 
}
if ($searchTitle) { $query .= " AND e.title LIKE ?"; $params[] = "%$searchTitle%"; $types .= 's'; }
$query .= " ORDER BY e.event_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Events - Council Adviser</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
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
        
        .filter-section .card-title {
            margin: 0;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .filter-section .card-body {
            padding: 2rem;
        }
        
        /* Event cards alternating colors */
        .col-md-6:nth-child(odd) .card {
            border-left: 5px solid #495057;
        }
        
        .col-md-6:nth-child(even) .card {
            border-left: 5px solid #6c757d;
        }
        
        .card-body {
            padding: 2rem;
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
        
        /* Input group styling */
        .input-group-text {
            background: linear-gradient(45deg, #495057, #6c757d);
            border: 2px solid #495057;
            color: white;
            font-weight: 600;
        }
        
        /* Button styling */
        .btn-primary {
            background: linear-gradient(45deg, #495057, #6c757d);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            padding: 0.6rem 1.5rem;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #343a40, #495057);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5a6268, #5a6268);
            transform: translateY(-2px);
        }
        
        /* Badge styling */
        .badge.bg-primary {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }
        
        /* Card title styling */
        .card-title {
            color: #343a40;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        /* Card text styling */
        .card-text {
            color: #6c757d !important;
            line-height: 1.6;
        }
        
        /* Icon styling */
        .bi-calendar,
        .bi-geo-alt {
            color: #495057 !important;
        }
        
        .col-md-6:nth-child(even) .bi-calendar,
        .col-md-6:nth-child(even) .bi-geo-alt {
            color: #6c757d !important;
        }
        
        /* Enhanced image gallery styling */
        .img-gallery {
            margin-bottom: 1rem;
        }
        
        .img-gallery .col-4 {
            padding: 0.25rem;
        }
        
        .img-gallery img {
            border-radius: 12px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            border: 3px solid transparent;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .img-gallery img:hover {
            transform: scale(1.08) rotate(1deg);
            border-color: #495057;
            box-shadow: 0 8px 25px rgba(73, 80, 87, 0.4);
            z-index: 10;
        }
        
        .col-md-6:nth-child(even) .img-gallery img:hover {
            border-color: #6c757d;
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4);
            transform: scale(1.08) rotate(-1deg);
        }
        
        /* Image overlay effect */
        .gallery-item {
            position: relative;
            display: block;
            overflow: hidden;
            border-radius: 12px;
            cursor: pointer;
        }
        
        .gallery-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, rgba(73, 80, 87, 0.8), rgba(108, 117, 125, 0.8));
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 2;
            border-radius: 12px;
        }
        
        .gallery-item:hover::before {
            opacity: 0.3;
        }
        
        .gallery-item::after {
            content: '🔍';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            font-size: 1.5rem;
            color: white;
            z-index: 3;
            transition: transform 0.3s ease;
        }
        
        .gallery-item:hover::after {
            transform: translate(-50%, -50%) scale(1);
        }
        
        /* Image Gallery Modal */
        .image-gallery-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 9999;
            backdrop-filter: blur(10px);
        }
        
        .gallery-modal-content {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .gallery-main-image {
            max-width: 80%;
            max-height: 80%;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .gallery-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            z-index: 10001;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .gallery-close:hover {
            background: rgba(73, 80, 87, 0.8);
            transform: scale(1.1);
        }
        
        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            font-size: 2rem;
            padding: 15px 20px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.3s ease;
            z-index: 10001;
        }
        
        .gallery-nav:hover {
            background: rgba(73, 80, 87, 0.8);
            transform: translateY(-50%) scale(1.1);
        }
        
        .gallery-prev {
            left: 30px;
        }
        
        .gallery-next {
            right: 30px;
        }
        
        .gallery-thumbnails {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            max-width: 80%;
            overflow-x: auto;
            padding: 10px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 25px;
            backdrop-filter: blur(10px);
        }
        
        .gallery-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .gallery-thumbnail:hover,
        .gallery-thumbnail.active {
            opacity: 1;
            border-color: #495057;
            transform: scale(1.1);
        }
        
        .gallery-info {
            position: absolute;
            top: 20px;
            left: 30px;
            color: white;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        
        .gallery-counter {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .gallery-title {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        /* Loading spinner for gallery */
        .gallery-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 2rem;
        }
        
        /* No images state */
        .no-images {
            background: linear-gradient(135deg, rgba(73, 80, 87, 0.05) 0%, rgba(108, 117, 125, 0.05) 100%);
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }
        
        .no-images i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #495057;
        }
        
        /* See More button styling */
        .see-more-btn {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            color: white;
            font-weight: 600;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .see-more-btn:hover {
            background: linear-gradient(45deg, #5a6268, #5a6268);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Table styling */
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .table thead th {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            font-weight: 600;
            border: none;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table tbody tr:nth-child(odd) {
            background: rgba(73, 80, 87, 0.03);
        }
        
        .table tbody tr:nth-child(even) {
            background: rgba(108, 117, 125, 0.03);
        }
        
        .table tbody tr:hover {
            background: rgba(108, 117, 125, 0.1) !important;
        }
        
        .table td {
            font-size: 0.85rem;
            border-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Section headers */
        h6 {
            color: #343a40;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .col-md-6:nth-child(odd) h6 {
            color: #495057;
        }
        
        .col-md-6:nth-child(even) h6 {
            color: #6c757d;
        }
        
        /* Modal styling for image gallery */
        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border: none;
            border-radius: 15px 15px 0 0;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        /* Gallery modal styling */
        .gallery-modal .modal-dialog {
            max-width: 90vw;
        }
        
        .gallery-modal .modal-body {
            padding: 1rem;
        }
        
        .gallery-modal .row {
            margin: 0;
        }
        
        .gallery-modal .col-md-3 {
            padding: 0.5rem;
        }
        
        .gallery-modal img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .gallery-modal img:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        /* Responsive gallery modal */
        @media (max-width: 768px) {
            .gallery-main-image {
                max-width: 95%;
                max-height: 70%;
            }
            
            .gallery-nav {
                font-size: 1.5rem;
                padding: 10px 15px;
            }
            
            .gallery-prev {
                left: 15px;
            }
            
            .gallery-next {
                right: 15px;
            }
            
            .gallery-close {
                top: 15px;
                right: 15px;
                font-size: 1.5rem;
                width: 40px;
                height: 40px;
            }
            
            .gallery-thumbnails {
                bottom: 15px;
                max-width: 90%;
            }
            
            .gallery-thumbnail {
                width: 50px;
                height: 50px;
            }
            
            .gallery-info {
                top: 15px;
                left: 15px;
                padding: 10px 15px;
            }
        }
        
        /* Image container styling */
        .img-gallery .col-4 {
            position: relative;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1.5rem;
            }
            
            .page-header h2 {
                font-size: 1.5rem;
            }
            
            .img-gallery img {
                height: 80px !important;
            }
        }
        
        /* Animation for cards */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .col-md-6 {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .col-md-6:nth-child(even) {
            animation-delay: 0.2s;
        }
        
        .col-md-6:nth-child(3n) {
            animation-delay: 0.4s;
        }
        
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
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-top: 1px solid #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: #f8f9fa;
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
        /* Text truncation styles */
        .text-truncate-container {
            position: relative;
        }
        .text-truncate-content {
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .text-truncate-content.truncated {
            max-height: 4.5em; /* Approximately 3 lines */
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .text-truncate-toggle {
            color: #0d6efd;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            margin-left: 5px;
        }
        .text-truncate-toggle:hover {
            text-decoration: underline;
            color: #0a58ca;
        }
    </style>
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>
<main class="py-4">
    <div class="container-fluid">
        <div class="page-header">
            <h2 class="page-title">Council Events</h2>
            <p class="page-subtitle">View events submitted by your council</p>
        </div>
        <div class="card filter-section mb-4">
            <div class="card-header bg-white">
                <h3 class="card-title h5 mb-0">Filters</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="search_title">Search Title</label>
                        <div class="input-group">
                            <input type="text" id="search_title" class="form-control" name="search_title" value="<?php echo htmlspecialchars($searchTitle); ?>" placeholder="Search event title...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="start_date">Start Date</label>
                        <div class="input-group">
                            <input type="date" id="start_date" class="form-control" name="start_date" value="<?php echo $startDate; ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="end_date">End Date</label>
                        <div class="input-group">
                            <input type="date" id="end_date" class="form-control" name="end_date" value="<?php echo $endDate; ?>">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-2"></i>Apply Filters</button>
                            <a href="council_adviser_view_events.php" class="btn btn-secondary"><i class="bi bi-x-circle me-2"></i>Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <?php foreach ($events as $event): ?>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($event['title']); ?></h5>
                            <span class="badge bg-primary" style="cursor: pointer;" onclick="showEventParticipants(<?php echo $event['id']; ?>, '<?php echo htmlspecialchars(addslashes($event['title'])); ?>')" title="Click to view participants">
                                <?php echo $event['participant_count']; ?> Participants
                            </span>
                        </div>
                        <div class="text-truncate-container">
                            <p class="card-text text-muted text-truncate-content"><?php echo htmlspecialchars($event['description']); ?></p>
                            <a href="#" class="text-truncate-toggle" style="display: none;">More</a>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-calendar text-warning me-2"></i>
                                <small class="text-muted" style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($event['event_date'])); ?></small>
                            </div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-geo-alt text-warning me-2"></i>
                                <small class="text-muted"><?php echo htmlspecialchars($event['venue']); ?></small>
                            </div>
                        </div>

                        <?php
                        $imageStmt = $conn->prepare("SELECT image_path FROM event_images WHERE event_id = ?");
                        $imageStmt->bind_param("i", $event['id']);
                        $imageStmt->execute();
                        $images = $imageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $imageStmt->close();
                        ?>
                        <?php if (!empty($images)): ?>
                            <div class="mb-3">
                                <h6 class="mb-2">
                                    <i class="bi bi-images me-2"></i>Event Gallery
                                    <span class="badge bg-secondary ms-2"><?php echo count($images); ?></span>
                                </h6>
                                <div class="row g-2 img-gallery">
                                    <?php 
                                    $totalImages = count($images);
                                    $displayImages = array_slice($images, 0, 3);
                                    foreach ($displayImages as $index => $image):
                                        $isLastImage = ($index == 2 && $totalImages > 3);
                                    ?>
                                    <div class="col-4">
                                        <div class="gallery-item position-relative" data-event-id="<?php echo $event['id']; ?>" data-image-index="<?php echo $index; ?>" title="View full image">
                                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" class="img-fluid" alt="Event Image <?php echo $index + 1; ?>" style="height: 120px; object-fit: cover; width: 100%; border-radius: 8px;" loading="lazy">
                                            <?php if ($isLastImage): ?>
                                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center gallery-item" style="background: rgba(0, 0, 0, 0.7); cursor: pointer; border-radius: 8px;" data-event-id="<?php echo $event['id']; ?>" data-image-index="<?php echo $index; ?>">
                                                <div class="text-center text-white">
                                                    <i class="bi bi-camera" style="font-size: 1.5rem;"></i>
                                                    <div style="font-size: 1rem; font-weight: 600; margin-top: 0.25rem;">+<?php echo $totalImages - 3; ?> more</div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-images">
                                <i class="bi bi-image"></i>
                                <p class="mb-0">No images available for this event</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<!-- Image Gallery Modal -->
<div class="image-gallery-modal" id="imageGalleryModal">
    <div class="gallery-modal-content">
        <div class="gallery-close" id="galleryClose">&times;</div>
        <div class="gallery-info">
            <div class="gallery-counter" id="galleryCounter">1 / 1</div>
            <div class="gallery-title" id="galleryTitle">Event Gallery</div>
        </div>
        <button class="gallery-nav gallery-prev" id="galleryPrev">&#8249;</button>
        <button class="gallery-nav gallery-next" id="galleryNext">&#8250;</button>
        <img class="gallery-main-image" id="galleryMainImage" src="" alt="Gallery Image">
        <div class="gallery-thumbnails" id="galleryThumbnails"></div>
        <div class="gallery-loading" id="galleryLoading" style="display: none;">
            <i class="bi bi-hourglass-split"></i>
        </div>
    </div>
</div>

<!-- Participants Modal -->
<div class="modal fade" id="participantsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="participantsModalTitle">Event Participants</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="participantsModalBody">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">Loading participants...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Event Documents Modal -->
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

<!-- Approve Event Document Modal -->
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
                <input type="hidden" id="rejectEventDocId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="handleEventDocRejection()">Confirm Rejection</button>
            </div>
        </div>
    </div>
    
</div>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    class ImageGallery {
        constructor() {
            this.modal = document.getElementById('imageGalleryModal');
            this.mainImage = document.getElementById('galleryMainImage');
            this.counter = document.getElementById('galleryCounter');
            this.title = document.getElementById('galleryTitle');
            this.thumbnails = document.getElementById('galleryThumbnails');
            this.loading = document.getElementById('galleryLoading');
            this.prevBtn = document.getElementById('galleryPrev');
            this.nextBtn = document.getElementById('galleryNext');
            this.closeBtn = document.getElementById('galleryClose');
            
            this.currentIndex = 0;
            this.images = [];
            this.eventTitle = '';
            
            this.initEventListeners();
        }
        
        initEventListeners() {
            // Gallery item clicks
            document.addEventListener('click', (e) => {
                const galleryItem = e.target.closest('.gallery-item');
                if (galleryItem) {
                    const eventId = galleryItem.dataset.eventId;
                    const imageIndex = parseInt(galleryItem.dataset.imageIndex);
                    this.openGallery(eventId, imageIndex);
                }
            });
            
            // Navigation
            this.prevBtn.addEventListener('click', () => this.previousImage());
            this.nextBtn.addEventListener('click', () => this.nextImage());
            this.closeBtn.addEventListener('click', () => this.closeGallery());
            
            // Keyboard navigation
            document.addEventListener('keydown', (e) => {
                if (this.modal.style.display === 'block') {
                    switch(e.key) {
                        case 'ArrowLeft':
                            this.previousImage();
                            break;
                        case 'ArrowRight':
                            this.nextImage();
                            break;
                        case 'Escape':
                            this.closeGallery();
                            break;
                    }
                }
            });
            
            // Close on background click
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) {
                    this.closeGallery();
                }
            });
        }
        
        async openGallery(eventId, startIndex = 0) {
            this.showLoading();
            this.modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            try {
                // Fetch all images for this event
                const response = await fetch(`get_event_images.php?event_id=${eventId}`);
                const data = await response.json();
                
                if (data.success) {
                    this.images = data.images;
                    this.eventTitle = data.event_title || 'Event Gallery';
                    this.currentIndex = Math.min(startIndex, this.images.length - 1);
                    
                    this.updateDisplay();
                    this.createThumbnails();
                    this.hideLoading();
                } else {
                    throw new Error(data.error || 'Failed to load images');
                }
            } catch (error) {
                console.error('Error loading gallery:', error);
                this.hideLoading();
                this.closeGallery();
                alert('Error loading gallery images. Please try again.');
            }
        }
        
        updateDisplay() {
            if (this.images.length === 0) return;
            
            const currentImage = this.images[this.currentIndex];
            this.mainImage.src = currentImage.image_path;
            this.mainImage.alt = `${this.eventTitle} - Image ${this.currentIndex + 1}`;
            
            this.counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;
            this.title.textContent = this.eventTitle;
            
            // Update navigation buttons
            this.prevBtn.style.display = this.images.length > 1 ? 'block' : 'none';
            this.nextBtn.style.display = this.images.length > 1 ? 'block' : 'none';
            
            // Update thumbnails
            this.updateThumbnails();
        }
        
        createThumbnails() {
            this.thumbnails.innerHTML = '';
            
            if (this.images.length <= 1) {
                this.thumbnails.style.display = 'none';
                return;
            }
            
            this.thumbnails.style.display = 'flex';
            
            this.images.forEach((image, index) => {
                const thumb = document.createElement('img');
                thumb.src = image.image_path;
                thumb.className = 'gallery-thumbnail';
                thumb.alt = `Thumbnail ${index + 1}`;
                thumb.addEventListener('click', () => {
                    this.currentIndex = index;
                    this.updateDisplay();
                });
                
                this.thumbnails.appendChild(thumb);
            });
        }
        
        updateThumbnails() {
            const thumbnails = this.thumbnails.querySelectorAll('.gallery-thumbnail');
            thumbnails.forEach((thumb, index) => {
                thumb.classList.toggle('active', index === this.currentIndex);
            });
            
            // Scroll active thumbnail into view
            const activeThumbnail = thumbnails[this.currentIndex];
            if (activeThumbnail) {
                activeThumbnail.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest',
                    inline: 'center'
                });
            }
        }
        
        previousImage() {
            if (this.images.length > 1) {
                this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
                this.updateDisplay();
            }
        }
        
        nextImage() {
            if (this.images.length > 1) {
                this.currentIndex = (this.currentIndex + 1) % this.images.length;
                this.updateDisplay();
            }
        }
        
        closeGallery() {
            this.modal.style.display = 'none';
            document.body.style.overflow = '';
            this.images = [];
            this.currentIndex = 0;
        }
        
        showLoading() {
            this.loading.style.display = 'block';
            this.mainImage.style.display = 'none';
        }
        
        hideLoading() {
            this.loading.style.display = 'none';
            this.mainImage.style.display = 'block';
        }
    }
    
    // Initialize gallery when DOM is loaded
    document.addEventListener('DOMContentLoaded', () => {
        new ImageGallery();
    });
    
    // Handle event documents modal
    document.getElementById('eventDocsModal').addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const eventId = button.getAttribute('data-event-id');
        
        document.getElementById('eventDocsModalTitle').textContent = 'Council Event Documents';
        document.getElementById('eventDocsModalBody').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
        
        fetch(`get_council_event_documents.php?approval_id=${eventId}`)
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
            body.innerHTML = '<div class="alert alert-info w-100">PDF file detected. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
        } else {
            setTimeout(function() {
                body.innerHTML = '<div class="alert alert-info w-100">Cannot preview this file type. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
            }, 300);
        }
        modal.show();
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
        if (!reason.trim()) {
            showModalAlert('danger', 'Please provide a rejection reason.');
            return;
        }
        submitEventDocAction('reject_event_doc', docId, reason);
    }

            function submitEventDocAction(action, docId, reason = '') {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('doc_id', docId);
            formData.append('ajax', '1');
            if (reason) {
                formData.append('reason', reason);
            }

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message in modal
                const actionText = action === 'approve_event_doc' ? 'approved' : 'rejected';
                showModalAlert('success', `Document ${actionText} successfully! You can continue reviewing other documents.`);
                
                // Update the specific document row in the modal
                updateEventDocumentRow(docId, action);
                
                // Close approval/rejection modal if open
                const approvalModal = bootstrap.Modal.getInstance(document.querySelector('#approveEventDocModal'));
                const rejectionModal = bootstrap.Modal.getInstance(document.querySelector('#rejectEventDocModal'));
                if (approvalModal) {
                    approvalModal.hide();
                }
                if (rejectionModal) {
                    rejectionModal.hide();
                }
                
                // Keep the event documents modal open - DO NOT close it
                // This allows users to continue approving/rejecting other documents
            } else {
                showModalAlert('danger', data.message);
            }
        })
        .catch(error => {
            showModalAlert('danger', 'An error occurred. Please try again.');
            console.error('Error:', error);
        });
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
    
    // Function to show event participants
    function showEventParticipants(eventId, eventTitle) {
        const modal = new bootstrap.Modal(document.getElementById('participantsModal'));
        const modalTitle = document.getElementById('participantsModalTitle');
        const modalBody = document.getElementById('participantsModalBody');
        
        modalTitle.textContent = `Participants - ${eventTitle}`;
        modalBody.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading participants...</p></div>';
        modal.show();
        
        fetch(`get_event_participants.php?event_id=${eventId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.participants && data.participants.length > 0) {
                        let html = `
                            <div class="mb-3">
                                <p class="text-muted mb-0"><strong>Total:</strong> ${data.participants.length} participant(s)</p>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Course</th>
                                            <th>Year - Section</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.participants.forEach((participant, index) => {
                            const yearSection = participant.yearSection || (participant.year_level ? `${participant.year_level}${participant.section ? ' - ' + participant.section : ''}` : '');
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><strong>${participant.name || 'N/A'}</strong></td>
                                    <td>${participant.course || 'N/A'}</td>
                                    <td>${yearSection || 'N/A'}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No participants recorded for this event.</div>';
                    }
                } else {
                    modalBody.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${data.error || 'Error loading participants.'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalBody.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>An error occurred while loading participants. Please try again.</div>';
            });
    }
    
    // Text truncation functionality
    function initTextTruncation() {
        document.querySelectorAll('.text-truncate-container').forEach(container => {
            const content = container.querySelector('.text-truncate-content');
            const toggle = container.querySelector('.text-truncate-toggle');
            
            if (!content || !toggle) return;
            
            // Check if text needs truncation
            const fullHeight = content.scrollHeight;
            const lineHeight = parseInt(window.getComputedStyle(content).lineHeight);
            const maxHeight = lineHeight * 3; // 3 lines
            
            if (fullHeight <= maxHeight) {
                // Text is short, hide toggle
                toggle.style.display = 'none';
                return;
            }
            
            // Add truncated class initially
            content.classList.add('truncated');
            toggle.style.display = 'inline';
            toggle.textContent = 'More';
            
            // Toggle functionality
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                if (content.classList.contains('truncated')) {
                    content.classList.remove('truncated');
                    toggle.textContent = 'Less';
                } else {
                    content.classList.add('truncated');
                    toggle.textContent = 'More';
                }
            });
        });
    }
    
    // Initialize text truncation on page load
    document.addEventListener('DOMContentLoaded', initTextTruncation);
</script>
</body>
</html>

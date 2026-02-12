<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator']);

// Get MIS coordinator's college
$userId = getCurrentUserId();
$stmt = $conn->prepare("
    SELECT mc.college_id
    FROM mis_coordinators mc
    WHERE mc.user_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$collegeId = $result['college_id'] ?? null;
$stmt->close();

// Get filter parameters
$organization = $_GET['organization'] ?? '';
$council = $_GET['council'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$searchTitle = $_GET['search_title'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($organization) || !empty($council) || !empty($startDate) || !empty($endDate) || !empty($searchTitle);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Get organizations for filter (college-specific)
$organizations = [];
if ($collegeId) {
    $stmt = $conn->prepare("SELECT id, org_name FROM organizations WHERE college_id = ? ORDER BY org_name");
    $stmt->bind_param("i", $collegeId);
    $stmt->execute();
    $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Get councils for filter (college-specific)
$councils = [];
if ($collegeId) {
    $stmt = $conn->prepare("SELECT id, council_name FROM council WHERE college_id = ? ORDER BY council_name");
    $stmt->bind_param("i", $collegeId);
    $stmt->execute();
    $councils = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Build events query
$query = "
    SELECT e.*, COALESCE(o.org_name, c.council_name) as organization_name,
           (SELECT COUNT(*) FROM event_images WHERE event_id = e.id) as image_count,
           (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
    FROM events e
    LEFT JOIN organizations o ON e.organization_id = o.id
    LEFT JOIN council c ON e.council_id = c.id
    JOIN academic_semesters s ON e.semester_id = s.id
    JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE $statusCondition
";

$params = [];
$types = "";

// Filter by MIS coordinator's college
if ($collegeId) {
    $query .= " AND (o.college_id = ? OR c.college_id = ?)";
    $params[] = $collegeId;
    $params[] = $collegeId;
    $types .= "ii";
}

if ($organization) {
    $query .= " AND e.organization_id = ?";
    $params[] = $organization;
    $types .= "i";
}

if ($council) {
    $query .= " AND e.council_id = ?";
    $params[] = $council;
    $types .= "i";
}

if ($startDate) {
    $query .= " AND e.event_date >= ?";
    $params[] = $startDate;
    $types .= "s";
}

if ($endDate) {
    // Append time to make end date inclusive of the entire day
    $endDateWithTime = $endDate . ' 23:59:59';
    $query .= " AND e.event_date <= ?";
    $params[] = $endDateWithTime;
    $types .= "s";
}

if ($searchTitle) {
    $query .= " AND e.title LIKE ?";
    $params[] = "%$searchTitle%";
    $types .= "s";
}

$query .= " ORDER BY e.event_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Events - MIS Coordinator</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Main styling */
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
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
            margin-bottom: 2rem !important;
        }
        
        /* Enhanced card styling */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-left: 5px solid transparent;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Filter section styling */
        .filter-section {
            border-left-color: #6c757d !important;
            background: #ffffff !important;
        }
        
        .filter-section .card-body {
            background: #ffffff !important;
        }
        
        .filter-section .card-header {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white !important;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
        }
        
        /* Event cards alternating colors */
        .row .col-md-6:nth-child(odd) .card {
            border-left-color: #495057; /* Dark Gray */
        }
        
        .row .col-md-6:nth-child(even) .card {
            border-left-color: #6c757d; /* Medium Gray */
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .card-title {
            color: #343a40;
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .row .col-md-6:nth-child(odd) .card .card-title {
            color: #212529;
        }
        
        .row .col-md-6:nth-child(even) .card .card-title {
            color: #495057;
        }
        
        .card-subtitle {
            color: #6c757d !important;
            font-weight: 500;
        }
        
        /* Button styling */
        .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            border-color: transparent !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40) !important;
            border: none !important;
            border-color: transparent !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
        }
        
        .btn-primary:focus,
        .btn-primary:active,
        .btn-primary.active {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            border-color: transparent !important;
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3) !important;
            outline: none !important;
        }
        
        .btn-outline-primary {
            border: 2px solid #495057 !important;
            color: #495057 !important;
            background: transparent !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(45deg, #495057, #6c757d) !important;
            border-color: #495057 !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3);
        }
        
        .row .col-md-6:nth-child(even) .btn-outline-primary {
            border-color: #6c757d;
            color: #6c757d;
        }
        
        .row .col-md-6:nth-child(even) .btn-outline-primary:hover {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border-color: #6c757d;
            color: white;
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5c636a, #6c757d);
            transform: translateY(-1px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d !important;
            color: #6c757d !important;
            background: white !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-secondary:hover {
            background: linear-gradient(45deg, #6c757d, #868e96) !important;
            border-color: #6c757d !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        /* Badge styling */
        .badge {
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .badge.bg-primary {
            background: linear-gradient(45deg, #495057, #6c757d) !important;
            color: white;
        }
        
        .badge.bg-success {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            color: white;
        }
        
        /* Input group styling */
        .input-group-text {
            background: linear-gradient(45deg, #495057, #6c757d);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        .form-control,
        .form-select {
            border: 2px solid #e9ecef;
            border-radius: 0 8px 8px 0;
            transition: all 0.3s ease;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        /* Form label styling */
        .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        /* Icon styling */
        .bi-calendar,
        .bi-geo-alt {
            color: #495057;
        }
        
        .row .col-md-6:nth-child(even) .bi-calendar,
        .row .col-md-6:nth-child(even) .bi-geo-alt {
            color: #6c757d;
        }
        
        .bi-building {
            color: #6c757d;
        }
        
        .bi-trophy {
            color: #495057;
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
        
        .row .col-md-6:nth-child(even) .img-gallery img:hover {
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
        
        /* Table styling */
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table thead th {
            background: linear-gradient(90deg, rgba(73, 80, 87, 0.1) 0%, rgba(108, 117, 125, 0.1) 100%);
            color: #495057;
            font-weight: 600;
            border: none;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:nth-child(odd) {
            background: rgba(73, 80, 87, 0.03);
        }
        
        .table tbody tr:nth-child(even) {
            background: rgba(108, 117, 125, 0.03);
        }
        
        .table tbody tr:hover {
            background: rgba(108, 117, 125, 0.1);
            transform: scale(1.01);
        }
        
        .table td {
            border-color: rgba(0, 0, 0, 0.05);
            font-size: 0.875rem;
        }
        
        /* Section headers */
        h6 {
            color: #495057;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }
        
        .row .col-md-6:nth-child(odd) h6 {
            color: #495057;
        }
        
        .row .col-md-6:nth-child(even) h6 {
            color: #6c757d;
        }
        
        /* Text styling */
        .text-muted {
            color: #6c757d !important;
        }
        
        .card-text {
            line-height: 1.6;
            margin-bottom: 1rem;
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
        
        .col-md-6 .card {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .col-md-6:nth-child(even) .card {
            animation-delay: 0.1s;
        }
        
        .col-md-6:nth-child(3n) .card {
            animation-delay: 0.2s;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
            }
            
            .page-header h2 {
                font-size: 1.5rem;
            }
            
            .page-header p {
                font-size: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-subtitle {
                font-size: 0.95rem;
            }
            
            .img-gallery img {
                height: 80px !important;
            }
            
            .table {
                font-size: 0.8rem;
            }
        }
        
        /* Enhanced spacing */
        .mb-3 {
            margin-bottom: 1.5rem !important;
        }
        
        /* Filter section enhancements */
        .filter-section .card-body {
            background: #ffffff !important;
        }
        
        /* Custom scrollbar */
        .table-responsive {
            scrollbar-width: thin;
            scrollbar-color: #495057 #f8f9fa;
        }
        
        .table-responsive::-webkit-scrollbar {
            height: 6px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f8f9fa;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #495057;
            border-radius: 3px;
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
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="page-header">
                <h2 class="page-title">All Organization Events</h2>
                <p class="page-subtitle">View and manage events across all organizations</p>
            </div>

            <!-- Filters -->
            <div class="card filter-section">
                <div class="card-header">
                    <h5 class="mb-0">Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label for="organization" class="form-label">Organization</label>
                            <div class="input-group">
                                <select class="form-select" id="organization" name="organization">
                                    <option value="">All Organizations</option>
                                    <?php foreach ($organizations as $org): ?>
                                        <option value="<?php echo $org['id']; ?>" <?php echo $organization == $org['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($org['org_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="council" class="form-label">Council</label>
                            <div class="input-group">
                                <select class="form-select" id="council" name="council">
                                    <option value="">All Councils</option>
                                    <?php foreach ($councils as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $council == $c['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($c['council_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="search_title" class="form-label">Search Title</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="search_title" name="search_title" value="<?php echo htmlspecialchars($searchTitle); ?>" placeholder="Search event title...">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date</label>
                            <div class="input-group">
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date</label>
                            <div class="input-group">
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel me-2"></i>Apply Filters
                                </button>
                                <a href="mis_view_events.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle me-2"></i>Reset
                                </a>
                                <?php
                                    $qs = http_build_query([
                                        'organization' => $organization,
                                        'council' => $council,
                                        'start_date' => $startDate,
                                        'end_date' => $endDate,
                                        'search_title' => $searchTitle
                                    ]);
                                ?>
                                <a href="export_mis_events.php?<?php echo $qs; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-download me-2"></i>Download CSV
                                </a>
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
                                <div>
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($event['title']); ?></h5>
                                    <h6 class="card-subtitle text-muted mt-1">
                                        <i class="bi bi-building me-1"></i>
                                        <?php echo htmlspecialchars($event['organization_name']); ?>
                                    </h6>
                                </div>
                                <span class="badge bg-primary" style="cursor: pointer;" onclick="showEventParticipants(<?php echo $event['id']; ?>, '<?php echo htmlspecialchars(addslashes($event['title'])); ?>')" title="Click to view participants">
                                    <?php echo $event['participant_count']; ?> PARTICIPANTS
                                </span>
                            </div>
                            <div class="text-truncate-container">
                                <p class="card-text text-muted text-truncate-content"><?php echo htmlspecialchars($event['description']); ?></p>
                                <a href="#" class="text-truncate-toggle" style="display: none;">More</a>
                            </div>
                            <div class="mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-calendar text-primary me-2"></i>
                                    <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($event['event_date'])); ?></small>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-geo-alt text-primary me-2"></i>
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

                            <div class="mb-3">
                                <h6 class="mb-2">
                                    <i class="bi bi-images me-2"></i>Event Gallery
                                    <span class="badge bg-secondary ms-2"><?php echo count($images); ?></span>
                                </h6>
                                <?php if (!empty($images)): ?>
                                <div class="row g-2 img-gallery">
                                    <?php 
                                    $totalImages = count($images);
                                    $displayImages = array_slice($images, 0, 3); // Show max 3 images
                                    foreach ($displayImages as $index => $image): 
                                        $isLastImage = ($index == 2 && $totalImages > 3);
                                    ?>
                                    <div class="col-4">
                                        <div class="gallery-item position-relative" data-event-id="<?php echo $event['id']; ?>" data-image-index="<?php echo $index; ?>" title="View full image">
                                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" 
                                                 class="img-fluid" 
                                                 alt="Event Image <?php echo $index + 1; ?>"
                                                 style="height: 120px; object-fit: cover; width: 100%; border-radius: 8px;"
                                                 loading="lazy">
                                            
                                            <?php if ($isLastImage): ?>
                                            <!-- Facebook-style "See more" overlay -->
                                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center gallery-item" 
                                                 style="background: rgba(0, 0, 0, 0.7); cursor: pointer; border-radius: 8px;"
                                                 data-event-id="<?php echo $event['id']; ?>" data-image-index="<?php echo $index; ?>">
                                                <div class="text-center text-white">
                                                    <i class="bi bi-camera" style="font-size: 1.5rem;"></i>
                                                    <div style="font-size: 1rem; font-weight: 600; margin-top: 0.25rem;">
                                                        +<?php echo $totalImages - 3; ?> more
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
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
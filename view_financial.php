<?php
require_once 'includes/session.php';
require_once 'config/database.php';

// Check if user is authorized
$role = getUserRole();
if (!in_array($role, ['osas', 'org_adviser', 'council_adviser'])) {
    header('Location: dashboard.php');
    exit();
}

// Get organization/council IDs for advisers
$org_id = null;
$council_id = null;
if ($role === 'org_adviser') {
    $stmt = $conn->prepare("SELECT id FROM organizations WHERE adviser_id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $org_id = $row['id'];
    }
} elseif ($role === 'council_adviser') {
    $stmt = $conn->prepare("SELECT id FROM council WHERE adviser_id = ? LIMIT 1");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $council_id = (int)$row['id'];
    }
}

// Handle filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$organization_filter = $_GET['organization'] ?? '';
$council_filter = $_GET['council'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($start_date) || !empty($end_date) || !empty($organization_filter) || !empty($council_filter);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Build the query based on role
if ($role === 'council_adviser' && $council_id) {
    // For council advisers, get only their own council's financial reports
    $query = "SELECT fr.*, 
                     c.council_name as organization_name,
                     'council' as source_type
              FROM financial_reports fr 
              JOIN council c ON fr.council_id = c.id
              JOIN academic_semesters s ON fr.semester_id = s.id
              JOIN academic_terms at ON s.academic_term_id = at.id
              WHERE $statusCondition AND fr.council_id = ?";
    $params = [$council_id];
    $types = "i";
    
    // Add date filters if provided
    if ($start_date) {
        $query .= " AND fr.report_date >= ?";
        $params[] = $start_date;
        $types .= "s";
    }
    
    if ($end_date) {
        $query .= " AND fr.report_date <= ?";
        $params[] = $end_date;
        $types .= "s";
    }
} else {
    // For organization advisers and OSAS, get financial reports from organizations and councils
    if ($role === 'osas') {
        // OSAS can see both organization and council reports
        $query = "SELECT fr.*, 
                         COALESCE(o.org_name, c.council_name) as organization_name,
                         CASE 
                             WHEN fr.organization_id IS NOT NULL THEN 'organization'
                             WHEN fr.council_id IS NOT NULL THEN 'council'
                         END as source_type
                  FROM financial_reports fr 
                  LEFT JOIN organizations o ON fr.organization_id = o.id 
                  LEFT JOIN council c ON fr.council_id = c.id
                  JOIN academic_semesters s ON fr.semester_id = s.id
                  JOIN academic_terms at ON s.academic_term_id = at.id
                  WHERE $statusCondition";
        $params = [];
        $types = "";

        if ($organization_filter) {
            $query .= " AND fr.organization_id = ?";
            $params[] = $organization_filter;
            $types .= "i";
        } elseif ($council_filter) {
            $query .= " AND fr.council_id = ?";
            $params[] = $council_filter;
            $types .= "i";
        }
        
        // Add date filters if provided
        if ($start_date) {
            $query .= " AND fr.report_date >= ?";
            $params[] = $start_date;
            $types .= "s";
        }
        
        if ($end_date) {
            $query .= " AND fr.report_date <= ?";
            $params[] = $end_date;
            $types .= "s";
        }
    } else {
        // For organization advisers, get only their organization's financial reports
        $query = "SELECT fr.*, o.org_name as organization_name, 'organization' as source_type
                  FROM financial_reports fr 
                  JOIN organizations o ON fr.organization_id = o.id 
                  JOIN academic_semesters s ON fr.semester_id = s.id
                  JOIN academic_terms at ON s.academic_term_id = at.id
                  WHERE $statusCondition";
        $params = [];
        $types = "";

        if ($role === 'org_adviser') {
            $query .= " AND fr.organization_id = ?";
            $params[] = $org_id;
            $types .= "i";
        }
        
        // Add date filters if provided
        if ($start_date) {
            $query .= " AND fr.report_date >= ?";
            $params[] = $start_date;
            $types .= "s";
        }
        
        if ($end_date) {
            $query .= " AND fr.report_date <= ?";
            $params[] = $end_date;
            $types .= "s";
        }
    }
}

$query .= " ORDER BY fr.report_date DESC";

// Debug information
if ($role === 'osas' || $role === 'council_adviser') {
    error_log("Query: " . $query);
    error_log("Params: " . print_r($params, true));
    error_log("Types: " . $types);
    error_log("Role: " . $role);
    if ($role === 'council_adviser') {
        error_log("Council ID: " . $council_id);
    }
    error_log("Start Date: " . $start_date);
    error_log("End Date: " . $end_date);
}

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Database error: " . $conn->error);
}

// Only bind parameters if there are any
if (!empty($params) && !empty($types)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    die("Database error: " . $stmt->error);
}
$result = $stmt->get_result();

// Debug information
if ($role === 'osas' || $role === 'council_adviser') {
    error_log("Number of records found: " . $result->num_rows);
    
    // Debug: Show first few records for council advisers
    if ($role === 'council_adviser' && $result->num_rows > 0) {
        $debug_result = $result;
        $debug_result->data_seek(0); // Reset pointer
        $count = 0;
        while ($debug_row = $debug_result->fetch_assoc() && $count < 3) {
            error_log("Debug Record " . ($count + 1) . ": " . print_r($debug_row, true));
            $count++;
        }
        $result->data_seek(0); // Reset pointer back
    }
}

// Get organizations and councils for filter dropdowns (only for OSAS)
$organizations = [];
$councils = [];
if ($role === 'osas') {
    // Get organizations
    $org_query = "SELECT id, org_name FROM organizations ORDER BY org_name";
    $org_result = $conn->query($org_query);
    while ($row = $org_result->fetch_assoc()) {
        $organizations[] = $row;
    }
    
    // Get councils
    $council_query = "SELECT id, council_name FROM council ORDER BY council_name";
    $council_result = $conn->query($council_query);
    while ($row = $council_result->fetch_assoc()) {
        $councils[] = $row;
    }
}

// Debug information
if ($role === 'osas') {
    error_log("Number of organizations: " . count($organizations));
    error_log("Number of councils: " . count($councils));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Financial Reports</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
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
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            color: #343a40;
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-size: 2rem;
        }
        
        .page-subtitle {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 0;
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
        
        /* Filter card styling */
        main .card:first-of-type {
            border: none;
            border-left: none;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        /* Financial reports card */
        main .card:nth-of-type(2) {
            border-left: 5px solid #495057;
        }
        
        main .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
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
        }
        
        /* General form styling */
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
        
        /* Filter card button styling */
        main .card:first-of-type .btn-primary {
            background: linear-gradient(45deg, #6c757d, #495057);
            border: none;
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
        }
        
        main .card:first-of-type .btn-secondary {
            background: linear-gradient(45deg, #adb5bd, #6c757d);
            border: none;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }
        
        /* General button styling */
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
        
        /* View Report button - specific green styling */
        main .btn-view-report {
            background: #157347 !important;
            border: none !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
            color: white !important;
        }
        
        main .btn-view-report:hover {
            background: #0f5132 !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(21, 115, 71, 0.3);
            color: white !important;
        }
        
        main .btn-sm {
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
        }
        
        /* Enhanced table styling */
        main .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            overflow-x: auto;
            overflow-y: visible;
        }
        
        main .table {
            margin-bottom: 0;
            border-radius: 12px;
            overflow: hidden;
            width: 100%;
            font-size: 0.85rem; /* Smaller font for better fit */
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
        
        /* Financial reports table - gray theme */
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
        
        main .table td {
            border-color: rgba(0, 0, 0, 0.05);
            font-size: 0.8rem;
            padding: 0.75rem 0.5rem;
            vertical-align: top;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Financial amount styling */
        main .text-danger {
            color: #dc3545 !important;
            font-weight: 600;
        }
        
        main .text-success {
            color: #198754 !important;
            font-weight: 600;
        }
        
        /* Revenue column - keep green theme for financial data */
        main .table tbody tr td:nth-child(5) {
            background: linear-gradient(90deg, rgba(25, 135, 84, 0.05), transparent);
            border-radius: 8px 0 0 8px;
        }
        
        /* Expenses column - keep red theme for financial data */
        main .table tbody tr td:nth-child(4) {
            background: linear-gradient(90deg, rgba(220, 53, 69, 0.05), transparent);
            border-radius: 8px 0 0 8px;
        }
        
        /* Turnover column - change to gray theme */
        main .table tbody tr td:nth-child(6) {
            background: linear-gradient(90deg, rgba(108, 117, 125, 0.05), transparent);
            border-radius: 8px 0 0 8px;
        }
        
        /* Balance column styling */
        main .table tbody tr td:nth-child(7) {
            font-weight: 700;
            font-size: 1rem;
        }
        
        /* Column width specifications for Financial Reports table */
        main .card:nth-of-type(2) .table th:nth-child(1), /* Date */
        main .card:nth-of-type(2) .table td:nth-child(1) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(2), /* Organization/Council */
        main .card:nth-of-type(2) .table td:nth-child(2) {
            width: 20%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.3;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(3), /* Title */
        main .card:nth-of-type(2) .table td:nth-child(3) {
            width: 20%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.2;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            hyphens: auto;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(4), /* Expenses */
        main .card:nth-of-type(2) .table td:nth-child(4) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(5), /* Revenue */
        main .card:nth-of-type(2) .table td:nth-child(5) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(6), /* Turnover */
        main .card:nth-of-type(2) .table td:nth-child(6) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(7), /* Balance */
        main .card:nth-of-type(2) .table td:nth-child(7) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
            font-size: 0.8rem;
        }
        
        main .card:nth-of-type(2) .table th:nth-child(8), /* Actions */
        main .card:nth-of-type(2) .table td:nth-child(8) {
            width: 12%;
            white-space: nowrap;
            vertical-align: top;
            padding: 0.75rem 0.5rem;
        }
        
        /* Alert styling */
        main .alert-info {
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.1), rgba(134, 142, 150, 0.1));
            border: 2px solid rgba(108, 117, 125, 0.2);
            border-radius: 12px;
            color: #495057;
            font-weight: 500;
        }
        
        /* Icon styling */
        main .bi-file-earmark-text {
            color: white;
        }
        
        /* Custom scrollbar */
        main .table-responsive {
            scrollbar-width: thin;
            scrollbar-color: #495057 #f8f9fa;
        }
        
        main .table-responsive::-webkit-scrollbar {
            height: 6px;
        }
        
        main .table-responsive::-webkit-scrollbar-track {
            background: #f8f9fa;
        }
        
        main .table-responsive::-webkit-scrollbar-thumb {
            background: #495057;
            border-radius: 3px;
        }
        
        /* Enhanced spacing and typography */
        main .container-fluid {
            padding: 0.5rem;
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            main .container-fluid {
                padding: 1rem;
            }
            
            main .card-body {
                padding: 1.5rem;
            }
            
            main h2 {
                font-size: 1.5rem;
            }
            
            main .table {
                font-size: 0.8rem;
            }
            
            main .table thead th,
            main .table td {
                padding: 0.75rem 0.5rem;
            }
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
        
        /* Enhanced form row styling */
        main .row.g-3 {
            margin-bottom: 2rem;
            align-items: end;
        }
        
        main .row.g-3 .col-md-2,
        main .row.g-3 .col-md-3 {
            display: flex;
            flex-direction: column;
        }
        
        main .row.g-3 .col-md-2 .form-label,
        main .row.g-3 .col-md-3 .form-label {
            margin-bottom: 0.5rem;
        }
        
        /* Button alignment styling */
        main .d-flex.align-items-end.gap-2 {
            gap: 0.5rem;
        }
        
        main .d-flex.align-items-end.gap-2 .btn {
            flex-shrink: 0;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="page-header">
                <h2 class="page-title">Financial Reports</h2>
                <p class="page-subtitle">View and manage financial reports from organizations and councils</p>
            </div>
            
            <?php if ($role === 'council_adviser' && $council_id): ?>
                <?php
                // Get council name for display
                $council_stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
                $council_stmt->bind_param("i", $council_id);
                $council_stmt->execute();
                $council_result = $council_stmt->get_result();
                $council_name = $council_result->fetch_assoc()['council_name'] ?? 'Unknown Council';
                ?>
                <div class="alert alert-info">
                    <i class="bi bi-building me-2"></i>
                    <strong>Council:</strong> <?php echo htmlspecialchars($council_name); ?>
                </div>
            <?php endif; ?>
            
            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title h5 mb-0">
                        Financial Report Filters
                    </h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-2">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <?php if ($role === 'osas'): ?>
                        <div class="col-md-3">
                            <label for="organization" class="form-label">Organization</label>
                            <select class="form-select" id="organization" name="organization">
                                <option value="">All Organizations</option>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>" 
                                            <?php echo $organization_filter == $org['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($org['org_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="council" class="form-label">Council</label>
                            <select class="form-select" id="council" name="council">
                                <option value="">All Councils</option>
                                <?php foreach ($councils as $council): ?>
                                    <option value="<?php echo $council['id']; ?>" 
                                            <?php echo $council_filter == $council['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($council['council_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-search me-2"></i>Apply Filters
                            </button>
                            <a href="view_financial.php" class="btn btn-secondary">
                                <i class="fas fa-refresh me-2"></i>Reset Filters
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Financial Reports -->
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title h5 mb-0">Financial Reports</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <?php if ($role === 'osas'): ?>
                                    <th>Organization/Council</th>
                                    <?php endif; ?>
                                    <th>Title</th>
                                    <th>Expenses</th>
                                    <th>Revenue</th>
                                    <th>Turnover</th>
                                    <th>Balance</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $row_count = 0;
                                while ($row = $result->fetch_assoc()): 
                                    $row_count++;
                                ?>
                                    <tr>
                                        <td style="white-space: nowrap;"><?php echo date('M d, Y', strtotime($row['report_date'])); ?></td>
                                        <?php if ($role === 'osas'): ?>
                                        <td><?php echo htmlspecialchars($row['organization_name']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($row['title']); ?></td>
                                        <td class="text-danger">₱<?php echo number_format($row['expenses'], 2); ?></td>
                                        <td class="text-success">₱<?php echo number_format($row['revenue'], 2); ?></td>
                                        <td>₱<?php echo number_format($row['turnover'], 2); ?></td>
                                        <td class="<?php echo $row['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            ₱<?php echo number_format($row['balance'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($row['file_path']): ?>
                                            <a href="<?php echo htmlspecialchars($row['file_path']); ?>" 
                                               class="btn btn-view-report btn-sm" target="_blank">
                                                <i class="bi bi-file-earmark-text"></i> View Report
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                        <?php if ($row_count === 0): ?>
                        <div class="alert alert-info mt-3">
                            No financial reports found for the selected criteria.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>

        // Handle mutual exclusivity of organization and council filters
        const organizationSelect = document.getElementById('organization');
        const councilSelect = document.getElementById('council');
        
        if (organizationSelect && councilSelect) {
            organizationSelect.addEventListener('change', function() {
                if (this.value) {
                    councilSelect.value = '';
                }
            });

            councilSelect.addEventListener('change', function() {
                if (this.value) {
                    organizationSelect.value = '';
                }
            });
        }
    </script>
</body>
</html> 
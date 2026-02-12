<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

$councilId = getCurrentCouncilId();
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Clear session messages
unset($_SESSION['message']);
unset($_SESSION['error']);

// Get council details
$stmt = $conn->prepare("SELECT council_name, adviser_name FROM council WHERE id = ?");
$stmt->bind_param("i", $councilId);
$stmt->execute();
$council = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Set status condition - only show active academic terms by default (no filter UI available)
$statusCondition = "at.status = 'active'";

// Get members scoped by council with custom position ordering
$sql = "
    SELECT so.*, 
    CASE 
        WHEN position = 'Adviser' THEN 1
        WHEN position = 'PRESIDENT' THEN 2
        WHEN position = 'VICE PRESIDENT FOR INTERNAL AFFAIRS' THEN 3
        WHEN position = 'VICE PRESIDENT FOR EXTERNAL AFFAIRS' THEN 4
        WHEN position = 'VICE PRESIDENT FOR RECORDS AND DOCUMENTATION' THEN 5
        WHEN position = 'VICE PRESIDENT FOR OPERATIONS AND ACADEMIC AFFAIRS' THEN 6
        WHEN position = 'VICE PRESIDENT FOR FINANCE AND BUDGET MANAGEMENT' THEN 7
        WHEN position = 'VICE PRESIDENT FOR AUDIT' THEN 8
        WHEN position = 'VICE PRESIDENT FOR LOGISTICS AND PROPERTY MANAGEMENT' THEN 9
        WHEN position = 'VICE PRESIDENT FOR PUBLIC RELATIONS AND INFORMATION' THEN 10
        WHEN position = 'VICE PRESIDENT FOR GENDER AND DEVELOPMENT' THEN 11
        WHEN position = '2ND YEAR REPRESENTATIVE' THEN 12
        WHEN position = '3RD YEAR REPRESENTATIVE' THEN 13
        WHEN position = '4TH YEAR REPRESENTATIVE' THEN 14
        ELSE 15
    END as position_order,
    'student' as member_type
    FROM student_officials so
    JOIN academic_terms at ON so.academic_year_id = at.id
    WHERE council_id = ?
    AND $statusCondition
    
    UNION ALL
    
    SELECT 
        ao.id,
        ao.organization_id,
        ao.council_id,
        ao.academic_year_id,
        c.adviser_name as name,
        u.email as student_number,
        '' as course,
        '' as year_section,
        ao.position,
        ao.picture_path,
        ao.created_at,
        1 as position_order,
        'adviser' as member_type
    FROM adviser_officials ao
    JOIN users u ON ao.adviser_id = u.id
    JOIN academic_terms at ON ao.academic_year_id = at.id
    LEFT JOIN council c ON ao.council_id = c.id
    WHERE ao.council_id = ?
    AND $statusCondition
    
    ORDER BY position_order, name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $councilId, $councilId);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members Management - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main class="main-content">
        <div class="container-fluid py-2">
            <!-- Header -->
            <div class="page-header">
                <h2 class="page-title">Council Officers</h2>
                <p class="page-subtitle">View all officers and members of <?php echo htmlspecialchars($council['council_name']); ?></p>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Officers Grid -->
            <div class="row">
                <?php if (empty($members)): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-people text-muted" style="font-size: 3rem;"></i>
                                <h5 class="text-muted mt-3">No officers found</h5>
                                <p class="text-muted mb-0">There are no officers registered for this council yet.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4">
                        <div class="card h-100 shadow-sm hover-card <?php echo ($member['member_type'] === 'adviser') ? 'adviser-card' : ''; ?>">
                            <div class="position-relative">
                                <?php if ($member['picture_path']): ?>
                                    <img src="<?php echo htmlspecialchars($member['picture_path']); ?>" class="card-img-top" alt="Member Picture" style="height: 200px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height: 200px;">
                                        <i class="bi bi-person-circle text-secondary" style="font-size: 4rem;"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if ($member['position']): ?>
                                <div class="position-absolute bottom-0 start-0 w-100">
                                    <div class="position-badge <?php echo ($member['member_type'] === 'adviser') ? 'adviser-badge' : ''; ?>">
                                        <span class="badge position-badge-text"><?php echo htmlspecialchars($member['position']); ?></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3">
                                <h6 class="card-title mb-2 fw-bold text-truncate"><?php echo htmlspecialchars($member['name']); ?></h6>
                                <div class="member-info">
                                    <?php if ($member['member_type'] === 'adviser'): ?>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-envelope me-2 flex-shrink-0"></i>
                                        <span class="text-muted small text-truncate"><?php echo htmlspecialchars($member['student_number']); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-person-badge me-2 flex-shrink-0"></i>
                                        <span class="text-muted small">Adviser</span>
                                    </div>
                                    <?php else: ?>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-book me-2 flex-shrink-0"></i>
                                        <span class="text-muted small text-truncate"><?php echo htmlspecialchars($member['course']); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-mortarboard me-2 flex-shrink-0"></i>
                                        <span class="text-muted small">Year & Section: <?php echo htmlspecialchars($member['year_section'] ?? ''); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="bi bi-person-badge me-2 flex-shrink-0"></i>
                                        <span class="text-muted small">Student #: <?php echo htmlspecialchars($member['student_number']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <style>
        .hover-card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            border: none;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-left: 4px solid transparent;
            max-width: 280px;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        
        .hover-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.15) !important;
        }
        
        .hover-card:nth-child(4n+1) {
            border-left-color: #343a40; /* Dark Gray */
        }
        
        .hover-card:nth-child(4n+2) {
            border-left-color: #6c757d; /* Medium Gray */
        }
        
        .hover-card:nth-child(4n+3) {
            border-left-color: #495057; /* Darker Gray */
        }
        
        .hover-card:nth-child(4n+4) {
            border-left-color: #212529; /* Almost Black */
        }
        
        .card-img-top {
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
            transition: transform 0.3s ease;
        }
        
        .hover-card:hover .card-img-top {
            transform: scale(1.05);
        }
        
        .card-title {
            color: #212529 !important;
            font-weight: 600;
            font-size: 1rem;
            line-height: 1.2;
        }
        
        .hover-card:nth-child(4n+1) .card-title {
            color: #343a40 !important;
        }
        
        .hover-card:nth-child(4n+2) .card-title {
            color: #495057 !important;
        }
        
        .hover-card:nth-child(4n+3) .card-title {
            color: #212529 !important;
        }
        
        .hover-card:nth-child(4n+4) .card-title {
            color: #000000 !important;
        }
        
        .member-info {
            font-size: 0.85rem;
        }
        
        .member-info .bi {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .hover-card:nth-child(4n+1) .member-info .bi {
            color: #495057;
        }
        
        .hover-card:nth-child(4n+2) .member-info .bi {
            color: #6c757d;
        }
        
        .hover-card:nth-child(4n+3) .member-info .bi {
            color: #343a40;
        }
        
        .hover-card:nth-child(4n+4) .member-info .bi {
            color: #212529;
        }
        
        .position-badge {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.6) 100%);
            padding: 0.5rem;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        
        .position-badge-text {
            background: linear-gradient(45deg, #fd7e14, #ff8c42);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        h2 {
            color: #212529;
            font-weight: 600;
        }
        
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
        
        /* Adviser card styling - matches existing card colors exactly */
        .adviser-card {
            border-left: 4px solid #343a40 !important; /* Same as first card color */
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        }
        
        .adviser-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 0.75rem 1.5rem rgba(0, 0, 0, 0.15) !important; /* Same as regular cards */
        }
        
        .adviser-badge {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.6) 100%);
        }
        
        .adviser-card .card-title {
            color: #212529 !important;
        }
        
        .adviser-card .member-info .bi {
            color: #6c757d;
        }
        
        /* Enhanced user-friendly features */
        .card-body {
            background: rgba(255, 255, 255, 0.9);
        }
        
        .hover-card:hover .card-body {
            background: rgba(255, 255, 255, 1);
        }
        
        /* Better contrast for text */
        .text-muted {
            color: #6c757d !important;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .hover-card {
                max-width: 100%;
            }
            
            .card-img-top {
                height: 180px !important;
            }
            
            .position-badge-text {
                font-size: 0.7rem;
            }
        }
        
        @media (max-width: 576px) {
            .card-img-top {
                height: 160px !important;
            }
            
            .card-body {
                padding: 0.75rem !important;
            }
        }
    </style>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html> 
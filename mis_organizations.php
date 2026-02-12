<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator']);

// Get MIS coordinator's college
$userId = getCurrentUserId();
$stmt = $conn->prepare("
    SELECT mc.college_id, c.name as college_name
    FROM mis_coordinators mc
    LEFT JOIN colleges c ON mc.college_id = c.id
    WHERE mc.user_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$collegeId = $result['college_id'] ?? null;
$collegeName = $result['college_name'] ?? 'Unknown College';
$stmt->close();

// Get all organizations in the MIS coordinator's college
$organizations = [];
if ($collegeId) {
    $stmt = $conn->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM events WHERE organization_id = o.id) as event_count,
               (SELECT COUNT(*) FROM awards WHERE organization_id = o.id) as award_count
        FROM organizations o
        WHERE o.college_id = ?
        ORDER BY o.org_name ASC
    ");
    $stmt->bind_param("i", $collegeId);
    $stmt->execute();
    $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizations - MIS Coordinator</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
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
        
        .page-header {
            margin-bottom: 2rem !important;
        }
        
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
        
        .row .col-md-6:nth-child(odd) .card {
            border-left-color: #495057;
        }
        
        .row .col-md-6:nth-child(even) .card {
            border-left-color: #6c757d;
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
        
        .stats-badge {
            background-color: #e4e6eb;
            color: #050505;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-right: 8px;
        }
        
        .btn-outline-primary {
            border: 2px solid #495057 !important;
            color: #495057 !important;
            background: transparent !important;
            font-weight: 600;
            transition: all 0.3s ease;
            border-radius: 8px;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(45deg, #495057, #6c757d) !important;
            border-color: #495057 !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3);
        }
        
        .gap-2 {
            gap: 0.5rem !important;
        }
        
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
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-subtitle {
                font-size: 0.95rem;
            }
        }
    </style>
    <link href="assets/css/custom.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap">
                    <div>
                        <h2 class="page-title">All Organizations</h2>
                        <p class="page-subtitle">View all organizations in <?php echo htmlspecialchars($collegeName); ?></p>
                    </div>
                    <div class="d-flex gap-2 mt-2 mt-md-0">
                        <a href="export_mis_organizations_csv.php" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export CSV
                        </a>
                        <a href="export_mis_organizations_pdf.php" class="btn btn-outline-primary">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <?php if (empty($organizations)): ?>
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-building text-muted" style="font-size: 3rem;"></i>
                                <p class="text-muted mt-3 mb-0">No organizations found in this college.</p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($organizations as $org): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-2"><?php echo htmlspecialchars($org['org_name']); ?></h5>
                                        <?php if (!empty($org['code'])): ?>
                                            <h6 class="card-subtitle text-muted mb-2">
                                                <i class="bi bi-tag me-1"></i>
                                                <?php echo htmlspecialchars($org['code']); ?>
                                            </h6>
                                        <?php endif; ?>
                                        <?php if (!empty($org['description'])): ?>
                                            <p class="card-text text-muted small mb-0">
                                                <?php echo htmlspecialchars(substr($org['description'], 0, 100)); ?><?php echo strlen($org['description']) > 100 ? '...' : ''; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($org['status'])): ?>
                                        <span class="badge <?php echo $org['status'] === 'recognized' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo ucfirst($org['status']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mt-3 pt-3 border-top">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <span class="stats-badge">
                                            <i class="bi bi-calendar-event me-1"></i>
                                            <?php echo $org['event_count']; ?> events
                                        </span>
                                        <span class="stats-badge">
                                            <i class="bi bi-trophy me-1"></i>
                                            <?php echo $org['award_count']; ?> awards
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if (!empty($org['president_name']) || !empty($org['adviser_name'])): ?>
                                <div class="mt-3 pt-3 border-top">
                                    <div class="row g-2">
                                        <?php if (!empty($org['president_name'])): ?>
                                            <div class="col-6">
                                                <small class="text-muted d-block">President</small>
                                                <small class="fw-semibold"><?php echo htmlspecialchars($org['president_name']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($org['adviser_name'])): ?>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Adviser</small>
                                                <small class="fw-semibold"><?php echo htmlspecialchars($org['adviser_name']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

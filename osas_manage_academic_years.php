<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/academic_year_archiver.php';

requireRole(['osas']);

// Get success/error messages from session and clear them
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message']);
unset($_SESSION['error']);

// Handle manual archiving
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'archive_year') {
        $year_id = $_POST['year_id'] ?? 0;
        
        if ($year_id) {
            $results = archiveSpecificAcademicYear($year_id);
            
            if ($results['success']) {
                $_SESSION['message'] = "Academic year archived successfully. Reset {$results['reset_organizations']} organizations and {$results['reset_councils']} councils. Deleted {$results['deleted_org_docs']} organization documents, {$results['deleted_council_docs']} council documents, and {$results['deleted_student_data']} student data records.";
            } else {
                $_SESSION['error'] = "Failed to archive academic year: " . implode(', ', $results['errors']);
            }
        } else {
            $_SESSION['error'] = "Invalid academic year ID";
        }
    } elseif ($_POST['action'] === 'run_auto_archive') {
        $results = archiveExpiredAcademicYears();
        
        if (!empty($results['archived_years'])) {
            $_SESSION['message'] = "Auto-archiving completed. Archived " . count($results['archived_years']) . " academic years: " . implode(', ', $results['archived_years']);
        } else {
            $_SESSION['message'] = "No academic years needed archiving";
        }
        
        if (!empty($results['errors'])) {
            $_SESSION['error'] = "Some errors occurred: " . implode(', ', $results['errors']);
        }
    }
    
    // Redirect to prevent form resubmission
    header('Location: osas_manage_academic_years.php');
    exit();
}

// Get all academic years
$academicYears = $conn->query("
    SELECT at.*, 
           COUNT(DISTINCT o.id) as organization_count,
           COUNT(DISTINCT c.id) as council_count
    FROM academic_terms at
    LEFT JOIN organizations o ON at.id = o.academic_year_id
    LEFT JOIN council c ON at.id = c.academic_year_id
    GROUP BY at.id
    ORDER BY at.start_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Get current active academic year
$currentYear = getCurrentActiveAcademicYear();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Year Management - OSAS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-badge {
            font-size: 0.8rem;
            padding: 0.4rem 0.8rem;
        }
        .archive-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #ffc107;
        }
        .current-year {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            border: 1px solid #28a745;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-2 page-title">
                            Academic Year Management
                        </h2>
                        <p class="page-subtitle">Manage academic years and semesters</p>
                    </div>
                    <div>
                        <span class="badge bg-primary">OSAS</span>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Current Academic Year Info -->
            <?php if ($currentYear): ?>
            <div class="alert current-year">
                <h5 class="mb-2">
                    <i class="fas fa-star me-2"></i>
                    Current Active Academic Year
                </h5>
                <p class="mb-1">
                    <strong><?php echo htmlspecialchars($currentYear['school_year']); ?></strong> 
                    (<?php echo date('M d, Y', strtotime($currentYear['start_date'])); ?> - 
                    <?php echo date('M d, Y', strtotime($currentYear['end_date'])); ?>)
                </p>
                <p class="mb-0 text-muted">
                    Document submission period: 
                    <?php if (isset($currentYear['document_start_date']) && isset($currentYear['document_end_date'])): ?>
                        <?php echo date('M d, Y', strtotime($currentYear['document_start_date'])); ?> - 
                        <?php echo date('M d, Y', strtotime($currentYear['document_end_date'])); ?>
                    <?php else: ?>
                        <span class="text-muted">Not set</span>
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Auto Archive Button -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="fas fa-robot me-2"></i>
                                Automatic Archiving
                            </h5>
                            <p class="card-text">
                                Run the automatic archiving process to archive expired academic years and reset organization/council status.
                            </p>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="run_auto_archive">
                                <button type="submit" class="btn btn-warning" onclick="return confirm('This will archive all expired academic years and reset all linked organizations and councils. Continue?')">
                                    <i class="fas fa-play me-2"></i>
                                    Run Auto-Archive Now
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Academic Years Table -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list me-2"></i>
                        All Academic Years
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>School Year</th>
                                    <th>Academic Period</th>
                                    <th>Document Period</th>
                                    <th>Status</th>
                                    <th>Organizations</th>
                                    <th>Councils</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($academicYears as $year): ?>
                                    <tr class="<?php echo $year['status'] === 'active' ? 'current-year' : ''; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($year['school_year']); ?></strong>
                                            <?php if ($year['status'] === 'active'): ?>
                                                <span class="badge bg-success status-badge ms-2">Current</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <i class="fas fa-calendar-start me-1"></i>
                                                <?php echo date('M d, Y', strtotime($year['start_date'])); ?><br>
                                                <i class="fas fa-calendar-end me-1"></i>
                                                <?php echo date('M d, Y', strtotime($year['end_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <i class="fas fa-file-upload me-1"></i>
                                                <?php echo date('M d, Y', strtotime($year['document_start_date'])); ?><br>
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M d, Y', strtotime($year['document_end_date'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                            $statusClass = '';
                                            $statusText = '';
                                            switch ($year['status']) {
                                                case 'active':
                                                    $statusClass = 'bg-success';
                                                    $statusText = 'Active';
                                                    break;
                                                case 'archived':
                                                    $statusClass = 'bg-secondary';
                                                    $statusText = 'Archived';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?> status-badge">
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $year['organization_count']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $year['council_count']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($year['status'] !== 'archived'): ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="archive_year">
                                                    <input type="hidden" name="year_id" value="<?php echo $year['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                            onclick="return confirm('Archive <?php echo htmlspecialchars($year['school_year']); ?>? This will reset all linked organizations and councils to unrecognized status.')">
                                                        <i class="fas fa-archive me-1"></i>
                                                        Archive
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">Archived</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Archive Warning -->
            <div class="alert archive-warning mt-4">
                <h6 class="alert-heading">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Important Archive Information
                </h6>
                <p class="mb-2">
                    When an academic year is archived:
                </p>
                <ul class="mb-0">
                    <li>All organizations linked to that year will be reset to "unrecognized" status</li>
                    <li>All councils linked to that year will be reset to "unrecognized" status</li>
                    <li>Archived data will no longer appear in the active system</li>
                    <li>Organizations and councils must re-submit documents for the new academic year</li>
                </ul>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

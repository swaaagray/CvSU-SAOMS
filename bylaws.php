<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

$userRole = getUserRole();
$message = '';
$error = '';

// Get by-laws documents based on user role
if ($userRole === 'council_president') {
    // For council presidents, get from council_documents table
    $councilId = getCurrentCouncilId();
    if (!$councilId) {
        $error = 'No council found for this president.';
    } else {
        $bylaws = $conn->query("
            SELECT cd.*, u.username as uploaded_by_name 
            FROM council_documents cd
            LEFT JOIN users u ON cd.submitted_by = u.id
            LEFT JOIN academic_terms at ON cd.academic_year_id = at.id
            WHERE cd.council_id = $councilId AND cd.document_type = 'constitution_bylaws' AND cd.osas_approved_at IS NOT NULL
            AND at.status = 'active'
            ORDER BY cd.osas_approved_at DESC
        ")->fetch_all(MYSQLI_ASSOC);
    }
} else {
    // For organization presidents, get from organization_documents table
    $orgId = getCurrentOrganizationId();
    if (!$orgId) {
        $error = 'No organization found for this president.';
    } else {
        $bylaws = $conn->query("
            SELECT od.*, u.username as uploaded_by_name 
            FROM organization_documents od
            LEFT JOIN users u ON od.submitted_by = u.id
            LEFT JOIN academic_terms at ON od.academic_year_id = at.id
            WHERE od.organization_id = $orgId AND od.document_type = 'constitution_bylaws' AND od.osas_approved_at IS NOT NULL
            AND at.status = 'active'
            ORDER BY od.osas_approved_at DESC
        ")->fetch_all(MYSQLI_ASSOC);
    }
}



// Get messages from session
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Clear session messages
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>By-Laws - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        
        /* Button styling */
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
        
        .btn-secondary {
            background: #6c757d !important;
            border: none !important;
            font-weight: 600;
            border-radius: 8px;
            color: white !important;
            border-color: #6c757d !important;
        }
        
        .btn-secondary:hover {
            background: #5c636a !important;
            transform: translateY(-1px);
            color: white !important;
            border-color: #5c636a !important;
        }
        
        .btn-secondary:focus {
            background: #5c636a !important;
            color: white !important;
            border-color: #5c636a !important;
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25);
        }
        
        .btn-secondary:active {
            background: #5c636a !important;
            color: white !important;
            border-color: #5c636a !important;
        }
        
        /* Action buttons */
        .btn-info {
            background: linear-gradient(45deg, #20c997);
            border: none;
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #1ea085);
            transform: scale(1.05);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #dc3545);
            border: none;
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #c82333);
            transform: scale(1.05);
            color: white;
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
            color: #495057;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Table styling */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background: #f8f9fa;
            padding: 1rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .table td {
            padding: 1rem 0.75rem;
            vertical-align: middle;
            border-top: 1px solid #dee2e6;
            font-size: 0.875rem;
        }
        
        /* Enhanced hover effects */
        .btn-sm {
            transition: all 0.2s ease;
            border-radius: 6px;
        }
        
        .btn-sm:hover {
            transform: scale(1.05);
        }
        
        /* PDF container styling */
        .pdf-container {
            background: #f8f9fa;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin: 20px 0;
            border: 1px solid #e9ecef;
        }
        
        .pdf-viewer-container {
            position: relative;
            background: #ffffff;
            min-height: 80vh;
            height: calc(100vh - 250px);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
            }
            
            .table td,
            .table th {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .pdf-viewer-container {
                height: calc(100vh - 200px);
                min-height: 400px;
            }
            
            .pdf-container {
                margin: 10px 0;
                border-radius: 8px;
            }
        }
        
        @media (max-width: 576px) {
            .pdf-viewer-container {
                height: calc(100vh - 150px);
                min-height: 300px;
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
        
        .table tbody tr {
            animation: fadeInUp 0.5s ease forwards;
        }
        
        .table tbody tr:nth-child(even) {
            animation-delay: 0.1s;
        }
        
        .table tbody tr:nth-child(3n) {
            animation-delay: 0.2s;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="page-header">
                <h2 class="page-title">By-Laws Documents</h2>
                <p class="page-subtitle">Manage and view <?php echo $userRole === 'council_president' ? 'council' : 'organization'; ?> by-laws and constitution</p>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php 
            // Get the most recent bylaws document to display
            $latestBylaws = !empty($bylaws) ? $bylaws[0] : null;
            
            // Debug: Check if we have OSAS-approved bylaws
            if (empty($bylaws)) {
                if ($userRole === 'council_president') {
                    $debugQuery = $conn->query("
                        SELECT cd.*, u.username as uploaded_by_name 
                        FROM council_documents cd
                        LEFT JOIN users u ON cd.submitted_by = u.id
                        WHERE cd.council_id = $councilId AND cd.document_type = 'constitution_bylaws'
                        ORDER BY cd.submitted_at DESC
                    ")->fetch_all(MYSQLI_ASSOC);
                } else {
                    $debugQuery = $conn->query("
                        SELECT od.*, u.username as uploaded_by_name 
                        FROM organization_documents od
                        LEFT JOIN users u ON od.submitted_by = u.id
                        WHERE od.organization_id = $orgId AND od.document_type = 'constitution_bylaws'
                        ORDER BY od.submitted_at DESC
                    ")->fetch_all(MYSQLI_ASSOC);
                }
                
                if (!empty($debugQuery)) {
                    $osasApproved = array_filter($debugQuery, function($doc) {
                        return $doc['osas_approved_at'] !== null;
                    });
                    $adviserApproved = array_filter($debugQuery, function($doc) {
                        return $doc['adviser_approved_at'] !== null && $doc['osas_approved_at'] === null;
                    });
                    $pending = array_filter($debugQuery, function($doc) {
                        return $doc['adviser_approved_at'] === null && $doc['osas_approved_at'] === null;
                    });
                    
                    $error = "Found " . count($debugQuery) . " by-laws documents total. ";
                    $error .= "OSAS approved: " . count($osasApproved) . ", ";
                    $error .= "Adviser approved (pending OSAS): " . count($adviserApproved) . ", ";
                    $error .= "Pending: " . count($pending);
                }
            }
            ?>

            <?php if ($latestBylaws): ?>
                <!-- Display the most recent bylaws document -->
                <div class="pdf-container">
                    <div id="pdf-viewer-<?php echo uniqid(); ?>" class="pdf-viewer-container"></div>
                </div>
            <?php else: ?>
                <!-- No bylaws document available -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-file-earmark-pdf" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3 text-muted">No Bylaws Document Available</h4>
                        <p class="text-muted">By-laws documents are uploaded through the <?php echo $userRole === 'council_president' ? 'Council Documents' : 'Organization Documents'; ?> section.</p>
                        <a href="<?php echo $userRole === 'council_president' ? 'council_documents.php' : 'organization_documents.php'; ?>" class="btn btn-primary">
                            <i class="bi bi-file-earmark-arrow-up"></i> Go to <?php echo $userRole === 'council_president' ? 'Council Documents' : 'Organization Documents'; ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize custom PDF viewers
            const pdfContainers = document.querySelectorAll('.pdf-viewer-container');
            
            pdfContainers.forEach((container, index) => {
                // Get the PDF URL from the container's data attribute or parent context
                let pdfUrl = null;
                
                // Check if this is for the latest bylaws
                const latestBylaws = '<?php echo $latestBylaws["file_path"] ?? ""; ?>';
                
                if (latestBylaws && latestBylaws !== '') {
                    pdfUrl = latestBylaws;
                }
                
                if (pdfUrl) {
                    // Create PDF viewer
                    const viewer = new CustomPDFViewer(container, {
                        showToolbar: true,
                        enableZoom: true,
                        enableDownload: true,
                        enablePrint: true
                    });
                    
                    // Load the PDF document
                    viewer.loadDocument(pdfUrl);
                }
            });
        });
    </script>
</body>
</html> 
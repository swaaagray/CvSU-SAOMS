<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_adviser']);

$orgId = getCurrentOrganizationId();

if (!$orgId) {
    echo '<div class="alert alert-danger">No organization found for this adviser.</div>';
    exit;
}

// Get organization details
$stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$organization = $stmt->get_result()->fetch_assoc();

// Get the most recent bylaws document only (from organization_documents table)
$stmt = $conn->prepare("
    SELECT od.*, u.username as uploaded_by_name 
    FROM organization_documents od
    LEFT JOIN users u ON od.submitted_by = u.id
    LEFT JOIN academic_terms at ON od.academic_year_id = at.id
    WHERE od.organization_id = ? AND od.document_type = 'constitution_bylaws' AND od.osas_approved_at IS NOT NULL AND at.status = 'active'
    ORDER BY od.osas_approved_at DESC
    LIMIT 1
");
$stmt->bind_param("i", $orgId); 
$stmt->execute();
$latestBylaws = $stmt->get_result()->fetch_assoc();

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
    <title>View Bylaws - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <style>
        /* Main heading styling */
        h2 {
            color: #343a40;
            margin-bottom: 0.5rem;
        }
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
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #0bb5d6, #25c5e8);
            color: white;
            transform: scale(1.05);
        }
        
        .btn-danger {
            background: #dc3545;
            border: none;
            color: white;
            font-weight: 500;
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        
        .btn-danger:hover {
            background: #c82333;
            color: white;
            transform: scale(1.05);
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #495057 0%, #343a40 100%);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            border: none;
            padding: 1rem 1.5rem;
        }
        
        /* PDF viewer styling */
        .pdf-viewer-container {
            width: 100%;
            height: calc(100vh - 200px);
            min-height: 500px;
            max-height: 800px;
            margin: 1rem 0;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
        
        /* Table styling */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            background: #f8f9fa;
            border-top: none;
            font-weight: 600;
            color: #495057;
        }
        
        .table td {
            vertical-align: middle;
            border-top: 1px solid #dee2e6;
        }
        
        /* Alert styling */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .pdf-viewer-container {
                height: 400px;
            }
            
            .card-header {
                padding: 0.75rem 1rem;
            }
        }
        
        /* Loading animation */
        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }
        
        .spinner-border {
            color: #495057;
        }
        
        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state i {
            font-size: 4rem;
            color: #6c757d;
            margin-bottom: 1rem;
        }
        
        .empty-state h4 {
            color: #495057;
            margin-bottom: 0.5rem;
        }
        
        .empty-state p {
            color: #6c757d;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><b>By-Laws Documents</b></h2>
                <div class="d-flex align-items-center">
                    <span class="badge bg-secondary me-2">Organization: <?php echo htmlspecialchars($organization['org_name']); ?></span>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($latestBylaws && file_exists($latestBylaws['file_path'])): ?>
                <!-- Display the most recent bylaws document -->
                <div id="pdf-viewer-<?php echo uniqid(); ?>" class="pdf-viewer-container"></div>
            <?php else: ?>
                <!-- No bylaws document available -->
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-file-earmark-pdf" style="font-size: 4rem; color: #6c757d;"></i>
                        <h4 class="mt-3 text-muted">No Bylaws Document Available</h4>
                        <p class="text-muted">The organization has not uploaded any bylaws document yet.</p>
                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Note:</strong> Only the most recent bylaws document is displayed here. 
                            Document history is not shown to advisers.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($latestBylaws && file_exists($latestBylaws['file_path'])): ?>
            // Initialize the custom PDF viewer
            const pdfContainers = document.querySelectorAll('.pdf-viewer-container');
            
            pdfContainers.forEach((container, index) => {
                const pdfUrl = '<?php echo htmlspecialchars($latestBylaws['file_path']); ?>';
                
                if (pdfUrl) {
                    // Initialize the custom PDF viewer
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
            <?php endif; ?>
            
            // Add keyboard shortcuts for better accessibility
            document.addEventListener('keydown', function(e) {
                // Ctrl/Cmd + P for print
                if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }
                
                // Ctrl/Cmd + S for download (if available)
                if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                    e.preventDefault();
                    const downloadLink = document.querySelector('a[download]');
                    if (downloadLink) {
                        downloadLink.click();
                    }
                }
            });
            

        });
    </script>
</body>
</html> 
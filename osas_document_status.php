<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['osas']);

$message = '';
$error = '';

// Get filter parameters
$selectedCollege = isset($_GET['college']) ? (int)$_GET['college'] : 0;
$selectedDocumentType = isset($_GET['document_type']) ? $_GET['document_type'] : 'organization'; // 'organization' or 'council'

// Pagination for entity list
$perPage = isset($_GET['per_page']) && (int)$_GET['per_page'] > 0 ? min(50, (int)$_GET['per_page']) : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Get all colleges for the filter dropdown
$colleges = $conn->query("SELECT id, name FROM colleges ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Document type labels for organizations
$organizationDocumentTypes = [
    'adviser_resume' => 'Resume / CV of Adviser',
    'student_profile' => 'Bio Data / Student Profile',
    'officers_list' => 'List of Officers',
    'calendar_activities' => 'Calendar of Activities',
    'official_logo' => 'Official Logo',
    'officers_grade' => 'Grade Certificate of Officers',
    'group_picture' => 'Group Picture with Captions',
    'constitution_bylaws' => 'Constitution and By-Laws',
    'members_list' => 'Updated List of Members',
    'good_moral' => 'Good Moral Certification',
    'adviser_acceptance' => 'Acceptance Letter of Adviser',
    'accomplishment_report' => 'Accomplishment Report',
    'previous_plan_of_activities' => 'Previous Plan of Activities',
    'financial_report' => 'Financial Report'
];

// Document type labels for councils
$councilDocumentTypes = [
    'adviser_resume' => 'Resume / CV of Adviser',
    'student_profile' => 'Bio Data / Student Profile',
    'officers_list' => 'List of Officers',
    'calendar_activities' => 'Calendar of Activities',
    'official_logo' => 'Official Logo',
    'officers_grade' => 'Grade Certificate of Officers',
    'group_picture' => 'Group Picture with Captions',
    'constitution_bylaws' => 'Constitution and By-Laws',
    'members_list' => 'Updated List of Members',
    'good_moral' => 'Good Moral Certification',
    'adviser_acceptance' => 'Acceptance Letter of Adviser',
    'accomplishment_report' => 'Accomplishment Report',
    'previous_plan_of_activities' => 'Previous Plan of Activities',
    'financial_report' => 'Financial Report'
];

// Initialize data arrays
$organizations = [];
$councils = [];
$organizationDocumentStatus = [];
$councilDocumentStatus = [];

if ($selectedDocumentType === 'organization') {
    // Get organizations with college filter
	$query = "SELECT o.id, o.org_name, o.type, c.name as college_name 
              FROM organizations o 
              LEFT JOIN colleges c ON o.college_id = c.id";
    if ($selectedCollege > 0) {
        $query .= " WHERE o.college_id = ? ORDER BY o.org_name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $selectedCollege);
        $stmt->execute();
        $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $query .= " ORDER BY o.org_name";
        $organizations = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    }

    // Get document status for organizations - ONLY OSAS actions affect status
    foreach ($organizations as $org) {
        $stmt = $conn->prepare("
            SELECT document_type, 
                   CASE 
                       WHEN osas_approved_at IS NOT NULL THEN 'approved'
                       WHEN osas_rejected_at IS NOT NULL THEN 'rejected'
                       ELSE 'not_submitted'
                   END as osas_status,
                   adviser_approved_at,
                   adviser_rejected_at,
                   osas_approved_at,
                   osas_rejected_at
            FROM organization_documents 
            WHERE organization_id = ? 
            ORDER BY submitted_at DESC
        ");
        $stmt->bind_param("i", $org['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $orgStatus = [];
        while ($row = $result->fetch_assoc()) {
            $orgStatus[$row['document_type']] = $row['osas_status'];
        }
        $organizationDocumentStatus[$org['id']] = $orgStatus;
    }
} else {
    // Get councils with college filter
    $query = "SELECT c.id, c.council_name, c.type, col.name as college_name 
              FROM council c 
              LEFT JOIN colleges col ON c.college_id = col.id";
    if ($selectedCollege > 0) {
        $query .= " WHERE c.college_id = ? ORDER BY c.council_name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $selectedCollege);
        $stmt->execute();
        $councils = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $query .= " ORDER BY c.council_name";
        $councils = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    }

    // Get document status for councils - ONLY OSAS actions affect status
    foreach ($councils as $council) {
        $stmt = $conn->prepare("
            SELECT document_type, 
                   CASE 
                       WHEN osas_approved_at IS NOT NULL THEN 'approved'
                       WHEN osas_rejected_at IS NOT NULL THEN 'rejected'
                       ELSE 'not_submitted'
                   END as osas_status,
                   adviser_approval_at,
                   adviser_rejected_at,
                   osas_approved_at,
                   osas_rejected_at
            FROM council_documents 
            WHERE council_id = ? 
            ORDER BY submitted_at DESC
        ");
        $stmt->bind_param("i", $council['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $councilStatus = [];
        while ($row = $result->fetch_assoc()) {
            $councilStatus[$row['document_type']] = $row['osas_status'];
        }
        $councilDocumentStatus[$council['id']] = $councilStatus;
    }
}

// Set the appropriate document types and data based on selection
$baseDocumentTypes = $selectedDocumentType === 'organization' ? $organizationDocumentTypes : $councilDocumentTypes;
$entities = $selectedDocumentType === 'organization' ? $organizations : $councils;
$documentStatus = $selectedDocumentType === 'organization' ? $organizationDocumentStatus : $councilDocumentStatus;
$entityNameField = $selectedDocumentType === 'organization' ? 'org_name' : 'council_name';

// Calculate overall statistics - ONLY based on OSAS actions
$totalEntities = count($entities);
// Total documents will be computed per-entity because doc set depends on type
$totalDocuments = 0;
$approvedCount = 0;
$rejectedCount = 0;
$notSubmittedCount = 0;

foreach ($entities as $entity) {
    // Build per-entity document types: hide 3 docs when type is 'new'
    $documentTypes = $baseDocumentTypes;
    if (($entity['type'] ?? 'old') === 'new') {
        unset($documentTypes['accomplishment_report']);
        unset($documentTypes['previous_plan_of_activities']);
        unset($documentTypes['financial_report']);
    }
    $totalDocuments += count($documentTypes);
    foreach ($documentTypes as $type => $label) {
        $status = $documentStatus[$entity['id']][$type] ?? 'not_submitted';
        switch ($status) {
            case 'approved':
                $approvedCount++;
                break;
            case 'rejected':
                $rejectedCount++;
                break;
            default:
                $notSubmittedCount++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Status - OSAS Portal</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --mono-gray-800: #1f2937;
            --mono-gray-700: #374151;
            --mono-gray-600: #4b5563;
            --mono-gray-500: #6b7280;
        }
        /* Main styling */
        main {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            margin-left: 250px;
            margin-top: 20px;
            padding: 20px;
            transition: all 0.3s;
        }
        
        main.expanded {
            margin-left: 0;
        }
        
        /* Page header styling */
        main h2 {
            color: #343a40;
            font-weight: 700;
            margin-bottom: 1.5rem;
        }
        
        /* Enhanced card styling */
        main .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            background: #ffffff;
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        
        main .card:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.12);
        }
        
        /* Card header styling (monochrome) */
        main .card-header {
            background: var(--mono-gray-600);
            color: white;
            border: none;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }
        
        main .card-header h3 {
            margin: 0;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* Form styling */
        main .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        main .form-control,
        main .form-select {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        main .form-control:focus,
        main .form-select:focus {
            border-color: var(--mono-gray-600);
            box-shadow: 0 0 0 0.2rem rgba(75, 85, 99, 0.2);
        }
        
        /* Compact statistics */
        .stats-summary {
            background: #ffffff;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }
        
        .stats-item {
            display: inline-flex;
            align-items: center;
            margin-right: 2rem;
            font-size: 0.9rem;
        }
        
        .stats-item:last-child {
            margin-right: 0;
        }
        
        .stats-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            margin-right: 0.5rem;
            color: white;
        }
        
        .stats-icon.approved {
            background: #28a745;
        }
        
        .stats-icon.rejected {
            background: #dc3545;
        }
        
        .stats-icon.not-submitted {
            background: #6c757d;
        }
        
        /* Entity list */
        .entity-list {
            max-height: 600px;
            overflow-y: auto;
        }
        
        .entity-item {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .entity-item:hover {
            border-color: #495057;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .entity-header {
            background: #f8f9fa;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #e9ecef;
            border-radius: 8px 8px 0 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .entity-header:hover {
            background: #e9ecef;
        }
        
        .entity-header.expanded {
            background: var(--mono-gray-600);
            color: white;
        }
        
        .entity-title {
            font-weight: 600;
            color: #343a40;
            font-size: 1rem;
            margin: 0;
        }
        
        .entity-header.expanded .entity-title {
            color: white;
        }
        
        .entity-college {
            color: #6c757d;
            font-size: 0.85rem;
            margin: 0;
        }
        
        .entity-header.expanded .entity-college {
            color: #dee2e6;
        }
        
        .completion-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .completion-badge.complete {
            background: #e5e7eb; /* light gray */
            color: var(--mono-gray-800);
        }
        
        .completion-badge.partial {
            background: #fff3cd;
            color: #856404;
        }
        
        .completion-badge.incomplete {
            background: #f8d7da;
            color: #721c24;
        }
        
        .entity-header.expanded .completion-badge.complete {
            background: var(--mono-gray-500);
            color: white;
        }
        
        .entity-header.expanded .completion-badge.partial {
            background: #ffc107;
            color: #212529;
        }
        
        .entity-header.expanded .completion-badge.incomplete {
            background: #dc3545;
            color: white;
        }
        
        /* Expand/collapse icon */
        .expand-icon {
            margin-left: 0.75rem;
            transition: transform 0.3s ease;
        }
        
        .entity-header.expanded .expand-icon {
            transform: rotate(180deg);
        }
        
        /* Document content */
        .document-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .document-content.expanded {
            max-height: 1000px;
        }
        
        .document-checklist {
            padding: 1rem;
        }
        
        .document-row {
            display: flex;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .document-row:last-child {
            border-bottom: none;
        }
        
        .document-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .document-icon.approved {
            background: #d4edda;
            color: #155724;
        }
        
        .document-icon.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .document-icon.not-submitted {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .document-info {
            flex: 1;
            min-width: 0;
        }
        
        .document-name {
            font-weight: 500;
            color: #343a40;
            font-size: 0.9rem;
            margin: 0;
            line-height: 1.3;
        }
        
        .document-status {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }
        
        .document-status.approved { color: var(--mono-gray-700); }
        
        .document-status.rejected {
            color: #721c24;
        }
        
        .document-status.not-submitted {
            color: #6c757d;
        }
        
        /* Status indicator */
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-left: 0.75rem;
            flex-shrink: 0;
        }
        
        .status-indicator.approved { background: var(--mono-gray-600); }
        
        .status-indicator.rejected {
            background: #dc3545;
        }
        
        .status-indicator.not-submitted {
            background: #6c757d;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            main {
                margin-left: 0;
                padding: 15px;
            }
            
            .entity-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .completion-badge {
                margin-top: 0.5rem;
            }
            
            .stats-item {
                margin-right: 1rem;
                margin-bottom: 0.5rem;
            }
            
            .document-row {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .document-icon {
                margin-bottom: 0.5rem;
            }
            
            .status-indicator {
                margin-left: 0;
                margin-top: 0.5rem;
            }
        }
        
        /* Pagination styling aligned with theme */
        .pagination .page-link {
            color: #495057;
            border: 1px solid #dee2e6;
            background: #ffffff;
            font-weight: 600;
        }
        .pagination .page-link:hover {
            color: #ffffff;
            background: var(--mono-gray-600);
            border-color: var(--mono-gray-600);
        }
        .pagination .page-item.active .page-link {
            color: #ffffff;
            background: var(--mono-gray-700);
            border-color: var(--mono-gray-700);
            box-shadow: 0 2px 8px rgba(55, 65, 81, 0.3);
        }
        .pagination .page-item.disabled .page-link {
            color: #adb5bd;
            background: #f8f9fa;
            border-color: #dee2e6;
        }

        /* Scrollbar styling */
        .entity-list::-webkit-scrollbar {
            width: 6px;
        }
        
        .entity-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .entity-list::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .entity-list::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <main>
        <div class="container-fluid">
            <h2 class="mb-2">Document Status</h2>
            <p class="text-muted mb-4">View and track OSAS review status for required <?php echo $selectedDocumentType === 'organization' ? 'organization' : 'council'; ?> documents across all colleges.</p>
            
            <!-- Filters -->
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title h6 mb-0">DOCUMENT STATUS FILTERS</h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="college" class="form-label">Filter by College</label>
                            <select class="form-select" id="college" name="college" onchange="this.form.submit()">
                                <option value="0">All Colleges</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?php echo $college['id']; ?>" <?php echo $selectedCollege == $college['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($college['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="document_type" class="form-label">Document Type</label>
                            <select class="form-select" id="document_type" name="document_type" onchange="this.form.submit()">
                                <option value="organization" <?php echo $selectedDocumentType === 'organization' ? 'selected' : ''; ?>>Organization Documents</option>
                                <option value="council" <?php echo $selectedDocumentType === 'council' ? 'selected' : ''; ?>>Council Documents</option>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Quick Statistics -->
            <div class="stats-summary">
                <div class="stats-item">
                    <div class="stats-icon approved">
                        <i class="fas fa-check"></i>
                    </div>
                    <span><strong><?php echo $approvedCount; ?></strong> Approved</span>
                </div>
                <div class="stats-item">
                    <div class="stats-icon rejected">
                        <i class="fas fa-times"></i>
                    </div>
                    <span><strong><?php echo $rejectedCount; ?></strong> Rejected</span>
                </div>
                <div class="stats-item">
                    <div class="stats-icon not-submitted">
                        <i class="fas fa-minus"></i>
                    </div>
                    <span><strong><?php echo $notSubmittedCount; ?></strong> Not Yet Reviewed</span>
                </div>
            </div>

            <!-- Entity List -->
            <?php if (empty($entities)): ?>
                <div class="card">
                    <div class="card-body text-center py-4">
                        <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                        <h6 class="text-muted">No <?php echo $selectedDocumentType; ?>s Found</h6>
                        <p class="text-muted small">No <?php echo $selectedDocumentType; ?>s match the current filter criteria.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="entity-list">
                    <?php 
                        $totalPages = max(1, (int)ceil(count($entities) / $perPage));
                        $startIndex = ($page - 1) * $perPage;
                        $pagedEntities = array_slice($entities, $startIndex, $perPage);
                    ?>
                    <?php foreach ($pagedEntities as $index => $entity): ?>
                        <?php
                        // Calculate completion for this entity - ONLY based on OSAS actions
                        $documentTypes = $baseDocumentTypes;
                        if (($entity['type'] ?? 'old') === 'new') {
                            unset($documentTypes['accomplishment_report']);
                            unset($documentTypes['previous_plan_of_activities']);
                            unset($documentTypes['financial_report']);
                        }
                        $totalDocs = count($documentTypes);
                        $approvedDocs = 0;
                        $rejectedDocs = 0;
                        $notSubmittedDocs = 0;
                        
                        foreach ($documentTypes as $type => $label) {
                            $status = $documentStatus[$entity['id']][$type] ?? 'not_submitted';
                            switch ($status) {
                                case 'approved':
                                    $approvedDocs++;
                                    break;
                                case 'rejected':
                                    $rejectedDocs++;
                                    break;
                                default:
                                    $notSubmittedDocs++;
                            }
                        }
                        
                        $completionPercentage = $totalDocs > 0 ? round(($approvedDocs / $totalDocs) * 100) : 0;
                        $completionClass = $completionPercentage >= 80 ? 'complete' : ($completionPercentage >= 50 ? 'partial' : 'incomplete');
                        ?>
                        <div class="entity-item">
                            <div class="entity-header" onclick="toggleEntity(<?php echo $startIndex + $index; ?>)">
                                <div>
                                    <h5 class="entity-title"><?php echo htmlspecialchars($entity[$entityNameField]); ?></h5>
                                    <p class="entity-college">
                                        <i class="fas fa-graduation-cap me-1"></i>
                                        <?php echo htmlspecialchars($entity['college_name'] ?? 'N/A'); ?>
                                    </p>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="completion-badge <?php echo $completionClass; ?>">
                                        <?php echo $completionPercentage; ?>% Complete
                                    </div>
                                    <i class="fas fa-chevron-down expand-icon"></i>
                                </div>
                            </div>
                            
                            <div class="document-content" id="entity-content-<?php echo $startIndex + $index; ?>">
                                <div class="document-checklist">
                                    <?php foreach ($documentTypes as $type => $label): ?>
                                        <?php
                                        $status = $documentStatus[$entity['id']][$type] ?? 'not_submitted';
                                        $iconMap = [
                                            'adviser_resume' => 'fa-user-tie',
                                            'student_profile' => 'fa-user',
                                            'officers_list' => 'fa-users',
                                            'calendar_activities' => 'fa-calendar',
                                            'official_logo' => 'fa-image',
                                            'officers_grade' => 'fa-certificate',
                                            'group_picture' => 'fa-camera',
                                            'constitution_bylaws' => 'fa-book',
                                            'members_list' => 'fa-list',
                                            'good_moral' => 'fa-award',
                                            'adviser_acceptance' => 'fa-envelope',
                                            'budget_resolution' => 'fa-money-bill',
                                            'other' => 'fa-file'
                                        ];
                                        $icon = $iconMap[$type] ?? 'fa-file';
                                        ?>
                                        <div class="document-row">
                                            <div class="document-icon <?php echo $status; ?>">
                                                <i class="fas <?php echo $icon; ?>"></i>
                                            </div>
                                            <div class="document-info">
                                                <p class="document-name"><?php echo htmlspecialchars($label); ?></p>
                                                <p class="document-status <?php echo $status; ?>">
                                                    <?php
                                                    switch ($status) {
                                                        case 'approved':
                                                            echo 'Approved';
                                                            break;
                                                        case 'rejected':
                                                            echo 'Rejected';
                                                            break;
                                                        default:
                                                            echo 'Not Yet Reviewed';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                            <div class="status-indicator <?php echo $status; ?>"></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($totalPages > 1): ?>
                    <nav class="mt-3">
                        <ul class="pagination pagination-sm">
                            <?php 
                            $qs = $_GET; 
                            for ($p = 1; $p <= $totalPages; $p++): 
                                $qs['page'] = $p;
                                $url = 'osas_document_status.php?' . http_build_query($qs);
                            ?>
                                <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo $url; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleEntity(index) {
            const header = event.currentTarget;
            const content = document.getElementById(`entity-content-${index}`);
            
            // Toggle expanded class on header
            header.classList.toggle('expanded');
            
            // Toggle expanded class on content
            content.classList.toggle('expanded');
            
            // Update expand icon
            const icon = header.querySelector('.expand-icon');
            if (header.classList.contains('expanded')) {
                icon.style.transform = 'rotate(180deg)';
            } else {
                icon.style.transform = 'rotate(0deg)';
            }
        }
        
        // Optional: Add keyboard support for accessibility
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                const focusedElement = document.activeElement;
                if (focusedElement && focusedElement.classList.contains('entity-header')) {
                    event.preventDefault();
                    focusedElement.click();
                }
            }
        });
    </script>
</body>
</html> 
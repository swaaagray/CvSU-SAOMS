<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : 0;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : 0;
$ownerId = $orgId ?: $councilId;
$message = '';
$error = '';

// Recognition guard (mirror events.php/awards.php)
$isRecognized = false;
$recognitionNotification = null;
if ($role === 'org_president' && $orgId) {
    $stmt = $conn->prepare("SELECT status FROM organizations WHERE id = ?");
    $stmt->bind_param("i", $orgId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($org = $result->fetch_assoc()) {
        $isRecognized = ($org['status'] === 'recognized');
        if (!$isRecognized) {
            $recognitionNotification = [
                'type' => 'organization',
                'message' => 'This Organization is not yet recognized. Please go to the Organization Documents section and complete the required submissions listed there.',
                'message_html' => 'This Organization is not yet recognized. Please go to the <a href="organization_documents.php" class="alert-link fw-bold">Organization Documents</a> section and complete the required submissions listed there.'
            ];
        }
    }
} elseif ($role === 'council_president' && $councilId) {
    $stmt = $conn->prepare("SELECT status FROM council WHERE id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($council = $result->fetch_assoc()) {
        $isRecognized = ($council['status'] === 'recognized');
        if (!$isRecognized) {
            $recognitionNotification = [
                'type' => 'council',
                'message' => 'This Council is not yet recognized. Please go to the Council Documents section and complete the required submissions listed there.',
                'message_html' => 'This Council is not yet recognized. Please go to the <a href="council_documents.php" class="alert-link fw-bold">Council Documents</a> section and complete the required submissions listed there.'
            ];
        }
    }
}

// Get filter parameters
$selectedYear = $_GET['year'] ?? '';
$selectedMonth = $_GET['month'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($selectedYear) || !empty($selectedMonth);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Get approved event proposals (where all documents are approved by OSAS)
$approvedEvents = [];
if ($orgId) {
    // Query for organization events where all documents are approved
    $eventsQuery = "
        SELECT ea.id, ea.title, ea.venue, ea.created_at,
               (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = ea.id) as total_docs,
               (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = ea.id AND osas_approved_at IS NOT NULL) as approved_docs
        FROM event_approvals ea
        WHERE ea.organization_id = ?
        HAVING total_docs > 0 AND approved_docs = total_docs
        ORDER BY ea.created_at DESC
    ";
    $eventsStmt = $conn->prepare($eventsQuery);
    if ($eventsStmt) {
        $eventsStmt->bind_param("i", $orgId);
        $eventsStmt->execute();
        $eventsResult = $eventsStmt->get_result();
        while ($event = $eventsResult->fetch_assoc()) {
            $approvedEvents[] = $event;
        }
        $eventsStmt->close();
    }
} elseif ($councilId) {
    // Query for council events where all documents are approved
    $eventsQuery = "
        SELECT ea.id, ea.title, ea.venue, ea.created_at,
               (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = ea.id) as total_docs,
               (SELECT COUNT(*) FROM event_documents WHERE event_approval_id = ea.id AND osas_approved_at IS NOT NULL) as approved_docs
        FROM event_approvals ea
        WHERE ea.council_id = ?
        HAVING total_docs > 0 AND approved_docs = total_docs
        ORDER BY ea.created_at DESC
    ";
    $eventsStmt = $conn->prepare($eventsQuery);
    if ($eventsStmt) {
        $eventsStmt->bind_param("i", $councilId);
        $eventsStmt->execute();
        $eventsResult = $eventsStmt->get_result();
        while ($event = $eventsResult->fetch_assoc()) {
            $approvedEvents[] = $event;
        }
        $eventsStmt->close();
    }
}

// Function to handle date formatting and validation
function formatDateForInput($date) {
    if (empty($date) || $date === '0000-00-00' || $date === '-0001-11-30') {
        return date('Y-m-d');
    }
    
    $date_parts = explode('-', $date);
    if (count($date_parts) === 3) {
        $year = $date_parts[0];
        $month = $date_parts[1];
        $day = $date_parts[2];
        if (checkdate($month, $day, $year)) {
            return $date;
        }
    }
    return date('Y-m-d');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'] ?? 'Not recognized.';
        header('Location: financial.php');
        exit;
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $event_name = $_POST['event_name'] ?? '';
                // Handle custom event name if "__custom__" is selected
                if ($event_name === '__custom__') {
                    $event_name = $_POST['event_name_custom'] ?? '';
                }
                $expenses = $_POST['expenses'] ?? 0;
                $revenue = $_POST['revenue'] ?? 0;
                $report_date = formatDateForInput($_POST['report_date'] ?? date('Y-m-d'));
                
                // Validate date is within active academic year
                $dateValidation = validateDateWithinAcademicYear($conn, $report_date);
                if (!$dateValidation['valid']) {
                    $_SESSION['error'] = $dateValidation['error'];
                    header('Location: financial.php');
                    exit;
                }
                
                if (!empty($event_name)) {
                    $turnover = $revenue - $expenses;
                    $balance = $revenue - $expenses;
                    
                    // Insert with correct ownership (organization or council)
                    // Use NULL for the non-applicable owner to satisfy foreign key constraints
                    $orgIdParam = $orgId ? $orgId : null;
                    $councilIdParam = $councilId ? $councilId : null;
                    $semesterId = getCurrentActiveSemesterId($conn);
                    if (!$semesterId) {
                        $_SESSION['error'] = 'No active semester found. Please contact OSAS.';
                        header('Location: financial.php');
                        exit;
                    }
                    $stmt = $conn->prepare("INSERT INTO financial_reports (organization_id, council_id, semester_id, title, expenses, revenue, turnover, balance, report_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iiisdddds", $orgIdParam, $councilIdParam, $semesterId, $event_name, $expenses, $revenue, $turnover, $balance, $report_date);
                    
                    if ($stmt->execute()) {
                        $reportId = $stmt->insert_id;
                        
                        // Handle file upload
                        if (!empty($_FILES['financial_file']['name'])) {
                            $file_name = $_FILES['financial_file']['name'];
                            $file_size = $_FILES['financial_file']['size'];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            
                            // Validate file size (10MB max)
                            if ($file_size > 10 * 1024 * 1024) {
                                $_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
                                header('Location: financial.php');
                                exit;
                            }
                            
                            if (in_array($file_ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx'])) {
                                // Additional MIME type validation
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $mime = $finfo ? finfo_file($finfo, $_FILES['financial_file']['tmp_name']) : '';
                                if ($finfo) { finfo_close($finfo); }
                                
                                $allowed_mimes = [
                                    'application/pdf',
                                    'application/msword',
                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                    'application/vnd.ms-excel',
                                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                                ];
                                
                                if (!in_array($mime, $allowed_mimes)) {
                                    $_SESSION['error'] = 'Invalid file type detected';
                                    header('Location: financial.php');
                                    exit;
                                }
                                
                                // Preserve original filename but ensure uniqueness
                                $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                                $counter = 1;
                                $new_name = $original_name . '.' . $file_ext;
                                $upload_dir = './uploads/financial';
                                
                                // If file already exists, add a number suffix
                                while (file_exists($upload_dir . '/' . $new_name)) {
                                    $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                    $counter++;
                                }
                                
                                if (!file_exists($upload_dir)) {
                                    if (!mkdir($upload_dir, 0777, true)) {
                                        $_SESSION['error'] = 'Failed to create upload directory';
                                        header('Location: financial.php');
                                        exit;
                                    }
                                }
                                
                                $upload_path = $upload_dir . '/' . $new_name;
                                
                                if (move_uploaded_file($_FILES['financial_file']['tmp_name'], $upload_path)) {
                                    $stmt = $conn->prepare("UPDATE financial_reports SET file_path = ? WHERE id = ?");
                                    $stmt->bind_param("si", $upload_path, $reportId);
                                    if (!$stmt->execute()) {
                                        $_SESSION['error'] = 'Error updating file path: ' . $stmt->error;
                                        header('Location: financial.php');
                                        exit;
                                    }
                                } else {
                                    $_SESSION['error'] = 'Error uploading file';
                                    header('Location: financial.php');
                                    exit;
                                }
                            } else {
                                $_SESSION['error'] = 'Invalid file format';
                                header('Location: financial.php');
                                exit;
                            }
                        }
                        
                        $_SESSION['message'] = 'Financial report added successfully!';
                        header('Location: financial.php');
                        exit;
                    } else {
                        $_SESSION['error'] = 'Error adding financial report: ' . $stmt->error;
                        header('Location: financial.php');
                        exit;
                    }
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header('Location: financial.php');
                    exit;
                }
                break;
                
            case 'edit':
                $reportId = $_POST['report_id'] ?? 0;
                $event_name = $_POST['event_name'] ?? '';
                // Handle custom event name if "__custom__" is selected
                if ($event_name === '__custom__') {
                    $event_name = $_POST['event_name_custom'] ?? '';
                }
                $expenses = $_POST['expenses'] ?? 0;
                $revenue = $_POST['revenue'] ?? 0;
                
                // Get the current report data scoped by owner
                $ownerColumn = $orgId ? 'organization_id' : 'council_id';
                $stmt = $conn->prepare("SELECT * FROM financial_reports WHERE id = ? AND $ownerColumn = ?");
                $stmt->bind_param("ii", $reportId, $ownerId);
                $stmt->execute();
                $result = $stmt->get_result();
                $currentReport = $result->fetch_assoc();
                
                if (!$currentReport) {
                    $_SESSION['error'] = 'Report not found or you do not have permission to edit it';
                    header('Location: financial.php');
                    exit;
                }
                
                // Keep the original date by default
                $report_date = $currentReport['report_date'];
                
                // Only update the date if a new valid date was provided and it's different from the current date
                $new_date = $_POST['report_date'] ?? '';
                if (!empty($new_date) && $new_date !== $currentReport['report_date']) {
                    $date_parts = explode('-', $new_date);
                    if (count($date_parts) === 3) {
                        $year = $date_parts[0];
                        $month = $date_parts[1];
                        $day = $date_parts[2];
                        if (checkdate($month, $day, $year)) {
                            // Validate date is within active academic year
                            $dateValidation = validateDateWithinAcademicYear($conn, $new_date);
                            if (!$dateValidation['valid']) {
                                $_SESSION['error'] = $dateValidation['error'];
                                header('Location: financial.php');
                                exit;
                            }
                            $report_date = $new_date;
                        }
                    }
                }
                
                if (!empty($event_name) && $reportId) {
                    $turnover = $revenue - $expenses;
                    $balance = $revenue - $expenses;
                    
                    // Format the date for MySQL
                    $formatted_date = date('Y-m-d', strtotime($report_date));
                    
                    // Update financial report scoped by owner
                    $ownerColumn = $orgId ? 'organization_id' : 'council_id';
                    $stmt = $conn->prepare("UPDATE financial_reports SET title = ?, expenses = ?, revenue = ?, turnover = ?, balance = ?, report_date = ? WHERE id = ? AND $ownerColumn = ?");
                    if (!$stmt) {
                        $_SESSION['error'] = 'Database error';
                        header('Location: financial.php');
                        exit;
                    }
                    
                    $stmt->bind_param("sddddsii", $event_name, $expenses, $revenue, $turnover, $balance, $formatted_date, $reportId, $ownerId);
                    if (!$stmt->execute()) {
                        $_SESSION['error'] = 'Error updating financial report: ' . $stmt->error;
                        header('Location: financial.php');
                        exit;
                    }
                    
                    // Handle file upload if a new file is provided
                    if (!empty($_FILES['financial_file']['name'])) {
                        $file_name = $_FILES['financial_file']['name'];
                        $file_size = $_FILES['financial_file']['size'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        
                        // Validate file size (10MB max)
                        if ($file_size > 10 * 1024 * 1024) {
                            $_SESSION['error'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
                            header('Location: financial.php');
                            exit;
                        }
                        
                        if (in_array($file_ext, ['pdf', 'doc', 'docx', 'xls', 'xlsx'])) {
                            // Additional MIME type validation
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime = $finfo ? finfo_file($finfo, $_FILES['financial_file']['tmp_name']) : '';
                            if ($finfo) { finfo_close($finfo); }
                            
                            $allowed_mimes = [
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                            ];
                            
                            if (!in_array($mime, $allowed_mimes)) {
                                $_SESSION['error'] = 'Invalid file type detected';
                                header('Location: financial.php');
                                exit;
                            }
                            
                            // Preserve original filename but ensure uniqueness
                            $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                            $counter = 1;
                            $new_name = $original_name . '.' . $file_ext;
                            $upload_dir = './uploads/financial';
                            
                            // If file already exists, add a number suffix
                            while (file_exists($upload_dir . '/' . $new_name)) {
                                $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                $counter++;
                            }
                            
                            if (!file_exists($upload_dir)) {
                                if (!mkdir($upload_dir, 0777, true)) {
                                    $_SESSION['error'] = 'Failed to create upload directory';
                                    header('Location: financial.php');
                                    exit;
                                }
                            }
                            
                            $upload_path = $upload_dir . '/' . $new_name;
                            
                            if (move_uploaded_file($_FILES['financial_file']['tmp_name'], $upload_path)) {
                                // Delete old file if exists
                                $stmt = $conn->prepare("SELECT file_path FROM financial_reports WHERE id = ?");
                                $stmt->bind_param("i", $reportId);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $oldFile = $result->fetch_assoc();
                                
                                if ($oldFile && $oldFile['file_path'] && file_exists($oldFile['file_path'])) {
                                    unlink($oldFile['file_path']);
                                }
                                
                                // Update file path
                                $stmt = $conn->prepare("UPDATE financial_reports SET file_path = ? WHERE id = ?");
                                $stmt->bind_param("si", $upload_path, $reportId);
                                if (!$stmt->execute()) {
                                    $_SESSION['error'] = 'Error updating file path: ' . $stmt->error;
                                    header('Location: financial.php');
                                    exit;
                                }
                            } else {
                                $_SESSION['error'] = 'Error uploading file';
                                header('Location: financial.php');
                                exit;
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid file format';
                            header('Location: financial.php');
                            exit;
                        }
                    }
                    
                    $_SESSION['message'] = 'Financial report updated successfully!';
                    header('Location: financial.php');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header('Location: financial.php');
                    exit;
                }
                break;
                
            case 'delete':
                $reportId = $_POST['report_id'] ?? 0;
                if ($reportId) {
                    // Get file path before deleting scoped by owner
                    $ownerColumn = $orgId ? 'organization_id' : 'council_id';
                    $stmt = $conn->prepare("SELECT file_path FROM financial_reports WHERE id = ? AND $ownerColumn = ?");
                    $stmt->bind_param("ii", $reportId, $ownerId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $report = $result->fetch_assoc();
                    
                    // Delete file if exists
                    if ($report && $report['file_path'] && file_exists($report['file_path'])) {
                        unlink($report['file_path']);
                    }
                    
                    // Delete financial report scoped by owner
                    $stmt = $conn->prepare("DELETE FROM financial_reports WHERE id = ? AND $ownerColumn = ?");
                    $stmt->bind_param("ii", $reportId, $ownerId);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Financial report deleted successfully!';
                        header('Location: financial.php');
                        exit;
                    } else {
                        $_SESSION['error'] = 'Error deleting financial report';
                        header('Location: financial.php');
                        exit;
                    }
                }
                break;
        }
    }
}

// Get financial reports with filtering for the correct owner (organization or council)
$ownerColumn = $orgId ? 'organization_id' : 'council_id';
$query = "SELECT fr.* FROM financial_reports fr 
JOIN academic_semesters s ON fr.semester_id = s.id 
JOIN academic_terms at ON s.academic_term_id = at.id 
WHERE fr.$ownerColumn = ? AND $statusCondition";
$params = [$ownerId];
$types = "i";

if ($selectedYear && $selectedMonth) {
    $query .= " AND YEAR(report_date) = ? AND MONTH(report_date) = ?";
    $params[] = (int)$selectedYear;
    $params[] = (int)$selectedMonth;
    $types .= "ii";
} elseif ($selectedYear) {
    $query .= " AND YEAR(report_date) = ?";
    $params[] = (int)$selectedYear;
    $types .= "i";
} elseif ($selectedMonth) {
    $query .= " AND MONTH(report_date) = ?";
    $params[] = (int)$selectedMonth;
    $types .= "i";
}
$query .= " ORDER BY report_date DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $reports = [];
}

// Get overall totals for the graph scoped by owner
$overallQuery = "SELECT 
    SUM(fr.revenue) as total_revenue,
    SUM(fr.expenses) as total_expenses,
    SUM(fr.turnover) as total_turnover
FROM financial_reports fr
JOIN academic_semesters s ON fr.semester_id = s.id 
JOIN academic_terms at ON s.academic_term_id = at.id 
WHERE fr.$ownerColumn = ? AND $statusCondition";
$params = [$ownerId];
$types = "i";

if ($selectedYear && $selectedMonth) {
    $overallQuery .= " AND YEAR(report_date) = ? AND MONTH(report_date) = ?";
    $params[] = (int)$selectedYear;
    $params[] = (int)$selectedMonth;
    $types .= "ii";
} elseif ($selectedYear) {
    $overallQuery .= " AND YEAR(report_date) = ?";
    $params[] = (int)$selectedYear;
    $types .= "i";
} elseif ($selectedMonth) {
    $params[] = (int)$selectedMonth;
    $types .= "i";
}

$stmt = $conn->prepare($overallQuery);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $overallTotals = $stmt->get_result()->fetch_assoc();
} else {
    $overallTotals = ['total_revenue' => 0, 'total_expenses' => 0, 'total_turnover' => 0];
}

// Calculate total statistics
$totalExpenses = 0;
$totalRevenue = 0;
$totalTurnover = 0;
$totalBalance = 0;
foreach ($reports as $report) {
    $totalExpenses += $report['expenses'];
    $totalRevenue += $report['revenue'];
    $totalTurnover += $report['turnover'];
    $totalBalance += $report['balance'];
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
    <title>Financial Management - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        
        /* Button styling */
        /* Unified light main action button */
        .main-action,
        .main-action:link,
        .main-action:visited,
        .main-action:focus,
        .main-action:active,
        .main-action:hover {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #495057 !important;
            text-decoration: none !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }

        .main-action:hover {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }

        .main-action:focus {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
        }

        .main-action:active {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
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
        
        /* Action buttons - KEEP ORIGINAL COLORS */
        .btn-info {
            background: linear-gradient(45deg, #20c997);
            border: none;
            color: white;
            font-weight: 500;
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #1aa179);
            color: white;
            transform: scale(1.05);
        }
        
        /* Edit button styling - Orange (only for table action buttons with pencil icon) */
        .table .btn-primary {
            background: linear-gradient(45deg, #fd7e14, #ff922b) !important;
            border: none !important;
            color: white !important;
            font-weight: 500;
        }
        
        .table .btn-primary:hover {
            background: linear-gradient(45deg, #e8690f, #e8690f) !important;
            color: white !important;
            transform: scale(1.05);
        }
        
        .table .btn-primary:focus {
            background: linear-gradient(45deg, #e8690f, #e8690f) !important;
            color: white !important;
            box-shadow: 0 0 0 0.2rem rgba(253, 126, 20, 0.25) !important;
        }
        
        .table .btn-primary:active {
            background: linear-gradient(45deg, #e8690f, #e8690f) !important;
            color: white !important;
        }
        
        .btn-danger {
            background: linear-gradient(45deg, #dc3545, #e55563);
            border: none;
            font-weight: 500;
        }
        
        .btn-danger:hover {
            background: linear-gradient(45deg, #c02a37, #d63447);
            transform: scale(1.05);
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
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Filter card styling */
        .card:first-child {
            border-left-color: #6c757d !important;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        /* Financial summary cards - KEEP ORIGINAL COLORS */
        .card.bg-danger {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
            border-left-color: #dc3545;
        }
        
        .card.bg-success {
            background: linear-gradient(135deg, #198754, #146c43) !important;
            border-left-color: #198754;
        }
        
        .card.bg-info {
            background: linear-gradient(135deg, #fd7e14, #e8690f) !important;
            border-left-color: #fd7e14;
        }
        
        .card.bg-primary {
            background: linear-gradient(135deg, #6c757d, #495057) !important;
            border-left-color: #6c757d;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .card-title {
            color: #212529;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 1rem;
        }
        .card-header .card-title{ color: #fff;}
        
        .card.bg-danger .card-title,
        .card.bg-success .card-title,
        .card.bg-info .card-title,
        .card.bg-primary .card-title {
            color: white !important;
            font-weight: 600;
        }
        
        .card.bg-danger .card-text,
        .card.bg-success .card-text,
        .card.bg-info .card-text,
        .card.bg-primary .card-text {
            color: white !important;
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        /* Chart card styling */
        .card:has(#financialChart) {
            border-left-color: #495057;
        }
        
        .card-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
            padding: 1rem 1.5rem;
        }
        
        /* Table styling */
        .table-responsive {
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            background: white;
        }
        
        .table {
            margin-bottom: 0;
            border-radius: 15px;
            overflow: hidden;
        }
        
        .table thead th {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(108, 117, 125, 0.1) 100%);
            color: #495057;
            font-weight: 600;
            border: none;
            padding: 1rem;
            font-size: 0.9rem;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:nth-child(odd) {
            background: rgba(52, 58, 64, 0.03);
        }
        
        .table tbody tr:nth-child(even) {
            background: rgba(108, 117, 125, 0.03);
        }
        
        .table tbody tr:hover {
            background: rgba(73, 80, 87, 0.1);
            transform: scale(1.01);
        }
        
        .table td {
            padding: 1rem;
            border-color: rgba(0, 0, 0, 0.05);
            vertical-align: middle;
            color: #212529;
        }
        
        /* Text color styling - KEEP ORIGINAL COLORS */
        .text-danger {
            color: #dc3545 !important;
            font-weight: 600;
        }
        
        .text-success {
            color: #198754 !important;
            font-weight: 600;
        }
        
        /* Alert styling */
        .alert-success {
            background: linear-gradient(90deg, rgba(73, 80, 87, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border: 1px solid rgba(73, 80, 87, 0.3);
            color: #495057;
            border-radius: 10px;
        }
        
        .alert-danger {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(248, 249, 250, 1) 100%);
            border: 1px solid rgba(52, 58, 64, 0.3);
            color: #495057;
            border-radius: 10px;
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 1.5rem;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 2rem;
            background: #fafafa;
        }
        
        /* Form styling */
        .form-label {
            color: #495057;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control,
        .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus,
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
            transform: translateY(-1px);
        }
        
        .form-control:hover,
        .form-select:hover {
            border-color: #6c757d;
        }
        
        /* Input group styling */
        .input-group-text {
            background: linear-gradient(45deg, #495057, #6c757d);
            color: white;
            border: none;
            font-weight: 600;
        }
        
        /* Button group styling */
        .btn-group {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        
        /* Enhanced hover effects */
        .btn-sm {
            transition: all 0.2s ease;
            border-radius: 6px;
        }
        
        .btn-sm:hover {
            transform: scale(1.05);
        }
        
        /* Loading spinner */
        .spinner-border {
            color: #495057;
        }
        
        /* Custom scrollbar */
        .modal-body {
            scrollbar-width: thin;
            scrollbar-color: #495057 #f8f9fa;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 6px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: #f8f9fa;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: #495057;
            border-radius: 3px;
        }
        
        /* Financial summary cards animation */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card.bg-danger,
        .card.bg-success,
        .card.bg-info,
        .card.bg-primary {
            animation: slideInUp 0.6s ease forwards;
        }
        
        .card.bg-success {
            animation-delay: 0.1s;
        }
        
        .card.bg-info {
            animation-delay: 0.2s;
        }
        
        .card.bg-primary {
            animation-delay: 0.3s;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
            }
            
            .table td,
            .table th {
                padding: 0.75rem 0.5rem;
                font-size: 0.875rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                border-radius: 6px !important;
                margin-bottom: 0.25rem;
            }
            
            .card.bg-danger .card-text,
            .card.bg-success .card-text,
            .card.bg-info .card-text,
            .card.bg-primary .card-text {
                font-size: 1.25rem;
            }
        }
        
        /* Enhanced table row styling */
        .table tbody tr {
            border-left: 3px solid transparent;
        }
        
        .table tbody tr:nth-child(4n+1) {
            border-left-color: #495057;
        }
        
        .table tbody tr:nth-child(4n+2) {
            border-left-color: #6c757d;
        }
        
        .table tbody tr:nth-child(4n+3) {
            border-left-color: #868e96;
        }
        
        .table tbody tr:nth-child(4n+4) {
            border-left-color: #343a40;
        }
        
        /* File link styling */
        .table a.btn-info {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        
        /* Text muted styling */
        .text-muted {
            color: #6c757d !important;
            font-style: italic;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="page-title">Financial Management</h2>
                        <p class="page-subtitle">Track and manage your organization's financial reports</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-light main-action" data-bs-toggle="modal" data-bs-target="#addReportModal" <?php echo !$isRecognized ? 'disabled' : ''; ?> >
                            <i class="bi bi-plus-circle"></i> Add New Report
                        </button>
                    </div>
                </div>
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

            <?php if (!$isRecognized && $recognitionNotification): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start">
                        <div class="me-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="alert-heading mb-2">
                                <i class="fas fa-bell me-2"></i>
                                Recognition Required
                            </h5>
                            <p class="mb-0">
                                <?php echo isset($recognitionNotification['message_html']) ? $recognitionNotification['message_html'] : htmlspecialchars($recognitionNotification['message']); ?>
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Filter Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label for="year" class="form-label">Year</label>
                            <select class="form-select" id="year" name="year">
                                <option value="">All Years</option>
                                <?php
                                $currentYear = date('Y');
                                for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
                                    $selected = ($year == $selectedYear) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="month" class="form-label">Month</label>
                            <select class="form-select" id="month" name="month">
                                <option value="">All Months</option>
                                <?php
                                $months = [
                                    '01' => 'January', '02' => 'February', '03' => 'March',
                                    '04' => 'April', '05' => 'May', '06' => 'June',
                                    '07' => 'July', '08' => 'August', '09' => 'September',
                                    '10' => 'October', '11' => 'November', '12' => 'December'
                                ];
                                foreach ($months as $value => $name) {
                                    $selected = ($value == $selectedMonth) ? 'selected' : '';
                                    echo "<option value='$value' $selected>$name</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-secondary">Filter</button>
                            <a href="financial.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Financial Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-danger text-white">
                        <div class="card-body">
                            <h5 class="card-title">Total Expenses</h5>
                            <h3 class="card-text">₱<?php echo number_format($totalExpenses, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <h5 class="card-title">Total Revenue</h5>
                            <h3 class="card-text">₱<?php echo number_format($totalRevenue, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <h5 class="card-title">Total Turnover</h5>
                            <h3 class="card-text">₱<?php echo number_format($totalTurnover, 2); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <h5 class="card-title">Total Balance</h5>
                            <h3 class="card-text">₱<?php echo number_format($totalBalance, 2); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Graph -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Financial Overview</h5>
                </div>
                <div class="card-body">
                    <canvas id="financialChart" height="100"></canvas>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th>Expenses</th>
                            <th>Revenue</th>
                            <th>Turnover</th>
                            <th>Balance</th>
                            <th>File</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['title']); ?></td>
                            <td><?php 
                                if ($report['report_date'] && $report['report_date'] !== '0000-00-00' && $report['report_date'] !== '-0001-11-30') {
                                    $date_parts = explode('-', $report['report_date']);
                                    if (count($date_parts) === 3) {
                                        $year = $date_parts[0];
                                        $month = $date_parts[1];
                                        $day = $date_parts[2];
                                        if (checkdate($month, $day, $year)) {
                                            // Use DateTime for more reliable date formatting
                                            $date = new DateTime($report['report_date']);
                                            echo $date->format('M d, Y');
                                        } else {
                                            echo 'No date';
                                        }
                                    } else {
                                        echo 'No date';
                                    }
                                } else {
                                    echo 'No date';
                                }
                            ?></td>
                            <td class="text-danger">₱<?php echo number_format($report['expenses'], 2); ?></td>
                            <td class="text-success">₱<?php echo number_format($report['revenue'], 2); ?></td>
                            <td class="<?php echo $report['turnover'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                ₱<?php echo number_format($report['turnover'], 2); ?>
                            </td>
                            <td class="<?php echo $report['balance'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                ₱<?php echo number_format($report['balance'], 2); ?>
                            </td>
                            <td>
                                <?php if ($report['file_path']): ?>
                                    <a href="<?php echo htmlspecialchars($report['file_path']); ?>" target="_blank" class="btn btn-sm btn-info">
                                        <i class="bi bi-file-earmark"></i> View
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">No file</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editReportModal<?php echo $report['id']; ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this report?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>

                        <!-- Edit Modal for each report -->
                        <div class="modal fade" id="editReportModal<?php echo $report['id']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Financial Report</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" enctype="multipart/form-data">
                                        <div class="modal-body">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            
                                            <div class="mb-3">
                                                <label for="event_name<?php echo $report['id']; ?>" class="form-label">Event Name</label>
                                                <?php if (!empty($approvedEvents)): ?>
                                                    <?php 
                                                    $currentEventValue = htmlspecialchars($report['title']);
                                                    $isInApprovedList = false;
                                                    foreach ($approvedEvents as $event) {
                                                        if ($event['title'] === $report['title']) {
                                                            $isInApprovedList = true;
                                                            break;
                                                        }
                                                    }
                                                    ?>
                                                    <select class="form-select" id="event_name<?php echo $report['id']; ?>" name="event_name" required>
                                                        <option value="">Select an approved event or enter custom name</option>
                                                        <?php foreach ($approvedEvents as $event): ?>
                                                            <option value="<?php echo htmlspecialchars($event['title']); ?>" <?php echo ($event['title'] === $report['title']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($event['title']); ?>
                                                                <?php if (!empty($event['venue'])): ?>
                                                                    - <?php echo htmlspecialchars($event['venue']); ?>
                                                                <?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                        <option value="__custom__" <?php echo !$isInApprovedList ? 'selected' : ''; ?>>-- Enter Custom Event Name --</option>
                                                    </select>
                                                    <input type="text" class="form-control mt-2" id="event_name_custom<?php echo $report['id']; ?>" name="event_name_custom" placeholder="Enter custom event name" value="<?php echo !$isInApprovedList ? $currentEventValue : ''; ?>" style="display: <?php echo !$isInApprovedList ? 'block' : 'none'; ?>;">
                                                    <small class="text-muted">Select from your approved event proposals or enter a custom event name.</small>
                                                <?php else: ?>
                                                    <input type="text" class="form-control" id="event_name<?php echo $report['id']; ?>" name="event_name" value="<?php echo htmlspecialchars($report['title']); ?>" required>
                                                    <small class="text-muted">No approved event proposals available. Enter event name manually.</small>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="expenses<?php echo $report['id']; ?>" class="form-label">Expenses</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₱</span>
                                                    <input type="number" class="form-control" id="expenses<?php echo $report['id']; ?>" name="expenses" step="0.01" min="0" value="<?php echo $report['expenses']; ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="revenue<?php echo $report['id']; ?>" class="form-label">Revenue</label>
                                                <div class="input-group">
                                                    <span class="input-group-text">₱</span>
                                                    <input type="number" class="form-control" id="revenue<?php echo $report['id']; ?>" name="revenue" step="0.01" min="0" value="<?php echo $report['revenue']; ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="report_date<?php echo $report['id']; ?>" class="form-label">Report Date</label>
                                                <input type="date" class="form-control" id="report_date<?php echo $report['id']; ?>" name="report_date" 
                                                value="<?php 
                                                    if ($report['report_date'] && $report['report_date'] !== '0000-00-00' && $report['report_date'] !== '-0001-11-30') {
                                                        $date_parts = explode('-', $report['report_date']);
                                                        if (count($date_parts) === 3) {
                                                            $year = $date_parts[0];
                                                            $month = $date_parts[1];
                                                            $day = $date_parts[2];
                                                            if (checkdate($month, $day, $year)) {
                                                                echo htmlspecialchars($report['report_date']);
                                                            } else {
                                                                echo date('Y-m-d');
                                                            }
                                                        } else {
                                                            echo date('Y-m-d');
                                                        }
                                                    } else {
                                                        echo date('Y-m-d');
                                                    }
                                                ?>" required>
                                                <small class="text-muted">Date must be within the current active academic year</small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="financial_file<?php echo $report['id']; ?>" class="form-label">Financial Document</label>
                                                <?php if ($report['file_path']): ?>
                                                    <div class="mb-2">
                                                        <a href="<?php echo htmlspecialchars($report['file_path']); ?>" target="_blank" class="btn btn-sm btn-info">
                                                            <i class="bi bi-file-earmark"></i> View Current File
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <input type="file" class="form-control" id="financial_file<?php echo $report['id']; ?>" name="financial_file" accept=".pdf,.doc,.docx,.xls,.xlsx">
                                                <small class="text-muted">Leave empty to keep current file. Accepted formats: PDF, DOC, DOCX, XLS, XLSX</small>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-primary">Update Report</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Add Report Modal -->
    <div class="modal fade" id="addReportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Financial Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="addReportForm" novalidate>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label for="event_name" class="form-label">Event Name</label>
                            <?php if (!empty($approvedEvents)): ?>
                                <select class="form-select" id="event_name" name="event_name" required>
                                    <option value="">Select an approved event or enter custom name</option>
                                    <?php foreach ($approvedEvents as $event): ?>
                                        <option value="<?php echo htmlspecialchars($event['title']); ?>">
                                            <?php echo htmlspecialchars($event['title']); ?>
                                            <?php if (!empty($event['venue'])): ?>
                                                - <?php echo htmlspecialchars($event['venue']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="__custom__">-- Enter Custom Event Name --</option>
                                </select>
                                <input type="text" class="form-control mt-2" id="event_name_custom" name="event_name_custom" placeholder="Enter custom event name" style="display: none;">
                                <small class="text-muted">Select from your approved event proposals or enter a custom event name.</small>
                            <?php else: ?>
                                <input type="text" class="form-control" id="event_name" name="event_name" required>
                                <small class="text-muted">No approved event proposals available. Enter event name manually.</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label for="expenses" class="form-label">Expenses</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="expenses" name="expenses" step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="revenue" class="form-label">Revenue</label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" class="form-control" id="revenue" name="revenue" step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="report_date" class="form-label">Report Date</label>
                            <input type="date" class="form-control" id="report_date" name="report_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                            <small class="text-muted">Date must be within the current active academic year</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="financial_file" class="form-label">Financial Document</label>
                            <input type="file" class="form-control" id="financial_file" name="financial_file" accept=".pdf,.doc,.docx,.xls,.xlsx">
                            <small class="text-muted">Accepted formats: PDF, DOC, DOCX, XLS, XLSX (Max size: 10MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Set academic year date constraints for date inputs
        document.addEventListener('DOMContentLoaded', function() {
            setAcademicYearDateConstraints();
        });
        
        // Function to set academic year date constraints
        function setAcademicYearDateConstraints() {
            fetch('get_academic_year_range.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.start_date && data.end_date) {
                        const startDate = new Date(data.start_date);
                        const endDate = new Date(data.end_date);
                        
                        // Format dates for date input (YYYY-MM-DD)
                        const startFormatted = startDate.toISOString().slice(0, 10);
                        const endFormatted = endDate.toISOString().slice(0, 10);
                        
                        // Set constraints for the main date input
                        const reportDateInput = document.getElementById('report_date');
                        if (reportDateInput) {
                            reportDateInput.min = startFormatted;
                            reportDateInput.max = endFormatted;
                        }
                        
                        // Set constraints for all edit date inputs
                        const editDateInputs = document.querySelectorAll('input[id^="report_date"]');
                        editDateInputs.forEach(input => {
                            input.min = startFormatted;
                            input.max = endFormatted;
                        });
                    }
                })
                .catch(error => {
                    console.error('Error fetching academic year range:', error);
                });
        }
        // Prepare data for the chart
        const overallData = {
            revenue: <?php echo $overallTotals['total_revenue'] ?? 0; ?>,
            expenses: <?php echo $overallTotals['total_expenses'] ?? 0; ?>,
            turnover: <?php echo $overallTotals['total_turnover'] ?? 0; ?>
        };

        // Create the chart
        const ctx = document.getElementById('financialChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Overall Financial Summary'],
                datasets: [
                    {
                        label: 'Total Revenue',
                        data: [overallData.revenue],
                        backgroundColor: 'rgba(25, 135, 84, 0.7)',
                        borderColor: 'rgb(25, 135, 84)',
                        borderWidth: 2,
                        borderRadius: 8
                    },
                    {
                        label: 'Total Expenses',
                        data: [overallData.expenses],
                        backgroundColor: 'rgba(220, 53, 69, 0.7)',
                        borderColor: 'rgb(220, 53, 69)',
                        borderWidth: 2,
                        borderRadius: 8
                    },
                    {
                        label: 'Total Turnover',
                        data: [overallData.turnover],
                        backgroundColor: 'rgba(253, 126, 20, 0.7)',
                        borderColor: 'rgb(253, 126, 20)',
                        borderWidth: 2,
                        borderRadius: 8
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: 'Overall Financial Performance'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ₱' + context.raw.toLocaleString();
                            }
                        }
                    },
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)'
                        }
                    }
                }
            }
        });
        
        // Function to setup event name dropdown handler
        function setupEventNameHandler(selectId, customInputId) {
            const eventNameSelect = document.getElementById(selectId);
            const eventNameCustom = document.getElementById(customInputId);
            
            if (eventNameSelect && eventNameCustom) {
                // Remove existing listeners by cloning
                const newSelect = eventNameSelect.cloneNode(true);
                eventNameSelect.parentNode.replaceChild(newSelect, eventNameSelect);
                
                newSelect.addEventListener('change', function() {
                    const customInput = document.getElementById(customInputId);
                    if (this.value === '__custom__') {
                        if (customInput) {
                            customInput.style.display = 'block';
                            customInput.setAttribute('required', 'required');
                            customInput.value = '';
                            // Make sure it's not hidden from screen readers
                            customInput.removeAttribute('hidden');
                            customInput.removeAttribute('aria-hidden');
                        }
                        // Remove required from select since we're using custom input
                        this.removeAttribute('required');
                    } else {
                        if (customInput) {
                            customInput.style.display = 'none';
                            customInput.removeAttribute('required');
                            customInput.value = '';
                        }
                        // Add required back to select
                        this.setAttribute('required', 'required');
                    }
                });
            }
        }
        
        // Handle event name dropdown - show custom input when "Enter Custom Event Name" is selected
        document.addEventListener('DOMContentLoaded', function() {
            // Handle add form - use Bootstrap modal event since modal loads dynamically
            const addReportModal = document.getElementById('addReportModal');
            if (addReportModal) {
                addReportModal.addEventListener('shown.bs.modal', function() {
                    // Attach handler directly when modal opens (same approach as edit forms)
                    const eventNameSelect = document.getElementById('event_name');
                    const eventNameCustom = document.getElementById('event_name_custom');
                    
                    if (eventNameSelect && eventNameCustom) {
                        // Remove any existing listeners by cloning
                        const newSelect = eventNameSelect.cloneNode(true);
                        eventNameSelect.parentNode.replaceChild(newSelect, eventNameSelect);
                        
                        // Attach change handler directly
                        newSelect.addEventListener('change', function() {
                            const customInput = document.getElementById('event_name_custom');
                            if (this.value === '__custom__') {
                                if (customInput) {
                                    customInput.style.display = 'block';
                                    customInput.setAttribute('required', 'required');
                                    customInput.value = '';
                                }
                                // Remove required from select since we're using custom input
                                this.removeAttribute('required');
                            } else {
                                if (customInput) {
                                    customInput.style.display = 'none';
                                    customInput.removeAttribute('required');
                                    customInput.value = '';
                                }
                                // Add required back to select
                                this.setAttribute('required', 'required');
                            }
                        });
                    }
                });
            }
            
            // Additional setup using event delegation for the add form
            // This ensures it works even if modal loads dynamically
            document.addEventListener('change', function(e) {
                if (e.target && e.target.id === 'event_name' && e.target.closest('#addReportForm')) {
                    const eventNameSelect = e.target;
                    const eventNameCustom = document.getElementById('event_name_custom');
                    
                    if (eventNameSelect.value === '__custom__') {
                        if (eventNameCustom) {
                            eventNameCustom.style.display = 'block';
                            eventNameCustom.setAttribute('required', 'required');
                            eventNameCustom.value = '';
                            // Ensure it's visible for validation
                            eventNameCustom.removeAttribute('hidden');
                            eventNameCustom.removeAttribute('aria-hidden');
                        }
                        // CRITICAL: Remove required from select immediately
                        eventNameSelect.removeAttribute('required');
                    } else {
                        if (eventNameCustom) {
                            eventNameCustom.style.display = 'none';
                            eventNameCustom.removeAttribute('required');
                            eventNameCustom.value = '';
                        }
                        // Add required back to select
                        eventNameSelect.setAttribute('required', 'required');
                    }
                }
            });
            
            // Handle edit forms (add form is handled by modal event above)
            // Only process selects that are NOT the add form's select (id !== 'event_name')
            document.querySelectorAll('[id^="event_name"]').forEach(function(select) {
                if (select.tagName === 'SELECT' && select.id !== 'event_name') {
                    const reportId = select.id.replace('event_name', '');
                    const customInput = document.getElementById('event_name_custom' + reportId);
                    
                    if (customInput) {
                        select.addEventListener('change', function() {
                            if (this.value === '__custom__') {
                                customInput.style.display = 'block';
                                customInput.required = true;
                                if (!customInput.value) {
                                    customInput.value = '';
                                }
                                // Remove required from select since we're using custom input
                                this.removeAttribute('required');
                            } else {
                                customInput.style.display = 'none';
                                customInput.required = false;
                                customInput.value = '';
                                // Add required back to select
                                this.setAttribute('required', 'required');
                            }
                        });
                    }
                }
            });
            
            // Handle form submission - ensure correct value is sent
            // Form has novalidate, so we handle all validation manually
            const addReportForm = document.getElementById('addReportForm');
            if (addReportForm) {
                addReportForm.addEventListener('submit', function(e) {
                    const eventNameSelect = this.querySelector('select[id="event_name"]');
                    const eventNameCustom = this.querySelector('input[id="event_name_custom"]');
                    
                    let isValid = true;
                    let firstInvalidField = null;
                    
                    // Handle custom event name - MUST happen first before other validation
                    if (eventNameSelect && eventNameSelect.value === '__custom__') {
                        // If custom is selected, check if custom input exists and has a value
                        if (!eventNameCustom || !eventNameCustom.value.trim()) {
                            isValid = false;
                            if (eventNameCustom) {
                                eventNameCustom.style.display = 'block';
                                eventNameCustom.setAttribute('required', 'required');
                                if (!firstInvalidField) {
                                    firstInvalidField = eventNameCustom;
                                }
                            } else {
                                if (!firstInvalidField) {
                                    firstInvalidField = eventNameSelect;
                                }
                            }
                        } else {
                            // CRITICAL: We can't set select to a value that's not in options
                            // Instead, create a temporary option with the custom value
                            const customValue = eventNameCustom.value.trim();
                            
                            // Check if an option with this value already exists
                            let optionExists = false;
                            for (let i = 0; i < eventNameSelect.options.length; i++) {
                                if (eventNameSelect.options[i].value === customValue) {
                                    optionExists = true;
                                    break;
                                }
                            }
                            
                            // If option doesn't exist, create it temporarily
                            if (!optionExists) {
                                const tempOption = document.createElement('option');
                                tempOption.value = customValue;
                                tempOption.textContent = customValue;
                                tempOption.selected = true;
                                eventNameSelect.appendChild(tempOption);
                            } else {
                                eventNameSelect.value = customValue;
                            }
                            
                            // Remove required from custom input since we're using select value
                            if (eventNameCustom) {
                                eventNameCustom.removeAttribute('required');
                                eventNameCustom.style.display = 'none';
                            }
                        }
                    }
                    
                    // Check if select has a value (either from dropdown or custom)
                    // This check happens AFTER we've replaced the value if custom was selected
                    if (eventNameSelect && !eventNameSelect.value.trim()) {
                        isValid = false;
                        if (!firstInvalidField) {
                            firstInvalidField = eventNameSelect;
                        }
                    }
                    
                    // Validate all other required fields
                    const requiredFields = this.querySelectorAll('[required]');
                    requiredFields.forEach(function(field) {
                        // Skip hidden fields and the custom input if it's hidden
                        if (field.offsetParent === null || (field.id === 'event_name_custom' && field.style.display === 'none')) {
                            return;
                        }
                        
                        if (!field.value || (field.type === 'number' && field.value === '')) {
                            isValid = false;
                            if (!firstInvalidField) {
                                firstInvalidField = field;
                            }
                        }
                    });
                    
                    // If validation failed, prevent submission and show error
                    if (!isValid) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (firstInvalidField) {
                            firstInvalidField.focus();
                            firstInvalidField.reportValidity();
                        } else {
                            alert('Please fill in all required fields');
                        }
                        return false;
                    }
                });
            }
            
            // Handle other forms (edit forms)
            document.querySelectorAll('form:not(#addReportForm)').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const eventNameSelect = this.querySelector('select[id^="event_name"]');
                    const eventNameCustom = this.querySelector('input[id*="event_name_custom"]');
                    
                    if (eventNameSelect && eventNameSelect.value === '__custom__') {
                        // If custom is selected, check if custom input exists and has a value
                        if (!eventNameCustom || !eventNameCustom.value.trim()) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (eventNameCustom) {
                                eventNameCustom.style.display = 'block';
                                eventNameCustom.focus();
                            } else {
                                eventNameSelect.focus();
                            }
                            alert('Please enter a custom event name.');
                            return false;
                        }
                        // Remove required from select BEFORE HTML5 validation runs
                        eventNameSelect.removeAttribute('required');
                        // Replace the select value with custom input value
                        eventNameSelect.value = eventNameCustom.value.trim();
                        // Remove required from custom input since we're using select value now
                        if (eventNameCustom) {
                            eventNameCustom.removeAttribute('required');
                        }
                    }
                });
            });
        });
    </script>
</body>
</html> 
<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/notification_helper.php';

requireRole(['org_president', 'council_president']);

// Determine ownership context (organization vs council)
$role = getUserRole();
// Use NULL for non-applicable owner to satisfy FK constraints
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;
$ownerId = ($role === 'org_president') ? $orgId : $councilId;
$message = '';
$error = '';

// Check recognition status
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

// Get courses for the dropdown
$courses = $conn->query("SELECT id, code, name FROM courses ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Get filter parameters
$selectedYear = $_GET['year'] ?? '';
$selectedMonth = $_GET['month'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($selectedYear) || !empty($selectedMonth);

// Pagination setup
$per_page = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Count total awards for pagination
$ownerColumn = ($role === 'org_president') ? 'a.organization_id' : 'a.council_id';
$countQuery = "SELECT COUNT(*) FROM awards a 
    LEFT JOIN academic_semesters s ON a.semester_id = s.id
    LEFT JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE $ownerColumn = ? 
    AND $statusCondition";
$count_types = 'i';
$count_params = [($role === 'org_president') ? $orgId : $councilId];

if ($selectedYear && $selectedMonth) {
    $countQuery .= " AND YEAR(a.award_date) = ? AND MONTH(a.award_date) = ?";
    $count_types .= 'ii';
    $count_params[] = (int)$selectedYear;
    $count_params[] = (int)$selectedMonth;
} elseif ($selectedYear) {
    $countQuery .= " AND YEAR(a.award_date) = ?";
    $count_types .= 'i';
    $count_params[] = (int)$selectedYear;
} elseif ($selectedMonth) {
    $countQuery .= " AND MONTH(a.award_date) = ?";
    $count_types .= 'i';
    $count_params[] = (int)$selectedMonth;
}

$count_stmt = $conn->prepare($countQuery);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$count_stmt->bind_result($total_awards);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_awards / $per_page);

// Get awards with filtering for the correct owner (organization or council)
$baseQuery = "SELECT a.*, 
    (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count,
    (SELECT COUNT(*) FROM award_images WHERE award_id = a.id) as image_count
    FROM awards a 
    LEFT JOIN academic_semesters s ON a.semester_id = s.id
    LEFT JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE $ownerColumn = ? 
    AND $statusCondition";
$types = 'i';
$params = [($role === 'org_president') ? $orgId : $councilId];

if ($selectedYear && $selectedMonth) {
    $baseQuery .= " AND YEAR(a.award_date) = ? AND MONTH(a.award_date) = ?";
    $types .= 'ii';
    $params[] = (int)$selectedYear;
    $params[] = (int)$selectedMonth;
} elseif ($selectedYear) {
    $baseQuery .= " AND YEAR(a.award_date) = ?";
    $types .= 'i';
    $params[] = (int)$selectedYear;
} elseif ($selectedMonth) {
    $baseQuery .= " AND MONTH(a.award_date) = ?";
    $types .= 'i';
    $params[] = (int)$selectedMonth;
}

$baseQuery .= " ORDER BY a.award_date DESC LIMIT ? OFFSET ?";
$types .= 'ii';
$params[] = $per_page;
$params[] = $offset;

$stmt = $conn->prepare($baseQuery);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $awards = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $awards = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if organization/council is recognized before allowing any actions
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'];
        header('Location: awards.php');
        exit;
    }
    
    // Check if student data has been uploaded before allowing actions
    $studentDataReminder = null;
    if ($role === 'org_president' && $orgId) {
        $studentDataReminder = getOrganizationStudentDataReminder($orgId);
    } elseif ($role === 'council_president' && $councilId) {
        $studentDataReminder = getCouncilStudentDataReminder($councilId);
    }
    
    if ($studentDataReminder) {
        $_SESSION['error'] = $studentDataReminder['message'];
        header('Location: awards.php');
        exit;
    }
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $title = $_POST['title'] ?? '';
                $description = $_POST['description'] ?? '';
                $award_date = $_POST['award_date'] ?? '';
                $venue = $_POST['venue'] ?? '';
                
                if (!empty($title) && !empty($award_date) && !empty($venue)) {
                    // Validate date is within active academic year
                    $dateValidation = validateDateWithinAcademicYear($conn, $award_date);
                    if (!$dateValidation['valid']) {
                        $_SESSION['error'] = $dateValidation['error'];
                        header("Location: awards.php");
                        exit;
                    }
                    
                    // Normalize date to Y-m-d
                    $formatted_date = date('Y-m-d', strtotime($award_date));
                    
                    // Get current active semester
                    $semesterId = getCurrentActiveSemesterId($conn);
                    if ($semesterId) {
                        error_log("Assigned semester_id: " . $semesterId . " to new award");
                    } else {
                        error_log("Warning: No active semester found for new award - semester_id will be NULL");
                        // Optional: You can uncomment the following lines to prevent award creation without active semester
                        // $_SESSION['error'] = 'No active semester found. Please contact administrator to set up the current semester.';
                        // header("Location: awards.php");
                        // exit;
                    }
                    
                    // Insert with correct ownership (organization or council) and semester
                    $stmt = $conn->prepare("INSERT INTO awards (organization_id, council_id, semester_id, title, description, award_date, venue) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    // Bind with NULL for non-applicable owner using dynamic types
                    $bindOrgId = ($role === 'org_president') ? $orgId : null;
                    $bindCouncilId = ($role === 'council_president') ? $councilId : null;
                    $stmt->bind_param("iiissss", $bindOrgId, $bindCouncilId, $semesterId, $title, $description, $formatted_date, $venue);
                    
                    if ($stmt->execute()) {
                        $awardId = $stmt->insert_id;
                        
                        // Create uploads directory if it doesn't exist
                        $upload_dir = './uploads/awards/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        // Handle image uploads
                        if (!empty($_FILES['images']['name'][0])) {
                            // Validate maximum 10 images - count files that were actually uploaded
                            $uploadedCount = 0;
                            foreach ($_FILES['images']['name'] as $key => $name) {
                                if (!empty($name) && !empty($_FILES['images']['tmp_name'][$key]) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                                    $uploadedCount++;
                                }
                            }
                            if ($uploadedCount > 10) {
                                // Delete the award that was just created
                                $deleteStmt = $conn->prepare("DELETE FROM awards WHERE id = ?");
                                $deleteStmt->bind_param("i", $awardId);
                                $deleteStmt->execute();
                                $deleteStmt->close();
                                
                                $_SESSION['error'] = 'Maximum 10 images allowed. You selected ' . $uploadedCount . ' images. Please select 10 or fewer images.';
                                header("Location: awards.php");
                                exit;
                            }
                            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                                $file_name = $_FILES['images']['name'][$key];
                                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                                $file_size = $_FILES['images']['size'][$key] ?? 0;
                                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                $mime = $finfo ? finfo_file($finfo, $tmp_name) : '';
                                if ($finfo) { finfo_close($finfo); }
                                
                                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']) && in_array($mime, ['image/jpeg','image/png','image/gif']) && $file_size <= 10*1024*1024) {
                                    // Preserve original filename but ensure uniqueness
                                    $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                                    $counter = 1;
                                    $new_name = $original_name . '.' . $file_ext;
                                    $upload_path = $upload_dir . $new_name;
                                    
                                    // If file already exists, add a number suffix
                                    while (file_exists($upload_path)) {
                                        $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                        $upload_path = $upload_dir . $new_name;
                                        $counter++;
                                    }
                                    
                                    if (move_uploaded_file($tmp_name, $upload_path)) {
                                        $stmt = $conn->prepare("INSERT INTO award_images (award_id, image_path) VALUES (?, ?)");
                                        $stmt->bind_param("is", $awardId, $upload_path);
                                        $stmt->execute();
                                    } else {
                                        error_log("Failed to move uploaded file to: " . $upload_path);
                                    }
                                }
                            }
                        }
                        
                        // Handle participants
                        if (!empty($_POST['participants'])) {
                            foreach ($_POST['participants'] as $participant) {
                                if (!empty($participant['name'])) {
                                    $name = $participant['name'];
                                    $course = $participant['course'] ?? null;
                                    $year_level = (isset($participant['year_level']) && $participant['year_level'] !== '') ? (int)$participant['year_level'] : null;
                                    $section = $participant['section'] ?? null;
                                    // Use section directly as-is from registry (already contains full year-section format like "3-2", "1-1")
                                    $sectionStr = trim((string)($section ?? ''));
                                    $yearStr = ($year_level !== null) ? (string)$year_level : '';
                                    
                                    // If section is provided, use it directly (it's already in the correct format from registry)
                                    if ($sectionStr !== '') {
                                        $yearSection = $sectionStr;
                                    } elseif ($yearStr !== '') {
                                        // Fallback: if only year is provided, use just the year
                                        $yearSection = $yearStr;
                                    } else {
                                        $yearSection = '';
                                    }
                                    $stmt = $conn->prepare("INSERT INTO award_participants (award_id, name, course, yearSection) VALUES (?, ?, ?, ?)");
                                    $stmt->bind_param("isss", $awardId, $name, $course, $yearSection);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        $_SESSION['message'] = 'Award created successfully!';
                        header('Location: awards.php');
                        exit;
                    } else {
                        $_SESSION['error'] = 'Error creating award: ' . $stmt->error;
                        header('Location: awards.php');
                        exit;
                    }
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header('Location: awards.php');
                    exit;
                }
                break;
                
            case 'delete':
                $awardId = $_POST['award_id'] ?? 0;
                if ($awardId) {
                    // Fetch and delete image files
                    $stmt = $conn->prepare("SELECT image_path FROM award_images WHERE award_id = ?");
                    $stmt->bind_param("i", $awardId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($img = $res->fetch_assoc()) {
                        if (!empty($img['image_path']) && file_exists($img['image_path'])) {
                            @unlink($img['image_path']);
                        }
                    }
                    // Delete award images
                    $stmt = $conn->prepare("DELETE FROM award_images WHERE award_id = ?");
                    $stmt->bind_param("i", $awardId);
                    $stmt->execute();
                    
                    // Delete award participants
                    $stmt = $conn->prepare("DELETE FROM award_participants WHERE award_id = ?");
                    $stmt->bind_param("i", $awardId);
                    $stmt->execute();
                    
                    // Delete award scoped by owner
                    $ownerColumn = ($role === 'org_president') ? 'organization_id' : 'council_id';
                    $stmt = $conn->prepare("DELETE FROM awards WHERE id = ? AND $ownerColumn = ?");
                    $ownerScopedId = ($role === 'org_president') ? $orgId : $councilId;
                    $stmt->bind_param("ii", $awardId, $ownerScopedId);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Award deleted successfully!';
                    } else {
                        $_SESSION['error'] = 'Error deleting award';
                    }
                    header('Location: awards.php');
                    exit;
                }
                break;
                
            // Removed legacy add_award branch that doesn't match current schema

            case 'edit_award':
                $awardId = $_POST['award_id'] ?? 0;
                $title = $_POST['title'] ?? '';
                $description = $_POST['description'] ?? '';
                $award_date = $_POST['award_date'] ?? '';
                $venue = $_POST['venue'] ?? '';
                
                if (!empty($title) && !empty($award_date) && !empty($venue)) {
                    // Validate date is within active academic year
                    $dateValidation = validateDateWithinAcademicYear($conn, $award_date);
                    if (!$dateValidation['valid']) {
                        $_SESSION['error'] = $dateValidation['error'];
                        header("Location: awards.php");
                        exit;
                    }
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = './uploads/awards/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    // Handle new image uploads
                    if (!empty($_FILES['images']['name'][0])) {
                        // Check existing image count
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM award_images WHERE award_id = ?");
                        $stmt->bind_param("i", $awardId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $existingCount = $result->fetch_assoc()['count'];
                        $stmt->close();
                        
                        // Count new uploads - count files that were actually uploaded
                        $newCount = 0;
                        foreach ($_FILES['images']['name'] as $key => $name) {
                            if (!empty($name) && !empty($_FILES['images']['tmp_name'][$key]) && $_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                                $newCount++;
                            }
                        }
                        
                        // Validate total doesn't exceed 10
                        if (($existingCount + $newCount) > 10) {
                            $_SESSION['error'] = 'Maximum 10 images allowed. You have ' . $existingCount . ' existing image' . ($existingCount != 1 ? 's' : '') . ' and selected ' . $newCount . ' new image' . ($newCount != 1 ? 's' : '') . '. Total would be ' . ($existingCount + $newCount) . ' images.';
                            header("Location: awards.php");
                            exit;
                        }
                        
                        foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                            $file_name = $_FILES['images']['name'][$key];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            $file_size = $_FILES['images']['size'][$key] ?? 0;
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime = $finfo ? finfo_file($finfo, $tmp_name) : '';
                            if ($finfo) { finfo_close($finfo); }
                            
                            if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']) && in_array($mime, ['image/jpeg','image/png','image/gif']) && $file_size <= 10*1024*1024) {
                                // Preserve original filename but ensure uniqueness
                                $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                                $counter = 1;
                                $new_name = $original_name . '.' . $file_ext;
                                $upload_path = $upload_dir . $new_name;
                                
                                // If file already exists, add a number suffix
                                while (file_exists($upload_path)) {
                                    $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                                    $upload_path = $upload_dir . $new_name;
                                    $counter++;
                                }
                                
                                if (move_uploaded_file($tmp_name, $upload_path)) {
                                    $stmt = $conn->prepare("INSERT INTO award_images (award_id, image_path) VALUES (?, ?)");
                                    $stmt->bind_param("is", $awardId, $upload_path);
                                    $stmt->execute();
                                } else {
                                    error_log("Failed to move uploaded file to: " . $upload_path);
                                }
                            }
                        }
                    }
                    
                    // Update award details
                    $ownerColumn = ($role === 'org_president') ? 'organization_id' : 'council_id';
                    $formatted_date = date('Y-m-d', strtotime($award_date));
                    $stmt = $conn->prepare("UPDATE awards SET title = ?, description = ?, award_date = ?, venue = ? WHERE id = ? AND $ownerColumn = ?");
                    $ownerScopedId = ($role === 'org_president') ? $orgId : $councilId;
                    $stmt->bind_param("ssssii", $title, $description, $formatted_date, $venue, $awardId, $ownerScopedId);
                    
                    if ($stmt->execute()) {
                        // Handle participants
                        if (!empty($_POST['participants'])) {
                            // Delete existing participants
                            $stmt = $conn->prepare("DELETE FROM award_participants WHERE award_id = ?");
                            $stmt->bind_param("i", $awardId);
                            $stmt->execute();
                            
                            // Add new participants
                            foreach ($_POST['participants'] as $participant) {
                                if (!empty($participant['name'])) {
                                    $name = $participant['name'];
                                    $course = $participant['course'] ?? null;
                                    $year_level = (isset($participant['year_level']) && $participant['year_level'] !== '') ? (int)$participant['year_level'] : null;
                                    $section = $participant['section'] ?? null;
                                    // Use section directly as-is from registry (already contains full year-section format like "3-2", "1-1")
                                    $sectionStr = trim((string)($section ?? ''));
                                    $yearStr = ($year_level !== null) ? (string)$year_level : '';
                                    
                                    // If section is provided, use it directly (it's already in the correct format from registry)
                                    if ($sectionStr !== '') {
                                        $yearSection = $sectionStr;
                                    } elseif ($yearStr !== '') {
                                        // Fallback: if only year is provided, use just the year
                                        $yearSection = $yearStr;
                                    } else {
                                        $yearSection = '';
                                    }
                                    $stmt = $conn->prepare("INSERT INTO award_participants (award_id, name, course, yearSection) VALUES (?, ?, ?, ?)");
                                    $stmt->bind_param("isss", $awardId, $name, $course, $yearSection);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        $_SESSION['message'] = 'Award updated successfully!';
                    } else {
                        $_SESSION['error'] = 'Error updating award: ' . $stmt->error;
                    }
                    header('Location: awards.php');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header('Location: awards.php');
                    exit;
                }
                break;
        }
    }
}

// Get messages from session
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Clear session messages
unset($_SESSION['message']);
unset($_SESSION['error']);

// Check for student data reminder
$studentDataReminder = null;
if ($role === 'org_president' && $orgId) {
    $studentDataReminder = getOrganizationStudentDataReminder($orgId);
} elseif ($role === 'council_president' && $councilId) {
    $studentDataReminder = getCouncilStudentDataReminder($councilId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awards Management - Academic Organization Management System</title>
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
        .btn-warning {
            background: linear-gradient(45deg, #fd7e14, #ff922b);
            border: none;
            color: white;
            font-weight: 500;
        }
        
        .btn-warning:hover {
            background: linear-gradient(45deg, #e8690f, #e8690f);
            color: white;
            transform: scale(1.05);
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
        
        .btn-info {
            background: linear-gradient(45deg, #20c997);
            border: none;
            color: white;
        }
        
        .btn-info:hover {
            background: linear-gradient(45deg, #0bb5d6, #25c5e8);
            color: white;
        }
        
        /* Card styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-left: 4px solid transparent;
        }
        
        .card:nth-child(odd) {
            border-left-color: #495057; /* Dark Gray */
        }
        
        .card:nth-child(even) {
            border-left-color: #6c757d; /* Medium Gray */
        }
        
        .card-header.bg-primary {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }
        
        .card-title {
            color: #212529;
            font-weight: 600;
        }
        
        /* Table styling */
        .table {
            border-radius: 8px;
            overflow: hidden;
        }
        
        .table-light th {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(108, 117, 125, 0.1) 100%);
            color: #495057;
            font-weight: 600;
            border: none;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background: rgba(52, 58, 64, 0.05);
        }
        
        .table-striped tbody tr:nth-of-type(even) {
            background: rgba(108, 117, 125, 0.05);
        }
        
        .table-hover tbody tr:hover {
            background: rgba(73, 80, 87, 0.1);
        }
        
        /* Better contrast for table text */
        .table tbody td {
            color: #212529;
        }
        
        /* Award images styling */
        .award-images {
            background: linear-gradient(135deg, rgba(52, 58, 64, 0.05) 0%, rgba(108, 117, 125, 0.05) 100%);
            border-radius: 12px;
            margin: 1rem;
        }
        
        .image-container {
            position: relative;
            overflow: hidden;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .award-thumbnail {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .award-thumbnail:hover {
            transform: scale(1.08);
            filter: brightness(1.1);
        }
        
        /* Badge styling */
        .badge {
            font-size: 0.8rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .badge.bg-dark {
            background: linear-gradient(45deg, #343a40, #495057) !important;
        }
        
        /* Participants table styling */
        .participants-section {
            background: rgba(248, 249, 250, 0.8);
            border-radius: 10px;
            padding: 1rem;
            border-left: 3px solid #495057;
        }
        
        .card:nth-child(even) .participants-section {
            border-left-color: #6c757d;
        }
        
        .table-sm {
            font-size: 0.875rem;
        }
        
        .table-sm th {
            background: linear-gradient(90deg, rgba(52, 58, 64, 0.1) 0%, rgba(108, 117, 125, 0.1) 100%);
            color: #495057;
            font-weight: 600;
            border: none;
            padding: 0.5rem;
        }
        
        .table-sm td {
            padding: 0.5rem;
            border-color: rgba(0, 0, 0, 0.05);
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
        
        .alert-info {
            background: linear-gradient(90deg, rgba(13, 202, 240, 0.1) 0%, rgba(13, 202, 240, 0.05) 100%) !important;
            border: 1px solid rgba(13, 202, 240, 0.3) !important;
            color: #0c5460 !important;
            border-radius: 8px !important;
        }
        
        /* Enhanced hover effects */
        .btn-group .btn {
            transition: all 0.2s ease;
        }
        
        .btn-group .btn:hover {
            transform: scale(1.05);
        }
        
        /* Table responsive wrapper */
        .table-responsive {
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        /* Filter styling */
        .form-select-sm {
            border-radius: 6px;
            border-color: #dee2e6;
        }
        
        .form-select-sm:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        /* Filter button styling to match system theme */
        .btn-filter {
            background: linear-gradient(45deg, #495057, #6c757d) !important;
            border: none !important;
            color: white !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
            transition: all 0.2s ease !important;
        }
        
        .btn-filter:hover {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            color: white !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
        }
        
        .btn-filter:focus {
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.5) !important;
        }
        
        .btn-reset {
            background: linear-gradient(45deg, #6c757d, #868e96) !important;
            border: none !important;
            color: white !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
            transition: all 0.2s ease !important;
        }
        
        .btn-reset:hover {
            background: linear-gradient(45deg, #5c636a, #6c757d) !important;
            color: white !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2) !important;
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border-radius: 0;
        }
        
        .modal-header .btn-close {
            filter: invert(1);
        }
        
        .modal-title {
            font-weight: 600;
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
        
        /* Participant entry styling */
        .participant-entry {
            background: linear-gradient(135deg, rgba(248, 249, 250, 0.8) 0%, rgba(233, 236, 239, 0.5) 100%);
            border-radius: 10px;
            padding: 1rem;
            border-left: 4px solid #495057;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .participant-entry:nth-child(even) {
            border-left-color: #6c757d;
        }
        
        /* Reduced hover effect - no transform to prevent dropdown positioning issues */
        .participant-entry:hover {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            /* Removed transform to prevent dropdown overlap */
        }
        
        /* Disable hover effect when input is focused (dropdown is open) */
        .participant-entry:has(.participant-name-search:focus) {
            transform: none !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }
        
        /* Fix dropdown overlapping issue - ensure it's on top of everything */
        .participant-entry .row {
            overflow: visible !important;
        }

        .participant-entry .col-md-4.position-relative {
            overflow: visible !important;
            position: relative !important;
        }

        /* Dropdown styling - must be above modals (z-index 1050-1075) */
        .participant-entry .list-group.position-absolute {
            z-index: 10000 !important;
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            max-height: 300px !important;
            overflow-y: auto !important;
            background: white !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 0.375rem !important;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
            margin-top: 2px !important;
        }

        /* Ensure modal doesn't clip dropdowns */
        .modal-body {
            overflow: visible !important;
        }

        .modal-content {
            overflow: visible !important;
        }

        .modal-dialog {
            overflow: visible !important;
        }
        
        /* Date and info styling */
        .text-muted {
            color: #6c757d !important;
        }
        
        .card .text-muted i {
            color: #495057;
        }
        
        .card:nth-child(even) .text-muted i {
            color: #6c757d;
        }
        
        /* Button group styling */
        .btn-group {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        
        /* Carousel styling */
        .carousel-control-prev,
        .carousel-control-next {
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }
        
        .carousel-control-prev:hover,
        .carousel-control-next:hover {
            opacity: 1;
        }
        
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            width: 2rem;
            height: 2rem;
        }
        
        .carousel-indicators {
            margin-bottom: 1rem;
        }
        
        .carousel-indicators [data-bs-target] {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin: 0 4px;
            background-color: rgba(255, 255, 255, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.8);
        }
        
        .carousel-indicators .active {
            background-color: #495057;
            border-color: #495057;
        }
        
        /* Gallery modal styling */
        #imageGalleryModal .modal-dialog {
            max-width: 95vw;
        }
        
        #imageGalleryModal .modal-content {
            border-radius: 15px;
            overflow: hidden;
        }
        
        #imageGalleryModal .modal-header {
            background: linear-gradient(90deg, #343a40, #495057);
            color: white;
            border-bottom: none;
        }
        
        #imageGalleryModal .modal-footer {
            border-top: 1px solid #dee2e6;
            background-color: #f8f9fa;
        }
        
        /* Image loading animation */
        .carousel-item img {
            transition: opacity 0.3s ease;
        }
        
        .carousel-item img[src=""] {
            opacity: 0;
        }
        
        /* Loading spinner */
        .spinner-border {
            color: #495057;
        }
        
        /* Enhanced hover effects */
        .btn-sm {
            transition: all 0.2s ease;
        }
        
        .btn-sm:hover {
            transform: scale(1.05);
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .card {
                margin-bottom: 1.5rem;
            }
            
            .award-images {
                margin: 0.5rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                border-radius: 6px !important;
                margin-bottom: 0.25rem;
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
        
        .card {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        .card:nth-child(even) {
            animation-delay: 0.1s;
        }
        
        .card:nth-child(3n) {
            animation-delay: 0.2s;
        }

        /* Constrain text columns to avoid horizontal scrolling */
        #awardsTable th:nth-child(1),
        #awardsTable td:nth-child(1) {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #awardsTable th:nth-child(3),
        #awardsTable td:nth-child(3) {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #awardsTable th:nth-child(4),
        #awardsTable td:nth-child(4) {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Ensure long descriptions wrap inside view modal */
        #viewAwardModal .modal-body,
        #viewAwardModal .modal-body p,
        #viewAwardModal .modal-body .description,
        #viewAwardModal .modal-body .card-text {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        #viewAwardModal .modal-body {
            overflow-x: hidden;
        }

        /* Custom styled notification */
        .custom-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 350px;
            max-width: 500px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 2px solid #ffc107;
            border-left: 5px solid #ffc107;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
            padding: 1.25rem;
            animation: slideInRight 0.3s ease-out;
            display: none;
        }

        .custom-notification.show {
            display: block;
        }

        .custom-notification-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .custom-notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(45deg, #ffc107, #ff9800);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .custom-notification-icon i {
            color: white;
            font-size: 1.25rem;
        }

        .custom-notification-title {
            font-weight: 600;
            color: #495057;
            font-size: 1.1rem;
            margin: 0;
            flex-grow: 1;
        }

        .custom-notification-close {
            background: none;
            border: none;
            color: #6c757d;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .custom-notification-close:hover {
            background: rgba(108, 117, 125, 0.1);
            color: #495057;
        }

        .custom-notification-body {
            color: #495057;
            font-size: 0.95rem;
            line-height: 1.5;
            padding-left: 3.5rem;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .custom-notification.hide {
            animation: slideOutRight 0.3s ease-out forwards;
        }

        @media (max-width: 768px) {
            .custom-notification {
                min-width: 300px;
                max-width: calc(100% - 40px);
                right: 20px;
                left: 20px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <!-- Custom Styled Notification -->
    <div id="customNotification" class="custom-notification">
        <div class="custom-notification-header">
            <div class="custom-notification-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h6 class="custom-notification-title">Duplicate Participant</h6>
            <button type="button" class="custom-notification-close" onclick="closeCustomNotification()">
                <i class="bi bi-x"></i>
            </button>
        </div>
        <div class="custom-notification-body" id="customNotificationBody">
            <!-- Message will be inserted here -->
        </div>
    </div>

    <main>
        <div class="container-fluid">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="page-title">Awards Management</h2>
                        <p class="page-subtitle">Create and manage organization awards and recognitions</p>
                    </div>
                    <div>
                        <button type="button" class="btn btn-light main-action" data-bs-toggle="modal" data-bs-target="#createAwardModal" <?php echo (!$isRecognized || $studentDataReminder) ? 'disabled' : ''; ?>>
                            <i class="bi bi-plus-circle"></i> Create New Award
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['message'];
                    unset($_SESSION['message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($recognitionNotification): ?>
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
            
            <?php if ($studentDataReminder): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start">
                        <div class="me-3">
                            <i class="fas fa-database fa-2x text-info"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="alert-heading mb-2">
                                <i class="fas fa-info-circle me-2"></i>
                                Student Data Required
                            </h5>
                            <p class="mb-0">
                                <?php echo isset($studentDataReminder['message_html']) ? $studentDataReminder['message_html'] : htmlspecialchars($studentDataReminder['message']); ?>
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Awards</h5>
                                <form method="GET" class="d-flex gap-2" style="margin: 0;">
                                    <select class="form-select form-select-sm" id="yearFilter" name="year" style="width: auto;">
                                        <option value="">All Years</option>
                                        <?php
                                        $currentYear = date('Y');
                                        for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
                                            $selected = ($selectedYear == $year) ? 'selected' : '';
                                            echo "<option value='$year' $selected>$year</option>";
                                        }
                                        ?>
                                    </select>
                                    <select class="form-select form-select-sm" id="monthFilter" name="month" style="width: auto;">
                                        <option value="">All Months</option>
                                        <?php
                                        $months = [
                                            '1' => 'January', '2' => 'February', '3' => 'March',
                                            '4' => 'April', '5' => 'May', '6' => 'June',
                                            '7' => 'July', '8' => 'August', '9' => 'September',
                                            '10' => 'October', '11' => 'November', '12' => 'December'
                                        ];
                                        foreach ($months as $num => $name) {
                                            $selected = ($selectedMonth == $num) ? 'selected' : '';
                                            echo "<option value='$num' $selected>$name</option>";
                                        }
                                        ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-filter">Filter</button>
                                    <?php if (!empty($selectedYear) || !empty($selectedMonth)): ?>
                                        <a href="awards.php" class="btn btn-sm btn-reset">Reset</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover align-middle" id="awardsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="px-3">Title</th>
                                            <th class="px-3">Date</th>
                                            <th class="px-3">Venue</th>
                                            <th class="px-3">Description</th>
                                            <th class="px-3 text-center">Images</th>
                                            <th class="px-3 text-center">Participants</th>
                                            <th class="px-3 text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if (empty($awards)) {
                                            echo '<tr><td colspan="7" class="text-center">No awards found</td></tr>';
                                        } else {
                                            foreach ($awards as $award): 
                                        ?>
                                        <tr>
                                            <td class="px-3"><?php echo htmlspecialchars($award['title']); ?></td>
                                            <td class="px-3"><?php echo date('M d, Y', strtotime($award['award_date'])); ?></td>
                                            <td class="px-3"><?php echo htmlspecialchars($award['venue']); ?></td>
                                            <td class="px-3"><?php echo htmlspecialchars(substr($award['description'], 0, 100)) . (strlen($award['description']) > 100 ? '...' : ''); ?></td>
                                            <td class="px-3 text-center"><?php echo $award['image_count']; ?> images</td>
                                            <td class="px-3 text-center"><?php echo $award['participant_count']; ?> participants</td>
                                            <td class="px-3 text-center">
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#viewAwardModal" data-award-id="<?php echo $award['id']; ?>">
                                                        <i class="bi bi-eye"></i> View
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editAwardModal" data-award-id="<?php echo $award['id']; ?>">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAwardModal" data-award-id="<?php echo $award['id']; ?>">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php 
                                            endforeach;
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <nav aria-label="Awards pagination" class="mt-3">
                                <ul class="pagination justify-content-center">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $selectedYear ? '&year=' . $selectedYear : ''; ?><?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>">Previous</a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">Previous</span>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    if ($start_page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=1<?php echo $selectedYear ? '&year=' . $selectedYear : ''; ?><?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>">1</a>
                                        </li>
                                        <?php if ($start_page > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $selectedYear ? '&year=' . $selectedYear : ''; ?><?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $selectedYear ? '&year=' . $selectedYear : ''; ?><?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>"><?php echo $total_pages; ?></a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $selectedYear ? '&year=' . $selectedYear : ''; ?><?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>">Next</a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">Next</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            
                            <div class="text-center text-muted mb-3">
                                <small>Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_awards; ?> total awards)</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Award Modal -->
    <div class="modal fade" id="createAwardModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create New Award</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="title" class="form-label">Award Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="award_date" class="form-label">Award Date</label>
                            <input type="date" class="form-control" id="award_date" name="award_date" required>
                            <small class="text-muted">Date must be within the current active academic year</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="venue" class="form-label">Venue</label>
                            <input type="text" class="form-control" id="venue" name="venue" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Award Images</label>
                            <input type="file" class="form-control" name="images[]" multiple accept="image/*">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Participants</label>
                            <div id="participants-container">
                                <div class="participant-entry mb-2">
                                    <div class="row">
                                        <div class="col-md-4 position-relative">
                                            <input type="text" class="form-control participant-name-search" name="participants[0][name]" placeholder="Search name or student no." autocomplete="off" required>
                                            <div class="list-group position-absolute w-100" style="z-index: 10000; display:none;"></div>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="text" class="form-control participant-course" name="participants[0][course]" placeholder="Course" readonly required>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" class="form-control participant-year-section" name="participants[0][year_section_display]" placeholder="Year - Section" readonly>
                                            <input type="hidden" name="participants[0][year_level]" class="participant-year-level">
                                            <input type="hidden" name="participants[0][section]" class="participant-section">
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger btn-sm remove-participant" style="display: none;">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm mt-2" id="add-participant">
                                <i class="bi bi-plus-circle"></i> Add Participant
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Award</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Award Modal -->
    <div class="modal fade" id="editAwardModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Award</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_award">
                        <input type="hidden" name="award_id" id="edit_award_id">
                        
                        <div class="mb-3">
                            <label for="edit_title" class="form-label">Award Title</label>
                            <input type="text" class="form-control" id="edit_title" name="title" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_award_date" class="form-label">Award Date</label>
                            <input type="date" class="form-control" id="edit_award_date" name="award_date" required>
                            <small class="text-muted">Date must be within the current active academic year</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_venue" class="form-label">Venue</label>
                            <input type="text" class="form-control" id="edit_venue" name="venue" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Add More Images</label>
                            <input type="file" class="form-control" name="images[]" multiple accept="image/*">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Current Images</label>
                            <div id="current-images" class="row">
                                <!-- Current images will be loaded here -->
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Participants</label>
                            <div id="edit-participants-container">
                                <!-- Participants will be loaded here -->
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm mt-2" id="add-edit-participant">
                                <i class="bi bi-plus-circle"></i> Add Participant
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Award</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Award Modal -->
    <div class="modal fade" id="viewAwardModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Award Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewAwardContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Award Modal -->
    <div class="modal fade" id="deleteAwardModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Award</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="award_id" id="delete_award_id">
                        <p>Are you sure you want to delete this award? This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Image Gallery Modal -->
    <div class="modal fade" id="imageGalleryModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="galleryModalTitle">Award Images</h5>
                    <div class="d-flex align-items-center">
                        <span class="badge bg-secondary me-3" id="imageCounter">1 / 1</span>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0">
                    <div id="galleryCarousel" class="carousel slide" data-bs-ride="false" data-bs-interval="false">
                        <div class="carousel-indicators" id="galleryIndicators">
                            <!-- Indicators will be loaded here -->
                        </div>
                        <div class="carousel-inner">
                            <!-- Images will be loaded here -->
                        </div>
                        <button class="carousel-control-prev" type="button" data-bs-target="#galleryCarousel" data-bs-slide="prev" style="width: 10%;">
                            <span class="carousel-control-prev-icon" style="background-color: rgba(0,0,0,0.5); border-radius: 50%; padding: 20px;"></span>
                            <span class="visually-hidden">Previous</span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#galleryCarousel" data-bs-slide="next" style="width: 10%;">
                            <span class="carousel-control-next-icon" style="background-color: rgba(0,0,0,0.5); border-radius: 50%; padding: 20px;"></span>
                            <span class="visually-hidden">Next</span>
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="d-flex justify-content-between w-100">
                        <div>
                            <button type="button" class="btn btn-outline-secondary" onclick="downloadCurrentImage()">
                                <i class="bi bi-download"></i> Download
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Image upload validation - maximum 10 images
            const imageInputs = document.querySelectorAll('input[name="images[]"]');
            imageInputs.forEach(function(input) {
                input.addEventListener('change', function() {
                    if (this.files.length > 10) {
                        alert('Maximum 10 images allowed. Please select 10 or fewer images.');
                        this.value = ''; // Clear selection
                    }
                });
            });
            
            // Set academic year date constraints for date inputs
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
                        
                        // Set constraints for both date inputs
                        const awardDateInput = document.getElementById('award_date');
                        const editAwardDateInput = document.getElementById('edit_award_date');
                        
                        if (awardDateInput) {
                            awardDateInput.min = startFormatted;
                            awardDateInput.max = endFormatted;
                        }
                        
                        if (editAwardDateInput) {
                            editAwardDateInput.min = startFormatted;
                            editAwardDateInput.max = endFormatted;
                        }
                    }
                })
                .catch(error => {
                    console.error('Error fetching academic year range:', error);
                });
        }

        // Participants UI and search (mirrors events.php)
        let participantCount = 1;
        document.getElementById('add-participant').addEventListener('click', function() {
            const container = document.getElementById('participants-container');
            const index = container.children.length;
            const participantHtml = `
                <div class="participant-entry mb-2">
                    <div class="row">
                        <div class="col-md-4 position-relative">
                            <input type="text" class="form-control participant-name-search" name="participants[${index}][name]" placeholder="Search name or student no." autocomplete="off" required>
                            <div class="list-group position-absolute w-100" style="z-index: 10000; display:none;"></div>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control participant-course" name="participants[${index}][course]" placeholder="Course" readonly required>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control participant-year-section" name="participants[${index}][year_section_display]" placeholder="Year - Section" readonly>
                            <input type="hidden" name="participants[${index}][year_level]" class="participant-year-level">
                            <input type="hidden" name="participants[${index}][section]" class="participant-section">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger btn-sm remove-participant">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            container.insertAdjacentHTML('beforeend', participantHtml);
            
            // Show remove button for all entries
            const removeButtons = container.querySelectorAll('.remove-participant');
            removeButtons.forEach(button => {
                button.style.display = container.children.length > 1 ? 'block' : 'none';
            });
            attachParticipantSearchHandlers(container.lastElementChild);
        });

        document.getElementById('participants-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-participant')) {
                const entry = e.target.closest('.participant-entry');
                const container = document.getElementById('participants-container');
                entry.remove();

                // Update remove button visibility
                const removeButtons = container.querySelectorAll('.remove-participant');
                removeButtons.forEach(button => {
                    button.style.display = container.children.length > 1 ? 'block' : 'none';
                });
            }
        });

        // Handle edit participants add button
        document.getElementById('add-edit-participant').addEventListener('click', function() {
            const container = document.getElementById('edit-participants-container');
            const index = container.children.length;
            const participantHtml = `
                <div class="participant-entry mb-2">
                    <div class="row">
                        <div class="col-md-4 position-relative">
                            <input type="text" class="form-control participant-name-search" name="participants[${index}][name]" placeholder="Search name or student no." autocomplete="off" required>
                            <div class="list-group position-absolute w-100" style="z-index: 10000; display:none;"></div>
                        </div>
                        <div class="col-md-3">
                            <input type="text" class="form-control participant-course" name="participants[${index}][course]" placeholder="Course" readonly required>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control participant-year-section" name="participants[${index}][year_section_display]" placeholder="Year - Section" readonly>
                            <input type="hidden" name="participants[${index}][year_level]" class="participant-year-level">
                            <input type="hidden" name="participants[${index}][section]" class="participant-section">
                        </div>
                        <div class="col-md-1">
                            <button type="button" class="btn btn-danger btn-sm remove-participant">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>`;
            container.insertAdjacentHTML('beforeend', participantHtml);
            
            // Show remove button for all entries
            const removeButtons = container.querySelectorAll('.remove-participant');
            removeButtons.forEach(button => {
                button.style.display = container.children.length > 1 ? 'block' : 'none';
            });
            attachParticipantSearchHandlers(container.lastElementChild);
        });

        document.getElementById('edit-participants-container').addEventListener('click', function(e) {
            if (e.target.closest('.remove-participant')) {
                const entry = e.target.closest('.participant-entry');
                const container = document.getElementById('edit-participants-container');
                entry.remove();

                // Update remove button visibility
                const removeButtons = container.querySelectorAll('.remove-participant');
                removeButtons.forEach(button => {
                    button.style.display = container.children.length > 1 ? 'block' : 'none';
                });
            }
        });

        // Attach search handlers for name → autofill course and year-section
        function attachParticipantSearchHandlers(entryElement) {
            const searchInput = entryElement.querySelector('.participant-name-search');
            const resultsList = entryElement.querySelector('.list-group');
            const courseInput = entryElement.querySelector('.participant-course');
            const yearLevelHidden = entryElement.querySelector('.participant-year-level');
            const sectionHidden = entryElement.querySelector('.participant-section');
            const yearSectionDisplay = entryElement.querySelector('.participant-year-section');

            let debounce;
            searchInput.addEventListener('input', function() {
                // Clear selection flag when user types manually
                this.removeAttribute('data-selected');
                clearTimeout(debounce);
                const q = this.value.trim();
                if (q.length < 2) {
                    resultsList.style.display = 'none';
                    resultsList.innerHTML = '';
                    return;
                }
                // Uppercase like members page behavior
                this.value = this.value.toUpperCase();
                resultsList.style.display = 'block';
                resultsList.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Searching...</div>';

                debounce = setTimeout(() => {
                    fetch(`members.php?student_search=1&q=${encodeURIComponent(q)}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data.students && data.students.length > 0) {
                                resultsList.innerHTML = data.students.map(s => `
                                    <button type="button" class="list-group-item list-group-item-action" data-name="${s.name}" data-year-level="${s.year_level || ''}" data-section="${s.section || ''}" data-course="${s.course || ''}">
                                        <strong>${s.name}</strong><br><small>${s.section || ''}</small>
                                    </button>
                                `).join('');
                                resultsList.style.display = 'block';
                            } else {
                                resultsList.innerHTML = '<div class="list-group-item text-muted">No students found.</div>';
                                resultsList.style.display = 'block';
                            }
                        })
                        .catch(() => {
                            resultsList.innerHTML = '<div class="list-group-item text-danger">Error searching students.</div>';
                            resultsList.style.display = 'block';
                        });
                }, 300);
            });

            resultsList.addEventListener('click', function(e) {
                const btn = e.target.closest('.list-group-item');
                if (!btn) return;
                const name = btn.getAttribute('data-name') || '';
                const course = btn.getAttribute('data-course') || '';
                const year = btn.getAttribute('data-year-level') || '';
                const section = btn.getAttribute('data-section') || '';

                // Check for duplicate participant
                const container = entryElement.closest('#participants-container, #edit-participants-container');
                if (container) {
                    const allNameInputs = container.querySelectorAll('.participant-name-search');
                    const nameUpper = name.toUpperCase().trim();
                    let isDuplicate = false;
                    
                    allNameInputs.forEach(input => {
                        if (input !== searchInput && input.value.toUpperCase().trim() === nameUpper && input.value.trim() !== '') {
                            isDuplicate = true;
                        }
                    });
                    
                    if (isDuplicate) {
                        showCustomNotification('This participant has already been added. Please select a different participant.');
                        resultsList.style.display = 'none';
                        resultsList.innerHTML = '';
                        searchInput.value = '';
                        return;
                    }
                }

                searchInput.value = name;
                // Set course (read-only input)
                courseInput.value = course;
                // Use section directly as-is from registry (already contains full year-section format like "3-2", "1-1")
                const yearSection = section || year || ''; // Use section if available, fallback to year
                yearLevelHidden.value = ''; // Not needed when using section directly
                sectionHidden.value = yearSection; // Store the full year-section
                yearSectionDisplay.value = yearSection; // Display as-is, no composition needed
                // Mark as selected from dropdown
                searchInput.setAttribute('data-selected', 'true');
                resultsList.style.display = 'none';
                resultsList.innerHTML = '';
            });
        }

        // Initialize search handlers for initial participant entry
        attachParticipantSearchHandlers(document.querySelector('#participants-container .participant-entry'));

        // Helper: compose Year - Section without duplication when section already contains year
        function composeYearSection(year, section) {
            const y = String(year || '').trim();
            const s = String(section || '').trim();
            if (!y && !s) return '';
            if (!y) return s;
            if (!s) return y;
            const yNorm = y.replace(/\s+/g, '');
            const sNorm = s.replace(/\s+/g, '');
            if (sNorm.startsWith(yNorm)) return s;
            return `${y} - ${s}`;
        }

        // Function to show custom styled notification
        function showCustomNotification(message, duration = 5000) {
            const notification = document.getElementById('customNotification');
            const notificationBody = document.getElementById('customNotificationBody');
            
            if (!notification || !notificationBody) return;
            
            // Set message
            notificationBody.textContent = message;
            
            // Remove hide class if present
            notification.classList.remove('hide');
            
            // Show notification
            notification.classList.add('show');
            
            // Auto-hide after duration
            if (duration > 0) {
                setTimeout(() => {
                    closeCustomNotification();
                }, duration);
            }
        }

        // Function to close custom notification
        function closeCustomNotification() {
            const notification = document.getElementById('customNotification');
            if (!notification) return;
            
            notification.classList.add('hide');
            setTimeout(() => {
                notification.classList.remove('show', 'hide');
            }, 300);
        }

        // Function to check for duplicate participants
        function checkDuplicateParticipants(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return false;
            
            const nameInputs = container.querySelectorAll('.participant-name-search');
            const names = [];
            let hasDuplicates = false;
            const duplicates = [];
            
            nameInputs.forEach(input => {
                const name = input.value.trim().toUpperCase();
                if (name !== '') {
                    if (names.includes(name)) {
                        hasDuplicates = true;
                        if (!duplicates.includes(name)) {
                            duplicates.push(name);
                        }
                    } else {
                        names.push(name);
                    }
                }
            });
            
            if (hasDuplicates) {
                const duplicateList = duplicates.join(', ');
                showCustomNotification('Duplicate participants detected: ' + duplicateList + '. Please remove duplicate entries before submitting.');
                return true;
            }
            return false;
        }

        // Function to check if all participants were selected from dropdown
        function validateParticipantSelection(containerId) {
            const container = document.getElementById(containerId);
            if (!container) return true; // If no container, allow submission
            
            const nameInputs = container.querySelectorAll('.participant-name-search');
            const invalidParticipants = [];
            
            nameInputs.forEach((input, index) => {
                const name = input.value.trim();
                const courseInput = input.closest('.participant-entry').querySelector('.participant-course');
                const isSelected = input.getAttribute('data-selected') === 'true';
                const hasCourse = courseInput && courseInput.value.trim() !== '';
                
                // If name is filled but not selected from dropdown
                if (name !== '' && !isSelected && !hasCourse) {
                    invalidParticipants.push(index + 1);
                }
            });
            
            if (invalidParticipants.length > 0) {
                const participantText = invalidParticipants.length === 1 ? 'participant' : 'participants';
                showCustomNotification(`Please select ${participantText} ${invalidParticipants.join(', ')} from the dropdown list. You cannot submit with manually typed names.`);
                return false;
            }
            
            return true;
        }

        // Single global outside-click handler to hide any open suggestions
        document.addEventListener('click', function(ev) {
            document.querySelectorAll('#participants-container .participant-entry .list-group, #edit-participants-container .participant-entry .list-group').forEach(list => {
                const container = list.closest('.participant-entry');
                if (container && !container.contains(ev.target)) {
                    list.style.display = 'none';
                }
            });
        });

        // Validate participants on form submit
        const createAwardForm = document.querySelector('#createAwardModal form');
        if (createAwardForm) {
            createAwardForm.addEventListener('submit', function(e) {
                if (checkDuplicateParticipants('participants-container')) {
                    e.preventDefault();
                    return false;
                }
                if (!validateParticipantSelection('participants-container')) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        const editAwardForm = document.querySelector('#editAwardModal form');
        if (editAwardForm) {
            editAwardForm.addEventListener('submit', function(e) {
                if (checkDuplicateParticipants('edit-participants-container')) {
                    e.preventDefault();
                    return false;
                }
                if (!validateParticipantSelection('edit-participants-container')) {
                    e.preventDefault();
                    return false;
                }
            });
        }

        // Handle edit award modal
        document.getElementById('editAwardModal').addEventListener('show.bs.modal', function(event) {
            const triggerEl = event.relatedTarget;
            const awardId = (triggerEl.getAttribute('data-award-id') || (triggerEl.closest('[data-award-id]') ? triggerEl.closest('[data-award-id]').getAttribute('data-award-id') : '')).trim();
            document.getElementById('edit_award_id').value = awardId;
            
            // Show loading state
            document.getElementById('edit_title').value = 'Loading...';
            document.getElementById('edit_description').value = 'Loading...';
            document.getElementById('edit_award_date').value = '';
            document.getElementById('edit_venue').value = '';
            document.getElementById('edit-participants-container').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading participants...</p></div>';
            document.getElementById('current-images').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading images...</p></div>';
            
            // Fetch award details
            if (!awardId) {
                document.getElementById('edit-participants-container').innerHTML = '<div class="alert alert-danger">Invalid award ID.</div>';
                document.getElementById('current-images').innerHTML = '<div class="alert alert-danger">Invalid award ID.</div>';
                return;
            }

            fetch(`get_award_details.php?award_id=${awardId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }

                    // Set form values
                    document.getElementById('edit_title').value = data.award.title || '';
                    document.getElementById('edit_description').value = data.award.description || '';
                    document.getElementById('edit_award_date').value = data.award.award_date || '';
                    document.getElementById('edit_venue').value = data.award.venue || '';
                    
                    // Load participants
                    const participantsContainer = document.getElementById('edit-participants-container');
                    participantsContainer.innerHTML = '';
                    
                    if (data.participants && data.participants.length > 0) {
                        data.participants.forEach((participant, index) => {
                            // Use yearSection directly as-is from database (already contains full format like "3-2", "1-1")
                            const yearSectionDisplay = participant.yearSection || participant.section || '';
                            const participantHtml = `
                                <div class=\"participant-entry mb-2\">
                                    <div class=\"row\">
                                        <div class=\"col-md-4 position-relative\">
                                            <input type=\"text\" class=\"form-control participant-name-search\" name=\"participants[${index}][name]\" value=\"${participant.name || ''}\" placeholder=\"Search name or student no.\" autocomplete=\"off\" required data-selected="true">
                                            <div class=\"list-group position-absolute w-100\" style=\"z-index: 10000; display:none;\"></div>
                                        </div>
                                        <div class=\"col-md-3\">
                                            <input type=\"text\" class=\"form-control participant-course\" name=\"participants[${index}][course]\" placeholder=\"Course\" value=\"${participant.course || ''}\" readonly required>
                                        </div>
                                        <div class=\"col-md-4\">
                                            <input type=\"text\" class=\"form-control participant-year-section\" name=\"participants[${index}][year_section_display]\" placeholder=\"Year - Section\" value=\"${yearSectionDisplay}\" readonly>
                                            <input type=\"hidden\" name=\"participants[${index}][year_level]\" class=\"participant-year-level\" value=\"\">
                                            <input type=\"hidden\" name=\"participants[${index}][section]\" class=\"participant-section\" value=\"${yearSectionDisplay}\">
                                        </div>
                                        <div class=\"col-md-1\">
                                            <button type=\"button\" class=\"btn btn-danger btn-sm remove-participant\">
                                                <i class=\"bi bi-trash\"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            participantsContainer.insertAdjacentHTML('beforeend', participantHtml);
                            attachParticipantSearchHandlers(participantsContainer.lastElementChild);
                        });
                    } else {
                        participantsContainer.innerHTML = '<div class="alert alert-info">No participants found</div>';
                    }
                    
                    // Load current images
                    const currentImagesContainer = document.getElementById('current-images');
                    if (data.images && data.images.length > 0) {
                        currentImagesContainer.innerHTML = '';
                        data.images.forEach((image, index) => {
                            const imageHtml = `
                                <div class="col-md-4 mb-3">
                                    <div class="card">
                                        <img src="${image.image_path}" class="card-img-top" alt="Award Image">
                                        <div class="card-body p-2">
                                            <button type="button" class="btn btn-danger btn-sm w-100" data-image-id="${image.id}" onclick="deleteImage(${image.id})">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            `;
                            currentImagesContainer.innerHTML += imageHtml;
                        });
                    } else {
                        currentImagesContainer.innerHTML = '<div class="col-12"><div class="alert alert-info">No images uploaded yet.</div></div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading award details:', error);
                    document.getElementById('edit_title').value = '';
                    document.getElementById('edit_description').value = '';
                    document.getElementById('edit_award_date').value = '';
                    document.getElementById('edit_venue').value = '';
                    document.getElementById('edit-participants-container').innerHTML = '<div class="alert alert-danger">Error loading award details. Please try again.</div>';
                    document.getElementById('current-images').innerHTML = '<div class="alert alert-danger">Error loading images. Please try again.</div>';
                });
        });

        // Function to delete an image
        function deleteImage(imageId) {
            if (confirm('Are you sure you want to delete this image?')) {
                fetch('delete_award_image.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `image_id=${imageId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Remove the image from the UI
                        const imageCard = document.querySelector(`[data-image-id="${imageId}"]`).closest('.col-md-4');
                        if (imageCard) {
                            imageCard.remove();
                            
                            // Check if there are any images left
                            const remainingImages = document.querySelectorAll('#current-images .col-md-4');
                            if (remainingImages.length === 0) {
                                document.getElementById('current-images').innerHTML = '<div class="col-12"><div class="alert alert-info">No images uploaded yet.</div></div>';
                            }
                        }
                        // Show success message
                        alert('Image deleted successfully');
                    } else {
                        throw new Error(data.error || 'Failed to delete image');
                    }
                })
                .catch(error => {
                    console.error('Error deleting image:', error);
                    alert('Error deleting image. Please try again.');
                });
            }
        }

        // Handle view award modal
        document.getElementById('viewAwardModal').addEventListener('show.bs.modal', function(event) {
            const triggerEl = event.relatedTarget;
            const awardId = (triggerEl.getAttribute('data-award-id') || (triggerEl.closest('[data-award-id]') ? triggerEl.closest('[data-award-id]').getAttribute('data-award-id') : '')).trim();
            
            // Show loading state
            document.getElementById('viewAwardContent').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            
            // Fetch award details
            if (!awardId) {
                document.getElementById('viewAwardContent').innerHTML = '<div class="alert alert-danger">Invalid award ID.</div>';
                return;
            }

            fetch(`get_award_details.php?award_id=${awardId}&format=html`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('viewAwardContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('viewAwardContent').innerHTML = '<div class="alert alert-danger">Error loading award details. Please try again.</div>';
                });
        });

        // Handle delete award modal
        document.getElementById('deleteAwardModal').addEventListener('show.bs.modal', function(event) {
            const triggerEl = event.relatedTarget;
            const awardId = (triggerEl.getAttribute('data-award-id') || (triggerEl.closest('[data-award-id]') ? triggerEl.closest('[data-award-id]').getAttribute('data-award-id') : '')).trim();
            document.getElementById('delete_award_id').value = awardId;
        });

        // Award filtering now handled server-side via form submission
        // Client-side filtering removed to enable archived data access

        // Global variable to store current images for download functionality
        let currentImages = [];
        let currentImageIndex = 0;

        // Function to open image gallery
        function openImageGallery(awardId, startIndex = 0) {
            fetch(`get_award_images.php?award_id=${awardId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error || !data.success) {
                        throw new Error(data.error || 'Failed to load images');
                    }

                    if (data.images && data.images.length > 0) {
                        currentImages = data.images;
                        currentImageIndex = startIndex;
                        
                        // Update modal title
                        document.getElementById('galleryModalTitle').textContent = data.award_title || 'Award Images';
                        
                        // Update image counter
                        updateImageCounter(startIndex + 1, data.images.length);
                        
                        // Build carousel slides
                        const carouselInner = document.querySelector('#galleryCarousel .carousel-inner');
                        carouselInner.innerHTML = '';
                        
                        // Build carousel indicators
                        const indicators = document.getElementById('galleryIndicators');
                        indicators.innerHTML = '';
                        
                        data.images.forEach((image, index) => {
                            const activeClass = index === startIndex ? 'active' : '';
                            
                            // Create carousel slide
                            const slide = `
                                <div class="carousel-item ${activeClass}">
                                    <div class="d-flex justify-content-center align-items-center" style="height: 70vh; background-color: #f8f9fa;">
                                        <img src="${image.image_path}" 
                                             class="img-fluid" 
                                             alt="Award Image ${index + 1}" 
                                             style="max-height: 100%; max-width: 100%; object-fit: contain; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);"
                                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                        <div style="display: none; text-align: center; color: #6c757d;">
                                            <i class="bi bi-image" style="font-size: 3rem;"></i>
                                            <p>Image not found</p>
                                        </div>
                                    </div>
                                </div>
                            `;
                            carouselInner.innerHTML += slide;
                            
                            // Create indicator (only show if more than 1 image)
                            if (data.images.length > 1) {
                                const indicator = document.createElement('button');
                                indicator.type = 'button';
                                indicator.setAttribute('data-bs-target', '#galleryCarousel');
                                indicator.setAttribute('data-bs-slide-to', index);
                                indicator.className = index === startIndex ? 'active' : '';
                                indicator.setAttribute('aria-label', `Slide ${index + 1}`);
                                if (index === startIndex) {
                                    indicator.setAttribute('aria-current', 'true');
                                }
                                indicators.appendChild(indicator);
                            }
                        });
                        
                        // Hide indicators if only one image
                        if (data.images.length <= 1) {
                            indicators.style.display = 'none';
                            // Also hide navigation arrows for single image
                            document.querySelector('.carousel-control-prev').style.display = 'none';
                            document.querySelector('.carousel-control-next').style.display = 'none';
                        } else {
                            indicators.style.display = 'flex';
                            document.querySelector('.carousel-control-prev').style.display = 'flex';
                            document.querySelector('.carousel-control-next').style.display = 'flex';
                        }

                        // Initialize carousel and show modal
                        const carouselElement = document.getElementById('galleryCarousel');
                        const carousel = new bootstrap.Carousel(carouselElement, {
                            interval: false,
                            wrap: true,
                            keyboard: true
                        });
                        
                        // Add event listener for slide changes to update counter
                        carouselElement.addEventListener('slide.bs.carousel', function (event) {
                            currentImageIndex = event.to;
                            updateImageCounter(event.to + 1, data.images.length);
                        });
                        
                        // Show the modal
                        const galleryModal = new bootstrap.Modal(document.getElementById('imageGalleryModal'));
                        galleryModal.show();
                        
                        // Add keyboard navigation
                        document.addEventListener('keydown', handleKeyboardNavigation);
                        
                        // Remove keyboard listener when modal is hidden
                        document.getElementById('imageGalleryModal').addEventListener('hidden.bs.modal', function () {
                            document.removeEventListener('keydown', handleKeyboardNavigation);
                        });
                        
                    } else {
                        alert('No images found for this award.');
                    }
                })
                .catch(error => {
                    console.error('Error loading images:', error);
                    alert('Error loading images. Please try again.');
                });
        }
        
        // Function to update image counter
        function updateImageCounter(current, total) {
            document.getElementById('imageCounter').textContent = `${current} / ${total}`;
        }
        
        // Function to handle keyboard navigation
        function handleKeyboardNavigation(event) {
            const modal = document.getElementById('imageGalleryModal');
            if (modal.classList.contains('show')) {
                if (event.key === 'ArrowLeft') {
                    event.preventDefault();
                    bootstrap.Carousel.getInstance(document.getElementById('galleryCarousel')).prev();
                } else if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    bootstrap.Carousel.getInstance(document.getElementById('galleryCarousel')).next();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    bootstrap.Modal.getInstance(modal).hide();
                }
            }
        }
        
        // Function to download current image
        function downloadCurrentImage() {
            if (currentImages.length > 0 && currentImageIndex < currentImages.length) {
                const currentImage = currentImages[currentImageIndex];
                const link = document.createElement('a');
                link.href = currentImage.image_path;
                link.download = `award_image_${currentImageIndex + 1}.${currentImage.image_path.split('.').pop()}`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    </script>
</body>
</html> 
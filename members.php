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
$ownerColumn = ($role === 'org_president') ? 'organization_id' : 'council_id';
	error_log("Current organization ID: " . $orgId);
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';

// Recognition guard (mirror events.php/awards.php)
$isRecognized = false;
$recognitionNotification = null;
if ($role === 'org_president' && $orgId) {
    $st = $conn->prepare("SELECT status FROM organizations WHERE id = ?");
    $st->bind_param("i", $orgId);
    $st->execute();
    $rs = $st->get_result();
    if ($org = $rs->fetch_assoc()) {
        $isRecognized = ($org['status'] === 'recognized');
        if (!$isRecognized) {
            $recognitionNotification = [
                'type' => 'organization',
                'message' => 'This Organization is not yet recognized. Please go to the Organization Documents section and complete the required submissions listed there.',
                'message_html' => 'This Organization is not yet recognized. Please go to the <a href="organization_documents.php" class="alert-link fw-bold">Organization Documents</a> section and complete the required submissions listed there.'
            ];
        }
    }
    $st->close();
} elseif ($role === 'council_president' && $councilId) {
    $st = $conn->prepare("SELECT status FROM council WHERE id = ?");
    $st->bind_param("i", $councilId);
    $st->execute();
    $rs = $st->get_result();
    if ($c = $rs->fetch_assoc()) {
        $isRecognized = ($c['status'] === 'recognized');
        if (!$isRecognized) {
            $recognitionNotification = [
                'type' => 'council',
                'message' => 'This Council is not yet recognized. Please go to the Council Documents section and complete the required submissions listed there.',
                'message_html' => 'This Council is not yet recognized. Please go to the <a href="council_documents.php" class="alert-link fw-bold">Council Documents</a> section and complete the required submissions listed there.'
            ];
        }
    }
    $st->close();
}

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

// Get courses for the dropdown
$courses = $conn->query("SELECT id, code, name FROM courses ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Registered adviser (Organization/Council) for prefill fallback
$registeredAdviser = ['name' => '', 'email' => ''];
if ($role === 'org_president' && $orgId) {
    $q = $conn->prepare("SELECT adviser_id, adviser_name FROM organizations WHERE id = ?");
    $q->bind_param("i", $orgId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if ($row) {
        $registeredAdviser['name'] = $row['adviser_name'] ?? '';
        if (!empty($row['adviser_id'])) {
            $u = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $u->bind_param("i", $row['adviser_id']);
            $u->execute();
            $usr = $u->get_result()->fetch_assoc();
            $u->close();
            if ($usr && !empty($usr['email'])) { $registeredAdviser['email'] = $usr['email']; }
        }
    }
} elseif ($role === 'council_president' && $councilId) {
    $q = $conn->prepare("SELECT adviser_id, adviser_name FROM council WHERE id = ?");
    $q->bind_param("i", $councilId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $q->close();
    if ($row) {
        $registeredAdviser['name'] = $row['adviser_name'] ?? '';
        if (!empty($row['adviser_id'])) {
            $u = $conn->prepare("SELECT email FROM users WHERE id = ?");
            $u->bind_param("i", $row['adviser_id']);
            $u->execute();
            $usr = $u->get_result()->fetch_assoc();
            $u->close();
            if ($usr && !empty($usr['email'])) { $registeredAdviser['email'] = $usr['email']; }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'] ?? 'Not recognized.';
        header('Location: members.php');
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
        header('Location: members.php');
        exit;
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $name = $_POST['name'] ?? '';
                $student_number = $_POST['student_number'] ?? '';
                $course = $_POST['course'] ?? '';
                $year_section = $_POST['year_section'] ?? '';
                $position = $_POST['position'] ?? '';
                
                if (!empty($name) && !empty($student_number) && !empty($position)) {
                    // Get current active academic year ID
                    $currentAcademicYearId = getCurrentAcademicTermId($conn);
                    if (!$currentAcademicYearId) {
                        $_SESSION['error'] = 'No active academic year found. Please contact OSAS.';
                        header('Location: members.php');
                        exit;
                    }
                    
                    // Check for duplicate student_number for this owner in ACTIVE academic year only
                    $dup_sql = "SELECT id FROM student_officials WHERE $ownerColumn = ? AND student_number = ? AND academic_year_id = ?";
                    $dup_stmt = $conn->prepare($dup_sql);
                    $dup_stmt->bind_param("isi", $ownerId, $student_number, $currentAcademicYearId);
                    $dup_stmt->execute();
                    $dup_stmt->store_result();
                    if ($dup_stmt->num_rows > 0) {
                        $_SESSION['error'] = 'This student number is already registered as a member in the current academic year.';
                        header('Location: members.php');
                        exit;
                    }
                    $dup_stmt->close();
                    
                    // Block organization presidents from being added to council
                    if ($role === 'council_president') {
                        // Check if this student is already a President in any organization
                        $president_check_sql = "SELECT so.id, so.name, o.org_name 
                                                FROM student_officials so 
                                                JOIN organizations o ON so.organization_id = o.id 
                                                WHERE so.organization_id IS NOT NULL 
                                                AND so.position = 'PRESIDENT' 
                                                AND so.student_number = ? 
                                                AND so.academic_year_id = ?";
                        $president_check_stmt = $conn->prepare($president_check_sql);
                        $president_check_stmt->bind_param("si", $student_number, $currentAcademicYearId);
                        $president_check_stmt->execute();
                        $president_result = $president_check_stmt->get_result();
                        
                        if ($president_result->num_rows > 0) {
                            $president_data = $president_result->fetch_assoc();
                            $president_check_stmt->close();
                            $_SESSION['error'] = 'Cannot add this student to the council. ' . htmlspecialchars($president_data['name']) . ' is already the President of ' . htmlspecialchars($president_data['org_name']) . '. Organization presidents cannot hold council positions.';
                            header('Location: members.php');
                            exit;
                        }
                        $president_check_stmt->close();
                    }
                    
                    // Block council presidents from being added to organization
                    if ($role === 'org_president') {
                        // Check if this student is already a President in any council
                        $council_president_check_sql = "SELECT so.id, so.name, c.council_name 
                                                        FROM student_officials so 
                                                        JOIN council c ON so.council_id = c.id 
                                                        WHERE so.council_id IS NOT NULL 
                                                        AND so.position = 'PRESIDENT' 
                                                        AND so.student_number = ? 
                                                        AND so.academic_year_id = ?";
                        $council_president_check_stmt = $conn->prepare($council_president_check_sql);
                        $council_president_check_stmt->bind_param("si", $student_number, $currentAcademicYearId);
                        $council_president_check_stmt->execute();
                        $council_president_result = $council_president_check_stmt->get_result();
                        
                        if ($council_president_result->num_rows > 0) {
                            $council_president_data = $council_president_result->fetch_assoc();
                            $council_president_check_stmt->close();
                            $_SESSION['error'] = 'Cannot add this student to the organization. ' . htmlspecialchars($council_president_data['name']) . ' is already the President of ' . htmlspecialchars($council_president_data['council_name']) . '. Council presidents cannot hold organization positions.';
                            header('Location: members.php');
                            exit;
                        }
                        $council_president_check_stmt->close();
                    }
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = './uploads/members/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $picture_path = '';
                    if (!empty($_FILES['picture']['name'])) {
                        $file_name = $_FILES['picture']['name'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $file_size = $_FILES['picture']['size'] ?? 0;
                        
                        if (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                            // MIME validation and size check (<=5MB)
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime = $finfo ? finfo_file($finfo, $_FILES['picture']['tmp_name']) : '';
                            if ($finfo) { finfo_close($finfo); }
                            if (!in_array($mime, ['image/jpeg','image/png']) || $file_size > 5*1024*1024) {
                                $_SESSION['error'] = 'Invalid image or file too large (max 5MB).';
                                header('Location: members.php');
                                exit;
                            }
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
                            
                            if (move_uploaded_file($_FILES['picture']['tmp_name'], $upload_path)) {
                                $picture_path = $upload_path;
                            } else {
                                $_SESSION['error'] = 'Error uploading image. Please try again.';
                                header('Location: members.php');
                                exit;
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid file type. Please upload a JPG, JPEG, or PNG image.';
                            header('Location: members.php');
                            exit;
                        }
                    }
                    
                    // Insert with correct ownership columns and current academic year
                    // Note: $currentAcademicYearId is already set above during duplicate check
                    $stmt = $conn->prepare("INSERT INTO student_officials (organization_id, council_id, academic_year_id, name, student_number, course, year_section, position, picture_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $bindOrgId = ($role === 'org_president') ? $orgId : null;
                    $bindCouncilId = ($role === 'council_president') ? $councilId : null;
                    $stmt->bind_param("iiissssss", $bindOrgId, $bindCouncilId, $currentAcademicYearId, $name, $student_number, $course, $year_section, $position, $picture_path);
                    
                    // Debug logging
                    error_log("Attempting to insert member - owner: $ownerColumn=$ownerId, name: $name, student_number: $student_number, course: $course, year_section: $year_section, position: $position");
                    
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Member added successfully!';
                        error_log("Member added successfully with ID: " . $conn->insert_id);
                    } else {
                        $_SESSION['error'] = 'Error adding member: ' . $stmt->error;
                        error_log("Error adding member: " . $stmt->error);
                    }
                    header('Location: members.php');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields';
                    header('Location: members.php');
                    exit;
                }
                break;
                
            case 'edit':
                $memberId = $_POST['member_id'] ?? 0;
                $name = $_POST['name'] ?? '';
                $student_number = $_POST['student_number'] ?? '';
                $course = $_POST['course'] ?? '';
                $year_section = $_POST['year_section'] ?? '';
                $position = $_POST['position'] ?? '';
                
                if (!empty($name) && !empty($student_number)) {
                    // Get current active academic year ID
                    $currentAcademicYearId = getCurrentAcademicTermId($conn);
                    if (!$currentAcademicYearId) {
                        $_SESSION['error'] = 'No active academic year found. Please contact OSAS.';
                        header('Location: members.php');
                        exit;
                    }
                    
                    // Check for duplicate student_number in ACTIVE academic year (excluding current member)
                    $dup_sql = "SELECT id FROM student_officials WHERE $ownerColumn = ? AND student_number = ? AND id != ? AND academic_year_id = ?";
                    $dup_stmt = $conn->prepare($dup_sql);
                    $dup_stmt->bind_param("isii", $ownerId, $student_number, $memberId, $currentAcademicYearId);
                    $dup_stmt->execute();
                    $dup_stmt->store_result();
                    if ($dup_stmt->num_rows > 0) {
                        $dup_stmt->close();
                        $_SESSION['error'] = 'This student number is already registered as a member in the current academic year.';
                        header('Location: members.php');
                        exit;
                    }
                    $dup_stmt->close();
                    
                    // Block organization presidents from being added to council
                    if ($role === 'council_president') {
                        // Check if this student is already a President in any organization
                        $president_check_sql = "SELECT so.id, so.name, o.org_name 
                                                FROM student_officials so 
                                                JOIN organizations o ON so.organization_id = o.id 
                                                WHERE so.organization_id IS NOT NULL 
                                                AND so.position = 'PRESIDENT' 
                                                AND so.student_number = ? 
                                                AND so.academic_year_id = ?";
                        $president_check_stmt = $conn->prepare($president_check_sql);
                        $president_check_stmt->bind_param("si", $student_number, $currentAcademicYearId);
                        $president_check_stmt->execute();
                        $president_result = $president_check_stmt->get_result();
                        
                        if ($president_result->num_rows > 0) {
                            $president_data = $president_result->fetch_assoc();
                            $president_check_stmt->close();
                            $_SESSION['error'] = 'Cannot update this student in the council. ' . htmlspecialchars($president_data['name']) . ' is already the President of ' . htmlspecialchars($president_data['org_name']) . '. Organization presidents cannot hold council positions.';
                            header('Location: members.php');
                            exit;
                        }
                        $president_check_stmt->close();
                    }
                    
                    // Block council presidents from being added to organization
                    if ($role === 'org_president') {
                        // Check if this student is already a President in any council
                        $council_president_check_sql = "SELECT so.id, so.name, c.council_name 
                                                        FROM student_officials so 
                                                        JOIN council c ON so.council_id = c.id 
                                                        WHERE so.council_id IS NOT NULL 
                                                        AND so.position = 'PRESIDENT' 
                                                        AND so.student_number = ? 
                                                        AND so.academic_year_id = ?";
                        $council_president_check_stmt = $conn->prepare($council_president_check_sql);
                        $council_president_check_stmt->bind_param("si", $student_number, $currentAcademicYearId);
                        $council_president_check_stmt->execute();
                        $council_president_result = $council_president_check_stmt->get_result();
                        
                        if ($council_president_result->num_rows > 0) {
                            $council_president_data = $council_president_result->fetch_assoc();
                            $council_president_check_stmt->close();
                            $_SESSION['error'] = 'Cannot update this student in the organization. ' . htmlspecialchars($council_president_data['name']) . ' is already the President of ' . htmlspecialchars($council_president_data['council_name']) . '. Council presidents cannot hold organization positions.';
                            header('Location: members.php');
                            exit;
                        }
                        $council_president_check_stmt->close();
                    }
                    
                    // Check if the position is already taken by another member (excluding current member)
                    if (!empty($position) && $position !== 'Adviser') {
                        $checkPosition = $conn->prepare("SELECT id FROM student_officials WHERE $ownerColumn = ? AND position = ? AND id != ? AND academic_year_id = ?");
                        $checkPosition->bind_param("issi", $ownerId, $position, $memberId, $currentAcademicYearId);
                        $checkPosition->execute();
                        $checkPosition->store_result();
                        if ($checkPosition->num_rows > 0) {
                            $checkPosition->close();
                            $_SESSION['error'] = 'This position is already taken by another member. Please select a different position.';
                            header('Location: members.php');
                            exit;
                        }
                        $checkPosition->close();
                    }
                    
                    // Create uploads directory if it doesn't exist
                    $upload_dir = './uploads/members/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $picture_path = '';
                    if (!empty($_FILES['picture']['name'])) {
                        $file_name = $_FILES['picture']['name'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $file_size = $_FILES['picture']['size'] ?? 0;
                        
                        if (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime = $finfo ? finfo_file($finfo, $_FILES['picture']['tmp_name']) : '';
                            if ($finfo) { finfo_close($finfo); }
                            if (!in_array($mime, ['image/jpeg','image/png']) || $file_size > 5*1024*1024) {
                                $_SESSION['error'] = 'Invalid image or file too large (max 5MB).';
                                header('Location: members.php');
                                exit;
                            }
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
                            
                            if (move_uploaded_file($_FILES['picture']['tmp_name'], $upload_path)) {
                                $picture_path = $upload_path;
                                
                                // Delete old picture if exists
                                $stmt = $conn->prepare("SELECT picture_path FROM student_officials WHERE id = ? AND $ownerColumn = ?");
                                $stmt->bind_param("ii", $memberId, $ownerId);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                $old_picture = $result->fetch_assoc();
                                
                                if ($old_picture && $old_picture['picture_path'] && file_exists($old_picture['picture_path'])) {
                                    unlink($old_picture['picture_path']);
                                }
                            } else {
                                $_SESSION['error'] = 'Error uploading image. Please try again.';
                                header('Location: members.php');
                                exit;
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid file type. Please upload a JPG, JPEG, or PNG image.';
                            header('Location: members.php');
                            exit;
                        }
                    }
                    
                    if ($picture_path) {
                        $stmt = $conn->prepare("UPDATE student_officials SET name = ?, student_number = ?, course = ?, year_section = ?, position = ?, picture_path = ? WHERE id = ? AND $ownerColumn = ?");
                        $stmt->bind_param("sssssssi", $name, $student_number, $course, $year_section, $position, $picture_path, $memberId, $ownerId);
                    } else {
                        $stmt = $conn->prepare("UPDATE student_officials SET name = ?, student_number = ?, course = ?, year_section = ?, position = ? WHERE id = ? AND $ownerColumn = ?");
                        $stmt->bind_param("sssssii", $name, $student_number, $course, $year_section, $position, $memberId, $ownerId);
                    }
                    
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Member updated successfully!';
                    } else {
                        $_SESSION['error'] = 'Error updating member: ' . $stmt->error;
                    }
                    header('Location: members.php');
                    exit;
                } else {
                    $_SESSION['error'] = 'Please fill in all required fields (name and student number)';
                    header('Location: members.php');
                    exit;
                }
                break;
                
            case 'delete':
                $memberId = $_POST['member_id'] ?? 0;
                if ($memberId) {
                    // Get member's picture path
                    $stmt = $conn->prepare("SELECT picture_path FROM student_officials WHERE id = ? AND $ownerColumn = ?");
                    $stmt->bind_param("ii", $memberId, $ownerId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $member = $result->fetch_assoc();
                    
                    // Delete picture file if exists
                    if ($member && $member['picture_path'] && file_exists($member['picture_path'])) {
                        unlink($member['picture_path']);
                    }
                    
                    // Delete member
                    $stmt = $conn->prepare("DELETE FROM student_officials WHERE id = ? AND $ownerColumn = ?");
                    $stmt->bind_param("ii", $memberId, $ownerId);
                    if ($stmt->execute()) {
                        $_SESSION['message'] = 'Member deleted successfully!';
                    } else {
                        $_SESSION['error'] = 'Error deleting member';
                    }
                    header('Location: members.php');
                    exit;
                }
                break;
                
            case 'create_adviser':
                // Only image should be handled here; name/email come from registered adviser on org/council
                $adviser_position = $_POST['adviser_position'] ?? 'Adviser';

                // Resolve registered adviser from org/council
                if ($role === 'org_president' && $orgId) {
                    $own = $conn->prepare("SELECT adviser_id FROM organizations WHERE id = ?");
                    $own->bind_param("i", $orgId);
                } elseif ($role === 'council_president' && $councilId) {
                    $own = $conn->prepare("SELECT adviser_id FROM council WHERE id = ?");
                    $own->bind_param("i", $councilId);
                } else {
                    $_SESSION['error'] = 'Ownership not found.';
                    header('Location: members.php');
                    exit;
                }
                $own->execute();
                $ownRow = $own->get_result()->fetch_assoc();
                $own->close();

                if (!$ownRow || empty($ownRow['adviser_id'])) {
                    $_SESSION['error'] = 'No registered adviser found on this Organization/Council.';
                    header('Location: members.php');
                    exit;
                }
                $adviser_user_id = (int)$ownRow['adviser_id'];

                // Guard: active academic term
                $currentAcademicYearId = getCurrentAcademicTermId($conn);
                if (!$currentAcademicYearId) {
                    $_SESSION['error'] = 'No active academic year found. Please contact OSAS.';
                    header('Location: members.php');
                    exit;
                }

                // Disallow duplicate assignment in same academic year
                $check_sql = "SELECT id FROM adviser_officials WHERE $ownerColumn = ? AND academic_year_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bind_param("ii", $ownerId, $currentAcademicYearId);
                $check_stmt->execute();
                $check_stmt->store_result();
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    $_SESSION['error'] = 'An adviser has already been assigned for this academic year.';
                    header('Location: members.php');
                    exit;
                }
                $check_stmt->close();

                // Handle optional picture upload
                $upload_dir = './uploads/advisers/';
                if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
                $picture_path = '';
                if (!empty($_FILES['adviser_picture']['name'])) {
                    $file_name = $_FILES['adviser_picture']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    $file_size = $_FILES['adviser_picture']['size'] ?? 0;

                    if (in_array($file_ext, ['jpg', 'jpeg', 'png'])) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = $finfo ? finfo_file($finfo, $_FILES['adviser_picture']['tmp_name']) : '';
                        if ($finfo) { finfo_close($finfo); }
                        if (!in_array($mime, ['image/jpeg','image/png']) || $file_size > 5*1024*1024) {
                            $_SESSION['error'] = 'Invalid image or file too large (max 5MB).';
                            header('Location: members.php');
                            exit;
                        }
                        $original_name = pathinfo($file_name, PATHINFO_FILENAME);
                        $counter = 1;
                        $new_name = $original_name . '.' . $file_ext;
                        $upload_path = $upload_dir . $new_name;
                        while (file_exists($upload_path)) {
                            $new_name = $original_name . '_' . $counter . '.' . $file_ext;
                            $upload_path = $upload_dir . $new_name;
                            $counter++;
                        }
                        if (move_uploaded_file($_FILES['adviser_picture']['tmp_name'], $upload_path)) {
                            $picture_path = $upload_path;
                        } else {
                            $_SESSION['error'] = 'Error uploading image. Please try again.';
                            header('Location: members.php');
                            exit;
                        }
                    } else {
                        $_SESSION['error'] = 'Invalid file type. Please upload a JPG, JPEG, or PNG image.';
                        header('Location: members.php');
                        exit;
                    }
                }

                // Insert adviser for this academic year (owner-specific)
                if ($role === 'org_president') { $bindOrgId = $orgId; $bindCouncilId = null; }
                else { $bindOrgId = null; $bindCouncilId = $councilId; }

                $stmt = $conn->prepare("INSERT INTO adviser_officials (organization_id, council_id, academic_year_id, adviser_id, position, picture_path) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiiss", $bindOrgId, $bindCouncilId, $currentAcademicYearId, $adviser_user_id, $adviser_position, $picture_path);

                if ($stmt->execute()) {
                    // Keep organizations/council registered adviser untouched (name/email are owned by adviser)
                    $_SESSION['message'] = 'Adviser added successfully!';
                } else {
                    $_SESSION['error'] = 'Error adding adviser: ' . $stmt->error;
                }
                $stmt->close();

                header('Location: members.php');
                exit;
                break;

            case 'edit_adviser':
                $adviserId = (int)($_POST['adviser_id'] ?? 0);
                if ($adviserId) {
                    // Only allow updating the picture; name/email are managed by adviser
                    if (!empty($_FILES['adviser_picture']['name'])) {
                        $upload_dir = './uploads/advisers/';
                        if (!file_exists($upload_dir)) { mkdir($upload_dir, 0777, true); }
                        $file_name = $_FILES['adviser_picture']['name'];
                        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = $finfo ? finfo_file($finfo, $_FILES['adviser_picture']['tmp_name']) : '';
                        if ($finfo) { finfo_close($finfo); }
                        if (in_array($ext, ['jpg','jpeg','png']) && in_array($mime, ['image/jpeg','image/png'])) {
                            $dest = $upload_dir . 'adv_' . $adviserId . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['adviser_picture']['tmp_name'], $dest)) {
                                $p = $conn->prepare("UPDATE adviser_officials SET picture_path = ? WHERE id = ?");
                                $p->bind_param("si", $dest, $adviserId);
                                $p->execute();
                                $p->close();
                            } else {
                                $_SESSION['error'] = 'Error uploading image.';
                                header('Location: members.php'); exit;
                            }
                        } else {
                            $_SESSION['error'] = 'Invalid image file.';
                            header('Location: members.php'); exit;
                        }
                    }
                    $_SESSION['message'] = 'Adviser updated successfully!';
                } else {
                    $_SESSION['error'] = 'Invalid adviser.';
                }
                header('Location: members.php');
                exit;

            case 'delete_adviser':
                $adviserId = (int)($_POST['adviser_id'] ?? 0);
                if ($adviserId) {
                    // Delete adviser_officials row (do not clear registered adviser on org/council)
                    $d = $conn->prepare("DELETE FROM adviser_officials WHERE id = ?");
                    $d->bind_param("i", $adviserId);
                    $d->execute();
                    $d->close();
                    $_SESSION['message'] = 'Adviser removed successfully!';
                }
                header('Location: members.php');
                exit;
        }
    }
}

// Inline student search logic (AJAX handler)
if (isset($_GET['student_search']) && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = strtoupper($conn->real_escape_string($_GET['q']));

    if ($role === 'council_president') {
        // Council: search across all organizations within the council's college
        $councilId = getCurrentCouncilId();
        // Find all organization ids under this council's college
        $orgIds = [];
        $orgQuery = $conn->prepare('SELECT o.id FROM organizations o INNER JOIN council c ON o.college_id = c.college_id WHERE c.id = ?');
        $orgQuery->bind_param('i', $councilId);
        $orgQuery->execute();
        $orgRes = $orgQuery->get_result();
        while ($row = $orgRes->fetch_assoc()) { $orgIds[] = (int)$row['id']; }
        $orgQuery->close();

        if (empty($orgIds)) {
            echo json_encode(['students' => []]);
            exit;
        }

        // Build IN clause dynamically and search
        $placeholders = implode(',', array_fill(0, count($orgIds), '?'));
        $types = str_repeat('i', count($orgIds));
        $sql = "SELECT student_number, student_name, section, course FROM student_data WHERE (student_name LIKE CONCAT('%', ?, '%') OR student_number LIKE CONCAT('%', ?, '%')) AND organization_id IN ($placeholders) LIMIT 10";
        $stmt = $conn->prepare($sql);
        $bindTypes = 'ss' . $types;
        $bindValues = array_merge([$q, $q], $orgIds);
        $stmt->bind_param($bindTypes, ...$bindValues);
    } else {
        // Organization: keep scoped to current organization
        $orgId = getCurrentOrganizationId();
        $stmt = $conn->prepare('SELECT student_number, student_name, section, course FROM student_data WHERE (student_name LIKE CONCAT("%", ?, "%") OR student_number LIKE CONCAT("%", ?, "%")) AND organization_id = ? LIMIT 10');
        $stmt->bind_param('ssi', $q, $q, $orgId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'student_number' => $row['student_number'],
            'name' => $row['student_name'],
            'section' => $row['section'],
            'course' => $row['course'],
            'year_level' => $row['section']
        ];
    }
    $stmt->close();
    echo json_encode(['students' => $students]);
    exit;
}

// Get members scoped by owner with custom position ordering
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
    WHERE $ownerColumn = ?
    AND at.status = 'active'
    
    UNION ALL
    
    SELECT 
        ao.id,
        ao.organization_id,
        ao.council_id,
        ao.academic_year_id,
        CASE 
            WHEN '$role' = 'org_president' THEN o.adviser_name
            WHEN '$role' = 'council_president' THEN c.adviser_name
            ELSE u.email
        END as name,
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
    LEFT JOIN organizations o ON ao.organization_id = o.id
    LEFT JOIN council c ON ao.council_id = c.id
    WHERE (CASE WHEN '$ownerColumn' = 'organization_id' THEN ao.organization_id = ? ELSE ao.council_id = ? END)
    AND at.status = 'active'
    
    ORDER BY position_order, name
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $ownerId, $ownerId, $ownerId);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hasAdviser = false;
$takenPositions = [];
foreach ($members as $m) {
    if (($m['member_type'] ?? '') === 'adviser') { $hasAdviser = true; }
    if (($m['member_type'] ?? '') === 'student' && !empty($m['position'])) { $takenPositions[$m['position']] = true; }
}
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

    <!-- Custom Styled Notification -->
    <div id="customNotification" class="custom-notification">
        <div class="custom-notification-header">
            <div class="custom-notification-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h6 class="custom-notification-title" id="customNotificationTitle">Duplicate Member</h6>
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
                        <h2 class="page-title">Officers Management</h2>
                        <p class="page-subtitle">Manage organization officers and members</p>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light officer-action" data-bs-toggle="modal" data-bs-target="#createMemberModal" <?php echo (!$isRecognized || $studentDataReminder) ? 'disabled' : ''; ?> >
                            <i class="bi bi-plus-circle"></i> Add New Officer
                        </button>
                        <button type="button" class="btn btn-light officer-action" data-bs-toggle="modal" data-bs-target="#createAdviserModal" <?php echo (!$isRecognized || $studentDataReminder || $hasAdviser) ? 'disabled' : ''; ?> >
                            <i class="bi bi-person-plus"></i> Add Adviser
                        </button>
                    </div>
                </div>
            </div>
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
            
            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['message'];
                    unset($_SESSION['message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <?php 
                $errorMessage = $error;
                $isPresidentError = strpos($errorMessage, 'already the President of') !== false;
                ?>
                <?php if ($isPresidentError): ?>
                    <!-- Error will be shown via custom notification -->
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            showCustomNotification(
                                <?php echo json_encode($errorMessage); ?>,
                                'Organization President',
                                8000
                            );
                        });
                    </script>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <?php echo htmlspecialchars($errorMessage); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="row">
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
                            <?php if ($member['member_type'] !== 'adviser'): ?>
                            <div class="position-absolute top-0 end-0 p-2">
                                <div class="btn-group-vertical" role="group">
                                    <button type="button" class="btn btn-light btn-sm rounded-circle shadow-sm mb-1" data-bs-toggle="modal" data-bs-target="#editMemberModal" data-member-id="<?php echo $member['id']; ?>" title="Edit Member">
                                        <i class="bi bi-pencil text-primary"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this member?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="member_id" value="<?php echo $member['id']; ?>">
                                        <button type="submit" class="btn btn-light btn-sm rounded-circle shadow-sm" title="Delete Member">
                                            <i class="bi bi-trash text-danger"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="position-absolute top-0 end-0 p-2">
                                <div class="btn-group-vertical" role="group">
                                    <button type="button" class="btn btn-light btn-sm rounded-circle shadow-sm mb-1" data-bs-toggle="modal" data-bs-target="#editAdviserModal" data-adviser-id="<?php echo $member['id']; ?>" title="Edit Adviser" <?php echo (!$isRecognized) ? 'disabled' : ''; ?>>
                                        <i class="bi bi-pencil text-primary"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove adviser for this academic year?');">
                                        <input type="hidden" name="action" value="delete_adviser">
                                        <input type="hidden" name="adviser_id" value="<?php echo $member['id']; ?>">
                                        <button type="submit" class="btn btn-light btn-sm rounded-circle shadow-sm" title="Delete Adviser" <?php echo (!$isRecognized) ? 'disabled' : ''; ?>>
                                            <i class="bi bi-trash text-danger"></i>
                                        </button>
                                    </form>
                                </div>
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
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Create Member Modal -->
    <div class="modal fade" id="createMemberModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Officer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label for="student_search" class="form-label">Search Student (Name or Student Number)</label>
                            <input type="text" class="form-control" id="student_search" placeholder="Type name or student number..." autocomplete="off">
                            <div id="student_search_results" class="list-group position-absolute w-100" style="z-index: 1051;"></div>
                        </div>
                        <input type="hidden" name="name" id="selected_name" required>
                        <input type="hidden" name="student_number" id="selected_student_number" required>
                        <div class="mb-3">
                            <label for="position" class="form-label">Position</label>
                            <select class="form-select" id="position" name="position" required>
                                <option value="">Select Position</option>
                                <option value="PRESIDENT">PRESIDENT</option>
                                <option value="VICE PRESIDENT FOR INTERNAL AFFAIRS">VICE PRESIDENT FOR INTERNAL AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR EXTERNAL AFFAIRS">VICE PRESIDENT FOR EXTERNAL AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR RECORDS AND DOCUMENTATION">VICE PRESIDENT FOR RECORDS AND DOCUMENTATION</option>
                                <option value="VICE PRESIDENT FOR OPERATIONS AND ACADEMIC AFFAIRS">VICE PRESIDENT FOR OPERATIONS AND ACADEMIC AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR FINANCE AND BUDGET MANAGEMENT">VICE PRESIDENT FOR FINANCE AND BUDGET MANAGEMENT</option>
                                <option value="VICE PRESIDENT FOR AUDIT">VICE PRESIDENT FOR AUDIT</option>
                                <option value="VICE PRESIDENT FOR LOGISTICS AND PROPERTY MANAGEMENT">VICE PRESIDENT FOR LOGISTICS AND PROPERTY MANAGEMENT</option>
                                <option value="VICE PRESIDENT FOR PUBLIC RELATIONS AND INFORMATION">VICE PRESIDENT FOR PUBLIC RELATIONS AND INFORMATION</option>
                                <option value="VICE PRESIDENT FOR GENDER AND DEVELOPMENT">VICE PRESIDENT FOR GENDER AND DEVELOPMENT</option>
                                <option value="2ND YEAR REPRESENTATIVE">2ND YEAR REPRESENTATIVE</option>
                                <option value="3RD YEAR REPRESENTATIVE">3RD YEAR REPRESENTATIVE</option>
                                <option value="4TH YEAR REPRESENTATIVE">4TH YEAR REPRESENTATIVE</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="course" class="form-label">Course</label>
                            <input type="text" class="form-control" id="course" name="course" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="year_section" class="form-label">Year & Section</label>
                            <input type="text" class="form-control" id="year_section" name="year_section" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="picture" class="form-label">Profile Picture</label>
                            <input type="file" class="form-control" id="picture" name="picture" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" id="resetMemberForm">Reset</button>
                        <button type="submit" class="btn btn-primary">Add Officer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Officer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="member_id" id="edit_member_id">
                        
                        <div class="mb-3">
                            <label for="edit_student_search" class="form-label">Search Student (Name or Student Number)</label>
                            <input type="text" class="form-control" id="edit_student_search" placeholder="Type name or student number..." autocomplete="off">
                            <div id="edit_student_search_results" class="list-group position-absolute w-100" style="z-index: 1051;"></div>
                        </div>
                        <input type="hidden" name="name" id="edit_selected_name" required>
                        <input type="hidden" name="student_number" id="edit_selected_student_number" required>
                        
                        <div class="mb-3">
                            <label for="edit_position" class="form-label">Position</label>
                            <select class="form-select" id="edit_position" name="position" required>
                                <option value="">Select Position</option>
                                <option value="PRESIDENT">PRESIDENT</option>
                                <option value="VICE PRESIDENT FOR INTERNAL AFFAIRS">VICE PRESIDENT FOR INTERNAL AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR EXTERNAL AFFAIRS">VICE PRESIDENT FOR EXTERNAL AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR RECORDS AND DOCUMENTATION">VICE PRESIDENT FOR RECORDS AND DOCUMENTATION</option>
                                <option value="VICE PRESIDENT FOR OPERATIONS AND ACADEMIC AFFAIRS">VICE PRESIDENT FOR OPERATIONS AND ACADEMIC AFFAIRS</option>
                                <option value="VICE PRESIDENT FOR FINANCE AND BUDGET MANAGEMENT">VICE PRESIDENT FOR FINANCE AND BUDGET MANAGEMENT</option>
                                <option value="VICE PRESIDENT FOR AUDIT">VICE PRESIDENT FOR AUDIT</option>
                                <option value="VICE PRESIDENT FOR LOGISTICS AND PROPERTY MANAGEMENT">VICE PRESIDENT FOR LOGISTICS AND PROPERTY MANAGEMENT</option>
                                <option value="VICE PRESIDENT FOR PUBLIC RELATIONS AND INFORMATION">VICE PRESIDENT FOR PUBLIC RELATIONS AND INFORMATION</option>
                                <option value="VICE PRESIDENT FOR GENDER AND DEVELOPMENT">VICE PRESIDENT FOR GENDER AND DEVELOPMENT</option>
                                <option value="2ND YEAR REPRESENTATIVE">2ND YEAR REPRESENTATIVE</option>
                                <option value="3RD YEAR REPRESENTATIVE">3RD YEAR REPRESENTATIVE</option>
                                <option value="4TH YEAR REPRESENTATIVE">4TH YEAR REPRESENTATIVE</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_course" class="form-label">Course</label>
                            <input type="text" class="form-control" id="edit_course" name="course" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_year_section" class="form-label">Year & Section</label>
                            <input type="text" class="form-control" id="edit_year_section" name="year_section">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_picture" class="form-label">Profile Picture</label>
                            <input type="file" class="form-control" id="edit_picture" name="picture" accept="image/*">
                            <div id="current_picture" class="mt-2"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Officer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Create Adviser Modal -->
    <div class="modal fade" id="createAdviserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Adviser</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_adviser">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" id="adviser_name" value="" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="adviser_email" value="" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="adviser_position" class="form-label">Position</label>
                            <input type="text" class="form-control" id="adviser_position" name="adviser_position" value="Adviser" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="adviser_picture" class="form-label">Profile Picture</label>
                            <input type="file" class="form-control" id="adviser_picture" name="adviser_picture" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning" id="resetAdviserForm">Reset</button>
                        <button type="submit" class="btn btn-primary">Add Adviser</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Adviser Modal -->
    <div class="modal fade" id="editAdviserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Adviser</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_adviser">
                        <input type="hidden" name="adviser_id" id="edit_adviser_id">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" id="edit_adviser_name" value="" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_adviser_email" value="" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="edit_adviser_picture" class="form-label">Profile Picture</label>
                            <input type="file" class="form-control" id="edit_adviser_picture" name="adviser_picture" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Adviser</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

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
        
        .btn-group-vertical .btn {
            padding: 0.4rem;
            margin-bottom: 0.25rem;
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }
        
        .btn-group-vertical .btn:hover {
            background-color: #f8f9fa;
            transform: scale(1.15);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .position-badge {
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.6) 100%);
            padding: 0.5rem;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
        }
        
        .position-badge-text {
            background: linear-gradient(45deg, #495057, #6c757d);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            font-weight: 600 !important;
            padding: 0.5rem 1.5rem !important;
            border-radius: 8px !important;
            color: white !important;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3) !important;
            color: white !important;
        }
        
        .btn-primary:focus {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            color: white !important;
            box-shadow: 0 0 0 0.2rem rgba(52, 58, 64, 0.25) !important;
        }
        
        .btn-primary:active {
            background: linear-gradient(45deg, #212529, #343a40) !important;
            border: none !important;
            color: white !important;
        }
        
        /* Force identical styling for both action buttons */
        .officer-action,
        .officer-action:link,
        .officer-action:visited,
        .officer-action:focus,
        .officer-action:active,
        .officer-action:hover {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            border: 1px solid #dee2e6 !important;
            color: #495057 !important;
            text-decoration: none !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
        }
        
        .officer-action:hover {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
        }
        
        .officer-action:focus {
            background: linear-gradient(45deg, #f8f9fa, #e9ecef) !important;
            background-color: #f8f9fa !important;
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
        }
        
        .officer-action:active {
            background: linear-gradient(45deg, #e9ecef, #dee2e6) !important;
            background-color: #e9ecef !important;
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
        
        .alert-info {
            background: linear-gradient(90deg, rgba(13, 202, 240, 0.1) 0%, rgba(13, 202, 240, 0.05) 100%) !important;
            border: 1px solid rgba(13, 202, 240, 0.3) !important;
            color: #0c5460 !important;
            border-radius: 8px !important;
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
        
        .form-label {
            color: #495057;
            font-weight: 500;
        }
        
        .form-control:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            border-radius: 6px;
        }
        
        .btn-secondary:hover {
            background-color: #5c636a;
            border-color: #565e64;
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
        
        /* Improved button visibility */
        .btn-group-vertical .btn i.text-primary {
            color: #fd7e14 !important; /* Orange color for edit button */
        }
        
        .btn-group-vertical .btn i.text-danger {
            color: #dc3545 !important; /* Red color for delete button */
        }
        
        .btn-group-vertical .btn:hover i.text-primary {
            color: #e8690f !important; /* Darker orange on hover for edit button */
        }
        
        .btn-group-vertical .btn:hover i.text-danger {
            color: #c02a37 !important; /* Darker red on hover for delete button */
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
            
            .btn-group-vertical .btn {
                width: 28px;
                height: 28px;
                padding: 0.3rem;
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
        
        /* Search results styling for both modals */
        #student_search_results, #edit_student_search_results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            background: white;
            z-index: 1051;
        }
        
        #student_search_results .list-group-item, #edit_student_search_results .list-group-item {
            border: none;
            border-bottom: 1px solid #f8f9fa;
            padding: 0.75rem;
            cursor: pointer;
        }
        
        #student_search_results .list-group-item:hover, #edit_student_search_results .list-group-item:hover {
            background-color: #f8f9fa;
        }
        
        #student_search_results .list-group-item:last-child, #edit_student_search_results .list-group-item:last-child {
            border-bottom: none;
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
            border: 2px solid #6c757d;
            border-left: 5px solid #6c757d;
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
            background: linear-gradient(45deg, #495057, #6c757d);
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const TAKEN_POSITIONS = <?php echo json_encode(array_keys($takenPositions)); ?>;
        
        // Store original position options for edit modal
        const editPositionSelect = document.getElementById('edit_position');
        const originalEditPositionOptions = Array.from(editPositionSelect.options).map(opt => ({
            value: opt.value,
            text: opt.text
        }));
        
        // Function to restore original position options
        function restoreEditPositionOptions() {
            editPositionSelect.innerHTML = '';
            originalEditPositionOptions.forEach(opt => {
                const option = document.createElement('option');
                option.value = opt.value;
                option.textContent = opt.text;
                editPositionSelect.appendChild(option);
            });
        }
        
        // Auto-fill course when Add Member modal opens
        document.getElementById('createMemberModal').addEventListener('show.bs.modal', function() {
            // For org presidents: prefill course via endpoint
            // For council presidents: leave course empty until a student is selected (college-wide)
            fetch('get_org_course.php')
                .then(response => response.json())
                .then(data => {
                    if (data.course) {
                        document.getElementById('course').value = data.course;
                    }
                })
                .catch(() => {});
            
            // Remove positions already taken (and hide Adviser entirely)
            const select = document.getElementById('position');
            const toRemove = [];
            Array.from(select.options).forEach(opt => {
                const val = opt.value;
                if (!val) return; // keep placeholder
                if (val === 'Adviser') { toRemove.push(opt); return; }
                if (TAKEN_POSITIONS.includes(val)) { toRemove.push(opt); }
            });
            toRemove.forEach(opt => opt.remove());
        });

        // Auto-fill adviser data when Add Adviser modal opens
        document.getElementById('createAdviserModal').addEventListener('show.bs.modal', function() {
            const REGISTERED_ADVISER = <?php echo json_encode($registeredAdviser); ?>;

            // Get current adviser information (cache-busted)
            fetch('get_current_adviser.php?t=' + Date.now(), { cache: 'no-store' })
                .then(response => response.json())
                .then(data => {
                    const name = (data && data.adviser_name) ? data.adviser_name : (REGISTERED_ADVISER.name || '');
                    const email = (data && data.adviser_email) ? data.adviser_email : (REGISTERED_ADVISER.email || '');
                    document.getElementById('adviser_name').value = name || '';
                    document.getElementById('adviser_email').value = email || '';
                })
                .catch(() => {
                    document.getElementById('adviser_name').value = REGISTERED_ADVISER.name || '';
                    document.getElementById('adviser_email').value = REGISTERED_ADVISER.email || '';
                });
        });

        // Populate Edit Adviser modal
        document.getElementById('editAdviserModal').addEventListener('show.bs.modal', function(event){
            const btn = event.relatedTarget;
            const adviserId = btn ? btn.getAttribute('data-adviser-id') : '';
            document.getElementById('edit_adviser_id').value = adviserId;
            // Try to derive name/email from card
            const card = btn.closest('.card');
            if (card){
                const name = card.querySelector('.card-title')?.textContent || '';
                const email = card.querySelector('.member-info span.text-muted')?.textContent || '';
                document.getElementById('edit_adviser_name').value = name || '';
                document.getElementById('edit_adviser_email').value = email || '';
            }
        });

        // Handle edit member modal
        document.getElementById('editMemberModal').addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const memberId = button.getAttribute('data-member-id');
            document.getElementById('edit_member_id').value = memberId;
            
            // Restore original position options first
            restoreEditPositionOptions();
            
            // Show loading state
            document.getElementById('edit_selected_name').value = 'Loading...';
            document.getElementById('edit_selected_student_number').value = 'Loading...';
            document.getElementById('edit_position').value = 'Loading...';
            document.getElementById('edit_course').value = 'Loading...';
            document.getElementById('edit_year_section').value = 'Loading...';
            document.getElementById('current_picture').innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading...</p></div>';
            
            // Fetch member details
            fetch(`get_member_details.php?member_id=${memberId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }

                    // Set form values
                    document.getElementById('edit_selected_name').value = data.name || '';
                    document.getElementById('edit_selected_student_number').value = data.student_number || '';
                    document.getElementById('edit_student_search').value = data.name || '';
                    
                    // Set position dropdown
                    const editPositionSelect = document.getElementById('edit_position');
                    const currentPosition = data.position || '';
                    
                    // Remove taken positions from dropdown, but keep current member's position available
                    Array.from(editPositionSelect.options).forEach(opt => {
                        const val = opt.value;
                        if (!val) return; // keep placeholder
                        if (val === 'Adviser') { opt.remove(); return; } // remove Adviser
                        // Remove if position is taken AND it's not the current member's position
                        if (TAKEN_POSITIONS.includes(val) && val !== currentPosition) {
                            opt.remove();
                        }
                    });
                    
                    editPositionSelect.value = currentPosition || '';
                    
                    document.getElementById('edit_course').value = data.course || '';
                    document.getElementById('edit_year_section').value = data.year_section || '';
                    
                    // Show current picture if exists
                    if (data.picture_path) {
                        document.getElementById('current_picture').innerHTML = `
                            <div class="alert alert-info">
                                <img src="${data.picture_path}" class="img-thumbnail" style="max-height: 100px;">
                                <p class="mt-2 mb-0">Current profile picture</p>
                            </div>
                        `;
                    } else {
                        document.getElementById('current_picture').innerHTML = '<div class="alert alert-info">No profile picture uploaded</div>';
                    }
                })
                .catch(error => {
                    console.error('Error loading member details:', error);
                    document.getElementById('edit_selected_name').value = '';
                    document.getElementById('edit_selected_student_number').value = '';
                    document.getElementById('edit_student_search').value = '';
                    document.getElementById('edit_position').value = '';
                    document.getElementById('edit_course').value = '';
                    document.getElementById('edit_year_section').value = '';
                    document.getElementById('current_picture').innerHTML = '<div class="alert alert-danger">Error loading member details. Please try again.</div>';
                });
        });

        // Student search autocomplete for Add Member
        const studentSearch = document.getElementById('student_search');
        const studentResults = document.getElementById('student_search_results');
        let searchTimeout;
        
        studentSearch.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            // Convert input to uppercase
            this.value = this.value.toUpperCase();
            const query = this.value.trim();
            
            // Clear results if query is too short
            if (query.length < 2) {
                studentResults.innerHTML = '';
                studentResults.style.display = 'none';
                document.getElementById('selected_name').value = '';
                document.getElementById('selected_student_number').value = '';
                // Do NOT clear the course field - keep it based on logged-in account
                document.getElementById('year_section').value = '';
                return;
            }
            
            // Show loading state
            studentResults.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Searching...</div>';
            studentResults.style.display = 'block';
            
            searchTimeout = setTimeout(() => {
                fetch(`members.php?student_search=1&q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.students && data.students.length > 0) {
                            studentResults.innerHTML = data.students.map(student =>
                                `<button type="button" class="list-group-item list-group-item-action" data-name="${student.name}" data-student-number="${student.student_number}" data-year-level="${student.year_level}" data-section="${student.section}" data-course="${student.course}">
                                    <strong>${student.name}</strong> (${student.student_number})<br>
                                    <small>${student.section}</small>
                                </button>`
                            ).join('');
                            studentResults.style.display = 'block';
                        } else {
                            studentResults.innerHTML = '<div class="list-group-item text-muted">No students found.</div>';
                            studentResults.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        studentResults.innerHTML = '<div class="list-group-item text-danger">Error searching students.</div>';
                        studentResults.style.display = 'block';
                    });
            }, 300);
        });
        
        studentResults.addEventListener('click', async function(e) {
            if (e.target.closest('button')) {
                const btn = e.target.closest('button');
                const studentNumber = btn.getAttribute('data-student-number');
                const studentName = btn.getAttribute('data-name');
                
                // Check for duplicate member
                const isDuplicate = await checkDuplicateMember(studentNumber, studentName, false);
                if (isDuplicate) {
                    // Clear the selection
                    document.getElementById('student_search').value = '';
                    document.getElementById('selected_name').value = '';
                    document.getElementById('selected_student_number').value = '';
                    document.getElementById('course').value = '';
                    document.getElementById('year_section').value = '';
                    studentResults.innerHTML = '';
                    studentResults.style.display = 'none';
                    return;
                }
                
                // Check if student is organization president (only for council presidents)
                <?php if ($role === 'council_president'): ?>
                const isOrgPresident = await checkOrganizationPresident(studentNumber, studentName);
                if (isOrgPresident) {
                    // Clear the selection
                    document.getElementById('student_search').value = '';
                    document.getElementById('selected_name').value = '';
                    document.getElementById('selected_student_number').value = '';
                    document.getElementById('course').value = '';
                    document.getElementById('year_section').value = '';
                    studentResults.innerHTML = '';
                    studentResults.style.display = 'none';
                    return;
                }
                <?php endif; ?>
                
                // Check if student is council president (only for org presidents)
                <?php if ($role === 'org_president'): ?>
                const isCouncilPresident = await checkCouncilPresident(studentNumber, studentName);
                if (isCouncilPresident) {
                    // Clear the selection
                    document.getElementById('student_search').value = '';
                    document.getElementById('selected_name').value = '';
                    document.getElementById('selected_student_number').value = '';
                    document.getElementById('course').value = '';
                    document.getElementById('year_section').value = '';
                    studentResults.innerHTML = '';
                    studentResults.style.display = 'none';
                    return;
                }
                <?php endif; ?>
                
                // If not duplicate and not organization president, proceed with selection
                document.getElementById('selected_name').value = studentName;
                document.getElementById('selected_student_number').value = studentNumber;
                // For council presidents, set course/section from selected student
                document.getElementById('course').value = btn.getAttribute('data-course');
                document.getElementById('year_section').value = btn.getAttribute('data-section');
                studentSearch.value = studentName + ' (' + studentNumber + ')';
                studentResults.innerHTML = '';
                studentResults.style.display = 'none';
            }
        });
        
        // Prevent form submission if no student is selected or no position is selected
        document.querySelector('#createMemberModal form').addEventListener('submit', function(e) {
            if (!document.getElementById('selected_name').value || !document.getElementById('selected_student_number').value) {
                alert('Please select a student from the search results.');
                e.preventDefault();
                return;
            }
            
            if (!document.getElementById('position').value) {
                alert('Please select a position.');
                e.preventDefault();
                return;
            }
        });

        // Student search autocomplete for Edit Member
        const editStudentSearch = document.getElementById('edit_student_search');
        const editStudentResults = document.getElementById('edit_student_search_results');
        let editSearchTimeout;
        
        editStudentSearch.addEventListener('input', function() {
            clearTimeout(editSearchTimeout);
            // Convert input to uppercase
            this.value = this.value.toUpperCase();
            const query = this.value.trim();
            
            // Clear results if query is too short
            if (query.length < 2) {
                editStudentResults.innerHTML = '';
                editStudentResults.style.display = 'none';
                document.getElementById('edit_selected_name').value = '';
                document.getElementById('edit_selected_student_number').value = '';
                document.getElementById('edit_year_section').value = '';
                return;
            }
            
            // Show loading state
            editStudentResults.innerHTML = '<div class="list-group-item text-center"><div class="spinner-border spinner-border-sm" role="status"></div> Searching...</div>';
            editStudentResults.style.display = 'block';
            
            editSearchTimeout = setTimeout(() => {
                fetch(`members.php?student_search=1&q=${encodeURIComponent(query)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.students && data.students.length > 0) {
                            editStudentResults.innerHTML = data.students.map(student =>
                                `<button type="button" class="list-group-item list-group-item-action" data-name="${student.name}" data-student-number="${student.student_number}" data-year-level="${student.year_level}" data-section="${student.section}" data-course="${student.course}">
                                    <strong>${student.name}</strong> (${student.student_number})<br>
                                    <small>${student.section}</small>
                                </button>`
                            ).join('');
                            editStudentResults.style.display = 'block';
                        } else {
                            editStudentResults.innerHTML = '<div class="list-group-item text-muted">No students found.</div>';
                            editStudentResults.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        editStudentResults.innerHTML = '<div class="list-group-item text-danger">Error searching students.</div>';
                        editStudentResults.style.display = 'block';
                    });
            }, 300);
        });
        
        editStudentResults.addEventListener('click', async function(e) {
            if (e.target.closest('button')) {
                const btn = e.target.closest('button');
                const studentNumber = btn.getAttribute('data-student-number');
                const studentName = btn.getAttribute('data-name');
                const currentMemberId = document.getElementById('edit_member_id').value;
                
                // Check for duplicate member (excluding current member being edited)
                const isDuplicate = await checkDuplicateMember(studentNumber, studentName, true, currentMemberId);
                if (isDuplicate) {
                    // Clear the selection
                    document.getElementById('edit_student_search').value = '';
                    document.getElementById('edit_selected_name').value = '';
                    document.getElementById('edit_selected_student_number').value = '';
                    document.getElementById('edit_year_section').value = '';
                    editStudentResults.innerHTML = '';
                    editStudentResults.style.display = 'none';
                    return;
                }
                
                // Check if student is organization president (only for council presidents)
                <?php if ($role === 'council_president'): ?>
                const isOrgPresident = await checkOrganizationPresident(studentNumber, studentName);
                if (isOrgPresident) {
                    // Clear the selection
                    document.getElementById('edit_student_search').value = '';
                    document.getElementById('edit_selected_name').value = '';
                    document.getElementById('edit_selected_student_number').value = '';
                    document.getElementById('edit_year_section').value = '';
                    editStudentResults.innerHTML = '';
                    editStudentResults.style.display = 'none';
                    return;
                }
                <?php endif; ?>
                
                // Check if student is council president (only for org presidents)
                <?php if ($role === 'org_president'): ?>
                const isCouncilPresident = await checkCouncilPresident(studentNumber, studentName);
                if (isCouncilPresident) {
                    // Clear the selection
                    document.getElementById('edit_student_search').value = '';
                    document.getElementById('edit_selected_name').value = '';
                    document.getElementById('edit_selected_student_number').value = '';
                    document.getElementById('edit_year_section').value = '';
                    editStudentResults.innerHTML = '';
                    editStudentResults.style.display = 'none';
                    return;
                }
                <?php endif; ?>
                
                // If not duplicate and not organization president, proceed with selection
                document.getElementById('edit_selected_name').value = studentName;
                document.getElementById('edit_selected_student_number').value = studentNumber;
                document.getElementById('edit_year_section').value = btn.getAttribute('data-section');
                editStudentSearch.value = studentName + ' (' + studentNumber + ')';
                editStudentResults.innerHTML = '';
                editStudentResults.style.display = 'none';
            }
        });
        
        // Prevent form submission if no student is selected or no position is selected for edit modal
        document.querySelector('#editMemberModal form').addEventListener('submit', function(e) {
            if (!document.getElementById('edit_selected_name').value || !document.getElementById('edit_selected_student_number').value) {
                alert('Please select a student from the search results.');
                e.preventDefault();
                return;
            }
            
            if (!document.getElementById('edit_position').value) {
                alert('Please select a position.');
                e.preventDefault();
                return;
            }
        });
        
        document.addEventListener('click', function(e) {
            if (!studentSearch.contains(e.target) && !studentResults.contains(e.target)) {
                studentResults.innerHTML = '';
                studentResults.style.display = 'none';
            }
            if (!editStudentSearch.contains(e.target) && !editStudentResults.contains(e.target)) {
                editStudentResults.innerHTML = '';
                editStudentResults.style.display = 'none';
            }
        });

        // Reset form button for Add Member modal
        document.getElementById('resetMemberForm').addEventListener('click', function() {
            // Clear all fields except course (which should remain based on logged-in account)
            document.getElementById('student_search').value = '';
            document.getElementById('selected_name').value = '';
            document.getElementById('selected_student_number').value = '';
            document.getElementById('position').value = '';
            document.getElementById('year_section').value = '';
            document.getElementById('picture').value = '';
            
            // Clear search results
            document.getElementById('student_search_results').innerHTML = '';
            document.getElementById('student_search_results').style.display = 'none';
            
            // Keep the course field as is (based on logged-in account)
            // The course field will remain unchanged
        });

        // Reset form button for Add Adviser modal
        document.getElementById('resetAdviserForm').addEventListener('click', function() {
            // Only clear picture; name/email are read-only display
            document.getElementById('adviser_picture').value = '';
            document.getElementById('adviser_position').value = 'Adviser';
        });

        // Function to show custom styled notification
        function showCustomNotification(message, title = 'Duplicate Member', duration = 5000) {
            const notification = document.getElementById('customNotification');
            const notificationBody = document.getElementById('customNotificationBody');
            const notificationTitle = document.getElementById('customNotificationTitle');
            
            if (!notification || !notificationBody || !notificationTitle) return;
            
            // Set title and message
            notificationTitle.textContent = title;
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

        // Function to check if student is already a member
        function checkDuplicateMember(studentNumber, studentName, isEdit = false, currentMemberId = null) {
            return fetch(`check_duplicate_member.php?student_number=${encodeURIComponent(studentNumber)}&name=${encodeURIComponent(studentName)}&is_edit=${isEdit ? 1 : 0}&current_id=${currentMemberId || ''}`)
                .then(response => response.json())
                .then(data => {
                    if (data.is_duplicate) {
                        showCustomNotification(data.message || 'This student is already registered as a member in this organization/council.');
                        return true;
                    }
                    return false;
                })
                .catch(error => {
                    console.error('Error checking duplicate:', error);
                    return false;
                });
        }

        // Function to check if student is an organization president
        function checkOrganizationPresident(studentNumber, studentName) {
            return fetch(`check_organization_president.php?student_number=${encodeURIComponent(studentNumber)}&name=${encodeURIComponent(studentName)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.is_president) {
                        showCustomNotification(
                            data.message || 'This student is already the President of an organization.',
                            'Organization President',
                            8000
                        );
                        return true;
                    }
                    return false;
                })
                .catch(error => {
                    console.error('Error checking organization president:', error);
                    return false;
                });
        }

        // Function to check if student is a council president
        function checkCouncilPresident(studentNumber, studentName) {
            return fetch(`check_council_president.php?student_number=${encodeURIComponent(studentNumber)}&name=${encodeURIComponent(studentName)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.is_president) {
                        showCustomNotification(
                            data.message || 'This student is already the President of a council.',
                            'Council President',
                            8000
                        );
                        return true;
                    }
                    return false;
                })
                .catch(error => {
                    console.error('Error checking council president:', error);
                    return false;
                });
        }
    </script>
</body>
</html> 
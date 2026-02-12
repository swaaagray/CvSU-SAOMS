<?php
/**
 * Organization Registration & Applications Management
 * 
 * This page allows OSAS to:
 * - Review and approve/reject organization/council applications
 * - Register MIS coordinators manually
 * - Register colleges and courses
 */

require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/email_helper.php';
require_once 'config/email.php';
require_once 'phpmailer/src/PHPMailer.php';
require_once 'phpmailer/src/SMTP.php';
require_once 'phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Try to use advanced email helper if available
if (file_exists('includes/email_helper_advanced.php')) {
    require_once 'includes/email_helper_advanced.php';
}

requireRole(['osas']);

// Function to check if there's an active academic year
function checkActiveAcademicYear($conn) {
    $stmt = $conn->prepare('SELECT id, school_year, start_date, end_date FROM academic_terms WHERE status = "active" ORDER BY start_date DESC LIMIT 1');
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return false;
}

// Function to check if a college has an MIS coordinator
function checkCollegeHasMIS($conn, $college_id) {
	$stmt = $conn->prepare('SELECT mc.id, mc.coordinator_name, u.email FROM mis_coordinators mc JOIN users u ON mc.user_id = u.id WHERE mc.college_id = ?');
    $stmt->bind_param('i', $college_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return false;
}

// Helper function to send registration emails for approved applications
function sendAppRegistrationEmail($email, $username, $password, $role, $orgName) {
    global $conn;
    
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);
        
        $emailTemplate = getRegistrationEmailTemplate($username, $password, $role, $orgName);
        $mail->isHTML(true);
        $mail->Subject = $emailTemplate['subject'];
        $mail->Body = $emailTemplate['body'];
        
        $mail->send();
    } catch (Exception $e) {
        error_log("Registration email error: " . $e->getMessage());
    }
}

// Helper function to send application status emails
function sendAppStatusEmail($email, $status, $applicationType, $orgName, $rejectionReason = '') {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);
        
        $emailTemplate = getApplicationStatusEmailTemplate($status, $applicationType, $orgName, $rejectionReason);
        $mail->isHTML(true);
        $mail->Subject = $emailTemplate['subject'];
        $mail->Body = $emailTemplate['body'];
        
        $mail->send();
    } catch (Exception $e) {
        error_log("Status email error: " . $e->getMessage());
    }
}

// Check for active academic year before allowing any registration
$activeAcademicYear = checkActiveAcademicYear($conn);
$academicYearError = '';

if (!$activeAcademicYear) {
    $academicYearError = 'No active academic year found. Please create an academic year in Calendar Management before registering organizations or councils.';
}

$message = '';
$success = '';
$error = '';

// Clear any previous error messages when starting fresh
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $error = '';
}

// Handle application approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['approve', 'reject'])) {
    $applicationId = intval($_POST['application_id'] ?? 0);
    $action = $_POST['action'];
    
    if ($applicationId) {
        // Get application details
        $stmt = $conn->prepare("
            SELECT a.*, c.name as college_name, c.code as college_code, 
                   co.name as course_name, co.code as course_code
            FROM organization_applications a
            JOIN colleges c ON a.college_id = c.id
            LEFT JOIN courses co ON a.course_id = co.id
            WHERE a.id = ? AND a.status = 'pending_review'
        ");
        $stmt->bind_param("i", $applicationId);
            $stmt->execute();
        $application = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$application) {
            $error = "Application not found or already processed.";
            } else {
            $conn->begin_transaction();
            
            try {
                if ($action === 'approve') {
                    // Check if emails are already registered before approval
                    $stmt = $conn->prepare("SELECT id, email FROM users WHERE email IN (?, ?)");
                    $stmt->bind_param("ss", $application['president_email'], $application['adviser_email']);
                    $stmt->execute();
                    $existingEmails = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                    
                    if (!empty($existingEmails)) {
                        $emailList = array_column($existingEmails, 'email');
                        throw new Exception("Cannot approve: The following email(s) are already registered in the system: " . implode(', ', $emailList) . ". Please reject this application and ask the applicant to use different email addresses.");
                    }
                    
                    // Get current active academic year
                    $academicYearResult = $conn->query("SELECT id FROM academic_terms WHERE status = 'active' LIMIT 1");
                    $academicYear = $academicYearResult->fetch_assoc();
                    $academicYearId = $academicYear ? $academicYear['id'] : null;
                    
                    if ($application['application_type'] === 'organization') {
                        // Create organization
                        $presidentUsername = strtolower(str_replace(' ', '.', $application['president_name'])) . '_' . substr(md5(time()), 0, 4);
                        $adviserUsername = strtolower(str_replace(' ', '.', $application['adviser_name'])) . '_' . substr(md5(time() + 1), 0, 4);
                        $presidentPassword = bin2hex(random_bytes(4));
                        $adviserPassword = bin2hex(random_bytes(4));
                        
                        // Create president user
                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'org_president')");
                        $hashedPresidentPassword = password_hash($presidentPassword, PASSWORD_DEFAULT);
                        $stmt->bind_param("sss", $presidentUsername, $hashedPresidentPassword, $application['president_email']);
                $stmt->execute();
                        $presidentId = $stmt->insert_id;
                    $stmt->close();
                        
                        // Create adviser user
                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'org_adviser')");
                        $hashedAdviserPassword = password_hash($adviserPassword, PASSWORD_DEFAULT);
                        $stmt->bind_param("sss", $adviserUsername, $hashedAdviserPassword, $application['adviser_email']);
                    $stmt->execute();
                        $adviserId = $stmt->insert_id;
                        $stmt->close();
                        
                        // Create organization
                        $stmt = $conn->prepare("
                            INSERT INTO organizations (code, org_name, course_id, college_id, academic_year_id, president_id, adviser_id, president_name, adviser_name, status, type)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'unrecognized', 'new')
                        ");
                        $stmt->bind_param("ssiiiisss",
                            $application['org_code'],
                            $application['org_name'],
                            $application['course_id'],
                            $application['college_id'],
                            $academicYearId,
                            $presidentId,
                            $adviserId,
                            $application['president_name'],
                            $application['adviser_name']
                        );
                        $stmt->execute();
                            $stmt->close();
                        
                        // Send registration emails
                        sendAppRegistrationEmail($application['president_email'], $presidentUsername, $presidentPassword, 'Organization President', $application['org_name']);
                        sendAppRegistrationEmail($application['adviser_email'], $adviserUsername, $adviserPassword, 'Organization Adviser', $application['org_name']);
                        
                        $orgName = $application['org_name'];
                        } else {
                        // Create council
                        $councilCode = $application['college_code'] . '-SC';
                        $councilName = $application['college_name'] . ' Student Council';
                        
                        // Create user accounts
                        $presidentUsername = strtolower(str_replace(' ', '.', $application['president_name'])) . '_' . substr(md5(time()), 0, 4);
                        $adviserUsername = strtolower(str_replace(' ', '.', $application['adviser_name'])) . '_' . substr(md5(time() + 1), 0, 4);
                        $presidentPassword = bin2hex(random_bytes(4));
                        $adviserPassword = bin2hex(random_bytes(4));
                        
                        // Create president user
                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'council_president')");
                        $hashedPresidentPassword = password_hash($presidentPassword, PASSWORD_DEFAULT);
                        $stmt->bind_param("sss", $presidentUsername, $hashedPresidentPassword, $application['president_email']);
                        $stmt->execute();
                        $presidentId = $stmt->insert_id;
                            $stmt->close();
                        
                        // Create adviser user
                        $stmt = $conn->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, 'council_adviser')");
                        $hashedAdviserPassword = password_hash($adviserPassword, PASSWORD_DEFAULT);
                        $stmt->bind_param("sss", $adviserUsername, $hashedAdviserPassword, $application['adviser_email']);
                        $stmt->execute();
                        $adviserId = $stmt->insert_id;
                                $stmt->close();
                        
                        // Create council
                        $stmt = $conn->prepare("
                            INSERT INTO council (college_id, council_code, council_name, academic_year_id, president_id, adviser_id, president_name, adviser_name, status, type)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unrecognized', 'new')
                        ");
                        $stmt->bind_param("issiisss",
                            $application['college_id'],
                            $councilCode,
                            $councilName,
                            $academicYearId,
                            $presidentId,
                            $adviserId,
                            $application['president_name'],
                            $application['adviser_name']
                        );
                        $stmt->execute();
                                        $stmt->close();
                        
                        // Send registration emails
                        sendAppRegistrationEmail($application['president_email'], $presidentUsername, $presidentPassword, 'Council President', $councilName);
                        sendAppRegistrationEmail($application['adviser_email'], $adviserUsername, $adviserPassword, 'Council Adviser', $councilName);
                        
                        $orgName = $councilName;
                    }
                    
                    // Update application status
                    $stmt = $conn->prepare("UPDATE organization_applications SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    $stmt->bind_param("ii", $_SESSION['user_id'], $applicationId);
                                        $stmt->execute();
                                        $stmt->close();
                                        
                    // Send status notification email
                    sendAppStatusEmail($application['verified_email'], 'approved', $application['application_type'], $orgName);
                    
                    $conn->commit();
                    $message = "Application approved successfully! Registration emails have been sent.";
                    
                                        } else {
                    // Reject application
                    $rejectionReason = trim($_POST['rejection_reason'] ?? '');
                    if (empty($rejectionReason)) {
                        throw new Exception("Rejection reason is required.");
                                        }
                                        
                    $stmt = $conn->prepare("UPDATE organization_applications SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
                    $stmt->bind_param("sii", $rejectionReason, $_SESSION['user_id'], $applicationId);
                    $stmt->execute();
                    $stmt->close();
                    
                    $orgName = $application['application_type'] === 'council' 
                        ? $application['college_name'] . ' Student Council'
                        : $application['org_name'];
                    
                    // Send rejection notification email
                    sendAppStatusEmail($application['verified_email'], 'rejected', $application['application_type'], $orgName, $rejectionReason);
                    
                    $conn->commit();
                    $message = "Application rejected. Notification email has been sent.";
                }
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
                                            }
                                        }
    }
}

// Handle MIS registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registration_type']) && $_POST['registration_type'] === 'mis') {
    if (!$activeAcademicYear) {
        $error = $academicYearError;
    } else {
        $college_id = intval($_POST['college_id'] ?? 0);
        $coordinator_name = strtoupper(trim($_POST['coordinator_name'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $academic_year_id = $activeAcademicYear ? intval($activeAcademicYear['id']) : null;

        // Input validation
        if (!$college_id || !$coordinator_name || !$username || !$email || !$password || !$confirm_password) {
            $error = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $email)) {
            $error = "Email must be a valid @cvsu.edu.ph email address.";
        } elseif ($password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } else {
            // Check for existing username/email
            $stmt = $conn->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ?');
            $stmt->bind_param('ss', $username, $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($existing_id, $existing_username, $existing_email);
                $stmt->fetch();
                $stmt->close();
                if ($existing_username === $username) {
                    $error = "Username '{$username}' is already taken.";
                } else {
                    $error = "Email '{$email}' is already registered.";
                }
            } else {
                $stmt->close();
                
                // Check for existing MIS coordinator for this college and academic year
                if ($academic_year_id === null) {
                    $stmt = $conn->prepare('SELECT id, coordinator_name FROM mis_coordinators WHERE college_id = ? AND academic_year_id IS NULL');
                    $stmt->bind_param('i', $college_id);
                } else {
                    $stmt = $conn->prepare('SELECT id, coordinator_name FROM mis_coordinators WHERE college_id = ? AND academic_year_id = ?');
                    $stmt->bind_param('ii', $college_id, $academic_year_id);
                }
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $stmt->close();
                    $error = "An MIS coordinator already exists for this college and academic year.";
                } else {
                    $stmt->close();
                    
                    // Begin transaction for atomic operation
                    $conn->begin_transaction();
                    
                    try {
                        // Create user account
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $role = 'mis_coordinator';
                        $stmt = $conn->prepare('INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)');
                        $stmt->bind_param('ssss', $username, $hashed_password, $email, $role);
                        
                        if (!$stmt->execute()) {
                            throw new Exception('Error creating MIS coordinator account: ' . $stmt->error);
                        }
                        
                        $user_id = $stmt->insert_id;
                        $stmt->close();
                        
                        // Create MIS coordinator record
                        if ($academic_year_id === null) {
                            $stmt = $conn->prepare('INSERT INTO mis_coordinators (college_id, user_id, coordinator_name, academic_year_id) VALUES (?, ?, ?, NULL)');
                            $stmt->bind_param('iis', $college_id, $user_id, $coordinator_name);
                        } else {
                            $stmt = $conn->prepare('INSERT INTO mis_coordinators (college_id, user_id, coordinator_name, academic_year_id) VALUES (?, ?, ?, ?)');
                            $stmt->bind_param('iisi', $college_id, $user_id, $coordinator_name, $academic_year_id);
                        }
                        
                        if (!$stmt->execute()) {
                            throw new Exception('Error registering MIS coordinator: ' . $stmt->error);
                        }
                        
                        $mis_id = $stmt->insert_id;
                        $stmt->close();
                        
                        // Get college name for email notification
                        $college_stmt = $conn->prepare('SELECT name FROM colleges WHERE id = ?');
                        $college_stmt->bind_param('i', $college_id);
                        $college_stmt->execute();
                        $college_stmt->bind_result($college_name);
                        $college_stmt->fetch();
                        $college_stmt->close();
                        
                        // Commit transaction - all database operations succeeded
                        $conn->commit();
                        
                        // Send registration email notifications (outside transaction)
                        $mis_data = array(
                            'email' => $email,
                            'username' => $username,
                            'password' => $password
                        );
                        
                        if (function_exists('sendRegistrationNotificationsAdvanced')) {
                            $email_results = sendRegistrationNotificationsAdvanced($mis_data, null, $college_name, 'mis');
                            logEmailResultsAdvanced($email_results, $college_name, 'mis');
                        } else {
                            $email_results = sendRegistrationNotifications($mis_data, null, $college_name, 'mis');
                            logEmailResults($email_results, $college_name, 'mis');
                        }
                        
                        $email_status = $email_results['president'] ? '&emails=success' : '&emails=failed';
                        
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1&type=mis' . $email_status . '&tab=mis');
                        exit;
                        
                    } catch (mysqli_sql_exception $e) {
                        // Rollback transaction on database error
                        $conn->rollback();
                        
                        if ($e->getCode() == 1062) {
                            $error = "Username or email already exists.";
                        } else {
                            $error = 'Error creating MIS coordinator account: ' . $e->getMessage();
                        }
                    } catch (Exception $e) {
                        // Rollback transaction on any error
                        $conn->rollback();
                        $error = $e->getMessage();
                    }
                }
            }
        }
    }
}

// Handle AJAX requests for adding college or course
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['ajax_action'] === 'add_college') {
            $college_code = trim($_POST['college_code'] ?? '');
            $college_name = trim($_POST['college_name'] ?? '');
            if (!$college_code || !$college_name) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                exit;
            }
            $stmt = $conn->prepare('SELECT id FROM colleges WHERE code = ?');
            $stmt->bind_param('s', $college_code);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'College code already exists.']);
                exit;
            }
            $stmt->close();
            $stmt = $conn->prepare('INSERT INTO colleges (code, name) VALUES (?, ?)');
            $stmt->bind_param('ss', $college_code, $college_name);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();
                echo json_encode(['success' => true, 'id' => $new_id, 'code' => $college_code, 'name' => $college_name]);
            } else {
                $stmt->close();
                echo json_encode(['success' => false, 'message' => 'Error adding college. Please try again.']);
            }
            exit;
        } elseif ($_POST['ajax_action'] === 'add_course') {
            $course_code = trim($_POST['course_code'] ?? '');
            $course_name = trim($_POST['course_name'] ?? '');
            $college_id = intval($_POST['college_id'] ?? 0);
            if (!$course_code || !$course_name || !$college_id) {
                echo json_encode(['success' => false, 'message' => 'All fields are required.']);
                exit;
            }
            // Check for duplicate course code
            $stmt = $conn->prepare('SELECT id FROM courses WHERE code = ?');
            $stmt->bind_param('s', $course_code);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->close();
                echo json_encode(['success' => false, 'message' => 'Course code already exists.']);
                exit;
            }
                $stmt->close();
            // Insert course
            $stmt = $conn->prepare('INSERT INTO courses (code, name, college_id) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $course_code, $course_name, $college_id);
            if ($stmt->execute()) {
                $new_id = $stmt->insert_id;
                $stmt->close();
                echo json_encode(['success' => true, 'id' => $new_id, 'code' => $course_code, 'name' => $course_name, 'college_id' => $college_id]);
            } else {
                $stmt->close();
                echo json_encode(['success' => false, 'message' => 'Failed to add course.']);
            }
            exit;
        } elseif ($_POST['ajax_action'] === 'check_college_mis') {
            $college_id = intval($_POST['college_id'] ?? 0);
            if (!$college_id) {
                echo json_encode(['success' => false, 'message' => 'College ID is required.']);
                exit;
            }
            $academic_year_id = $activeAcademicYear ? intval($activeAcademicYear['id']) : 0;
            if (!$academic_year_id) {
                echo json_encode(['success' => false, 'message' => 'No active academic year found.']);
                exit;
            }
            
            $stmt = $conn->prepare('SELECT id, coordinator_name FROM mis_coordinators WHERE college_id = ? AND academic_year_id = ?');
            $stmt->bind_param('ii', $college_id, $academic_year_id);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($existing_mis_id, $existing_coordinator_name);
                $stmt->fetch();
                $stmt->close();
                echo json_encode([
                    'success' => true, 
                    'has_mis' => true, 
                    'coordinator_name' => $existing_coordinator_name,
                    'message' => "An MIS coordinator already exists for this college and academic year."
                ]);
            } else {
                $stmt->close();
                echo json_encode([
                    'success' => true, 
                    'has_mis' => false,
                    'message' => 'College is available for MIS coordinator registration for this academic year.'
                ]);
            }
            exit;
        } elseif ($_POST['ajax_action'] === 'check_mis_username_email') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            if (!$username && !$email) {
                echo json_encode(['success' => false, 'message' => 'Username or email is required.']);
                exit;
            }
            $stmt = $conn->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ?');
            $stmt->bind_param('ss', $username, $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($existing_id, $existing_username, $existing_email);
                $stmt->fetch();
                $stmt->close();
                if ($existing_username === $username) {
                    echo json_encode([
                        'success' => true, 
                        'has_duplicate' => true, 
                        'message' => "Username '{$username}' is already taken."
                    ]);
                } else {
                    echo json_encode([
                        'success' => true, 
                        'has_duplicate' => true, 
                        'message' => "Email '{$email}' is already registered."
                    ]);
                }
            } else {
                $stmt->close();
                echo json_encode([
                    'success' => true, 
                    'has_duplicate' => false,
                    'message' => 'Username and email are available.'
                ]);
            }
            exit;
        }
    } catch (Exception $e) {
        error_log("AJAX Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
        exit;
    }
}

// Fetch colleges for dropdowns
$colleges = [];
$college_sql = "SELECT id, code, name FROM colleges ORDER BY name ASC";
$college_result = $conn->query($college_sql);
if ($college_result && $college_result->num_rows > 0) {
    while ($row = $college_result->fetch_assoc()) {
        $colleges[] = $row;
    }
}

// Get filter parameters for applications
$statusFilter = $_GET['status'] ?? 'pending_review';
$typeFilter = $_GET['type'] ?? '';
$activeTab = $_GET['tab'] ?? 'applications';

// Build query for applications
$query = "
    SELECT a.*, 
           c.name as college_name, c.code as college_code,
           co.name as course_name, co.code as course_code,
           u.username as reviewed_by_name
    FROM organization_applications a
    JOIN colleges c ON a.college_id = c.id
    LEFT JOIN courses co ON a.course_id = co.id
    LEFT JOIN users u ON a.reviewed_by = u.id
    WHERE 1=1
";

$params = [];
$types = "";

if ($statusFilter) {
    $query .= " AND a.status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($typeFilter && $typeFilter !== 'mis') {
    $query .= " AND a.application_type = ?";
    $params[] = $typeFilter;
    $types .= "s";
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get counts for application tabs
$countQuery = "SELECT status, COUNT(*) as count FROM organization_applications GROUP BY status";
$countResult = $conn->query($countQuery);
$counts = ['pending_review' => 0, 'approved' => 0, 'rejected' => 0];
while ($row = $countResult->fetch_assoc()) {
    $counts[$row['status']] = $row['count'];
}

// Handle success message from redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $type = $_GET['type'] ?? 'organization';
    $email_status = $_GET['emails'] ?? '';
    
    if ($type === 'mis') {
        $success = 'MIS Coordinator account registered successfully!';
        switch ($email_status) {
            case 'success':
                $success .= ' ✅ Email notification has been sent successfully.';
                break;
            case 'failed':
                $success .= ' ❌ Email notification could not be sent. Please manually provide the login credentials.';
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management - OSAS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        main {
            margin-left: 250px;
            margin-top: 56px;
            padding: 20px;
            transition: all 0.3s;
        }
        main.expanded { margin-left: 0; }
        
        .page-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f0f0f0;
        }
        .page-title { 
            font-weight: 700; 
            color: #343a40;
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        .page-subtitle {
            color: #6c757d;
            font-size: 1rem;
            margin-bottom: 0;
        }
        
        /* Main tabs styling */
        .main-tabs {
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 1.5rem;
        }
        .main-tabs .nav-link {
            color: #495057 !important;
            font-weight: 500;
            border: none !important;
            border-bottom: 3px solid transparent !important;
            padding: 0.75rem 1.5rem;
            transition: all 0.2s ease;
            background: transparent !important;
        }
        .main-tabs .nav-link:hover {
            color: #343a40 !important;
            border-bottom-color: rgba(52, 58, 64, 0.3) !important;
        }
        .main-tabs .nav-link.active {
            color: #343a40 !important;
            font-weight: 600;
            border-bottom: 3px solid #343a40 !important;
            background: transparent !important;
        }
        
        /* Application card styling */
        .application-card { 
            border-left: 4px solid #343a40;
            border-radius: 1rem;
            border: none;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .application-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        .application-card.council { 
            border-left: 4px solid #6c757d; 
        }
        .application-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            border-radius: 1rem 1rem 0 0;
        }
        .application-card .card-footer {
            border-radius: 0 0 1rem 1rem;
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }
        
        /* Status filter tabs */
        .status-tabs {
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 1rem;
        }
        .status-tabs .nav-link {
            color: #495057 !important;
            font-weight: 500;
            border: none !important;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            background: transparent !important;
        }
        .status-tabs .nav-link:hover {
            color: #343a40 !important;
        }
        .status-tabs .nav-link.active {
            color: #343a40 !important;
            font-weight: 600;
            border-bottom: 2px solid #343a40 !important;
            background: transparent !important;
        }
        
        /* Status badges - monochrome */
        .badge-pending { background-color: #6c757d; color: #fff; }
        .badge-approved { background-color: #343a40; color: #fff; }
        .badge-rejected { background-color: #495057; color: #fff; }
        
        /* Action buttons - monochrome */
        .btn-approve { 
            background-color: #343a40; 
            border-color: #343a40; 
            color: #fff;
        }
        .btn-approve:hover { 
            background-color: #23272b; 
            border-color: #1d2124; 
            color: #fff;
        }
        .btn-reject { 
            background-color: #6c757d; 
            border-color: #6c757d; 
            color: #fff;
        }
        .btn-reject:hover { 
            background-color: #5a6268; 
            border-color: #545b62; 
            color: #fff;
        }
        
        /* Type filter buttons */
        .filter-btn-group .btn-outline-secondary {
            border-color: #dee2e6;
            color: #495057;
        }
        .filter-btn-group .btn-outline-secondary:hover {
            background-color: #e9ecef;
            border-color: #dee2e6;
            color: #212529;
        }
        .filter-btn-group .btn-outline-secondary.active {
            background-color: #343a40;
            border-color: #343a40;
            color: #fff;
        }
        
        /* Empty state */
        .empty-state {
            padding: 4rem 2rem;
        }
        .empty-state i {
            color: #dee2e6;
        }
        
        /* Modal refinements - monochrome */
        .modal-header.bg-success {
            background: linear-gradient(90deg, #343a40, #495057) !important;
        }
        .modal-header.bg-danger {
            background: linear-gradient(90deg, #495057, #6c757d) !important;
        }
        .modal-content {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        /* Form card for MIS registration */
        .form-card {
            max-width: 600px;
            border-radius: 1rem;
            border: none;
            background: #fff;
            box-shadow: 0 4px 24px 0 rgba(0,0,0,0.07);
        }
        
        /* College/Course registration section */
        .quick-actions-card {
            border-radius: 1rem;
            border: none;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
        }
        .quick-actions-card .card-header {
            padding: 0.5rem 1rem;
        }
        .quick-actions-card .card-body {
            padding: 0.75rem 1rem;
        }
        .quick-actions-card .card-body .btn {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        
        /* Academic Year Status - Slimmer */
        .academic-year-status {
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
        }
        .academic-year-status i {
            font-size: 1rem !important;
            margin-right: 0.75rem !important;
        }
        .academic-year-status .alert-heading {
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }
        .academic-year-status p {
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }
        .academic-year-status small {
            font-size: 0.8rem;
        }
        
        @media (max-width: 991.98px) {
            main { margin-left: 0; padding: 15px; }
        }
        @media (max-width: 768px) {
            .page-title { font-size: 1.5rem; }
            .main-tabs .nav-link { 
                padding: 0.5rem 0.75rem;
                font-size: 0.875rem;
            }
    }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
<main>
<div class="container-fluid py-2">
    <!-- Page Header -->
    <div class="page-header">
        <h2 class="page-title">Account Management</h2>
        <p class="page-subtitle">Review applications, register accounts, and manage colleges/courses.</p>
    </div>
    
    <!-- Success/Error Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Quick Actions: College & Course Registration -->
    <div class="card quick-actions-card mb-3">
        <div class="card-header bg-light">
            <h6 class="mb-0 fw-bold"><i class="bi bi-lightning me-2"></i>Quick Actions</h6>
        </div>
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row gap-2">
                <button type="button" class="btn btn-outline-dark btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#addCollegeModal">
                    <i class="bi bi-building me-2"></i>Register College
                </button>
                <button type="button" class="btn btn-outline-dark btn-sm flex-fill" data-bs-toggle="modal" data-bs-target="#addCourseModal">
                    <i class="bi bi-journal me-2"></i>Register Course
                </button>
            </div>
            </div>
        </div>
    
    <!-- Academic Year Status -->
    <?php if ($academicYearError): ?>
    <div class="alert alert-danger d-flex align-items-center academic-year-status mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div class="flex-grow-1">
            <h6 class="alert-heading mb-1">Academic Year Required</h6>
            <p class="mb-2"><?= htmlspecialchars($academicYearError) ?></p>
                        <a href="osas_calendar_management.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-calendar3 me-1"></i>Go to Calendar Management
                        </a>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-success d-flex align-items-center academic-year-status mb-3" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <div class="flex-grow-1">
            <strong class="small">Active Academic Year:</strong> <span class="small"><?= htmlspecialchars($activeAcademicYear['school_year']) ?></span>
            <small class="text-muted ms-2">
                (<?= date('M d, Y', strtotime($activeAcademicYear['start_date'])) ?> - <?= date('M d, Y', strtotime($activeAcademicYear['end_date'])) ?>)
                    </small>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Navigation Tabs -->
    <ul class="nav nav-tabs main-tabs" id="mainTabs" role="tablist">
                <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'applications' ? 'active' : '' ?>" id="applications-tab" data-bs-toggle="tab" data-bs-target="#applications-content" type="button" role="tab">
                <i class="bi bi-inbox me-2"></i>Applications
                <?php if ($counts['pending_review'] > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $counts['pending_review'] ?></span>
                <?php endif; ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
            <button class="nav-link <?= $activeTab === 'mis' ? 'active' : '' ?>" id="mis-tab" data-bs-toggle="tab" data-bs-target="#mis-content" type="button" role="tab" <?= $academicYearError ? 'disabled' : '' ?>>
                <i class="bi bi-person-gear me-2"></i>MIS Registration
                    </button>
                </li>
            </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="mainTabContent">
        <!-- Applications Tab -->
        <div class="tab-pane fade <?= $activeTab === 'applications' ? 'show active' : '' ?>" id="applications-content" role="tabpanel">
            <!-- Status Filter Tabs -->
            <ul class="nav status-tabs mb-3">
                <li class="nav-item">
                    <a class="nav-link <?= $statusFilter === 'pending_review' ? 'active' : '' ?>" href="?tab=applications&status=pending_review">
                        <i class="bi bi-hourglass-split me-1"></i>Pending
                        <?php if ($counts['pending_review'] > 0): ?>
                            <span class="badge bg-secondary ms-1"><?= $counts['pending_review'] ?></span>
                    <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="?tab=applications&status=approved">
                        <i class="bi bi-check-circle me-1"></i>Approved
                        <span class="badge bg-dark ms-1"><?= $counts['approved'] ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="?tab=applications&status=rejected">
                        <i class="bi bi-x-circle me-1"></i>Rejected
                        <span class="badge bg-secondary ms-1"><?= $counts['rejected'] ?></span>
                    </a>
                </li>
            </ul>
                        
            <!-- Type Filter -->
                        <div class="mb-4">
                <div class="btn-group filter-btn-group" role="group">
                    <a href="?tab=applications&status=<?= htmlspecialchars($statusFilter) ?>" class="btn btn-outline-secondary btn-sm <?= !$typeFilter || $typeFilter === 'mis' ? 'active' : '' ?>">All Types</a>
                    <a href="?tab=applications&status=<?= htmlspecialchars($statusFilter) ?>&type=organization" class="btn btn-outline-secondary btn-sm <?= $typeFilter === 'organization' ? 'active' : '' ?>">Organizations</a>
                    <a href="?tab=applications&status=<?= htmlspecialchars($statusFilter) ?>&type=council" class="btn btn-outline-secondary btn-sm <?= $typeFilter === 'council' ? 'active' : '' ?>">Councils</a>
                            </div>
                        </div>
                        
            <!-- Applications List -->
            <?php if (empty($applications)): ?>
                <div class="card" style="border-radius: 1rem; border: none; box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);">
                    <div class="card-body empty-state text-center">
                        <i class="bi bi-inbox" style="font-size: 4rem;"></i>
                        <h5 class="text-muted mt-3">No applications found</h5>
                        <p class="text-muted mb-0">There are no <?= $statusFilter ? str_replace('_', ' ', $statusFilter) : '' ?> applications at this time.</p>
                            </div>
                                    </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($applications as $app): ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card application-card <?= $app['application_type'] ?>">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge <?= $app['application_type'] === 'council' ? 'bg-secondary' : 'bg-dark' ?> me-2">
                                            <?= ucfirst($app['application_type']) ?>
                                        </span>
                                        <?php if ($app['application_type'] === 'organization'): ?>
                                            <strong><?= htmlspecialchars($app['org_name']) ?></strong>
                                            <span class="text-muted">(<?= htmlspecialchars($app['org_code']) ?>)</span>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($app['college_name']) ?> Student Council</strong>
                                        <?php endif; ?>
                                </div>
                                    <span class="badge badge-<?= $app['status'] === 'pending_review' ? 'pending' : ($app['status'] === 'approved' ? 'approved' : 'rejected') ?>">
                                        <?= ucfirst(str_replace('_', ' ', $app['status'])) ?>
                                    </span>
                            </div>
                                <div class="card-body">
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block">College</small>
                                            <strong><?= htmlspecialchars($app['college_name']) ?></strong>
                        </div>
                                        <?php if ($app['application_type'] === 'organization'): ?>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Course</small>
                                                <strong><?= htmlspecialchars($app['course_name']) ?></strong>
                </div>
                    <?php endif; ?>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block">President</small>
                                            <strong><?= htmlspecialchars($app['president_name']) ?></strong>
                                            <br><small class="text-primary"><?= htmlspecialchars($app['president_email']) ?></small>
                                            <?php if ($app['verified_by'] === 'president'): ?>
                                                <br><span class="badge bg-dark" style="font-size: 0.75rem;"><i class="bi bi-check-circle me-1"></i>Verified</span>
                                            <?php endif; ?>
                        </div>
                                        <div class="col-6">
                                            <small class="text-muted d-block">Adviser</small>
                                            <strong><?= htmlspecialchars($app['adviser_name']) ?></strong>
                                            <br><small class="text-primary"><?= htmlspecialchars($app['adviser_email']) ?></small>
                                            <?php if ($app['verified_by'] === 'adviser'): ?>
                                                <br><span class="badge bg-dark" style="font-size: 0.75rem;"><i class="bi bi-check-circle me-1"></i>Verified</span>
                    <?php endif; ?>
                            </div>
                        </div>
                        
                                    <div class="d-flex justify-content-between align-items-center text-muted small">
                                        <span><i class="bi bi-calendar me-1"></i>Submitted: <?= date('M d, Y h:i A', strtotime($app['created_at'])) ?></span>
                                </div>

                                    <?php if ($app['status'] === 'rejected' && $app['rejection_reason']): ?>
                                        <div class="alert alert-danger mt-3 mb-0 py-2">
                                            <small><strong>Rejection Reason:</strong> <?= htmlspecialchars($app['rejection_reason']) ?></small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($app['reviewed_at']): ?>
                                        <div class="text-muted small mt-2">
                                            <i class="bi bi-person-check me-1"></i>
                                            Reviewed by <?= htmlspecialchars($app['reviewed_by_name']) ?> on <?= date('M d, Y', strtotime($app['reviewed_at'])) ?>
                                    </div>
                                    <?php endif; ?>
                        </div>
                        
                                <?php if ($app['status'] === 'pending_review'): ?>
                                    <div class="card-footer bg-transparent">
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-approve btn-sm flex-grow-1" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#approveModal"
                                                    data-id="<?= $app['id'] ?>"
                                                    data-name="<?= htmlspecialchars($app['application_type'] === 'organization' ? $app['org_name'] : $app['college_name'] . ' Student Council') ?>">
                                                <i class="bi bi-check-circle me-1"></i>Approve
                                        </button>
                                            <button type="button" class="btn btn-reject btn-sm flex-grow-1"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#rejectModal"
                                                    data-id="<?= $app['id'] ?>"
                                                    data-name="<?= htmlspecialchars($app['application_type'] === 'organization' ? $app['org_name'] : $app['college_name'] . ' Student Council') ?>">
                                                <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            </div>
                    <?php endforeach; ?>
                        </div>
            <?php endif; ?>
                </div>
                
        <!-- MIS Registration Tab -->
        <div class="tab-pane fade <?= $activeTab === 'mis' ? 'show active' : '' ?>" id="mis-content" role="tabpanel">
            <div class="row justify-content-center">
                <div class="col-lg-8 col-xl-6">
                    <div class="card form-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-person-gear me-2"></i>Register MIS Coordinator</h5>
                        </div>
                        <div class="card-body p-4">
                            <form id="misRegForm" method="post" action="?tab=mis" autocomplete="off" <?= $academicYearError ? 'style="pointer-events: none; opacity: 0.6;"' : '' ?>>
                                <input type="hidden" name="registration_type" value="mis">
                                
            <!-- Step Indicator -->
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center">
                                        <div class="step-indicator flex-fill d-flex flex-column align-items-center" id="mis-indicator-1">
                                            <span class="badge bg-dark rounded-circle" style="width: 30px; height: 30px; line-height: 20px;">1</span>
                                            <span class="small mt-1">MIS Info</span>
                    </div>
                    <div class="flex-fill text-center">
                        <hr class="m-0" style="border-top: 2px solid #dee2e6;">
                    </div>
                                        <div class="step-indicator flex-fill d-flex flex-column align-items-center" id="mis-indicator-2">
                                            <span class="badge bg-secondary rounded-circle" style="width: 30px; height: 30px; line-height: 20px;">2</span>
                                            <span class="small mt-1">Account</span>
                    </div>
                    </div>
                    </div>
                                
                                <!-- Step 1: MIS Info -->
                                <div class="step" id="mis-step-1">
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="form-floating">
                                                <input type="text" class="form-control" id="mis_academic_year_display" value="<?= htmlspecialchars($activeAcademicYear ? $activeAcademicYear['school_year'] : 'No active academic year') ?>" readonly>
                                                <label for="mis_academic_year_display">Academic Year</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                                <select class="form-select" id="mis_college_id" name="college_id" required>
                            <option value="">Select College</option>
                            <?php foreach ($colleges as $college): ?>
                                                        <option value="<?= $college['id'] ?>"><?= htmlspecialchars($college['name'] . ' (' . $college['code'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                                                <label for="mis_college_id">College</label>
                            </div>
                                            <div id="mis-college-status"></div>
                    </div>
                        <div class="col-12">
                            <div class="form-floating">
                                                <input type="text" class="form-control" id="mis_coordinator_name" name="coordinator_name" required>
                                                <label for="mis_coordinator_name">MIS Coordinator Name</label>
                            </div>
                        </div>
                            </div>
                                    <div class="d-flex justify-content-end">
                                        <button type="button" class="btn btn-dark btn-lg w-100" id="mis-next-btn">
                            Next <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
                                
                                <!-- Step 2: Account Info -->
                                <div class="step" id="mis-step-2" style="display:none;">
                                    <h6 class="mb-3 fw-bold">Create Account</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <div class="form-floating">
                                                <input type="text" class="form-control" id="mis_username" name="username" required>
                                                <label for="mis_username">Username</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating">
                                                <input type="email" class="form-control" id="mis_email" name="email" required>
                                                <label for="mis_email">Email (@cvsu.edu.ph)</label>
                            </div>
                                            <div class="form-text text-danger d-none" id="mis-email-error">Email must be a valid @cvsu.edu.ph address.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating position-relative">
                                                <input type="password" class="form-control" id="mis_password" name="password" required>
                                                <label for="mis_password">Password</label>
                                                <button type="button" class="btn btn-sm btn-link position-absolute top-50 end-0 translate-middle-y me-2 p-0 show-password-toggle" tabindex="-1" data-target="mis_password">
                                                    <i class="bi bi-eye" id="icon-mis_password"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-floating position-relative">
                                                <input type="password" class="form-control" id="mis_confirm_password" name="confirm_password" required>
                                                <label for="mis_confirm_password">Confirm Password</label>
                                                <button type="button" class="btn btn-sm btn-link position-absolute top-50 end-0 translate-middle-y me-2 p-0 show-password-toggle" tabindex="-1" data-target="mis_confirm_password">
                                                    <i class="bi bi-eye" id="icon-mis_confirm_password"></i>
                                </button>
                    </div>
                                            <div class="form-text text-danger d-none" id="mis-pass-match">Passwords do not match.</div>
                    </div>
                    </div>
                    <div class="d-flex justify-content-between gap-2">
                                        <button type="button" class="btn btn-outline-secondary btn-lg w-50" id="mis-back-btn">
                            <i class="bi bi-arrow-left me-1"></i> Back
                        </button>
                                        <button type="submit" class="btn btn-dark btn-lg w-50" id="mis-submit-btn">
                                            <span class="submit-text">Register <i class="bi bi-check-circle ms-1"></i></span>
                            <span class="loading-text d-none">
                                                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                                                Registering...
                            </span>
                        </button>
                    </div>
                </div>
            </form>
                </div>
                        </div>
                                </div>
                                </div>
                                </div>
                                </div>
                                </div>
</main>
                        
<!-- Approve Application Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="application_id" id="approveApplicationId">
                <input type="hidden" name="action" value="approve">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                <div class="modal-body">
                    <p>Are you sure you want to approve the application for <strong id="approveOrgName"></strong>?</p>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-2"></i>
                        <small>This will create the organization/council and send login credentials to the president and adviser.</small>
                                </div>
                                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark">
                        <i class="bi bi-check-circle me-1"></i>Approve
                                </button>
                            </div>
            </form>
                        </div>
                            </div>
                        </div>
                        
<!-- Reject Application Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="application_id" id="rejectApplicationId">
                <input type="hidden" name="action" value="reject">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Application</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                <div class="modal-body">
                    <p>Are you sure you want to reject the application for <strong id="rejectOrgName"></strong>?</p>
                    <div class="mb-3">
                        <label for="rejectionReason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="rejectionReason" name="rejection_reason" rows="4" required placeholder="Please provide a clear reason for rejection..."></textarea>
                                </div>
                                    </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <!-- Add College Modal -->
<div class="modal fade" id="addCollegeModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form id="addCollegeForm" onsubmit="return false;">
            <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i>Register College</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="college-modal-alert"></div>
              <div class="mb-3">
                <label for="college_code" class="form-label">College Code</label>
                        <input type="text" class="form-control" id="college_code" name="college_code" required placeholder="e.g., CEIT">
              </div>
              <div class="mb-3">
                <label for="college_name" class="form-label">College Name</label>
                        <input type="text" class="form-control" id="college_name" name="college_name" required placeholder="e.g., College of Engineering and Information Technology">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-dark">Add College</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add Course Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content">
          <form id="addCourseForm" onsubmit="return false;">
            <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal me-2"></i>Register Course</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
              <div id="course-modal-alert"></div>
              <div class="mb-3">
                <label for="course_college_id" class="form-label">College</label>
                <select class="form-select" id="course_college_id" name="college_id" required>
                  <option value="">Select College</option>
                  <?php foreach ($colleges as $college): ?>
                                <option value="<?= $college['id'] ?>"><?= htmlspecialchars($college['name'] . ' (' . $college['code'] . ')') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
                    <div class="mb-3">
                        <label for="course_code" class="form-label">Course Code</label>
                        <input type="text" class="form-control" id="course_code" name="course_code" required placeholder="e.g., BSIT">
                    </div>
                    <div class="mb-3">
                        <label for="course_name" class="form-label">Course Name</label>
                        <input type="text" class="form-control" id="course_name" name="course_name" required placeholder="e.g., Bachelor of Science in Information Technology">
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-dark">Add Course</button>
            </div>
          </form>
        </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle approve modal
    const approveModal = document.getElementById('approveModal');
    approveModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('approveApplicationId').value = button.getAttribute('data-id');
        document.getElementById('approveOrgName').textContent = button.getAttribute('data-name');
    });
            
    // Handle reject modal
    const rejectModal = document.getElementById('rejectModal');
    rejectModal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        document.getElementById('rejectApplicationId').value = button.getAttribute('data-id');
        document.getElementById('rejectOrgName').textContent = button.getAttribute('data-name');
        document.getElementById('rejectionReason').value = '';
        });
    
    // MIS Registration Step Navigation
    const misStep1 = document.getElementById('mis-step-1');
    const misStep2 = document.getElementById('mis-step-2');
    const misNextBtn = document.getElementById('mis-next-btn');
    const misBackBtn = document.getElementById('mis-back-btn');
    const misIndicator1 = document.getElementById('mis-indicator-1');
    const misIndicator2 = document.getElementById('mis-indicator-2');
    
    function setMISStep(step) {
        if (step === 1) {
            misStep1.style.display = 'block';
            misStep2.style.display = 'none';
            misIndicator1.querySelector('.badge').classList.remove('bg-secondary', 'bg-dark');
            misIndicator1.querySelector('.badge').classList.add('bg-dark');
            misIndicator2.querySelector('.badge').classList.remove('bg-dark', 'bg-secondary');
            misIndicator2.querySelector('.badge').classList.add('bg-secondary');
        } else {
            misStep1.style.display = 'none';
            misStep2.style.display = 'block';
            misIndicator1.querySelector('.badge').classList.remove('bg-dark', 'bg-secondary');
            misIndicator1.querySelector('.badge').classList.add('bg-secondary');
            misIndicator2.querySelector('.badge').classList.remove('bg-secondary', 'bg-dark');
            misIndicator2.querySelector('.badge').classList.add('bg-dark');
        }
    }
    
    if (misNextBtn) {
        misNextBtn.addEventListener('click', function() {
            const collegeId = document.getElementById('mis_college_id').value;
            const coordinatorName = document.getElementById('mis_coordinator_name').value.trim();
            
            if (!collegeId || !coordinatorName) {
                alert('Please fill in all fields.');
                return;
            }
            setMISStep(2);
        });
}
    
    if (misBackBtn) {
        misBackBtn.addEventListener('click', function() {
            setMISStep(1);
    });
}

    // Check MIS college availability
    const misCollegeSelect = document.getElementById('mis_college_id');
    
    if (misCollegeSelect) {
        misCollegeSelect.addEventListener('change', function() {
            const collegeId = this.value;
            const statusDiv = document.getElementById('mis-college-status');
            
    if (!collegeId) {
                statusDiv.innerHTML = '';
                if (misNextBtn) misNextBtn.disabled = false;
        return;
    }
    
            fetch(window.location.pathname, {
        method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_action=check_college_mis&college_id=' + collegeId
    })
            .then(response => response.json())
    .then(data => {
                if (data.has_mis) {
                    statusDiv.innerHTML = '<div class="alert alert-danger mt-2 py-2"><i class="bi bi-x-circle me-2"></i>' + data.message + '</div>';
                    if (misNextBtn) misNextBtn.disabled = true;
        } else {
                    statusDiv.innerHTML = '<div class="alert alert-success mt-2 py-2"><i class="bi bi-check-circle me-2"></i>College available for MIS registration.</div>';
                    if (misNextBtn) misNextBtn.disabled = false;
        }
    })
    .catch(error => {
                console.error('Error checking MIS:', error);
                statusDiv.innerHTML = '<div class="alert alert-danger mt-2 py-2">Error checking college availability.</div>';
                if (misNextBtn) misNextBtn.disabled = true;
            });
    });
}

    // Email validation
    function validateCvsuEmail(email) {
        return /^[^\s@]+@cvsu\.edu\.ph$/.test(email);
    }
    
    const misEmailInput = document.getElementById('mis_email');
    if (misEmailInput) {
        misEmailInput.addEventListener('blur', function() {
            const errorDiv = document.getElementById('mis-email-error');
            if (this.value && !validateCvsuEmail(this.value)) {
                errorDiv.classList.remove('d-none');
        } else {
                errorDiv.classList.add('d-none');
            }
    });
}

    // Password match validation
    const misPassword = document.getElementById('mis_password');
    const misConfirmPassword = document.getElementById('mis_confirm_password');
    if (misConfirmPassword) {
        misConfirmPassword.addEventListener('input', function() {
            const matchDiv = document.getElementById('mis-pass-match');
            if (this.value && misPassword.value !== this.value) {
                matchDiv.classList.remove('d-none');
        } else {
                matchDiv.classList.add('d-none');
        }
        });
    }
    
    // Password visibility toggle
    document.querySelectorAll('.show-password-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
        } else {
                input.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
    });
});

    // MIS Form submission with validation
    const misForm = document.getElementById('misRegForm');
    if (misForm) {
        misForm.addEventListener('submit', function(e) {
            const email = document.getElementById('mis_email').value.trim();
            const password = document.getElementById('mis_password').value;
            const confirmPassword = document.getElementById('mis_confirm_password').value;
            
    if (!validateCvsuEmail(email)) {
                e.preventDefault();
        alert('Email must be a valid @cvsu.edu.ph address.');
                return false;
    }
            
    if (password !== confirmPassword) {
                e.preventDefault();
        alert('Passwords do not match.');
                return false;
    }
            
    if (password.length < 8) {
                e.preventDefault();
        alert('Password must be at least 8 characters.');
                return false;
            }
            
            const submitBtn = document.getElementById('mis-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.querySelector('.submit-text').classList.add('d-none');
                submitBtn.querySelector('.loading-text').classList.remove('d-none');
    }
        });
    }
    
    // Add College Form
    const addCollegeForm = document.getElementById('addCollegeForm');
if (addCollegeForm) {
    addCollegeForm.addEventListener('submit', function(e) {
    e.preventDefault();
            const collegeCode = document.getElementById('college_code').value.trim();
            const collegeName = document.getElementById('college_name').value.trim();
            const alertDiv = document.getElementById('college-modal-alert');
            
            if (!collegeCode || !collegeName) {
                alertDiv.innerHTML = '<div class="alert alert-danger">All fields are required.</div>';
                return;
            }
            
            fetch(window.location.pathname, {
        method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_action=add_college&college_code=' + encodeURIComponent(collegeCode) + '&college_name=' + encodeURIComponent(collegeName)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
        }
                return response.json();
    })
    .then(data => {
        if (data.success) {
            alertDiv.innerHTML = '<div class="alert alert-success">College added successfully!</div>';
                    document.getElementById('college_code').value = '';
                    document.getElementById('college_name').value = '';
                    
                    // Add to dropdowns
                    const option = new Option(data.name + ' (' + data.code + ')', data.id);
                    document.getElementById('mis_college_id')?.appendChild(option.cloneNode(true));
                    document.getElementById('course_college_id')?.appendChild(option.cloneNode(true));
                    
                    setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('addCollegeModal')).hide(); alertDiv.innerHTML = ''; }, 1500);
        } else {
                    alertDiv.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
        }
    })
    .catch(error => {
                console.error('Error adding college:', error);
                alertDiv.innerHTML = '<div class="alert alert-danger">Failed to add college. Check your connection.</div>';
    });
});
    }

    // Add Course Form
    const addCourseForm = document.getElementById('addCourseForm');
if (addCourseForm) {
    addCourseForm.addEventListener('submit', function(e) {
    e.preventDefault();
            const collegeId = document.getElementById('course_college_id').value;
            const courseCode = document.getElementById('course_code').value.trim();
            const courseName = document.getElementById('course_name').value.trim();
            const alertDiv = document.getElementById('course-modal-alert');
            
            if (!collegeId || !courseCode || !courseName) {
                alertDiv.innerHTML = '<div class="alert alert-danger">All fields are required.</div>';
                return;
            }
            
            fetch(window.location.pathname, {
        method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_action=add_course&college_id=' + collegeId + '&course_code=' + encodeURIComponent(courseCode) + '&course_name=' + encodeURIComponent(courseName)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
        }
                return response.json();
    })
    .then(data => {
        if (data.success) {
            alertDiv.innerHTML = '<div class="alert alert-success">Course added successfully!</div>';
                    document.getElementById('course_code').value = '';
                    document.getElementById('course_name').value = '';
                    document.getElementById('course_college_id').value = '';
                    
                    setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('addCourseModal')).hide(); alertDiv.innerHTML = ''; }, 1500);
        } else {
                    alertDiv.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
        }
    })
    .catch(error => {
                console.error('Error adding course:', error);
                alertDiv.innerHTML = '<div class="alert alert-danger">Failed to add course. Check your connection.</div>';
            });
        });
    }
});
</script>
</body>
</html> 

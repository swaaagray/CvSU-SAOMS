<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
require_once 'config/database.php';
require_once 'includes/session.php';
requireRole(['org_president']);

$presidentId = $_SESSION['user_id'] ?? null;
if (!$presidentId) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

// Get orgId for this president
$orgId = null;
$stmt = $conn->prepare("SELECT id, course_id FROM organizations WHERE president_id = ?");
$stmt->bind_param("i", $presidentId);
$stmt->execute();
$stmt->bind_result($orgId, $orgCourseId);
$stmt->fetch();
$stmt->close();
if (!$orgId) {
    echo json_encode(['success' => false, 'message' => 'No organization found.']);
    exit;
}

// Validate POST fields
$student_name = strtoupper(trim($_POST['student_name'] ?? ''));
$student_number = trim($_POST['student_number'] ?? '');
$course = trim($_POST['course'] ?? '');
$sex = trim($_POST['sex'] ?? '');
$section = trim($_POST['section'] ?? '');

if (!$student_name || !$student_number || !$course || !$sex || !$section) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

// Check for duplicate
$checkStmt = $conn->prepare("SELECT COUNT(*) FROM student_data WHERE organization_id = ? AND student_number = ?");
$checkStmt->bind_param("is", $orgId, $student_number);
$checkStmt->execute();
$checkStmt->bind_result($exists);
$checkStmt->fetch();
$checkStmt->close();
if ($exists > 0) {
    echo json_encode(['success' => false, 'message' => 'Student number already exists in your registry.']);
    exit;
}

// Insert student with current active semester
$semesterId = getCurrentActiveSemesterId($conn);
if (!$semesterId) {
    echo json_encode(['success' => false, 'message' => 'No active semester found. Please contact OSAS.']);
    exit;
}
$stmt = $conn->prepare("INSERT INTO student_data (organization_id, semester_id, student_name, student_number, course, sex, section, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("iisssss", $orgId, $semesterId, $student_name, $student_number, $course, $sex, $section);
try {
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Student added successfully.']);
} catch (mysqli_sql_exception $e) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 
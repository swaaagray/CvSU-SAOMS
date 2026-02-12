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
$stmt = $conn->prepare("SELECT id FROM organizations WHERE president_id = ?");
$stmt->bind_param("i", $presidentId);
$stmt->execute();
$stmt->bind_result($orgId);
$stmt->fetch();
$stmt->close();
if (!$orgId) {
    echo json_encode(['success' => false, 'message' => 'No organization found.']);
    exit;
}

// Validate POST fields
$student_name = strtoupper(trim($_POST['student_name'] ?? ''));
$student_number = trim($_POST['student_number'] ?? '');
$sex = trim($_POST['sex'] ?? '');
$section = trim($_POST['section'] ?? '');
$original_student_number = trim($_POST['original_student_number'] ?? '');

if (!$student_name || !$student_number || !$sex || !$section || !$original_student_number) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}
if (!preg_match('/^\d{9}$/', $student_number)) {
    echo json_encode(['success' => false, 'message' => 'Student number must be exactly 9 digits.']);
    exit;
}
// If student_number changed, check for duplicate
if ($student_number !== $original_student_number) {
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
}
// Update student
$stmt = $conn->prepare("UPDATE student_data SET student_name = ?, student_number = ?, sex = ?, section = ? WHERE organization_id = ? AND student_number = ?");
$stmt->bind_param("ssssis", $student_name, $student_number, $sex, $section, $orgId, $original_student_number);
try {
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Student updated successfully.']);
} catch (mysqli_sql_exception $e) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 
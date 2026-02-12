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

$student_number = trim($_POST['student_number'] ?? '');
if (!$student_number) {
    echo json_encode(['success' => false, 'message' => 'Student number is required.']);
    exit;
}
$stmt = $conn->prepare("DELETE FROM student_data WHERE organization_id = ? AND student_number = ?");
$stmt->bind_param("is", $orgId, $student_number);
try {
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Student deleted successfully.']);
} catch (mysqli_sql_exception $e) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} 
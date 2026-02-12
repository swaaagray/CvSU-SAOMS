<?php
require_once 'config/database.php';
require_once 'includes/session.php';

header('Content-Type: application/json');

if (!isRole(['org_president', 'council_president'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$status = isset($_POST['status']) ? $_POST['status'] : '';

if (!$id || !in_array($status, ['Member', 'Non-Member'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$orgId = getCurrentOrganizationId();
$stmt = $conn->prepare("UPDATE student_data SET org_status = ? WHERE id = ? AND organization_id = ?");
$stmt->bind_param("sii", $status, $id, $orgId);
if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
$stmt->close(); 
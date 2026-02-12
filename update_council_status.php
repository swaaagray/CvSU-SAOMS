<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_president']);

// Get the current user's council ID
$councilId = getCurrentCouncilId();
if (!$councilId) {
    echo json_encode(['success' => false, 'message' => 'Council not found']);
    exit;
}

// Get the college ID from the council
$stmt = $conn->prepare("SELECT college_id FROM council WHERE id = ?");
$stmt->bind_param("i", $councilId);
$stmt->execute();
$result = $stmt->get_result();
if (!$result->num_rows) {
    echo json_encode(['success' => false, 'message' => 'College not found']);
    exit;
}
$council = $result->fetch_assoc();
$collegeId = $council['college_id'];
$stmt->close();

// Get POST data
$studentId = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$studentId || !$status) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Validate status
if (!in_array($status, ['Member', 'Non-Member'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

// Verify the student belongs to an organization in the same college
$stmt = $conn->prepare("
    SELECT sd.id 
    FROM student_data sd 
    JOIN organizations o ON sd.organization_id = o.id 
    WHERE sd.id = ? AND o.college_id = ?
");
$stmt->bind_param("ii", $studentId, $collegeId);
$stmt->execute();
$result = $stmt->get_result();

if (!$result->num_rows) {
    echo json_encode(['success' => false, 'message' => 'Student not found or not in your college']);
    exit;
}
$stmt->close();

// Update the council_status
$stmt = $conn->prepare("UPDATE student_data SET council_status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $studentId);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $stmt->error]);
}

$stmt->close();
?>

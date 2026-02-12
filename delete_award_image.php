<?php
require_once 'config/database.php';
require_once 'includes/session.php';

header('Content-Type: application/json');

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['org_president', 'council_president', 'council_adviser'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

if (!isset($_POST['image_id'])) {
    echo json_encode(['error' => 'Image ID is required']);
    exit;
}

$imageId = $_POST['image_id'];

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : 0;
$councilId = ($role === 'council_president' || $role === 'council_adviser') ? getCurrentCouncilId() : 0;
$ownerId = $orgId ?: $councilId;

// Get image details and verify ownership based on role
if ($role === 'council_president' || $role === 'council_adviser') {
    // For council presidents and advisers, check awards where council_id matches
    $stmt = $conn->prepare("
        SELECT ai.* 
        FROM award_images ai
        JOIN awards a ON ai.award_id = a.id
        WHERE ai.id = ? AND a.council_id = ?
    ");
    $stmt->bind_param("ii", $imageId, $councilId);
} else {
    // For organization presidents, check awards where organization_id matches
    $stmt = $conn->prepare("
        SELECT ai.* 
        FROM award_images ai
        JOIN awards a ON ai.award_id = a.id
        WHERE ai.id = ? AND a.organization_id = ?
    ");
    $stmt->bind_param("ii", $imageId, $orgId);
}
$stmt->execute();
$result = $stmt->get_result();
$image = $result->fetch_assoc();

if (!$image) {
    echo json_encode(['error' => 'Image not found or unauthorized']);
    exit;
}

// Delete the physical file
if (file_exists($image['image_path'])) {
    unlink($image['image_path']);
}

// Delete from database
$stmt = $conn->prepare("DELETE FROM award_images WHERE id = ?");
$stmt->bind_param("i", $imageId);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to delete image']);
} 
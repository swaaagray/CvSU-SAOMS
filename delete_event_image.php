<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president', 'council_adviser']);

header('Content-Type: application/json');

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : 0;
$councilId = ($role === 'council_president' || $role === 'council_adviser') ? getCurrentCouncilId() : 0;
$ownerId = $orgId ?: $councilId;

$imageId = $_POST['image_id'] ?? 0;

if (!$imageId) {
    echo json_encode(['error' => 'Invalid image ID.']);
    exit;
}

// Get image details based on role
if ($role === 'council_president' || $role === 'council_adviser') {
    // For council presidents and advisers, check events where council_id matches
    $stmt = $conn->prepare("
        SELECT i.* 
        FROM event_images i
        JOIN events e ON i.event_id = e.id
        WHERE i.id = ? AND e.council_id = ?
    ");
    $stmt->bind_param("ii", $imageId, $councilId);
} else {
    // For organization presidents, check events where organization_id matches
    $stmt = $conn->prepare("
        SELECT i.* 
        FROM event_images i
        JOIN events e ON i.event_id = e.id
        WHERE i.id = ? AND e.organization_id = ?
    ");
    $stmt->bind_param("ii", $imageId, $orgId);
}
$stmt->execute();
$image = $stmt->get_result()->fetch_assoc();

if (!$image) {
    echo json_encode(['error' => 'Image not found.']);
    exit;
}

// Delete the image file
if ($image['image_path'] && file_exists($image['image_path'])) {
    unlink($image['image_path']);
}

// Delete from database
$stmt = $conn->prepare("DELETE FROM event_images WHERE id = ?");
$stmt->bind_param("i", $imageId);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['error' => 'Failed to delete image from database.']);
}
?> 
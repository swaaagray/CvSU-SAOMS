<?php
require_once 'config/database.php';
require_once 'includes/session.php';

// Set content type to JSON
header('Content-Type: application/json');

requireRole(['org_president', 'council_president', 'org_adviser', 'council_adviser', 'osas', 'mis_coordinator']);

$eventId = $_GET['event_id'] ?? 0;

if (!$eventId) {
    echo json_encode(['error' => 'Invalid event ID', 'success' => false]);
    exit;
}

// For organization and council users, verify event belongs to their organization/council
if (in_array($_SESSION['role'], ['org_president', 'council_president', 'org_adviser', 'council_adviser'])) {
    $role = getUserRole();
    
    if ($role === 'council_president' || $role === 'council_adviser') {
        // For council presidents and advisers, check events where council_id matches
        $councilId = getCurrentCouncilId();
        $stmt = $conn->prepare("SELECT title FROM events WHERE id = ? AND council_id = ?");
        $stmt->bind_param("ii", $eventId, $councilId);
    } else {
        // For organization presidents and advisers, check events where organization_id matches
        $orgId = getCurrentOrganizationId();
        $stmt = $conn->prepare("SELECT title FROM events WHERE id = ? AND organization_id = ?");
        $stmt->bind_param("ii", $eventId, $orgId);
    }
    
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    
    if (!$event) {
        echo json_encode(['error' => 'Event not found', 'success' => false]);
        exit;
    }
} else {
    // For OSAS and MIS coordinators, allow access to all events
    $stmt = $conn->prepare("SELECT title FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    
    if (!$event) {
        echo json_encode(['error' => 'Event not found', 'success' => false]);
        exit;
    }
}

// Get all images for this event
$stmt = $conn->prepare("
    SELECT id, image_path 
    FROM event_images 
    WHERE event_id = ? 
    ORDER BY id ASC
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$result = $stmt->get_result();
$images = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'event_title' => $event['title'],
    'images' => $images
]);
?> 
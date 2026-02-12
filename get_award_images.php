<?php
require_once 'config/database.php';
require_once 'includes/session.php';

// Set content type to JSON
header('Content-Type: application/json');

requireRole(['org_president', 'council_president', 'org_adviser', 'council_adviser', 'osas', 'mis_coordinator']);

$awardId = $_GET['award_id'] ?? 0;

if (!$awardId) {
    echo json_encode(['error' => 'Invalid award ID', 'success' => false]);
    exit;
}

// For organization and council users, verify award belongs to their organization/council
if (in_array($_SESSION['role'], ['org_president', 'council_president', 'org_adviser', 'council_adviser'])) {
    $role = getUserRole();
    
    if ($role === 'council_president' || $role === 'council_adviser') {
        // For council presidents and advisers, check awards where council_id matches
        $councilId = getCurrentCouncilId();
        $stmt = $conn->prepare("SELECT title FROM awards WHERE id = ? AND council_id = ?");
        $stmt->bind_param("ii", $awardId, $councilId);
    } else {
        // For organization presidents and advisers, check awards where organization_id matches
        $orgId = getCurrentOrganizationId();
        $stmt = $conn->prepare("SELECT title FROM awards WHERE id = ? AND organization_id = ?");
        $stmt->bind_param("ii", $awardId, $orgId);
    }
    
    $stmt->execute();
    $award = $stmt->get_result()->fetch_assoc();
    
    if (!$award) {
        echo json_encode(['error' => 'Award not found', 'success' => false]);
        exit;
    }
} else {
    // For OSAS and MIS coordinators, allow access to all awards
    $stmt = $conn->prepare("SELECT title FROM awards WHERE id = ?");
    $stmt->bind_param("i", $awardId);
    $stmt->execute();
    $award = $stmt->get_result()->fetch_assoc();
    
    if (!$award) {
        echo json_encode(['error' => 'Award not found', 'success' => false]);
        exit;
    }
}

// Get all images for this award
$stmt = $conn->prepare("
    SELECT id, image_path 
    FROM award_images 
    WHERE award_id = ? 
    ORDER BY id ASC
");
$stmt->bind_param("i", $awardId);
$stmt->execute();
$result = $stmt->get_result();
$images = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode([
    'success' => true,
    'award_title' => $award['title'],
    'images' => $images
]);
?> 
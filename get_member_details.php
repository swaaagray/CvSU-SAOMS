<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

header('Content-Type: application/json');

if (!isset($_GET['member_id'])) {
    echo json_encode(['error' => 'Member ID is required']);
    exit;
}

$memberId = $_GET['member_id'];
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;
$ownerId = ($role === 'org_president') ? $orgId : $councilId;
$ownerColumn = ($role === 'org_president') ? 'organization_id' : 'council_id';

// Get member details
$stmt = $conn->prepare("
    SELECT * FROM student_officials 
    WHERE id = ? AND $ownerColumn = ?
");
$stmt->bind_param("ii", $memberId, $ownerId);
$stmt->execute();
$result = $stmt->get_result();
$member = $result->fetch_assoc();

if (!$member) {
    echo json_encode(['error' => 'Member not found']);
    exit;
}

// Return member data
echo json_encode($member); 
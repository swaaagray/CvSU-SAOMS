<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

header('Content-Type: application/json');

$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;
$ownerId = ($role === 'org_president') ? $orgId : $councilId;
$ownerColumn = ($role === 'org_president') ? 'organization_id' : 'council_id';

$studentNumber = $_GET['student_number'] ?? '';
$studentName = $_GET['name'] ?? '';
$isEdit = isset($_GET['is_edit']) && $_GET['is_edit'] == '1';
$currentId = $_GET['current_id'] ?? null;

if (empty($studentNumber) && empty($studentName)) {
    echo json_encode(['is_duplicate' => false]);
    exit;
}

// Get current active academic year ID
$currentAcademicYearId = getCurrentAcademicTermId($conn);
if (!$currentAcademicYearId) {
    echo json_encode(['is_duplicate' => false, 'error' => 'No active academic year found']);
    exit;
}

// Check for duplicate by student_number or name
$sql = "SELECT id, name, student_number, position FROM student_officials 
        WHERE $ownerColumn = ? 
        AND academic_year_id = ?";
$params = [$ownerId, $currentAcademicYearId];
$types = 'ii';

if ($isEdit && $currentId) {
    $sql .= " AND id != ?";
    $params[] = (int)$currentId;
    $types .= 'i';
}

if (!empty($studentNumber)) {
    $sql .= " AND (student_number = ?";
    $params[] = $studentNumber;
    $types .= 's';
    
    if (!empty($studentName)) {
        $sql .= " OR UPPER(name) = UPPER(?)";
        $params[] = $studentName;
        $types .= 's';
    }
    $sql .= ")";
} elseif (!empty($studentName)) {
    $sql .= " AND UPPER(name) = UPPER(?)";
    $params[] = $studentName;
    $types .= 's';
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $existing = $result->fetch_assoc();
    $message = "This student {$existing['name']} is already registered as a member with the position: {$existing['position']}.";
    echo json_encode([
        'is_duplicate' => true,
        'message' => $message,
        'existing' => $existing
    ]);
} else {
    echo json_encode(['is_duplicate' => false]);
}

$stmt->close();
?>


<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser', 'org_adviser', 'osas', 'mis_coordinator']);

$awardId = $_GET['award_id'] ?? 0;

if (!$awardId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid award ID.']);
    exit;
}

// Verify the award belongs to this adviser's council or organization (skip for osas/mis_coordinator)
$userId = getCurrentUserId();
$role = getUserRole();

if ($role === 'osas' || $role === 'mis_coordinator') {
    // OSAS and MIS coordinators can view all awards
    $stmt = $conn->prepare("SELECT id, title FROM awards WHERE id = ?");
    $stmt->bind_param("i", $awardId);
} elseif ($role === 'council_adviser') {
    $stmt = $conn->prepare("
        SELECT a.id, a.title
        FROM awards a
        JOIN council c ON a.council_id = c.id
        WHERE a.id = ? AND c.adviser_id = ?
    ");
    $stmt->bind_param("ii", $awardId, $userId);
} else { // org_adviser
    $orgId = getCurrentOrganizationId();
    $stmt = $conn->prepare("
        SELECT a.id, a.title
        FROM awards a
        WHERE a.id = ? AND a.organization_id = ?
    ");
    $stmt->bind_param("ii", $awardId, $orgId);
}
$stmt->execute();
$award = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$award) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Award not found or access denied.']);
    exit;
}

// Get participants
$stmt = $conn->prepare("
    SELECT id, name, course, yearSection
    FROM award_participants
    WHERE award_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("i", $awardId);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format participants
$participantsFormatted = array_map(function($p) {
    $ys = $p['yearSection'] ?? '';
    $year_level = '';
    $section = '';
    if ($ys !== '') {
        $parts = explode('-', $ys, 2);
        $year_level = trim($parts[0]);
        if (count($parts) > 1) {
            $section = trim($parts[1]);
        }
    }
    return [
        'id' => $p['id'],
        'name' => $p['name'],
        'course' => $p['course'] ?? '',
        'year_level' => $year_level,
        'section' => $section,
        'yearSection' => $ys,
    ];
}, $participants);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'award_title' => $award['title'],
    'participants' => $participantsFormatted
]);
?>


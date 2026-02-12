<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser', 'org_adviser', 'osas', 'mis_coordinator']);

$eventId = $_GET['event_id'] ?? 0;

if (!$eventId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Invalid event ID.']);
    exit;
}

// Verify the event belongs to this adviser's council or organization (skip for osas/mis_coordinator)
$userId = getCurrentUserId();
$role = getUserRole();

if ($role === 'osas' || $role === 'mis_coordinator') {
    // OSAS and MIS coordinators can view all events
    $stmt = $conn->prepare("SELECT id, title FROM events WHERE id = ?");
    $stmt->bind_param("i", $eventId);
} elseif ($role === 'council_adviser') {
    $stmt = $conn->prepare("
        SELECT e.id, e.title
        FROM events e
        JOIN council c ON e.council_id = c.id
        WHERE e.id = ? AND c.adviser_id = ?
    ");
    $stmt->bind_param("ii", $eventId, $userId);
} else { // org_adviser
    $orgId = getCurrentOrganizationId();
    $stmt = $conn->prepare("
        SELECT e.id, e.title
        FROM events e
        WHERE e.id = ? AND e.organization_id = ?
    ");
    $stmt->bind_param("ii", $eventId, $orgId);
}
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Event not found or access denied.']);
    exit;
}

// Get participants
$stmt = $conn->prepare("
    SELECT id, name, course, yearSection
    FROM event_participants
    WHERE event_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("i", $eventId);
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
    'event_title' => $event['title'],
    'participants' => $participantsFormatted
]);
?>


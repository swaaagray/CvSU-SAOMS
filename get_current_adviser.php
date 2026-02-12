<?php
require_once 'config/database.php';
require_once 'includes/session.php';

header('Content-Type: application/json');

$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : null;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : null;

$adviserId = null;
$adviserName = '';
$adviserEmail = '';

try {
    if ($role === 'org_president' && $orgId) {
        $q = $conn->prepare("SELECT adviser_id, adviser_name FROM organizations WHERE id = ?");
        $q->bind_param("i", $orgId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) {
            $adviserId = (int)($row['adviser_id'] ?? 0);
            $adviserName = $row['adviser_name'] ?? '';
        }
    } elseif ($role === 'council_president' && $councilId) {
        $q = $conn->prepare("SELECT adviser_id, adviser_name FROM council WHERE id = ?");
        $q->bind_param("i", $councilId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if ($row) {
            $adviserId = (int)($row['adviser_id'] ?? 0);
            $adviserName = $row['adviser_name'] ?? '';
        }
    }

    if ($adviserId) {
        $u = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $u->bind_param("i", $adviserId);
        $u->execute();
        $usr = $u->get_result()->fetch_assoc();
        $u->close();
        if ($usr && !empty($usr['email'])) { $adviserEmail = $usr['email']; }
    }

    echo json_encode([
        'adviser_name' => $adviserName,
        'adviser_email' => $adviserEmail
    ]);
} catch (Throwable $e) {
    echo json_encode(['adviser_name' => '', 'adviser_email' => '']);
}

<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

header('Content-Type: application/json');

$role = getUserRole();
$adviser_name = '';
$adviser_email = '';

if ($role === 'org_president') {
    $orgId = getCurrentOrganizationId();
    if ($orgId) {
        $stmt = $conn->prepare("SELECT adviser_name, adviser_id FROM organizations WHERE id = ?");
        $stmt->bind_param("i", $orgId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $adviser_name = $row['adviser_name'] ?? '';
            if ($row['adviser_id']) {
                // Get email from users table
                $user_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                $user_stmt->bind_param("i", $row['adviser_id']);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $adviser_email = $user_row['email'] ?? '';
                }
                $user_stmt->close();
            }
        }
        $stmt->close();
    }
} elseif ($role === 'council_president') {
    $councilId = getCurrentCouncilId();
    if ($councilId) {
        $stmt = $conn->prepare("SELECT adviser_name, adviser_id FROM council WHERE id = ?");
        $stmt->bind_param("i", $councilId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $adviser_name = $row['adviser_name'] ?? '';
            if ($row['adviser_id']) {
                // Get email from users table
                $user_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                $user_stmt->bind_param("i", $row['adviser_id']);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                if ($user_row = $user_result->fetch_assoc()) {
                    $adviser_email = $user_row['email'] ?? '';
                }
                $user_stmt->close();
            }
        }
        $stmt->close();
    }
}

echo json_encode([
    'adviser_name' => $adviser_name,
    'adviser_email' => $adviser_email
]);
?>

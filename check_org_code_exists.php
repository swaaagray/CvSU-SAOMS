<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$org_code = trim($_GET['org_code'] ?? '');

if (!$org_code) {
    echo json_encode(['success' => false, 'message' => 'Organization code is required']);
    exit;
}

try {
    // Check if organization code already exists
    $stmt = $conn->prepare('SELECT id, org_name FROM organizations WHERE code = ?');
    $stmt->bind_param('s', $org_code);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($org_id, $org_name);
        $stmt->fetch();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'exists' => true,
            'org_name' => $org_name,
            'message' => "Organization code '" . htmlspecialchars($org_code) . "' already exists for: " . htmlspecialchars($org_name)
        ]);
    } else {
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'Organization code is available'
        ]);
    }
} catch (Exception $e) {
    error_log("Error checking organization code existence: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error checking organization code existence'
    ]);
}
?>

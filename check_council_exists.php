<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$college_id = intval($_GET['college_id'] ?? 0);

if (!$college_id) {
    echo json_encode(['success' => false, 'message' => 'College ID is required']);
    exit;
}

try {
    // Check if council already exists for this college
    $stmt = $conn->prepare('SELECT id, council_name FROM council WHERE college_id = ?');
    $stmt->bind_param('i', $college_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $stmt->bind_result($council_id, $council_name);
        $stmt->fetch();
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'exists' => true,
            'council_name' => $council_name,
            'message' => "A student council already exists for this college: " . htmlspecialchars($council_name)
        ]);
    } else {
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'No existing council found for this college'
        ]);
    }
} catch (Exception $e) {
    error_log("Error checking council existence: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error checking council existence'
    ]);
}
?>

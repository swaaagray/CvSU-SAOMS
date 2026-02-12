<?php
require_once 'config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$course_id = intval($_GET['course_id'] ?? 0);

if (!$course_id) {
    echo json_encode(['success' => false, 'message' => 'Course ID is required']);
    exit;
}

try {
    // Check if organization already exists for this course
    $stmt = $conn->prepare('SELECT id, org_name FROM organizations WHERE course_id = ?');
    $stmt->bind_param('i', $course_id);
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
            'message' => "There is already an existing organization for this course: " . htmlspecialchars($org_name)
        ]);
    } else {
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'exists' => false,
            'message' => 'No existing organization found for this course'
        ]);
    }
} catch (Exception $e) {
    error_log("Error checking course organization existence: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error checking course organization existence'
    ]);
}
?>

<?php
require_once 'config/database.php';

$college_id = isset($_GET['college_id']) ? intval($_GET['college_id']) : 0;
$courses = [];

if ($college_id > 0) {
    $stmt = $conn->prepare("SELECT id, code, name FROM courses WHERE college_id = ? ORDER BY name ASC");
    $stmt->bind_param("i", $college_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $courses[] = $row;
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($courses);
$conn->close(); 
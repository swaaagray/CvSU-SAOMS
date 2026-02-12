<?php
require_once 'config/database.php';

$colleges = [];

try {
    $stmt = $conn->prepare("SELECT id, code, name FROM colleges ORDER BY name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $colleges[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching colleges: " . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode($colleges);
$conn->close();
?>
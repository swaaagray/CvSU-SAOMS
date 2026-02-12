<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_president']);

header('Content-Type: application/json');

$studentNumber = $_GET['student_number'] ?? '';
$studentName = $_GET['name'] ?? '';

if (empty($studentNumber) && empty($studentName)) {
    echo json_encode(['is_president' => false]);
    exit;
}

// Get current active academic year ID
$currentAcademicYearId = getCurrentAcademicTermId($conn);
if (!$currentAcademicYearId) {
    echo json_encode(['is_president' => false, 'error' => 'No active academic year found']);
    exit;
}

// Check if this student is already a President in any organization
$president_check_sql = "SELECT so.id, so.name, o.org_name 
                        FROM student_officials so 
                        JOIN organizations o ON so.organization_id = o.id 
                        WHERE so.organization_id IS NOT NULL 
                        AND so.position = 'PRESIDENT' 
                        AND so.student_number = ? 
                        AND so.academic_year_id = ?";
$president_check_stmt = $conn->prepare($president_check_sql);
$president_check_stmt->bind_param("si", $studentNumber, $currentAcademicYearId);
$president_check_stmt->execute();
$president_result = $president_check_stmt->get_result();

if ($president_result->num_rows > 0) {
    $president_data = $president_result->fetch_assoc();
    $president_check_stmt->close();
    $message = 'Cannot add this student to the council. ' . htmlspecialchars($president_data['name']) . ' is already the President of ' . htmlspecialchars($president_data['org_name']) . '. Organization presidents cannot hold council positions.';
    echo json_encode([
        'is_president' => true,
        'message' => $message,
        'president_data' => $president_data
    ]);
} else {
    $president_check_stmt->close();
    echo json_encode(['is_president' => false]);
}
?>


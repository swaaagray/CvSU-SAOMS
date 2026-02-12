<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_president']);

header('Content-Type: application/json');

$collegeId = null;

// For Council President, get the college ID from their council
$councilId = getCurrentCouncilId();
if ($councilId) {
    $stmt = $conn->prepare("SELECT college_id FROM council WHERE id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $collegeId = $row['college_id'];
    }
    $stmt->close();
}

if (!$collegeId) {
    echo json_encode(['success' => false, 'message' => 'College not found']);
    exit;
}

$selected_course = $_GET['course'] ?? '';

// Fetch unique sections for dropdown - only for council
// If a specific course is selected, only show sections for that course
$sectionSql = "SELECT DISTINCT sd.section FROM student_data sd 
               JOIN organizations o ON sd.organization_id = o.id 
               JOIN academic_semesters s ON sd.semester_id = s.id
               JOIN academic_terms at ON s.academic_term_id = at.id
               WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member') AND s.status = 'active' AND at.status = 'active'";
$sectionParams = [$collegeId];
$sectionTypes = "i";

if ($selected_course !== '' && $selected_course !== 'All') {
    $sectionSql .= " AND sd.course = ?";
    $sectionParams[] = $selected_course;
    $sectionTypes .= "s";
}

$sectionSql .= " ORDER BY sd.section ASC";

$sectionStmt = $conn->prepare($sectionSql);
$sectionStmt->bind_param($sectionTypes, ...$sectionParams);
$sectionStmt->execute();
$sectionResult = $sectionStmt->get_result();
$sections = [];
while ($row = $sectionResult->fetch_assoc()) {
    $sections[] = $row['section'];
}
$sectionStmt->close();

echo json_encode(['success' => true, 'sections' => $sections]);
?>

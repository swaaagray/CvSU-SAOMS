<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator']);

// Get MIS coordinator's college
$userId = getCurrentUserId();
$stmt = $conn->prepare("
    SELECT mc.college_id
    FROM mis_coordinators mc
    WHERE mc.user_id = ?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$collegeId = $result['college_id'] ?? null;
$stmt->close();

// Read filters from query string
$organization = $_GET['organization'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$searchTitle = $_GET['search_title'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($organization) || !empty($startDate) || !empty($endDate) || !empty($searchTitle);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Build query (mirror of mis_view_awards.php)
$query = "
    SELECT a.id, a.title, a.description, a.award_date, a.venue,
           o.org_name AS organization_name,
           (SELECT COUNT(*) FROM award_images WHERE award_id = a.id) AS image_count,
           (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) AS participant_count
    FROM awards a
    JOIN organizations o ON a.organization_id = o.id
    JOIN academic_semesters s ON a.semester_id = s.id
    JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE $statusCondition
";

$params = [];
$types = "";

// Filter by MIS coordinator's college
if ($collegeId) {
    $query .= " AND o.college_id = ?";
    $params[] = $collegeId;
    $types .= "i";
}

if ($organization) {
	$query .= " AND a.organization_id = ?";
	$params[] = $organization;
	$types .= "i";
}

if ($startDate) {
	$query .= " AND a.award_date >= ?";
	$params[] = $startDate;
	$types .= "s";
}

if ($endDate) {
	$query .= " AND a.award_date <= ?";
	$params[] = $endDate;
	$types .= "s";
}

if ($searchTitle) {
	$query .= " AND a.title LIKE ?";
	$params[] = "%$searchTitle%";
	$types .= "s";
}

$query .= " ORDER BY a.award_date DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
	$stmt->bind_param($types, ...$params);
}
$stmt->execute();
$awards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// CSV headers
$csvHeaders = ['Title', 'Organization', 'Award Date', 'Venue', 'Recipients', 'Images', 'Description'];

// Output CSV
if (ob_get_length()) ob_end_clean();
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="MIS_Awards.csv"');
$output = fopen('php://output', 'w');
fputcsv($output, $csvHeaders);
foreach ($awards as $row) {
	$data = [
		$row['title'],
		$row['organization_name'],
		$row['award_date'],
		$row['venue'],
		$row['participant_count'],
		$row['image_count'],
		$row['description']
	];
	fputcsv($output, $data);
}
fclose($output);
exit;
?>


<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator', 'osas', 'org_adviser']);

$rankingType = $_GET['ranking_type'] ?? 'events';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

function getEventRankings($conn, $startDate, $endDate) {
	$query = "
		SELECT 
			o.org_name as organization_name,
			o.code,
			c.name as college_name,
			COUNT(e.id) as total_activities,
			SUM((SELECT COUNT(*) FROM event_participants WHERE event_id = e.id)) as total_participants,
			SUM((SELECT COUNT(*) FROM event_images WHERE event_id = e.id)) as total_images,
			MAX(e.event_date) as latest_activity_date
		FROM organizations o
		LEFT JOIN colleges c ON o.college_id = c.id
		LEFT JOIN events e ON e.organization_id = o.id
		WHERE o.status = 'recognized' AND e.id IS NOT NULL
	";

	$params = [];
	$types = "";

	if ($startDate) { $query .= " AND e.event_date >= ?"; $params[] = $startDate; $types .= "s"; }
	if ($endDate) { $query .= " AND e.event_date <= ?"; $params[] = $endDate; $types .= "s"; }

	$query .= " GROUP BY o.id, o.org_name, o.code, c.name ORDER BY total_activities DESC, total_participants DESC, total_images DESC LIMIT 5";

	$stmt = $conn->prepare($query);
	if (!empty($params)) { $stmt->bind_param($types, ...$params); }
	$stmt->execute();
	return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getAwardRankings($conn, $startDate, $endDate) {
	$query = "
		SELECT 
			o.org_name as organization_name,
			o.code,
			c.name as college_name,
			COUNT(a.id) as total_awards,
			SUM((SELECT COUNT(*) FROM award_participants WHERE award_id = a.id)) as total_recipients,
			SUM((SELECT COUNT(*) FROM award_images WHERE award_id = a.id)) as total_images,
			MAX(a.award_date) as latest_award_date
		FROM organizations o
		LEFT JOIN colleges c ON o.college_id = c.id
		LEFT JOIN awards a ON a.organization_id = o.id
		WHERE o.status = 'recognized' AND a.id IS NOT NULL
	";

	$params = [];
	$types = "";

	if ($startDate) { $query .= " AND a.award_date >= ?"; $params[] = $startDate; $types .= "s"; }
	if ($endDate) { $query .= " AND a.award_date <= ?"; $params[] = $endDate; $types .= "s"; }

	$query .= " GROUP BY o.id, o.org_name, o.code, c.name ORDER BY total_awards DESC, total_recipients DESC, total_images DESC LIMIT 5";

	$stmt = $conn->prepare($query);
	if (!empty($params)) { $stmt->bind_param($types, ...$params); }
	$stmt->execute();
	return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

if ($rankingType === 'awards') {
	$data = getAwardRankings($conn, $startDate, $endDate);
	$headers = ['Organization', 'Code', 'College', 'Total Awards', 'Total Recipients', 'Total Images', 'Latest Award Date'];
} else {
	$data = getEventRankings($conn, $startDate, $endDate);
	$headers = ['Organization', 'Code', 'College', 'Total Activities', 'Total Participants', 'Total Images', 'Latest Activity Date'];
}

if (ob_get_length()) ob_end_clean();
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="Rankings_' . ($rankingType === 'awards' ? 'Awards' : 'Events') . '.csv"');
$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($data as $row) {
	if ($rankingType === 'awards') {
		fputcsv($output, [
			$row['organization_name'],
			$row['code'],
			$row['college_name'],
			$row['total_awards'],
			$row['total_recipients'],
			$row['total_images'],
			$row['latest_award_date']
		]);
	} else {
		fputcsv($output, [
			$row['organization_name'],
			$row['code'],
			$row['college_name'],
			$row['total_activities'],
			$row['total_participants'],
			$row['total_images'],
			$row['latest_activity_date']
		]);
	}
}
fclose($output);
exit;
?>


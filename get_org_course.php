<?php
require_once 'config/database.php';
require_once 'includes/session.php';

header('Content-Type: application/json');

$orgId = getCurrentOrganizationId();

// Get the course for the current organization from courses table
$orgCourse = '';
$orgResult = $conn->prepare('SELECT c.name FROM courses c INNER JOIN organizations o ON c.id = o.course_id WHERE o.id = ?');
$orgResult->bind_param('i', $orgId);
$orgResult->execute();
$orgResult->bind_result($orgCourse);
$orgResult->fetch();
$orgResult->close();

echo json_encode(['course' => $orgCourse]); 
<?php
require_once 'config/database.php';
require_once 'includes/session.php';

header('Content-Type: application/json');

try {
    $academicYear = getActiveAcademicYearDateRange($conn);
    
    if ($academicYear['found']) {
        echo json_encode([
            'success' => true,
            'start_date' => $academicYear['start_date'],
            'end_date' => $academicYear['end_date']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No active academic year found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

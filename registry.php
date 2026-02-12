<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';
require_once 'includes/session.php';
require 'phpspreadsheet/vendor/autoload.php';

requireRole(['org_president']);

// Get the current user's ID (president)
$presidentId = $_SESSION['user_id'];

// Fetch the organization_id and course_id for this president
$orgId = null;
$stmt = $conn->prepare("SELECT id, course_id FROM organizations WHERE president_id = ?");
$stmt->bind_param("i", $presidentId);
$stmt->execute();
$stmt->bind_result($orgId, $orgCourseId);
$stmt->fetch();
$stmt->close();

if (!$orgId || !$orgCourseId) {
    die("No organization or course found for this president.");
}

// Mirror document cleanup pattern: delete student_data outside active semester and for archived semesters
try {
    // Delete rows joined to archived semesters
    $conn->query("DELETE sd FROM student_data sd INNER JOIN academic_semesters s ON s.id = sd.semester_id WHERE sd.organization_id = {$orgId} AND s.status = 'archived'");
    
    // Delete rows not belonging to the active semester (if one exists)
    $activeSemesterId = getCurrentActiveSemesterId($conn);
    if ($activeSemesterId) {
        $stmtCleanup = $conn->prepare("DELETE FROM student_data WHERE organization_id = ? AND semester_id IS NOT NULL AND semester_id <> ?");
        if ($stmtCleanup) {
            $stmtCleanup->bind_param("ii", $orgId, $activeSemesterId);
            $stmtCleanup->execute();
            $stmtCleanup->close();
        }
    }
} catch (Exception $e) {
    // Non-fatal; continue page load
}

// Fetch course code and name
$course_code = '';
$course_name = '';
$stmt = $conn->prepare("SELECT code, name FROM courses WHERE id = ?");
$stmt->bind_param("i", $orgCourseId);
$stmt->execute();
$stmt->bind_result($course_code, $course_name);
$stmt->fetch();
$stmt->close();

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Handle template download
if (isset($_GET['download_template']) && $_GET['download_template'] == '1') {
    try {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set headers
        $headers = ['Student Name', 'Student Number', 'Course', 'Sex'];
        $sheet->fromArray($headers, NULL, 'A1');
        
        // Style header row
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0b5d1e'], // CvSU green
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A1:D1')->applyFromArray($headerStyle);
        
        // Add sample data rows
        $sampleData = [
            ['Juan Dela Cruz', '2020-12345', $course_code, 'Male'],
            ['Maria Santos', '2020-67890', $course_code, 'Female'],
        ];
        $sheet->fromArray($sampleData, NULL, 'A2');
        
        // Style sample data rows
        $dataStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
        $sheet->getStyle('A2:D3')->applyFromArray($dataStyle);
        
        // Set column widths
        $sheet->getColumnDimension('A')->setWidth(25);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(15);
        $sheet->getColumnDimension('D')->setWidth(10);
        
        // Add instructions note
        $sheet->setCellValue('A5', 'Instructions:');
        $sheet->setCellValue('A6', '1. Fill in the student information in the rows below the sample data.');
        $sheet->setCellValue('A7', '2. Course column must match your assigned course code: ' . $course_code);
        $sheet->setCellValue('A8', '3. Sex column should be either "Male" or "Female".');
        $sheet->setCellValue('A9', '4. You can delete the sample rows (rows 2-3) before uploading.');
        $sheet->getStyle('A5:A9')->getFont()->setItalic(true);
        $sheet->getStyle('A5')->getFont()->setBold(true);
        
        // Set row heights
        $sheet->getRowDimension(1)->setRowHeight(25);
        
        // Output file
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Student_Registry_Template.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } catch (Exception $e) {
        error_log("Template download error: " . $e->getMessage());
        $_SESSION['upload_message'] = 'Error generating template file. Please try again.';
        $_SESSION['upload_success'] = false;
        header('Location: registry.php');
        exit;
    }
}

// Helper function to validate header row
function validateHeaderRow($headerRow, $expectedColumns) {
    // Check if header row exists and has minimum columns
    if (empty($headerRow) || count($headerRow) < 4) {
        return [
            'valid' => false,
            'message' => 'The file must have a header row with at least 4 columns. Found ' . (count($headerRow) ?? 0) . ' column(s).'
        ];
    }
    
    // Normalize header values (case-insensitive, trim whitespace)
    $normalizedHeaders = array_map(function($val) {
        return strtolower(trim($val ?? ''));
    }, array_slice($headerRow, 0, 4));
    
    // Expected normalized headers (flexible matching)
    $expectedNormalized = [
        strtolower(trim($expectedColumns[0])), // Student Name
        strtolower(trim($expectedColumns[1])), // Student Number
        strtolower(trim($expectedColumns[2])), // Course
        strtolower(trim($expectedColumns[3]))  // Sex
    ];
    
    // Check for common variations of column names
    $validPatterns = [
        ['student name', 'name', 'student', 'full name', 'student_name'],
        ['student number', 'student no', 'student no.', 'student_number', 'id number', 'id no', 'id'],
        ['course', 'course code', 'course_code', 'program', 'programme'],
        ['sex', 'gender', 'sex/gender']
    ];
    
    $missingColumns = [];
    
    // Check each expected column
    for ($i = 0; $i < 4; $i++) {
        $found = false;
        $headerValue = $normalizedHeaders[$i];
        
        // Check if header matches any valid pattern for this column
        foreach ($validPatterns[$i] as $pattern) {
            if (strpos($headerValue, $pattern) !== false || $headerValue === $pattern) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $missingColumns[] = $expectedColumns[$i];
        }
    }
    
    if (!empty($missingColumns)) {
        return [
            'valid' => false,
            'message' => 'Invalid column headers. Expected columns: ' . implode(', ', $expectedColumns) . '. Missing or incorrect columns: ' . implode(', ', $missingColumns) . '. Please ensure your file has the correct column headers in the first row.'
        ];
    }
    
    return ['valid' => true, 'message' => ''];
}

// Retrieve messages from session (for GET requests after redirect)
$uploadSuccess = $_SESSION['upload_success'] ?? false;
$uploadMessage = $_SESSION['upload_message'] ?? '';

// Clear session messages after retrieving them (but keep show_categorize if we have uploaded students)
unset($_SESSION['upload_success'], $_SESSION['upload_message']);

// Check if we should show categorize step (from session or if we have uploaded students)
$showCategorize = false;
if (isset($_SESSION['show_categorize']) && $_SESSION['show_categorize']) {
    $showCategorize = true;
} elseif (isset($_SESSION['uploaded_students']) && !empty($_SESSION['uploaded_students'])) {
    $showCategorize = true;
    $_SESSION['show_categorize'] = true; // Persist it
}

// Recognition guard (mirror events.php/awards.php)
$isRecognized = false;
$recognitionNotification = null;
$statusStmt = $conn->prepare("SELECT status FROM organizations WHERE id = ?");
$statusStmt->bind_param("i", $orgId);
$statusStmt->execute();
$statusRes = $statusStmt->get_result();
if ($orgRow = $statusRes->fetch_assoc()) {
    $isRecognized = ($orgRow['status'] === 'recognized');
    if (!$isRecognized) {
        $recognitionNotification = [
            'type' => 'organization',
            'message' => 'This Organization is not yet recognized. Please go to the Organization Documents section and complete the required submissions listed there.',
            'message_html' => 'This Organization is not yet recognized. Please go to the <a href="organization_documents.php" class="alert-link fw-bold">Organization Documents</a> section and complete the required submissions listed there.'
        ];
    }
}
$statusStmt->close();

// Step 1: Handle Excel upload and year-section input
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'upload') {
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'] ?? 'Your organization is not recognized.';
        header('Location: registry.php');
        exit;
    }
    $year_section = trim($_POST['year_section'] ?? '');
    $section = '';
    if ($course_code && $year_section) {
        $section = $year_section; // Only year-section, not full code
    }
    if (!$section) {
        $_SESSION['upload_message'] = 'Please enter a year-section.';
        $_SESSION['upload_success'] = false;
        header('Location: registry.php');
        exit;
    } else if ($_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['upload_message'] = 'File upload error: ' . $_FILES['excelFile']['error'];
        $_SESSION['upload_success'] = false;
        header('Location: registry.php');
        exit;
    } else {
        // Validate file type and size
        $file_name = $_FILES['excelFile']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $file_size = $_FILES['excelFile']['size'] ?? 0;
        $fileTmpPath = $_FILES['excelFile']['tmp_name'];
        
        // Validate file size (10MB max)
        if ($file_size > 10 * 1024 * 1024) {
            $_SESSION['upload_message'] = 'File size exceeds 10MB limit. Please upload a smaller file.';
            $_SESSION['upload_success'] = false;
            header('Location: registry.php');
            exit;
        } elseif (!in_array($file_ext, ['xls', 'xlsx', 'csv'])) {
            $_SESSION['upload_message'] = 'Invalid file type. Only Excel files (XLS, XLSX) or CSV files are allowed.';
            $_SESSION['upload_success'] = false;
            header('Location: registry.php');
            exit;
        } else {
            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $fileTmpPath) : '';
            if ($finfo) { finfo_close($finfo); }
            
            $allowed_mimes = [
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'application/csv',
                'text/plain' // Some CSV files may have this MIME type
            ];
            
            if (!in_array($mime, $allowed_mimes)) {
                $_SESSION['upload_message'] = 'Invalid file type detected. Please upload a valid Excel or CSV file.';
                $_SESSION['upload_success'] = false;
                header('Location: registry.php');
                exit;
            } else {
                try {
                    $spreadsheet = IOFactory::load($fileTmpPath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();
                    
                    // Validate that file is not empty
                    if (empty($rows) || count($rows) < 2) {
                        $_SESSION['upload_message'] = 'Error: The file is empty or has no data rows. Please ensure the file contains at least a header row and one data row.';
                        $_SESSION['upload_success'] = false;
                        header('Location: registry.php');
                        exit;
                    } else {
                        // Validate header row (row 0)
                        $headerRow = $rows[0];
                        $expectedColumns = ['Student Name', 'Student Number', 'Course', 'Sex'];
                        $headerValidation = validateHeaderRow($headerRow, $expectedColumns);
                        
                        if (!$headerValidation['valid']) {
                            $_SESSION['upload_message'] = 'Error: Invalid file format. ' . $headerValidation['message'];
                            $_SESSION['upload_success'] = false;
                            header('Location: registry.php');
                            exit;
                        } else {
                            // Validate minimum column count
                            $maxColumns = 0;
                            foreach ($rows as $row) {
                                $maxColumns = max($maxColumns, count($row));
                            }
                            
                            if ($maxColumns < 4) {
                                $_SESSION['upload_message'] = 'Error: The file must have at least 4 columns (Student Name, Student Number, Course, Sex). Found only ' . $maxColumns . ' column(s).';
                                $_SESSION['upload_success'] = false;
                                header('Location: registry.php');
                                exit;
                            } else {
                                $students = [];
                                $courseMismatch = false;
                                $mismatchRows = [];
                                $missingDataRows = [];
                                
                                // Process data rows (starting from row 1, skipping header)
                                for ($i = 1; $i < count($rows); $i++) {
                                    $row = $rows[$i];
                                    
                                    // Check if row has minimum required columns
                                    if (count($row) < 4) {
                                        $missingDataRows[] = $i + 1; // Excel rows are 1-indexed
                                        continue;
                                    }
                                    
                                    $student_name = trim($row[0] ?? '');
                                    $student_number = trim($row[1] ?? '');
                                    $course = trim($row[2] ?? '');
                                    $sex = trim($row[3] ?? '');
                                    
                                    // Validate required fields are not empty
                                    if (empty($student_name) || empty($student_number) || empty($course) || empty($sex)) {
                                        $missingDataRows[] = $i + 1;
                                        continue;
                                    }
                                    
                                    // Validate course code match
                                    if (strcasecmp($course, $course_code) !== 0) {
                                        $courseMismatch = true;
                                        $mismatchRows[] = $i + 1;
                                    }
                                    
                                    $students[] = [
                                        'name' => $student_name,
                                        'number' => $student_number,
                                        'course' => $course,
                                        'sex' => $sex,
                                        'section' => $section // Only year-section
                                    ];
                                }
                                
                                // Provide feedback based on validation results
                                if (!empty($missingDataRows)) {
                                    $_SESSION['upload_message'] = 'Error: The following row(s) have missing or incomplete data (missing required columns or empty values): Rows ' . implode(', ', $missingDataRows) . '. Please ensure all rows have Student Name, Student Number, Course, and Sex filled in.';
                                    $_SESSION['upload_success'] = false;
                                    header('Location: registry.php');
                                    exit;
                                } elseif ($courseMismatch) {
                                    $_SESSION['upload_message'] = 'Error: The following row(s) in your file have a course that does not match your assigned course ("' . htmlspecialchars($course_code) . '"): Rows ' . implode(', ', $mismatchRows) . '. Please correct and re-upload.';
                                    $_SESSION['upload_success'] = false;
                                    header('Location: registry.php');
                                    exit;
                                } elseif (empty($students)) {
                                    $_SESSION['upload_message'] = 'Error: No valid student records found in the file. Please ensure the file contains valid data rows with all required fields filled in.';
                                    $_SESSION['upload_success'] = false;
                                    header('Location: registry.php');
                                    exit;
                                } else {
                                    $_SESSION['uploaded_students'] = $students;
                                    $_SESSION['default_section'] = $section;
                                    $_SESSION['show_categorize'] = true;
                                    header('Location: registry.php');
                                    exit;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    $_SESSION['upload_message'] = 'PhpSpreadsheet error: ' . $e->getMessage();
                    $_SESSION['upload_success'] = false;
                    header('Location: registry.php');
                    exit;
                }
            }
        }
    }
}

// Step 2: Handle categorization and save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] === 'categorize') {
    if (!$isRecognized) {
        $_SESSION['error'] = $recognitionNotification['message'] ?? 'Your organization is not recognized.';
        header('Location: registry.php');
        exit;
    }
    $students = $_SESSION['uploaded_students'] ?? [];
    $inserted = 0;
    $duplicates = 0;
    $duplicate_students = [];
    foreach ($students as $idx => $student) {
        $student_name = $student['name'];
        $student_number = $student['number'];
        $course = $student['course'];
        $sex = $student['sex'];
        $section = $student['section']; // Only year-section
        if ($student_name && $student_number && $course && $sex && $section) {
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM student_data WHERE organization_id = ? AND student_number = ?");
            $checkStmt->bind_param("is", $orgId, $student_number);
            $checkStmt->execute();
            $checkStmt->bind_result($exists);
            $checkStmt->fetch();
            $checkStmt->close();
            if ($exists == 0) {
                $semesterId = getCurrentActiveSemesterId($conn);
                if (!$semesterId) {
                    $_SESSION['upload_message'] = 'No active semester found. Please contact OSAS.';
                    $_SESSION['upload_success'] = false;
                    unset($_SESSION['uploaded_students']);
                    unset($_SESSION['default_section']);
                    header('Location: registry.php');
                    exit;
                }
                $stmt = $conn->prepare("INSERT INTO student_data (organization_id, semester_id, student_name, student_number, course, sex, section, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iisssss", $orgId, $semesterId, $student_name, $student_number, $course, $sex, $section); // Only year-section
                try {
                    $stmt->execute();
                    $inserted++;
                } catch (mysqli_sql_exception $e) {
                    // Error code 1062 is for duplicate entry
                    if ($e->getCode() == 1062) {
                        $duplicates++;
                        $duplicate_students[] = $student_number . ' (' . $student_name . ')';
                    } else {
                        throw $e; // rethrow if it's a different error
                    }
                }
                $stmt->close();
            } else {
                $duplicates++;
                $duplicate_students[] = $student_number . ' (' . $student_name . ')';
            }
        }
    }
    unset($_SESSION['uploaded_students']);
    unset($_SESSION['default_section']);
    $_SESSION['upload_success'] = $inserted > 0;
    if ($duplicates > 0) {
        $_SESSION['upload_message'] = "Upload Failed. Some students already exist. ";
    } else {
        $_SESSION['upload_message'] = "Upload successful. $inserted new student(s) added.";
    }
    header('Location: registry.php');
    exit;
}

// Fetch students for this org
$students_db = [];
$sections_db = [];
// Fetch unique sections for dropdown
$sectionSql = "SELECT DISTINCT sd.section FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = $orgId AND s.status = 'active' AND at.status = 'active' ORDER BY sd.section ASC";
$sectionResult = $conn->query($sectionSql);
while ($row = $sectionResult->fetch_assoc()) {
    $sections_db[] = $row['section'];
}

// Handle section filter from GET
$selected_section = $_GET['section_filter'] ?? 'All';

// Pagination setup
$per_page = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Count total students for pagination (with filter)
$count_sql = "SELECT COUNT(*) FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = $orgId AND s.status = 'active' AND at.status = 'active'";
if ($selected_section !== 'All') {
    $safe_section = $conn->real_escape_string($selected_section);
    $count_sql .= " AND section = '" . $safe_section . "'";
}
$count_result = $conn->query($count_sql);
$total_students_all = $count_result->fetch_row()[0];

$offset = ($page - 1) * $per_page;

// Fetch students for current page
$sql = "SELECT sd.student_name, sd.student_number, sd.course, sd.sex, sd.section FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = $orgId AND s.status = 'active' AND at.status = 'active'";
if ($selected_section !== 'All') {
    $safe_section = $conn->real_escape_string($selected_section);
    $sql .= " AND section = '" . $safe_section . "'";
}
$sql .= " LIMIT $per_page OFFSET $offset";
$studentResult = $conn->query($sql);
$students_db = [];
while ($row = $studentResult->fetch_assoc()) {
    $students_db[] = [
        'name' => $row['student_name'],
        'number' => $row['student_number'],
        'course' => $row['course'],
        'sex' => $row['sex'],
        'section' => $row['section'],
    ];
}
$total_pages = ceil($total_students_all / $per_page);

$uploaded_students = $_SESSION['uploaded_students'] ?? [];
$default_section = $_SESSION['default_section'] ?? '';

// Handle cancel button
if (isset($_GET['cancel'])) {
    unset($_SESSION['uploaded_students']);
    unset($_SESSION['default_section']);
    unset($_SESSION['show_categorize']);
    header("Location: registry.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registry - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        /* Override pagination blue colors to gray */
        .pagination .page-link {
            color: #6c757d !important;
            border-color: #dee2e6 !important;
            background-color: #fff !important;
        }
        
        .pagination .page-link:hover {
            color: #495057 !important;
            background-color: #e9ecef !important;
            border-color: #dee2e6 !important;
        }
        
        .pagination .page-item.active .page-link {
            background-color: #6c757d !important;
            border-color: #6c757d !important;
            color: #fff !important;
        }
        
        .pagination .page-item.disabled .page-link {
            color: #adb5bd !important;
            background-color: #fff !important;
            border-color: #dee2e6 !important;
            cursor: not-allowed;
        }
        
        .pagination .page-link:focus {
            box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <main>
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Registry</h2>
                    <p class="page-subtitle">Manage student registry and upload student data</p>
                </div>
            </div>
            <?php if ($uploadMessage): ?>
                <div class="alert <?php echo $uploadSuccess ? 'alert-success' : 'alert-danger'; ?>">
                    <?php echo htmlspecialchars($uploadMessage); ?>
                </div>
            <?php endif; ?>
            <?php if (!$isRecognized && $recognitionNotification): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <div class="d-flex align-items-start">
                        <div class="me-3">
                            <i class="fas fa-exclamation-triangle fa-2x text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="alert-heading mb-2">
                                <i class="fas fa-bell me-2"></i>
                                Recognition Required
                            </h5>
                            <p class="mb-0">
                                <?php echo isset($recognitionNotification['message_html']) ? $recognitionNotification['message_html'] : htmlspecialchars($recognitionNotification['message']); ?>
                            </p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>
            <!-- Step 1: Upload Form -->
            <?php if (!$showCategorize): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" action="">
                        <input type="hidden" name="step" value="upload">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-auto">
                                <label class="form-label mb-0">Course:</label>
                            </div>
                            <div class="col-8">
                                <input type="text" class="form-control" style="width:100%;" value="<?php echo htmlspecialchars($course_name . ' (' . $course_code . ')'); ?>" readonly>
                            </div>
                            <div class="col-auto">
                                <label for="year_section" class="form-label mb-0">Year-Section:</label>
                            </div>
                            <div class="col-auto">
                                <input type="text" class="form-control" id="year_section" name="year_section" placeholder="e.g. 3-2" required value="<?php echo htmlspecialchars($_POST['year_section'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-auto">
                                <label for="excelFile" class="form-label mb-0">Upload File (Excel, CSV, XLSX):</label>
                            </div>
                            <div class="col-auto">
                                <input type="file" class="form-control" id="excelFile" name="excelFile" accept=".xlsx,.xls,.csv" required <?php echo !$isRecognized ? 'disabled' : ''; ?>>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-secondary" <?php echo !$isRecognized ? 'disabled' : ''; ?>>Upload</button>
                            </div>
                            <div class="col-auto ms-2">
                                <a href="?download_template=1" class="btn btn-outline-primary" <?php echo !$isRecognized ? 'onclick="return false;" style="pointer-events: none; opacity: 0.6;"' : ''; ?>>
                                    <i class="bi bi-download me-1"></i> Download Template
                                </a>
                            </div>
                            <div class="col-auto ms-2">
                                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#manualStudentModal" <?php echo !$isRecognized ? 'disabled' : ''; ?> >
                                    <i class="bi bi-person-plus"></i> Manual Student Entry
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-text mt-2">
                            <strong>Accepted columns:</strong> Student Name, Student Number, Course, Sex<br>
                            <strong>Note:</strong> All students will be pre-filled with the entered section.<br>
                            <strong>Tip:</strong> Click "Download Template" to get a properly formatted Excel file with sample data.
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <!-- Step 2: Categorize Students -->
            <?php if ($showCategorize && count($uploaded_students) > 0): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="step" value="categorize">
                        <h5 class="mb-3">Categorize Students by Section</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Student Number</th>
                                        <th>Course</th>
                                        <th>Sex</th>
                                        <th class="text-center">Section</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($uploaded_students as $idx => $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['number']); ?></td>
                                        <td><?php echo htmlspecialchars($student['course']); ?></td>
                                        <td><?php echo htmlspecialchars($student['sex']); ?></td>
                                        <td class="text-center"><?php echo htmlspecialchars($course_code . ' ' . $student['section']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-success" <?php echo !$isRecognized ? 'disabled' : ''; ?>>Save Students</button>
                            <a href="registry.php?cancel=1" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Manual Student Entry Modal -->
            <div class="modal fade" id="manualStudentModal" tabindex="-1" aria-labelledby="manualStudentModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form id="manualStudentForm">
                    <div class="modal-header">
                      <h5 class="modal-title" id="manualStudentModalLabel">Manual Student Entry</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <label for="manualStudentName" class="form-label">Student Name</label>
                        <input type="text" class="form-control" id="manualStudentName" name="student_name" required placeholder="e.g. LAST NAME, FIRST NAME, M.I" style="text-transform: uppercase;">
                      </div>
                      <div class="mb-3">
                        <label for="manualStudentNumber" class="form-label">Student Number</label>
                        <input type="text" class="form-control" id="manualStudentNumber" name="student_number" required maxlength="9">
                      </div>
                      <div class="mb-3">
                        <label for="manualStudentCourse" class="form-label">Course</label>
                        <input type="text" class="form-control" id="manualStudentCourse" name="course" value="<?php echo htmlspecialchars($course_code); ?>" readonly>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Sex</label><br>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="radio" name="sex" id="sexMale" value="MALE" required>
                          <label class="form-check-label" for="sexMale">MALE</label>
                        </div>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="radio" name="sex" id="sexFemale" value="FEMALE" required>
                          <label class="form-check-label" for="sexFemale">FEMALE</label>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="manualStudentSection" class="form-label">Section</label>
                        <input type="text" class="form-control" id="manualStudentSection" name="section" placeholder="e.g. 3-2" required>
                      </div>
                      <div id="manualStudentError" class="alert alert-danger d-none"></div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-secondary">Add Student</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!-- Edit Student Modal -->
            <div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
              <div class="modal-dialog">
                <div class="modal-content">
                  <form id="editStudentForm">
                    <div class="modal-header">
                      <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div class="mb-3">
                        <label for="editStudentName" class="form-label">Student Name</label>
                        <input type="text" class="form-control" id="editStudentName" name="student_name" required style="text-transform: uppercase;">
                      </div>
                      <div class="mb-3">
                        <label for="editStudentNumber" class="form-label">Student Number</label>
                        <input type="text" class="form-control" id="editStudentNumber" name="student_number" required maxlength="9">
                        <input type="hidden" id="editOriginalStudentNumber" name="original_student_number">
                      </div>
                      <div class="mb-3">
                        <label for="editStudentCourse" class="form-label">Course</label>
                        <input type="text" class="form-control" id="editStudentCourse" name="course" value="<?php echo htmlspecialchars($course_code); ?>" readonly>
                      </div>
                      <div class="mb-3">
                        <label class="form-label">Sex</label><br>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="radio" name="sex" id="editSexMale" value="MALE" required>
                          <label class="form-check-label" for="editSexMale">MALE</label>
                        </div>
                        <div class="form-check form-check-inline">
                          <input class="form-check-input" type="radio" name="sex" id="editSexFemale" value="FEMALE" required>
                          <label class="form-check-label" for="editSexFemale">FEMALE</label>
                        </div>
                      </div>
                      <div class="mb-3">
                        <label for="editStudentSection" class="form-label">Section</label>
                        <input type="text" class="form-control" id="editStudentSection" name="section" required>
                      </div>
                      <div id="editStudentError" class="alert alert-danger d-none"></div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-secondary">Save Changes</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <!-- Student Table -->
            <div class="card">
                <div class="card-body">
                    <form method="get" class="mb-3" id="section-filter-form">
                        <div class="row g-2 align-items-center">
                            <div class="col-auto">
                                <label for="section_filter" class="form-label mb-0">Filter by Section:</label>
                            </div>
                            <div class="col-auto">
                                <select class="form-select" id="section_filter" name="section_filter">
                                    <option value="All" <?php echo ($selected_section === 'All') ? 'selected' : ''; ?>>All</option>
                                    <?php foreach ($sections_db as $section): ?>
                                        <option value="<?php echo htmlspecialchars($section); ?>" <?php echo ($selected_section === $section) ? 'selected' : ''; ?>><?php echo htmlspecialchars($course_code . ' ' . $section); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </form>
                    <div id="table-container">
                        <?php include 'registry_ajax.php'; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function attachRegistryAjaxPagination() {
    document.querySelectorAll('.ajax-page-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.parentElement.classList.contains('disabled') || this.parentElement.classList.contains('active')) return;
            const page = this.getAttribute('data-page');
            loadRegistryTable(page);
        });
    });
}

document.getElementById('section_filter').addEventListener('change', function() {
    loadRegistryTable(1);
});
attachRegistryAjaxPagination();

// Manual Student Entry Modal Submission
const manualStudentForm = document.getElementById('manualStudentForm');
manualStudentForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const errorDiv = document.getElementById('manualStudentError');
    errorDiv.classList.add('d-none');
    
    // Convert student name to uppercase before submission
    const studentNameInput = document.getElementById('manualStudentName');
    studentNameInput.value = studentNameInput.value.toUpperCase();
    
    // Validate student number: must be exactly 9 digits
    const studentNumber = document.getElementById('manualStudentNumber').value.trim();
    if (!/^\d{9}$/.test(studentNumber)) {
        errorDiv.textContent = 'Student number must be exactly 9 digits.';
        errorDiv.classList.remove('d-none');
        document.getElementById('manualStudentNumber').focus();
        return;
    }
    const formData = new FormData(manualStudentForm);
    fetch('manual_student_entry.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Hide error, close modal, reload table
            errorDiv.classList.add('d-none');
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('manualStudentModal'));
            modal.hide();
            loadRegistryTable();
            manualStudentForm.reset();
        } else {
            errorDiv.textContent = data.message || 'An error occurred.';
            errorDiv.classList.remove('d-none');
        }
    })
    .catch(() => {
        errorDiv.textContent = 'An error occurred.';
        errorDiv.classList.remove('d-none');
    });
});

// Edit and Delete Student Actions
function attachStudentActionButtons() {
    // Edit button
    document.querySelectorAll('.edit-student-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            // Fill modal fields
            document.getElementById('editStudentName').value = this.getAttribute('data-name');
            document.getElementById('editStudentNumber').value = this.getAttribute('data-number');
            document.getElementById('editOriginalStudentNumber').value = this.getAttribute('data-number');
            document.getElementById('editStudentSection').value = this.getAttribute('data-section');
            if (this.getAttribute('data-sex') === 'MALE') {
                document.getElementById('editSexMale').checked = true;
            } else {
                document.getElementById('editSexFemale').checked = true;
            }
            document.getElementById('editStudentError').classList.add('d-none');
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editStudentModal'));
            modal.show();
        });
    });
    // Delete button
    document.querySelectorAll('.delete-student-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const studentNumber = this.getAttribute('data-number');
            if (confirm('Are you sure you want to delete this student?')) {
                fetch('delete_student.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'student_number=' + encodeURIComponent(studentNumber)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadRegistryTable();
                    } else {
                        alert(data.message || 'Delete failed.');
                    }
                })
                .catch(() => {
                    alert('Delete failed.');
                });
            }
        });
    });
}
// Attach after table loads
function loadRegistryTable(page = 1) {
    const section = document.getElementById('section_filter').value;
    const params = new URLSearchParams({
        section_filter: section,
        page: page
    });
    fetch('registry_ajax.php?' + params.toString())
        .then(response => response.text())
        .then(html => {
            document.getElementById('table-container').innerHTML = html;
            attachRegistryAjaxPagination();
            attachStudentActionButtons();
        });
}
attachStudentActionButtons();

// Edit Student Modal Submission
const editStudentForm = document.getElementById('editStudentForm');
editStudentForm.addEventListener('submit', function(e) {
    e.preventDefault();
    const errorDiv = document.getElementById('editStudentError');
    errorDiv.classList.add('d-none');
    
    // Convert student name to uppercase before submission
    const studentNameInput = document.getElementById('editStudentName');
    studentNameInput.value = studentNameInput.value.toUpperCase();
    
    // Validate student number: must be exactly 9 digits
    const studentNumber = document.getElementById('editStudentNumber').value.trim();
    if (!/^\d{9}$/.test(studentNumber)) {
        errorDiv.textContent = 'Student number must be exactly 9 digits.';
        errorDiv.classList.remove('d-none');
        document.getElementById('editStudentNumber').focus();
        return;
    }
    const formData = new FormData(editStudentForm);
    fetch('edit_student.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            errorDiv.classList.add('d-none');
            const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editStudentModal'));
            modal.hide();
            loadRegistryTable();
        } else {
            errorDiv.textContent = data.message || 'An error occurred.';
            errorDiv.classList.remove('d-none');
        }
    })
    .catch(() => {
        errorDiv.textContent = 'An error occurred.';
        errorDiv.classList.remove('d-none');
    });
});
</script>
</body>
</html> 
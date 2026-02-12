<?php
require_once 'includes/session.php';
requireRole(['osas']);
require_once 'config/database.php';
require_once 'includes/academic_year_archiver.php';

$success = '';
$error = '';

// Show success message after redirect (PRG pattern)
if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $success = 'Academic term saved successfully.';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $success = 'Academic term updated successfully.';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $success = 'Academic term deleted successfully.';
}

// Detect available columns in academic_terms so we can adapt to existing schema
$termColumns = [];
try {
    $colResult = $conn->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string(DB_NAME) . "' AND TABLE_NAME='academic_terms'");
    if ($colResult) {
        while ($c = $colResult->fetch_assoc()) {
            $termColumns[$c['COLUMN_NAME']] = true;
        }
        $colResult->free();
    }
} catch (Exception $e) {
    // ignore
}
$hasStatusCol = isset($termColumns['status']);
$hasRecognitionValidityCol = isset($termColumns['recognition_validity']);

// Ensure academic_terms table exists with proper schema
try {
    $conn->query("CREATE TABLE IF NOT EXISTS academic_terms (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_year VARCHAR(9) NOT NULL UNIQUE,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        document_start_date DATE NOT NULL,
        document_end_date DATE NOT NULL,
        recognition_validity ENUM('automatic', 'manual') DEFAULT 'automatic',
        status ENUM('active', 'archived') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_school_year (school_year),
        INDEX idx_status (status),
        INDEX idx_dates (start_date, end_date),
        CONSTRAINT chk_academic_year_dates CHECK (start_date < end_date),
        CONSTRAINT chk_document_dates CHECK (document_start_date <= document_end_date),
        CONSTRAINT chk_document_within_academic_year CHECK (
            document_start_date >= start_date AND 
            document_end_date <= end_date
        )
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    $error = 'Initialization error: ' . $e->getMessage();
}

// Ensure academic_semesters table exists (in case migration hasn't been run yet)
try {
    $conn->query("CREATE TABLE IF NOT EXISTS academic_semesters (
        id INT PRIMARY KEY AUTO_INCREMENT,
        academic_term_id INT NOT NULL,
        semester ENUM('1st','2nd') NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('inactive', 'active', 'archived') NOT NULL DEFAULT 'inactive',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
        UNIQUE KEY unique_semester_per_term (academic_term_id, semester),
        INDEX idx_semester_status (status),
        INDEX idx_semester_dates_status (start_date, end_date, status),
        CONSTRAINT chk_semester_dates CHECK (start_date < end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // If creation fails, surface as page error
    $error = 'Initialization error: ' . $e->getMessage();
}

function getSemesterStatus($startDate, $endDate) {
    $today = date('Y-m-d');
    if ($today >= $startDate && $today <= $endDate) {
        return 'active';
    } elseif ($today > $endDate) {
        return 'archived';
    } else {
        return 'inactive';
    }
}

function getAcademicYearStatus($startDate, $endDate) {
    $today = date('Y-m-d');
    if ($today >= $startDate && $today <= $endDate) {
        return 'active';
    } elseif ($today > $endDate) {
        return 'archived';
    } else {
        return 'inactive';
    }
}

function formatSchoolYear($startDate, $endDate) {
    $startYear = (new DateTime($startDate))->format('Y');
    $endYear = (new DateTime($endDate))->format('Y');
    return $startYear . '-' . $endYear;
}

function validateAcademicYear($startDate, $endDate) {
    $errors = [];
    
    if (!$startDate || !$endDate) {
        $errors[] = 'Start and End dates are required.';
        return $errors;
    }
    
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $today = new DateTime();
    $today->setTime(0,0,0);
    
    // Disallow past dates
    if ($start < $today || $end < $today) {
        $errors[] = 'Academic year dates cannot be in the past.';
    }
    
    // Check if start date is before end date
    if ($start >= $end) {
        $errors[] = 'Academic year start date must be before end date.';
    }
    
    // Check if academic year is at least 6 months long
    $diff = $start->diff($end);
    if ($diff->days < 180) {
        $errors[] = 'Academic year must be at least 6 months long.';
    }
    
    // Check if academic year is not more than 2 years long
    if ($diff->days > 730) {
        $errors[] = 'Academic year cannot be longer than 2 years.';
    }
    
    return $errors;
}

function validateRecognitionDates($documentStart, $documentEnd, $academicStart, $academicEnd) {
    $errors = [];
    
    if (!$documentStart || !$documentEnd) {
        $errors[] = 'Document finalization dates are required.';
        return $errors;
    }
    
    $docStart = new DateTime($documentStart);
    $docEnd = new DateTime($documentEnd);
    $acadStart = new DateTime($academicStart);
    $acadEnd = new DateTime($academicEnd);
    $today = new DateTime();
    $today->setTime(0,0,0);
    
    // Disallow past dates for document finalization
    if ($docStart < $today || $docEnd < $today) {
        $errors[] = 'Document finalization dates cannot be in the past.';
    }
    
    // Check if document start is before document end
    if ($docStart > $docEnd) {
        $errors[] = 'Document start date must be before document end date.';
    }
    
    // Check if document dates fall within academic year
    if ($docStart < $acadStart || $docEnd > $acadEnd) {
        $errors[] = 'Document finalization dates must fall within the academic year.';
    }
    
    return $errors;
}

function validateSemesters($sem1Start, $sem1End, $sem2Start, $sem2End, $academicStart, $academicEnd) {
    $errors = [];
    
    if (!$sem1Start || !$sem1End || !$sem2Start || !$sem2End) {
        $errors[] = 'Both semester start and end dates are required.';
        return $errors;
    }
    
    $sem1Start = new DateTime($sem1Start);
    $sem1End = new DateTime($sem1End);
    $sem2Start = new DateTime($sem2Start);
    $sem2End = new DateTime($sem2End);
    $acadStart = new DateTime($academicStart);
    $acadEnd = new DateTime($academicEnd);
    $today = new DateTime();
    $today->setTime(0,0,0);
    
    // Disallow past dates for semesters
    if ($sem1Start < $today || $sem1End < $today || $sem2Start < $today || $sem2End < $today) {
        $errors[] = 'Semester dates cannot be in the past.';
    }
    
    // Validate semester 1
    if ($sem1Start >= $sem1End) {
        $errors[] = 'Semester 1 start date must be before end date.';
    }
    
    // Validate semester 2
    if ($sem2Start >= $sem2End) {
        $errors[] = 'Semester 2 start date must be before end date.';
    }
    
    // Check if semesters fall within academic year
    if ($sem1Start < $acadStart || $sem1End > $acadEnd) {
        $errors[] = 'Semester 1 must fall within the academic year.';
    }
    
    if ($sem2Start < $acadStart || $sem2End > $acadEnd) {
        $errors[] = 'Semester 2 must fall within the academic year.';
    }
    
    // Check if semesters don't overlap
    if (($sem1Start <= $sem2End && $sem1End >= $sem2Start)) {
        $errors[] = 'Semesters cannot overlap.';
    }
    
    // Check if semester 1 comes before semester 2
    if ($sem1Start >= $sem2Start) {
        $errors[] = 'Semester 1 must start before Semester 2.';
    }
    
    return $errors;
}

function checkForOverlappingAcademicYears($startDate, $endDate, $conn) {
    $errors = [];
    
    $stmt = $conn->prepare("SELECT id, school_year FROM academic_terms WHERE 
        (start_date <= ? AND end_date >= ?) OR 
        (start_date <= ? AND end_date >= ?) OR
        (start_date >= ? AND end_date <= ?)");
    $stmt->bind_param('ssssss', $startDate, $startDate, $endDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $overlapping = [];
        while ($row = $result->fetch_assoc()) {
            $overlapping[] = $row['school_year'];
        }
        $errors[] = 'Academic year overlaps with existing year(s): ' . implode(', ', $overlapping);
    }
    
    $stmt->close();
    return $errors;
}

function checkForOverlappingAcademicYearsExcluding($startDate, $endDate, $excludeId, $conn) {
    $errors = [];
    
    $stmt = $conn->prepare("SELECT id, school_year FROM academic_terms WHERE id != ? AND 
        ((start_date <= ? AND end_date >= ?) OR 
         (start_date <= ? AND end_date >= ?) OR
         (start_date >= ? AND end_date <= ?))");
    $stmt->bind_param('issssss', $excludeId, $startDate, $startDate, $endDate, $endDate, $startDate, $endDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $overlapping = [];
        while ($row = $result->fetch_assoc()) {
            $overlapping[] = $row['school_year'];
        }
        $errors[] = 'Academic year overlaps with existing year(s): ' . implode(', ', $overlapping);
    }
    
    $stmt->close();
    return $errors;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_term') {
        $term_id = $_POST['term_id'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $document_start_date = $_POST['document_start_date'] ?? '';
        $document_end_date = $_POST['document_end_date'] ?? '';
        $sem1_start = $_POST['sem1_start'] ?? '';
        $sem1_end = $_POST['sem1_end'] ?? '';
        $sem2_start = $_POST['sem2_start'] ?? '';
        $sem2_end = $_POST['sem2_end'] ?? '';
        $status = $_POST['status'] ?? 'active';

        try {
            // Comprehensive validation
            $validationErrors = [];
            
            // Validate academic year
            $academicYearErrors = validateAcademicYear($start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $academicYearErrors);
            
            // Check for overlapping academic years (excluding current term)
            $overlapErrors = checkForOverlappingAcademicYearsExcluding($start_date, $end_date, $term_id, $conn);
            $validationErrors = array_merge($validationErrors, $overlapErrors);
            
            // Validate recognition dates
            $recognitionErrors = validateRecognitionDates($document_start_date, $document_end_date, $start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $recognitionErrors);
            
            // Validate semesters
            $semesterErrors = validateSemesters($sem1_start, $sem1_end, $sem2_start, $sem2_end, $start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $semesterErrors);
            
            // If there are validation errors, throw them
            if (!empty($validationErrors)) {
                throw new Exception(implode(' ', $validationErrors));
            }

            $school_year = formatSchoolYear($start_date, $end_date);

            $conn->begin_transaction();

            // Determine the proper status based on current date vs academic year dates
            // Only override if the user hasn't explicitly set it to archived
            $academicYearStatus = $status;
            if ($status !== 'archived') {
                $academicYearStatus = getAcademicYearStatus($start_date, $end_date);
            }

            // Update academic term
            $stmt = $conn->prepare("UPDATE academic_terms SET school_year = ?, start_date = ?, end_date = ?, document_start_date = ?, document_end_date = ?, status = ? WHERE id = ?");
            $stmt->bind_param('ssssssi', $school_year, $start_date, $end_date, $document_start_date, $document_end_date, $academicYearStatus, $term_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to update academic term.');
            }
            $stmt->close();

            // If status is being set to archived, delete all related data
            if ($academicYearStatus === 'archived') {
                try {
                    // Delete organization documents for this academic year
                    $deleteOrgDocsStmt = $conn->prepare("DELETE FROM organization_documents WHERE academic_year_id = ?");
                    $deleteOrgDocsStmt->bind_param("i", $term_id);
                    $deleteOrgDocsStmt->execute();
                    $deletedOrgDocs = $deleteOrgDocsStmt->affected_rows;
                    
                    // Delete council documents for this academic year
                    $deleteCouncilDocsStmt = $conn->prepare("DELETE FROM council_documents WHERE academic_year_id = ?");
                    $deleteCouncilDocsStmt->bind_param("i", $term_id);
                    $deleteCouncilDocsStmt->execute();
                    $deletedCouncilDocs = $deleteCouncilDocsStmt->affected_rows;
                    
                    // Delete student data for all semesters of this academic year
                    $deleteStudentDataStmt = $conn->prepare("
                        DELETE sd FROM student_data sd 
                        INNER JOIN academic_semesters s ON sd.semester_id = s.id 
                        WHERE s.academic_term_id = ?
                    ");
                    $deleteStudentDataStmt->bind_param("i", $term_id);
                    $deleteStudentDataStmt->execute();
                    $deletedStudentData = $deleteStudentDataStmt->affected_rows;
                    
                    // Log the data deletion
                    error_log("Calendar management: Deleted data for archived academic year ID {$term_id}: {$deletedOrgDocs} organization documents, {$deletedCouncilDocs} council documents, {$deletedStudentData} student data records");
                    
                } catch (Exception $e) {
                    error_log("Calendar management: Data deletion failed for academic year ID {$term_id}: " . $e->getMessage());
                    // Don't throw exception here as the academic year update was successful
                }
            }

            // Delete existing semesters
            $stmt = $conn->prepare("DELETE FROM academic_semesters WHERE academic_term_id = ?");
            $stmt->bind_param('i', $term_id);
            $stmt->execute();
            $stmt->close();

            // Insert updated semesters
            if ($sem1_start && $sem1_end) {
                $status = getSemesterStatus($sem1_start, $sem1_end);
                $stmt = $conn->prepare("INSERT INTO academic_semesters (academic_term_id, semester, start_date, end_date, status) VALUES (?, '1st', ?, ?, ?)");
                $stmt->bind_param('isss', $term_id, $sem1_start, $sem1_end, $status);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update Semester 1.');
                }
                $stmt->close();
            }

            if ($sem2_start && $sem2_end) {
                $status = getSemesterStatus($sem2_start, $sem2_end);
                $stmt = $conn->prepare("INSERT INTO academic_semesters (academic_term_id, semester, start_date, end_date, status) VALUES (?, '2nd', ?, ?, ?)");
                $stmt->bind_param('isss', $term_id, $sem2_start, $sem2_end, $status);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update Semester 2.');
                }
                $stmt->close();
            }

            $conn->commit();
            
            // If the updated academic year is set to active, update existing organizations and councils
            if ($academicYearStatus === 'active') {
                $updateResults = updateExistingRecordsToActiveAcademicYear();
                if (!empty($updateResults['errors'])) {
                    error_log("Warning: Some records could not be updated to active academic year: " . implode(', ', $updateResults['errors']));
                }
            }
            
            // Redirect to avoid form resubmission on refresh/back
            header('Location: osas_calendar_management.php?updated=1');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } elseif ($action === 'delete_term') {
        $term_id = $_POST['term_id'] ?? '';
        
        try {
            $conn->begin_transaction();
            
            // Delete semesters first (due to foreign key constraint)
            $stmt = $conn->prepare("DELETE FROM academic_semesters WHERE academic_term_id = ?");
            $stmt->bind_param('i', $term_id);
            $stmt->execute();
            $stmt->close();
            
            // Delete academic term
            $stmt = $conn->prepare("DELETE FROM academic_terms WHERE id = ?");
            $stmt->bind_param('i', $term_id);
            if (!$stmt->execute()) {
                throw new Exception('Failed to delete academic term.');
            }
            $stmt->close();
            
            $conn->commit();
            // Redirect to avoid form resubmission on refresh/back
            header('Location: osas_calendar_management.php?deleted=1');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } elseif ($action === 'save_term') {
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $document_start_date = $_POST['document_start_date'] ?? '';
        $document_end_date = $_POST['document_end_date'] ?? '';
        $sem1_start = $_POST['sem1_start'] ?? '';
        $sem1_end = $_POST['sem1_end'] ?? '';
        $sem2_start = $_POST['sem2_start'] ?? '';
        $sem2_end = $_POST['sem2_end'] ?? '';

        try {
            // Comprehensive validation
            $validationErrors = [];
            
            // Validate academic year
            $academicYearErrors = validateAcademicYear($start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $academicYearErrors);
            
            // Check for overlapping academic years
            $overlapErrors = checkForOverlappingAcademicYears($start_date, $end_date, $conn);
            $validationErrors = array_merge($validationErrors, $overlapErrors);
            
            // Validate recognition dates
            $recognitionErrors = validateRecognitionDates($document_start_date, $document_end_date, $start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $recognitionErrors);
            
            // Validate semesters
            $semesterErrors = validateSemesters($sem1_start, $sem1_end, $sem2_start, $sem2_end, $start_date, $end_date);
            $validationErrors = array_merge($validationErrors, $semesterErrors);
            
            // If there are validation errors, throw them
            if (!empty($validationErrors)) {
                throw new Exception(implode(' ', $validationErrors));
            }

            $school_year = formatSchoolYear($start_date, $end_date);

            $conn->begin_transaction();

            // Determine the proper status based on current date vs academic year dates
            $academicYearStatus = getAcademicYearStatus($start_date, $end_date);
            
            // Insert academic term with proper schema
            $stmt = $conn->prepare("INSERT INTO academic_terms (school_year, start_date, end_date, document_start_date, document_end_date, recognition_validity, status) VALUES (?, ?, ?, ?, ?, 'automatic', ?)");
            $stmt->bind_param('ssssss', $school_year, $start_date, $end_date, $document_start_date, $document_end_date, $academicYearStatus);
            if (!$stmt->execute()) {
                throw new Exception('Failed to save academic term.');
            }
            $termId = $stmt->insert_id;
            $stmt->close();

            // Semester 1
            if ($sem1_start && $sem1_end) {
                $status = getSemesterStatus($sem1_start, $sem1_end);
                $stmt = $conn->prepare("INSERT INTO academic_semesters (academic_term_id, semester, start_date, end_date, status) VALUES (?, '1st', ?, ?, ?)");
                $stmt->bind_param('isss', $termId, $sem1_start, $sem1_end, $status);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save Semester 1.');
                }
                $stmt->close();
            }

            // Semester 2
            if ($sem2_start && $sem2_end) {
                $status = getSemesterStatus($sem2_start, $sem2_end);
                $stmt = $conn->prepare("INSERT INTO academic_semesters (academic_term_id, semester, start_date, end_date, status) VALUES (?, '2nd', ?, ?, ?)");
                $stmt->bind_param('isss', $termId, $sem2_start, $sem2_end, $status);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to save Semester 2.');
                }
                $stmt->close();
            }

            $conn->commit();
            
            // Update existing organizations and councils to link them to the new active academic year
            // Only do this if the academic year is actually active
            if ($academicYearStatus === 'active') {
                $updateResults = updateExistingRecordsToActiveAcademicYear();
                if (!empty($updateResults['errors'])) {
                    error_log("Warning: Some records could not be updated to new academic year: " . implode(', ', $updateResults['errors']));
                }
            }
            
            // Redirect to avoid form resubmission on refresh/back
            header('Location: osas_calendar_management.php?saved=1');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar Management - OSAS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main class="py-4">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-2 page-title">Calendar Management</h2>
                <p class="page-subtitle">Manage academic years, recognition periods, and semester schedules</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="osas_manage_academic_years.php" class="btn btn-outline-secondary">
                    <i class="bi bi-archive me-1"></i>
                    Manage Academic Years
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="step-indicator flex-fill d-flex flex-column align-items-center" id="indicator-1">
                            <span class="badge bg-secondary">1</span>
                            <span class="ms-2 step-label">Academic Year</span>
                                <small class="text-muted">Set start and end dates</small>
                        </div>
                        <div class="flex-fill text-center">
                            <hr class="m-0" style="border-top: 2px solid #dee2e6;">
                        </div>
                        <div class="step-indicator flex-fill d-flex flex-column align-items-center" id="indicator-2">
                            <span class="badge bg-secondary">2</span>
                            <span class="ms-2 step-label">Recognition Eligibility</span>
                                <small class="text-muted">Document submission period</small>
                        </div>
                        <div class="flex-fill text-center">
                            <hr class="m-0" style="border-top: 2px solid #dee2e6;">
                        </div>
                        <div class="step-indicator flex-fill d-flex flex-column align-items-center" id="indicator-3">
                            <span class="badge bg-secondary">3</span>
                                <span class="ms-2 step-label">Semester Schedule</span>
                                <small class="text-muted">1st and 2nd semester dates</small>
                        </div>
                    </div>
                </div>

                <form id="calendarForm" method="post" action="">
                    <input type="hidden" name="action" value="save_term">
                    <!-- Step 1: Academic Year -->
                    <div class="step active" id="step-1">
                        <div class="alert alert-secondary" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Academic Year Setup:</strong> Define the start and end dates for the academic year. This will be used to validate all organization and council document deadlines, as well as semester schedules.
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Academic Year Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" id="start_date" required>
                                <div class="form-text">Beginning of the academic year</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Academic Year End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date" id="end_date" required>
                                <div class="form-text">End of the academic year</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">School Year</label>
                                <input type="text" class="form-control" id="school_year" placeholder="YYYY-YYYY" readonly>
                                <div class="form-text">Auto-generated from dates</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-dark btn-lg w-100 rounded-3 shadow-sm" id="next-btn-1">
                                Next: Recognition Eligibility <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Recognition Eligibility -->
                    <div class="step" id="step-2" style="display:none;">
                        <div class="alert alert-secondary" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Recognition Eligibility Period:</strong> Set the submission period for organizations and councils to submit required documents for recognition. You can configure any duration as long as the dates fall within the academic year.
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Validity Period</label>
                                <input type="text" class="form-control" id="validity_period" readonly value="Automatic (based on Academic Year)">
                                <div class="form-text">Recognition validity is automatic</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Document Submission Start <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="document_start_date" id="document_start_date" required>
                                <div class="form-text">When organizations can start submitting</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Document Submission Deadline <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="document_end_date" id="document_end_date" required>
                                <div class="form-text">Final deadline for submissions</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-lg w-50 rounded-3 shadow-sm" id="back-btn-2">
                                <i class="bi bi-arrow-left me-1"></i> Back to Academic Year
                            </button>
                            <button type="button" class="btn btn-dark btn-lg w-50 rounded-3 shadow-sm" id="next-btn-2">
                                Next: Semester Schedule <i class="bi bi-arrow-right ms-1"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 3: Semester Schedule -->
                    <div class="step" id="step-3" style="display:none;">
                        <div class="alert alert-secondary" role="alert">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Semester Schedule:</strong> Define the start and end dates for both semesters. Both semesters must fall within the academic year and cannot overlap. You can configure any duration for each semester as long as they don't overlap and fall within the academic year.
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Academic Year</label>
                                <input type="text" class="form-control" id="school_year_3" placeholder="YYYY-YYYY" readonly>
                                <div class="form-text">Reference for semester validation</div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary">
                                    <i class="bi bi-calendar-week me-2"></i>First Semester
                                </h6>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester 1 Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="sem1_start" id="sem1_start" required>
                                <div class="form-text">Beginning of first semester</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester 1 End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="sem1_end" id="sem1_end" required>
                                <div class="form-text">End of first semester</div>
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-12">
                                <h6 class="fw-bold text-primary">
                                    <i class="bi bi-calendar-week me-2"></i>Second Semester
                                </h6>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester 2 Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="sem2_start" id="sem2_start" required>
                                <div class="form-text">Beginning of second semester</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Semester 2 End Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="sem2_end" id="sem2_end" required>
                                <div class="form-text">End of second semester</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-lg w-50 rounded-3 shadow-sm" id="back-btn-3">
                                <i class="bi bi-arrow-left me-1"></i> Back to Recognition Eligibility
                            </button>
                            <button type="submit" class="btn btn-dark btn-lg w-50 rounded-3 shadow-sm" id="save-btn">
                                <i class="bi bi-check-circle me-1"></i> Save Academic Year
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong>Existing Academic Terms</strong>
                <span class="badge bg-secondary"><?php 
                    $countResult = $conn->query("SELECT COUNT(*) as count FROM academic_terms");
                    $count = $countResult ? $countResult->fetch_assoc()['count'] : 0;
                    echo $count . ' term' . ($count !== 1 ? 's' : '');
                ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>School Year</th>
                                <th>Academic Period</th>
                                <th>Recognition Period</th>
                                <th>Status</th>
                                <th>Semesters</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $result = $conn->query("SELECT id, school_year, start_date, end_date, document_start_date, document_end_date, status FROM academic_terms ORDER BY start_date DESC");
                            if ($result && $result->num_rows > 0):
                                while ($row = $result->fetch_assoc()):
                                    // Get semester details for this academic term
                                    $semesterResult = $conn->query("SELECT semester, start_date, end_date FROM academic_semesters WHERE academic_term_id = " . $row['id'] . " ORDER BY semester");
                                    $semesters = [];
                                    if ($semesterResult) {
                                        while ($sem = $semesterResult->fetch_assoc()) {
                                            $semesters[$sem['semester']] = [
                                                'start' => $sem['start_date'],
                                                'end' => $sem['end_date']
                                            ];
                                        }
                                    }
                                    
                                    // Format dates
                                    $academicStart = date('M d, Y', strtotime($row['start_date']));
                                    $academicEnd = date('M d, Y', strtotime($row['end_date']));
                                    $docStart = date('M d, Y', strtotime($row['document_start_date']));
                                    $docEnd = date('M d, Y', strtotime($row['document_end_date']));
                                    
                                    // Status badge color
                                    $statusClass = $row['status'] === 'active' ? 'bg-success' : 'bg-secondary';
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['school_year']); ?></strong>
                                </td>
                                <td>
                                    <div class="small">
                                        <i class="bi bi-calendar-event me-1"></i>
                                        <strong>Start:</strong> <?php echo $academicStart; ?><br>
                                        <i class="bi bi-calendar-check me-1"></i>
                                        <strong>End:</strong> <?php echo $academicEnd; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="small">
                                        <i class="bi bi-file-earmark-text me-1"></i>
                                        <strong>Start:</strong> <?php echo $docStart; ?><br>
                                        <i class="bi bi-clock me-1"></i>
                                        <strong>Deadline:</strong> <?php echo $docEnd; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $statusClass; ?>"><?php echo ucfirst(htmlspecialchars($row['status'])); ?></span>
                                </td>
                                <td>
                                    <div class="small">
                                        <?php if (isset($semesters['1st'])): ?>
                                        <div><strong>1st:</strong> <?php echo date('M d', strtotime($semesters['1st']['start'])); ?> - <?php echo date('M d, Y', strtotime($semesters['1st']['end'])); ?></div>
                                <?php endif; ?>
                                        <?php if (isset($semesters['2nd'])): ?>
                                        <div><strong>2nd:</strong> <?php echo date('M d', strtotime($semesters['2nd']['start'])); ?> - <?php echo date('M d, Y', strtotime($semesters['2nd']['end'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editTerm(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['school_year'], ENT_QUOTES); ?>', '<?php echo $row['start_date']; ?>', '<?php echo $row['end_date']; ?>', '<?php echo $row['document_start_date']; ?>', '<?php echo $row['document_end_date']; ?>', '<?php echo $row['status']; ?>', '<?php echo htmlspecialchars(json_encode($semesters), ENT_QUOTES); ?>')">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteTerm(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['school_year'], ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x me-2"></i>
                                    No academic terms found. Create your first academic year above.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Edit Academic Term Modal -->
<div class="modal fade" id="editTermModal" tabindex="-1" aria-labelledby="editTermModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTermModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Academic Term
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editTermForm" method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_term">
                    <input type="hidden" name="term_id" id="edit_term_id">
                    
                    <div class="alert alert-info" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Edit Academic Term:</strong> Modify the academic year details. All validation rules apply to ensure data integrity.
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Academic Year Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" id="edit_start_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Academic Year End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" id="edit_end_date" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Document Submission Start <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="document_start_date" id="edit_document_start_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Document Submission Deadline <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="document_end_date" id="edit_document_end_date" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="archived">Archived</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <h6 class="fw-bold text-primary">
                                <i class="bi bi-calendar-week me-2"></i>First Semester
                            </h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Semester 1 Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="sem1_start" id="edit_sem1_start" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Semester 1 End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="sem1_end" id="edit_sem1_end" required>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <h6 class="fw-bold text-primary">
                                <i class="bi bi-calendar-week me-2"></i>Second Semester
                            </h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Semester 2 Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="sem2_start" id="edit_sem2_start" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Semester 2 End Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="sem2_end" id="edit_sem2_end" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Update Academic Term
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteTermModal" tabindex="-1" aria-labelledby="deleteTermModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteTermModalLabel">
                    <i class="bi bi-exclamation-triangle me-2 text-warning"></i>Confirm Deletion
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone. Deleting an academic term will also remove all associated semester data.
                </div>
                <p>Are you sure you want to delete the academic term <strong id="delete_term_name"></strong>?</p>
                <p class="text-muted small">This will permanently remove all semester schedules associated with this academic year.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteTermForm" method="post" action="" style="display: inline;">
                    <input type="hidden" name="action" value="delete_term">
                    <input type="hidden" name="term_id" id="delete_term_id">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Academic Term
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>

<style>
.page-title { margin-top: 0; margin-bottom: .5rem; font-weight: 700; font-size: 2.25rem; color: #1f2937; }
.page-subtitle { margin: 0 0 .75rem 0; color: #6b7280; }
.step-indicator { min-width: 120px; }
.step-indicator .badge { font-size: 1.1rem; padding: 0.6em 1em; }
.step-label { font-weight: 500; font-size: 1rem; }
.step-indicator .badge { background: #9ca3af; color: #fff; }
.step-indicator.active .badge { background: #6b7280 !important; color: #fff; }
.step-indicator.completed .badge { background: #4b5563 !important; color: #fff; }
.step-indicator .step-label { color: #6c757d; }
.step-indicator.active .step-label { color: #212529; font-weight: 600; }
.step-indicator.completed .step-label { color: #198754; }

/* Button styling to match monochrome theme */
.btn-dark {
    background: linear-gradient(90deg, #343a40, #495057) !important;
    border: none !important;
    color: white !important;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-dark:hover {
    background: linear-gradient(90deg, #212529, #343a40) !important;
    color: white !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
}

.btn-dark:focus,
.btn-dark:active,
.btn-dark.active {
    background: linear-gradient(90deg, #343a40, #495057) !important;
    border: none !important;
    box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3) !important;
    outline: none !important;
    color: white !important;
}

/* Responsive adjustments to match system layout */
@media (max-width: 768px) {
    .step-indicator { min-width: 80px; }
    .step-label { font-size: 0.95rem; }
    .container-fluid {
        padding-left: 10px;
        padding-right: 10px;
    }
    .card-body {
        padding: 1rem;
    }
}

@media (max-width: 576px) {
    .container-fluid {
        max-width: 98vw;
    }
    .step-indicator {
        min-width: 60px;
    }
    .step-label {
        font-size: 0.85rem;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const steps = [
        document.getElementById('step-1'),
        document.getElementById('step-2'),
        document.getElementById('step-3')
    ];
    const indicators = [
        document.getElementById('indicator-1'),
        document.getElementById('indicator-2'),
        document.getElementById('indicator-3')
    ];

    function updateIndicators(currentIndex) {
        indicators.forEach((indicator, i) => {
            const badge = indicator.querySelector('.badge');
            const label = indicator.querySelector('.step-label');
            // Reset classes
            indicator.classList.remove('active', 'completed');
            // Apply states
            if (i < currentIndex) {
                indicator.classList.add('completed');
                if (badge) badge.innerHTML = '<i class="bi bi-check"></i>';
            } else if (i === currentIndex) {
                indicator.classList.add('active');
                if (badge) badge.textContent = String(i + 1);
            } else {
                if (badge) badge.textContent = String(i + 1);
            }
        });
    }

    function showStep(index) {
        steps.forEach((s, i) => {
            if (i === index) {
                s.style.display = '';
                s.classList.add('active');
            } else {
                s.style.display = 'none';
                s.classList.remove('active');
            }
        });
        updateIndicators(index);
    }

    function updateSchoolYear() {
        const start = document.getElementById('start_date').value;
        const end = document.getElementById('end_date').value;
        if (start && end) {
            const sy = new Date(start).getFullYear() + '-' + new Date(end).getFullYear();
            document.getElementById('school_year').value = sy;
            document.getElementById('school_year_3').value = sy;
            const vp = document.getElementById('validity_period');
            if (vp) {
                vp.value = sy;
            }
        }
    }

    document.getElementById('start_date').addEventListener('change', updateSchoolYear);
    document.getElementById('end_date').addEventListener('change', updateSchoolYear);

    // Real-time validation functions
    function validateStep1() {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const errors = [];
        
        if (!startDate || !endDate) {
            errors.push('Please set both Start and End dates for the Academic Year.');
            return errors;
        }
        
        const start = new Date(startDate);
        const end = new Date(endDate);
        
        if (start >= end) {
            errors.push('Academic year start date must be before end date.');
        }
        
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays < 180) {
            errors.push('Academic year must be at least 6 months long.');
        }
        
        if (diffDays > 730) {
            errors.push('Academic year cannot be longer than 2 years.');
        }
        
        return errors;
    }
    
    function validateStep2() {
        const docStart = document.getElementById('document_start_date').value;
        const docEnd = document.getElementById('document_end_date').value;
        const acadStart = document.getElementById('start_date').value;
        const acadEnd = document.getElementById('end_date').value;
        const errors = [];
        
        if (!docStart || !docEnd) {
            errors.push('Please set both Document Submission Start and End dates.');
            return errors;
        }
        
        const docStartDate = new Date(docStart);
        const docEndDate = new Date(docEnd);
        const acadStartDate = new Date(acadStart);
        const acadEndDate = new Date(acadEnd);
        
        if (docStartDate > docEndDate) {
            errors.push('Document start date must be before document end date.');
        }
        
        if (docStartDate < acadStartDate || docEndDate > acadEndDate) {
            errors.push('Document finalization dates must fall within the academic year.');
        }
        
        return errors;
    }
    
    function validateStep3() {
        const sem1Start = document.getElementById('sem1_start').value;
        const sem1End = document.getElementById('sem1_end').value;
        const sem2Start = document.getElementById('sem2_start').value;
        const sem2End = document.getElementById('sem2_end').value;
        const acadStart = document.getElementById('start_date').value;
        const acadEnd = document.getElementById('end_date').value;
        const errors = [];
        
        if (!sem1Start || !sem1End || !sem2Start || !sem2End) {
            errors.push('Please set all semester start and end dates.');
            return errors;
        }
        
        const sem1StartDate = new Date(sem1Start);
        const sem1EndDate = new Date(sem1End);
        const sem2StartDate = new Date(sem2Start);
        const sem2EndDate = new Date(sem2End);
        const acadStartDate = new Date(acadStart);
        const acadEndDate = new Date(acadEnd);
        
        // Validate semester 1
        if (sem1StartDate >= sem1EndDate) {
            errors.push('Semester 1 start date must be before end date.');
        }
        
        // Validate semester 2
        if (sem2StartDate >= sem2EndDate) {
            errors.push('Semester 2 start date must be before end date.');
        }
        
        // Check if semesters fall within academic year
        if (sem1StartDate < acadStartDate || sem1EndDate > acadEndDate) {
            errors.push('Semester 1 must fall within the academic year.');
        }
        
        if (sem2StartDate < acadStartDate || sem2EndDate > acadEndDate) {
            errors.push('Semester 2 must fall within the academic year.');
        }
        
        // Check if semesters don't overlap
        if (sem1StartDate <= sem2EndDate && sem1EndDate >= sem2StartDate) {
            errors.push('Semesters cannot overlap.');
        }
        
        // Check if semester 1 comes before semester 2
        if (sem1StartDate >= sem2StartDate) {
            errors.push('Semester 1 must start before Semester 2.');
        }
        
        return errors;
    }
    
    function showValidationErrors(errors) {
        if (errors.length > 0) {
            alert('Validation Errors:\n\n' + errors.join('\n'));
            return false;
        }
        return true;
    }

    // Navigation buttons with validation
    document.getElementById('next-btn-1').addEventListener('click', function() {
        const errors = validateStep1();
        if (showValidationErrors(errors)) {
        showStep(1);
        }
    });

    document.getElementById('back-btn-2').addEventListener('click', function() {
        showStep(0);
    });

    document.getElementById('next-btn-2').addEventListener('click', function() {
        const errors = validateStep2();
        if (showValidationErrors(errors)) {
        showStep(2);
        }
    });

    document.getElementById('back-btn-3').addEventListener('click', function() {
        showStep(1);
    });
    
    // Form submission validation
    document.getElementById('calendarForm').addEventListener('submit', function(e) {
        const errors = validateStep3();
        if (!showValidationErrors(errors)) {
            e.preventDefault();
        }
    });

    // Real-time validation on input changes
    document.getElementById('start_date').addEventListener('change', function() {
        updateSchoolYear();
        // Clear any previous validation errors
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('end_date').addEventListener('change', function() {
        updateSchoolYear();
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('document_start_date').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('document_end_date').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('sem1_start').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('sem1_end').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('sem2_start').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });
    
    document.getElementById('sem2_end').addEventListener('change', function() {
        this.classList.remove('is-invalid');
    });

    // Initialize first step and populate derived fields
    showStep(0);
    updateSchoolYear();
    
    // Set min attributes on date inputs so past dates cannot be selected
    const todayStr = new Date().toISOString().slice(0,10);
    const dateIds = [
        'start_date','end_date',
        'document_start_date','document_end_date',
        'sem1_start','sem1_end','sem2_start','sem2_end',
        // edit modal fields
        'edit_start_date','edit_end_date',
        'edit_document_start_date','edit_document_end_date',
        'edit_sem1_start','edit_sem1_end','edit_sem2_start','edit_sem2_end'
    ];
    dateIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.setAttribute('min', todayStr);
    });

    // Enhance client-side validation to disallow past dates
    function isDateInPast(d) {
        const date = new Date(d);
        date.setHours(0,0,0,0);
        const today = new Date();
        today.setHours(0,0,0,0);
        return date < today;
    }

    // Patch validateStep1 to check past dates
    const origValidateStep1 = validateStep1;
    window.validateStep1 = function() {
        const errors = origValidateStep1();
        const start = document.getElementById('start_date').value;
        const end = document.getElementById('end_date').value;
        if (start && isDateInPast(start)) errors.push('Academic year start date cannot be in the past.');
        if (end && isDateInPast(end)) errors.push('Academic year end date cannot be in the past.');
        return errors;
    };

    // Patch validateStep2 to check past dates
    const origValidateStep2 = validateStep2;
    window.validateStep2 = function() {
        const errors = origValidateStep2();
        const docStart = document.getElementById('document_start_date').value;
        const docEnd = document.getElementById('document_end_date').value;
        if (docStart && isDateInPast(docStart)) errors.push('Document submission start date cannot be in the past.');
        if (docEnd && isDateInPast(docEnd)) errors.push('Document submission end date cannot be in the past.');
        return errors;
    };

    // Patch validateStep3 to check past dates
    const origValidateStep3 = validateStep3;
    window.validateStep3 = function() {
        const errors = origValidateStep3();
        const sem1Start = document.getElementById('sem1_start').value;
        const sem1End = document.getElementById('sem1_end').value;
        const sem2Start = document.getElementById('sem2_start').value;
        const sem2End = document.getElementById('sem2_end').value;
        if (sem1Start && isDateInPast(sem1Start)) errors.push('Semester 1 start date cannot be in the past.');
        if (sem1End && isDateInPast(sem1End)) errors.push('Semester 1 end date cannot be in the past.');
        if (sem2Start && isDateInPast(sem2Start)) errors.push('Semester 2 start date cannot be in the past.');
        if (sem2End && isDateInPast(sem2End)) errors.push('Semester 2 end date cannot be in the past.');
        return errors;
    };

    // Auto-close modals after successful form submission
    const editModal = document.getElementById('editTermModal');
    const deleteModal = document.getElementById('deleteTermModal');
    
    if (editModal) {
        editModal.addEventListener('hidden.bs.modal', function() {
            // Reset form when modal is closed
            document.getElementById('editTermForm').reset();
        });
    }
    
    if (deleteModal) {
        deleteModal.addEventListener('hidden.bs.modal', function() {
            // Reset form when modal is closed
            document.getElementById('deleteTermForm').reset();
        });
    }
});

// Global functions for edit and delete (must be outside DOMContentLoaded)
window.editTerm = function(id, schoolYear, startDate, endDate, docStartDate, docEndDate, status, semestersJson) {
    // Ensure edit modal date inputs cannot select past dates
    const todayStr = new Date().toISOString().slice(0,10);
    const editIds = ['edit_start_date','edit_end_date','edit_document_start_date','edit_document_end_date','edit_sem1_start','edit_sem1_end','edit_sem2_start','edit_sem2_end'];
    editIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.setAttribute('min', todayStr);
    });

    // Populate the edit form
    document.getElementById('edit_term_id').value = id;
    document.getElementById('edit_start_date').value = startDate;
    document.getElementById('edit_end_date').value = endDate;
    document.getElementById('edit_document_start_date').value = docStartDate;
    document.getElementById('edit_document_end_date').value = docEndDate;
    document.getElementById('edit_status').value = status;
    
    // Parse semester data from JSON string
    try {
        const semesters = JSON.parse(semestersJson);
        if (semesters && semesters['1st']) {
            document.getElementById('edit_sem1_start').value = semesters['1st'].start;
            document.getElementById('edit_sem1_end').value = semesters['1st'].end;
        }
        if (semesters && semesters['2nd']) {
            document.getElementById('edit_sem2_start').value = semesters['2nd'].start;
            document.getElementById('edit_sem2_end').value = semesters['2nd'].end;
        }
    } catch (e) {
        console.error('Error parsing semester data:', e);
    }
    
    // Show the modal
    const editModal = new bootstrap.Modal(document.getElementById('editTermModal'));
    editModal.show();
};

window.deleteTerm = function(id, schoolYear) {
    document.getElementById('delete_term_id').value = id;
    document.getElementById('delete_term_name').textContent = schoolYear;
    
    // Show the modal
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteTermModal'));
    deleteModal.show();
};

// Add validation to edit form (wait for DOM to be ready)
document.addEventListener('DOMContentLoaded', function() {
    const editForm = document.getElementById('editTermForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Updating...';
            submitBtn.disabled = true;
    const startDate = document.getElementById('edit_start_date').value;
    const endDate = document.getElementById('edit_end_date').value;
    const docStart = document.getElementById('edit_document_start_date').value;
    const docEnd = document.getElementById('edit_document_end_date').value;
    const sem1Start = document.getElementById('edit_sem1_start').value;
    const sem1End = document.getElementById('edit_sem1_end').value;
    const sem2Start = document.getElementById('edit_sem2_start').value;
    const sem2End = document.getElementById('edit_sem2_end').value;
    
    const errors = [];
    
    // Validate academic year
    if (!startDate || !endDate) {
        errors.push('Please set both Start and End dates for the Academic Year.');
    } else {
        const start = new Date(startDate);
        const end = new Date(endDate);
        const today = new Date(); today.setHours(0,0,0,0);
        if (start < today) errors.push('Academic year start date cannot be in the past.');
        if (end < today) errors.push('Academic year end date cannot be in the past.');
        // Check if start date is before end date
        if (start >= end) {
            errors.push('Academic year start date must be before end date.');
        }
        
        const diffTime = Math.abs(end - start);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays < 180) {
            errors.push('Academic year must be at least 6 months long.');
        }
        
        if (diffDays > 730) {
            errors.push('Academic year cannot be longer than 2 years.');
        }
    }
    
    // Validate recognition dates
    if (!docStart || !docEnd) {
        errors.push('Document finalization dates are required.');
    } else {
        const docStartDate = new Date(docStart);
        const docEndDate = new Date(docEnd);
        const acadStartDate = new Date(startDate);
        const acadEndDate = new Date(endDate);
        const today = new Date(); today.setHours(0,0,0,0);
        
        // Disallow past dates for document finalization
        if (docStartDate < today) errors.push('Document submission start date cannot be in the past.');
        if (docEndDate < today) errors.push('Document submission end date cannot be in the past.');
        
        // Check if document start is before document end
        if (docStart > docEnd) {
            errors.push('Document start date must be before document end date.');
        }
        
        // Check if document dates fall within academic year
        if (docStartDate < acadStartDate || docEndDate > acadEndDate) {
            errors.push('Document finalization dates must fall within the academic year.');
        }
    }
    
    // Validate semesters
    if (!sem1Start || !sem1End || !sem2Start || !sem2End) {
        errors.push('Both semester start and end dates are required.');
    } else {
        const sem1StartDate = new Date(sem1Start);
        const sem1EndDate = new Date(sem1End);
        const sem2StartDate = new Date(sem2Start);
        const sem2EndDate = new Date(sem2End);
        const acadStartDate = new Date(startDate);
        const acadEndDate = new Date(endDate);
        const today = new Date(); today.setHours(0,0,0,0);
        
        // Disallow past dates for semesters
        if (sem1StartDate < today || sem1EndDate < today || sem2StartDate < today || sem2EndDate < today) {
            errors.push('Semester dates cannot be in the past.');
        }
        
        // Validate semester 1
        if (sem1StartDate >= sem1EndDate) {
            errors.push('Semester 1 start date must be before end date.');
        }
        
        // Validate semester 2
        if (sem2StartDate >= sem2EndDate) {
            errors.push('Semester 2 start date must be before end date.');
        }
        
        // Check if semesters fall within academic year
        if (sem1StartDate < acadStartDate || sem1EndDate > acadEndDate) {
            errors.push('Semester 1 must fall within the academic year.');
        }
        
        if (sem2StartDate < acadStartDate || sem2EndDate > acadEndDate) {
            errors.push('Semester 2 must fall within the academic year.');
        }
        
        // Check if semesters don't overlap
        if (sem1StartDate <= sem2EndDate && sem1EndDate >= sem2StartDate) {
            errors.push('Semesters cannot overlap.');
        }
        
        // Check if semester 1 comes before semester 2
        if (sem1StartDate >= sem2StartDate) {
            errors.push('Semester 1 must start before Semester 2.');
        }
    }
    
            if (errors.length > 0) {
                e.preventDefault();
                alert('Validation Errors:\n\n' + errors.join('\n'));
                // Restore button state
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
            // If no errors, form will submit and page will redirect
        });
    }
    
    // Add loading state to delete form
    const deleteForm = document.getElementById('deleteTermForm');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Deleting...';
            submitBtn.disabled = true;
        });
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
require_once 'config/database.php';

$error = '';
$success = '';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log form submission
    error_log('Form submitted with data: ' . print_r($_POST, true));
    
    // Get form type to determine processing path
    $isStudentCouncil = $_POST['is_student_council'] ?? '';
    $orgType = $_POST['org_type'] ?? '';
    
    error_log("Form type - isStudentCouncil: $isStudentCouncil, orgType: $orgType");
    
    // Validate organization type
    if ($orgType !== 'academic') {
        $error = 'This form is intended only for academic organizations.';
    } else {
        // Process based on organization type - COMPLETELY SEPARATE LOGIC
        if ($isStudentCouncil === 'yes') {
            // ===== COUNCIL FORM PROCESSING =====
            $collegeId = intval($_POST['council_college_id'] ?? 0);
            $presidentName = strtoupper(trim($_POST['council_president_name'] ?? ''));
            $adviserName = strtoupper(trim($_POST['council_adviser_name'] ?? ''));
            $presidentEmail = trim($_POST['council_president_email'] ?? '');
            $adviserEmail = trim($_POST['council_adviser_email'] ?? '');
            
            // Validate ONLY council fields
            if (!$collegeId || !$presidentName || !$adviserName || !$presidentEmail || !$adviserEmail) {
                $error = 'Please fill in all required fields for council registration.';
            } else if (!filter_var($presidentEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $presidentEmail)) {
                $error = "Council president's email must be a valid @cvsu.edu.ph email address.";
            } else if (!filter_var($adviserEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $adviserEmail)) {
                $error = "Council adviser's email must be a valid @cvsu.edu.ph email address.";
            } else {
                // Check if council already exists for this college
                $stmt = $conn->prepare('SELECT id, council_name FROM council WHERE college_id = ?');
                $stmt->bind_param('i', $collegeId);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $stmt->bind_result($existingId, $existingName);
                    $stmt->fetch();
                    $stmt->close();
                    $error = "A student council already exists for this college: " . htmlspecialchars($existingName) . ". Each college can only have one council.";
                } else {
                    $stmt->close();
                    // Generate council code and name
                    $collegeStmt = $conn->prepare('SELECT code, name FROM colleges WHERE id = ?');
                    $collegeStmt->bind_param('i', $collegeId);
                    $collegeStmt->execute();
                    $collegeResult = $collegeStmt->get_result();
                    $college = $collegeResult->fetch_assoc();
                    
                    $councilCode = $college['code'] . '-SC';
                    $councilName = $college['name'] . ' Student Council';
                    
                    // Store ONLY council form data for PDF generation
                    $_SESSION['council_form_data'] = [
                        'form_type' => 'council_registration',
                        'council_code' => $councilCode,
                        'council_name' => $councilName,
                        'college_id' => $collegeId,
                        'president_name' => $presidentName,
                        'president_email' => $presidentEmail,
                        'adviser_name' => $adviserName,
                        'adviser_email' => $adviserEmail
                    ];
                    
                    error_log('Council form data stored, setting success flag');
                    // Set success flag for modal display
                    $_SESSION['pdf_generation_success'] = true;
                    $_SESSION['pdf_type'] = 'council';
                    // Redirect back to form with success parameter
                    header('Location: applicationOrg.php?success=1');
                    exit;
                }
            }
        } else {
            // ===== ORGANIZATION FORM PROCESSING =====
            $orgCode = trim($_POST['org_code'] ?? '');
            $orgName = ucwords(strtolower(trim($_POST['org_name'] ?? '')));
            $collegeId = intval($_POST['college_id'] ?? 0);
            $courseId = intval($_POST['course_id'] ?? 0);
            $presidentName = strtoupper(trim($_POST['president_name'] ?? ''));
            $adviserName = strtoupper(trim($_POST['adviser_name'] ?? ''));
            $presidentEmail = trim($_POST['president_email'] ?? '');
            $adviserEmail = trim($_POST['adviser_email'] ?? '');
            
            // Validate ONLY organization fields
            if (!$orgCode || !$orgName || !$collegeId || !$courseId || !$presidentName || !$adviserName || !$presidentEmail || !$adviserEmail) {
                $error = 'Please fill in all required fields for organization registration.';
            } else if (!filter_var($presidentEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $presidentEmail)) {
                $error = "Organization president's email must be a valid @cvsu.edu.ph email address.";
            } else if (!filter_var($adviserEmail, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $adviserEmail)) {
                $error = "Organization adviser's email must be a valid @cvsu.edu.ph email address.";
            } else {
                // Check if organization code already exists
                $stmt = $conn->prepare('SELECT id, org_name FROM organizations WHERE code = ?');
                $stmt->bind_param('s', $orgCode);
                $stmt->execute();
                $stmt->store_result();
                
                if ($stmt->num_rows > 0) {
                    $stmt->bind_result($existingId, $existingName);
                    $stmt->fetch();
                    $stmt->close();
                    $error = "Organization code '" . htmlspecialchars($orgCode) . "' already exists for: " . htmlspecialchars($existingName) . ". Please choose a different organization code.";
                } else {
                    $stmt->close();
                    // Check if organization already exists for this course
                    $stmt = $conn->prepare('SELECT id, org_name FROM organizations WHERE course_id = ?');
                    $stmt->bind_param('i', $courseId);
                    $stmt->execute();
                    $stmt->store_result();
                    
                    if ($stmt->num_rows > 0) {
                        $stmt->bind_result($existingId, $existingName);
                        $stmt->fetch();
                        $stmt->close();
                        $error = "There is already an existing organization for this course: " . htmlspecialchars($existingName) . ". Each course can only have one organization.";
                    } else {
                        $stmt->close();
                        // Store ONLY organization form data for PDF generation
                        $_SESSION['organization_form_data'] = [
                            'form_type' => 'organization_registration',
                            'org_code' => $orgCode,
                            'org_name' => $orgName,
                            'college_id' => $collegeId,
                            'course_id' => $courseId,
                            'president_name' => $presidentName,
                            'president_email' => $presidentEmail,
                            'adviser_name' => $adviserName,
                            'adviser_email' => $adviserEmail
                        ];
                        
                        error_log('Organization form data stored, setting success flag');
                        // Set success flag for modal display
                        $_SESSION['pdf_generation_success'] = true;
                        $_SESSION['pdf_type'] = 'organization';
                        // Redirect back to form with success parameter
                        header('Location: applicationOrg.php?success=1');
                        exit;
                    }
                }
            }
        }
    }
}

// Get colleges for dropdown
$colleges = $conn->query("SELECT * FROM colleges ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// Check for success modal
$showSuccessModal = false;
$pdfType = '';
if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_SESSION['pdf_generation_success'])) {
    $showSuccessModal = true;
    $pdfType = $_SESSION['pdf_type'] ?? '';
    // Clear the success flags after capturing them
    unset($_SESSION['pdf_generation_success']);
    unset($_SESSION['pdf_type']);
}

// Modal flag (legacy)
$showModal = isset($_GET['show_modal']) || (!empty($_SESSION['show_application_modal']));

// Check if we're in preview mode (legacy)
$isPreview = isset($_GET['action']) && $_GET['action'] === 'preview';
$previewData = $_SESSION['application_form_preview'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start an Organization - Application Form</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --cvsu-green: #0b5d1e;
            --cvsu-green-600: #0f7a2a;
            --cvsu-light: #e8f5ed;
            --cvsu-gold: #ffb400;
        }
        body {
            background-color: #f0f2f5;
            padding-top: 48px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .navbar {
            background-color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }
        
        .navbar .container {
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        
        .navbar-brand {
            color: var(--cvsu-green) !important;
            font-weight: bold;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        
        .nav-link {
            color: var(--cvsu-green) !important;
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }
        .brand-logo {
            height: 36px;
            width: auto;
            margin-right: 0.5rem;
        }
        
        .container {
            padding-top: 20px;
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,.2);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid #dddfe2;
            padding: 16px 20px;
        }
        
        .hero-section {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,.2);
            margin-bottom: 20px;
            overflow: hidden;
            position: relative;
        }
        
        .hero-header {
            background: var(--cvsu-green);
            color: #ffffff;
            padding: 25px 20px;
            text-align: center;
            position: relative;
            z-index: 1;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .logo-container {
            position: relative;
            z-index: 10;
            margin-bottom: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .cvsu-logo {
            height: 50px;
            width: auto;
            margin-bottom: 0;
            display: block;
            margin-left: auto;
            margin-right: auto;
            position: relative;
            z-index: 10;
            background: transparent;
            border: none;
            outline: none;
            max-width: 100%;
            object-fit: contain;
        }
        
        .hero-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 6px;
            margin-top: 0;
        }
        
        .hero-subtitle {
            font-size: 1rem;
            opacity: 0.9;
            margin-bottom: 0;
            margin-top: 0;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 30px;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 60%;
            height: 2px;
            background: #dddfe2;
            z-index: 1;
        }
        
        .step {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: #ffffff;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid #dddfe2;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .step.active {
            background: var(--cvsu-green);
            color: #ffffff;
            border-color: var(--cvsu-green);
            transform: scale(1.1);
        }
        
        .step.completed {
            background: var(--cvsu-gold);
            color: #2f2f2f;
            border-color: var(--cvsu-gold);
        }
        
        .form-section {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .form-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--cvsu-green);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
        }
        
        .section-title i {
            margin-right: 10px;
            font-size: 1.3rem;
        }
        
        .form-floating {
            margin-bottom: 20px;
        }
        
        .form-control, .form-select {
            border: 1px solid #dddfe2;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--cvsu-green);
            box-shadow: 0 0 0 2px rgba(11, 93, 30, 0.15);
            outline: none;
        }
        
        .form-control.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.1);
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
        }
        
        .help-text {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 500;
            line-height: 1.4;
        }
        
        .instruction-text {
            font-size: 0.9rem;
            color: #495057;
            margin-bottom: 12px;
            font-weight: 500;
            line-height: 1.4;
        }
        
        .required-note {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .required-note.invalid {
            color: #dc3545;
            font-weight: 600;
        }
        
        .auto-generated-note {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 8px;
            font-style: italic;
        }
        
        .example-text {
            color: var(--cvsu-green);
            font-weight: 500;
        }
        
        .btn {
            border-radius: 6px;
            padding: 10px 24px;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            border: none;
        }
        
        .btn-primary {
            background-color: var(--cvsu-green);
            border-color: var(--cvsu-green);
        }
        
        .btn-primary:hover {
            background-color: var(--cvsu-green-600);
            border-color: var(--cvsu-green-600);
            transform: translateY(-1px);
        }
        
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }
        
        .btn-success {
            background-color: var(--cvsu-gold);
            color: #2f2f2f;
            border-color: var(--cvsu-gold);
        }
        
        .btn-success:hover {
            background-color: #e6a700;
            border-color: #e6a700;
            transform: translateY(-1px);
        }
        
        .alert {
            border-radius: 6px;
            border: none;
            padding: 16px 20px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }
        
        .navigation-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dddfe2;
            position: relative;
            z-index: 10;
            background: #ffffff;
        }
        
        .navigation-buttons .btn {
            position: relative;
            z-index: 15;
            pointer-events: auto;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .navigation-buttons .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .navigation-buttons .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        /* Ensure buttons are always clickable */
        .navigation-buttons .btn:focus {
            outline: 2px solid #1877f2;
            outline-offset: 2px;
        }
        
        .form-check {
            margin-bottom: 15px;
            padding: 12px 16px;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            transition: all 0.2s ease;
            background: #ffffff;
        }
        
        .form-check:hover {
            background: #f8f9fa;
            border-color: #1877f2;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            border: 2px solid #dddfe2;
            border-radius: 3px;
        }
        
        .form-check-input:checked {
            background-color: #1877f2;
            border-color: #1877f2;
        }
        
        .form-check-label {
            font-weight: 500;
            color: #495057;
            margin-left: 8px;
            font-size: 0.95rem;
        }
        
        .form-check-input:checked + .form-check-label {
            color: #1877f2;
            font-weight: 600;
        }
        
        .form-check:has(.form-check-input:checked) {
            background: #f0f7ff;
            border-color: #1877f2;
        }
        
        .form-check-input.is-invalid {
            border-color: #dc3545;
        }
        
        .form-check:has(.form-check-input.is-invalid) {
            border-color: #dc3545;
            background: #fff5f5;
        }
        
        .invalid-feedback {
            display: block;
            width: 100%;
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: #dc3545;
        }
        
        .modal-content {
            border-radius: 8px;
            border: none;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            background: var(--cvsu-green);
            color: #ffffff;
            border-radius: 8px 8px 0 0;
            padding: 20px 24px;
        }
        
        .modal-title {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .spinner-border { width: 2rem; height: 2rem; color: var(--cvsu-green); }
        
        /* Modal Styles */
        .modal-content {
            border: none;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        
        .modal-header.bg-success {
            background-color: #28a745 !important;
            border-bottom: none;
        }
        
        .modal-header.bg-danger {
            background-color: #dc3545 !important;
            border-bottom: none;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 1.5rem;
        }
        
        /* Error Alert Improvements */
        .alert {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .alert-heading {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        /* Shake animation for errors */
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 1.5rem;
            }
            
            .hero-header {
                padding: 20px 15px;
                min-height: 160px;
            }
            
            .cvsu-logo {
                height: 45px;
            }
            
            .logo-container {
                margin-bottom: 8px;
            }
            
            .step {
                width: 30px;
                height: 30px;
                margin: 0 8px;
            }
            
            .navigation-buttons {
                flex-direction: column;
                gap: 15px;
            }
            
            .navigation-buttons .btn {
                width: 100%;
            }
        }
        
        /* Force uppercase for name fields */
        #president_name,
        #adviser_name,
        #council_president_name,
        #council_adviser_name {
            text-transform: uppercase;
        }
        
        /* Uppercase for organization name */
        #org_name {
            text-transform: uppercase;
        }
        
        /* OTP Input Styling */
        .otp-input {
            border: 2px solid #dddfe2;
            border-radius: 8px;
            transition: all 0.2s ease;
        }
        
        .otp-input:focus {
            border-color: var(--cvsu-green);
            box-shadow: 0 0 0 3px rgba(11, 93, 30, 0.2);
        }
        
        .otp-input.filled {
            border-color: var(--cvsu-green);
            background-color: var(--cvsu-light);
        }
        
        .otp-input.error {
            border-color: #dc3545;
            background-color: #fff5f5;
        }
        
        #otp-verify-section {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .btn-lg {
            padding: 12px 24px;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/SAOMS-LOGO-GREEN.png" alt="CvSU SAOMS Logo" class="brand-logo">
                Academic Organizations
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-house me-1"></i>
                            Home
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <!-- Hero Section -->
                <div class="hero-section">
                    <div class="hero-header">
                        <div class="logo-container">
                            <img src="assets/img/SAOMS-LOGO-WHITE.png" alt="CvSU SAOMS Logo" class="cvsu-logo">
                        </div>
                        <h1 class="hero-title">Organization Registration</h1>
                        <p class="hero-subtitle">Start your academic organization journey with CvSU</p>
                    </div>
                </div>

                <!-- Form Container -->
                <div class="card">
                    <div class="card-header text-center">
                        <h4 class="mb-2">
                            <i class="bi bi-file-earmark-text me-2"></i>
                            Application Form
                        </h4>
                        <p class="text-muted mb-0">Complete the steps below to register your organization</p>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.5rem;"></i>
                                    <div>
                                        <h6 class="alert-heading mb-1">Registration Error</h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-check-circle-fill me-3" style="font-size: 1.5rem;"></i>
                                    <div>
                                        <h6 class="alert-heading mb-1">Success</h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($success); ?></p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Step Indicator -->
                        <div class="step-indicator">
                            <div class="step active" id="step-indicator-1">1</div>
                            <div class="step" id="step-indicator-2">2</div>
                            <div class="step" id="step-indicator-3">3</div>
                            <div class="step" id="step-indicator-4">4</div>
                            <div class="step" id="step-indicator-5">5</div>
                            <div class="step" id="step-indicator-6">6</div>
                        </div>

                        <form id="orgForm" method="POST" action="">
                            <!-- Step 1: Organization Type -->
                            <div class="form-section active" id="step1">
                                <h5 class="section-title">
                                    <i class="bi bi-building"></i>
                                    Organization Type
                                </h5>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="org_type" id="academic_org" value="academic" required>
                                    <label class="form-check-label" for="academic_org">
                                        <strong>Academic Organization</strong>
                                        <br><small class="text-muted">Student organizations focused on academic programs, courses, or fields of study</small>
                                    </label>
                                </div>
                                <div class="form-check disabled-option">
                                    <input class="form-check-input" type="radio" name="org_type" id="non_academic_org" value="non-academic" disabled>
                                    <label class="form-check-label" for="non_academic_org">
                                        <strong>Non-Academic Organization</strong>
                                        <br><small class="text-muted">This form is intended only for academic organizations</small>
                                    </label>
                                </div>
                            </div>

                            <!-- Step 2: Student Council Question -->
                            <div class="form-section" id="step2">
                                <h5 class="section-title">
                                    <i class="bi bi-people"></i>
                                    Organization Classification
                                </h5>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="is_student_council" id="regular_org" value="no" required>
                                    <label class="form-check-label" for="regular_org">
                                        <strong>Regular Academic Organization</strong>
                                        <br><small class="text-muted">A student organization focused on a specific academic program or course</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="is_student_council" id="student_council" value="yes" required>
                                    <label class="form-check-label" for="student_council">
                                        <strong>Student Council</strong>
                                        <br><small class="text-muted">The official student government body representing an entire college</small>
                                    </label>
                                </div>
                            </div>


                            <!-- Step 3: College Selection (for Council) -->
                            <div class="form-section" id="step3">
                                <h5 class="section-title">
                                    <i class="bi bi-building"></i>
                                    College Selection
                                </h5>
                                <div class="instruction-text">
                                    Select the college for which you are registering the student council.
                                </div>
                                <div class="form-floating">
                                    <select class="form-select" id="council_college_id" name="council_college_id" required>
                                        <option value="">Select College</option>
                                        <?php foreach ($colleges as $college): ?>
                                            <option value="<?php echo $college['id']; ?>">
                                                <?php echo htmlspecialchars($college['name'] . ' (' . $college['code'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label for="council_college_id">College</label>
                                </div>
                            </div>

                            <!-- Step 4: Regular Organization Details -->
                            <div class="form-section" id="step4">
                                <h5 class="section-title">
                                    <i class="bi bi-file-text"></i>
                                    Organization Details
                                </h5>
                            
                            <div class="help-text">
                                Enter a short code for your organization. 
                                <span class="example-text">Example: CSSO</span>
                            </div>
                            <div class="form-floating">
                                <input type="text" class="form-control" id="org_code" name="org_code" placeholder="Organization Code" required>
                                <label for="org_code">Organization Code</label>
                            </div>

                            <div class="help-text">
                                Enter the full name of your organization. 
                                <span class="example-text">Example: Computer Science Student Organization</span>
                            </div>
                            <div class="form-floating">
                                <input type="text" class="form-control" id="org_name" name="org_name" placeholder="Organization Name" required>
                                <label for="org_name">Organization Name</label>
                            </div>

                            <div class="form-floating">
                                <select class="form-select" id="college_id" name="college_id" required>
                                    <option value="">Select College</option>
                                    <?php foreach ($colleges as $college): ?>
                                        <option value="<?php echo $college['id']; ?>">
                                            <?php echo htmlspecialchars($college['name'] . ' (' . $college['code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label for="college_id">College</label>
                            </div>

                            <div class="form-floating">
                                <select class="form-select" id="course_id" name="course_id" required disabled>
                                    <option value="">Select Course</option>
                                </select>
                                <label for="course_id">Course</label>
                            </div>

                            <div class="form-floating">
                                <input type="text" class="form-control" id="president_name" name="president_name" placeholder="President's Name" required>
                                <label for="president_name">President's Name</label>
                            </div>

                            <div class="required-note">
                                Must be a valid @cvsu.edu.ph email address
                            </div>
                            <div class="form-floating">
                                <input type="email" class="form-control" id="president_email" name="president_email" 
                                       placeholder="President's @cvsu.edu.ph email" 
                                       pattern="[a-zA-Z0-9._%+-]+@cvsu\.edu\.ph$"
                                       title="Email must end with @cvsu.edu.ph"
                                       required>
                                <label for="president_email">President's Email</label>
                            </div>

                            <div class="form-floating">
                                <input type="text" class="form-control" id="adviser_name" name="adviser_name" placeholder="Adviser's Name" required>
                                <label for="adviser_name">Adviser's Name</label>
                            </div>

                            <div class="required-note">
                                Must be a valid @cvsu.edu.ph email address
                            </div>
                            <div class="form-floating">
                                <input type="email" class="form-control" id="adviser_email" name="adviser_email" 
                                       placeholder="Adviser's @cvsu.edu.ph email" 
                                       pattern="[a-zA-Z0-9._%+-]+@cvsu\.edu\.ph$"
                                       title="Email must end with @cvsu.edu.ph"
                                       required>
                                <label for="adviser_email">Adviser's Email</label>
                            </div>
                        </div>

                            <!-- Step 5: Student Council Details -->
                            <div class="form-section" id="step5">
                                <h5 class="section-title">
                                    <i class="bi bi-building-gear"></i>
                                    Student Council Details
                                </h5>
                            
                            <div class="auto-generated-note">
                                <span class="example-text">Example: CSSO</span> - Automatically generated based on college selection
                            </div>
                            <div class="form-floating">
                                <input type="text" class="form-control" id="council_code" name="council_code" readonly>
                                <label for="council_code">Council Code</label>
                            </div>

                            <div class="auto-generated-note">
                                <span class="example-text">Example: Computer Science Student Council</span> - Automatically generated based on college selection
                            </div>
                            <div class="form-floating">
                                <input type="text" class="form-control" id="council_name" name="council_name" readonly>
                                <label for="council_name">Council Name</label>
                            </div>

                            <div class="form-floating">
                                <input type="text" class="form-control" id="council_president_name" name="council_president_name" placeholder="President's Name" required>
                                <label for="council_president_name">President's Name</label>
                            </div>

                            <div class="required-note">
                                Must be a valid @cvsu.edu.ph email address
                            </div>
                            <div class="form-floating">
                                <input type="email" class="form-control" id="council_president_email" name="council_president_email" 
                                       placeholder="President's @cvsu.edu.ph email" 
                                       pattern="[a-zA-Z0-9._%+-]+@cvsu\.edu\.ph$"
                                       title="Email must end with @cvsu.edu.ph"
                                       required>
                                <label for="council_president_email">President's Email</label>
                            </div>

                            <div class="form-floating">
                                <input type="text" class="form-control" id="council_adviser_name" name="council_adviser_name" placeholder="Adviser's Name" required>
                                <label for="council_adviser_name">Adviser's Name</label>
                            </div>

                            <div class="required-note">
                                Must be a valid @cvsu.edu.ph email address
                            </div>
                            <div class="form-floating">
                                <input type="email" class="form-control" id="council_adviser_email" name="council_adviser_email" 
                                       placeholder="Adviser's @cvsu.edu.ph email" 
                                       pattern="[a-zA-Z0-9._%+-]+@cvsu\.edu\.ph$"
                                       title="Email must end with @cvsu.edu.ph"
                                       required>
                                <label for="council_adviser_email">Adviser's Email</label>
                            </div>
                        </div>

                            <!-- Step 6: Email Verification -->
                            <div class="form-section" id="step6">
                                <h5 class="section-title">
                                    <i class="bi bi-envelope-check"></i>
                                    Email Verification
                                </h5>
                                
                                <div class="alert alert-info mb-4">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Verify your identity</strong> - An OTP code will be sent to the selected email to confirm this application.
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Select who will verify this application:</label>
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="verify_by" id="verify_president" value="president" checked>
                                        <label class="form-check-label" for="verify_president">
                                            <strong>President</strong>
                                            <span class="text-primary d-block" id="verify_president_email_display"></span>
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="verify_by" id="verify_adviser" value="adviser">
                                        <label class="form-check-label" for="verify_adviser">
                                            <strong>Adviser</strong>
                                            <span class="text-primary d-block" id="verify_adviser_email_display"></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="otp-send-section">
                                    <button type="button" class="btn btn-primary btn-lg w-100 mb-3" id="sendOtpBtn">
                                        <i class="bi bi-envelope me-2"></i>
                                        Send Verification Code
                                    </button>
                                    <div id="otp-countdown" class="text-center text-muted mb-3" style="display: none;">
                                        <small>Resend code in <span id="countdown-timer">30</span> second(s)</small>
                                    </div>
                                </div>
                                
                                <div id="otp-verify-section" style="display: none;">
                                    <div class="alert alert-success mb-3" id="otp-sent-message">
                                        <i class="bi bi-check-circle me-2"></i>
                                        Verification code sent to <strong id="otp-sent-email"></strong>
                                    </div>
                                    
                                    <label class="form-label fw-bold">Enter the 6-digit verification code:</label>
                                    <div class="otp-input-container d-flex justify-content-center gap-2 mb-4">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                        <input type="text" class="form-control otp-input text-center" maxlength="1" pattern="[0-9]" inputmode="numeric" style="width: 50px; height: 60px; font-size: 1.5rem; font-weight: bold;">
                                    </div>
                                    <input type="hidden" id="full_otp_code" name="otp_code">
                                    <input type="hidden" id="otp_verified_email" name="otp_verified_email">
                                    
                                    <div id="otp-error" class="alert alert-danger mb-3" style="display: none;"></div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i>
                                            Code expires in <span id="otp-expiry-timer">10:00</span>
                                        </small>
                                        <button type="button" class="btn btn-link btn-sm p-0" id="resendOtpBtn" disabled>
                                            Resend Code
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="navigation-buttons">
                                <button type="button" class="btn btn-secondary" id="prevBtn" style="display: none;">
                                    <i class="bi bi-arrow-left me-2"></i>
                                    Previous
                                </button>
                                <button type="button" class="btn btn-primary" id="nextBtn">
                                    Next
                                    <i class="bi bi-arrow-right ms-2"></i>
                                </button>
                                <button type="button" class="btn btn-success" id="verifyBtn" style="display: none;">
                                    <i class="bi bi-shield-check me-2"></i>
                                    Proceed to Verification
                                </button>
                                <button type="button" class="btn btn-success" id="submitBtn" style="display: none;">
                                    <i class="bi bi-check-circle me-2"></i>
                                    Verify & Submit Application
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" aria-labelledby="successModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="successModalLabel">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        Application Submitted Successfully!
                    </h5>
                </div>
                <div class="modal-body text-center py-4">
                    <div class="mb-4">
                        <i class="bi bi-send-check text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h5 class="mb-3">Your application has been submitted for review!</h5>
                    <p class="text-muted mb-4">
                        Your organization registration application has been verified and submitted to the Office of Student Affairs and Services (OSAS) for review.
                    </p>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>What happens next?</strong><br>
                        OSAS will review your application. Once approved, login credentials will be sent to the president and adviser email addresses.
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="btn btn-success btn-lg" id="closeSuccessModal">
                        <i class="bi bi-check-circle me-2"></i>
                        Done
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="errorModalLabel">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        Error
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="errorModalBody">
                    <!-- Error message will be inserted here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStep = 1;
        const totalSteps = 6;
        let isStudentCouncil = false;
        let otpSent = false;
        let otpEmail = '';
        let otpExpiryInterval = null;
        let resendCooldownInterval = null;
        let otpExpired = false; // Track if OTP has expired
        
        // Function to show success modal
        function showSuccessModal() {
            const successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        }
        
        // Handle close button on success modal
        document.getElementById('closeSuccessModal').addEventListener('click', function() {
            const successModal = bootstrap.Modal.getInstance(document.getElementById('successModal'));
            if (successModal) successModal.hide();
            resetFormAndGoToStart();
        });
        
        // Function to reset form and go to start
        function resetFormAndGoToStart() {
            // Clear URL parameters
            window.history.replaceState({}, document.title, 'applicationOrg.php');
            
            // Reset form completely
            document.getElementById('orgForm').reset();
            
            // Reset all form fields
            const allInputs = document.querySelectorAll('#orgForm input, #orgForm select, #orgForm textarea');
            allInputs.forEach(input => {
                if (input.type === 'radio' || input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
                input.classList.remove('is-invalid');
            });
            
            // Reset dropdowns
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                courseSelect.disabled = true;
                courseSelect.innerHTML = '<option value="">Select Course</option>';
            }
            
            // Reset auto-generated fields
            const councilCodeInput = document.getElementById('council_code');
            const councilNameInput = document.getElementById('council_name');
            if (councilCodeInput) councilCodeInput.value = '';
            if (councilNameInput) councilNameInput.value = '';
            
            // Clear all error messages
            hideCouncilError();
            document.querySelectorAll('.invalid-feedback').forEach(error => error.remove());
            document.querySelectorAll('.alert-danger').forEach(alert => alert.remove());
            
            // Reset OTP section
            resetOtpSection();
            
            // Reset to step 1
            currentStep = 1;
            isStudentCouncil = false;
            otpSent = false;
            otpExpired = false;
            showStep(1);
        }
        
        // Reset OTP section to initial state
        function resetOtpSection() {
            const sendSection = document.getElementById('otp-send-section');
            const verifySection = document.getElementById('otp-verify-section');
            const countdown = document.getElementById('otp-countdown');
            const otpError = document.getElementById('otp-error');
            const sendBtn = document.getElementById('sendOtpBtn');
            const verifiedEmailEl = document.getElementById('otp_verified_email');
            const fullOtpEl = document.getElementById('full_otp_code');
            
            if (sendSection) sendSection.style.display = 'block';
            if (verifySection) verifySection.style.display = 'none';
            if (countdown) countdown.style.display = 'none';
            if (otpError) otpError.style.display = 'none';
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="bi bi-envelope me-2"></i>Send Verification Code';
            }
            
            // Clear hidden fields
            if (verifiedEmailEl) verifiedEmailEl.value = '';
            if (fullOtpEl) fullOtpEl.value = '';
            
            // Clear OTP inputs
            document.querySelectorAll('.otp-input').forEach(input => {
                input.value = '';
                input.classList.remove('filled', 'error');
            });
            
            // Clear intervals
            if (otpExpiryInterval) clearInterval(otpExpiryInterval);
            if (resendCooldownInterval) clearInterval(resendCooldownInterval);
            
            // Reset expired flag
            otpExpired = false;
        }
        
        // Function to show error modal
        function showErrorModal(message) {
            const errorModalBody = document.getElementById('errorModalBody');
            errorModalBody.innerHTML = `
                <div class="text-center py-3">
                    <i class="bi bi-exclamation-circle text-danger" style="font-size: 3rem;"></i>
                    <p class="mt-3 mb-0">${message}</p>
                </div>
            `;
            const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
            errorModal.show();
            
            // Scroll to top smoothly
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // Scroll to error alerts on page load if they exist
        <?php if ($error): ?>
        window.addEventListener('load', function() {
            const errorAlert = document.querySelector('.alert-danger');
            if (errorAlert) {
                errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                // Add a subtle shake animation
                errorAlert.style.animation = 'shake 0.5s';
                setTimeout(() => {
                    errorAlert.style.animation = '';
                }, 500);
            }
        });
        <?php endif; ?>

        // Simple, bulletproof button functionality
        function initButtons() {
            // Next button
            const nextBtn = document.getElementById('nextBtn');
            if (nextBtn) {
                nextBtn.onclick = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (validateCurrentStep()) {
                        if (isStudentCouncil) {
                            // Council flow: 1 -> 2 -> 3 -> 5
                            if (currentStep === 1) currentStep = 2;
                            else if (currentStep === 2) currentStep = 3;
                            else if (currentStep === 3) currentStep = 5; // Skip step 4 for council
                        } else {
                            // Regular organization flow: 1 -> 2 -> 4
                            if (currentStep === 1) currentStep = 2;
                            else if (currentStep === 2) currentStep = 4; // Skip step 3 for regular org
                        }
                        showStep(currentStep);
                    }
                };
            }

            // Previous button
            const prevBtn = document.getElementById('prevBtn');
            if (prevBtn) {
                prevBtn.onclick = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (isStudentCouncil) {
                        // Council flow: 6 -> 5 -> 3 -> 2 -> 1
                        if (currentStep === 6) currentStep = 5;
                        else if (currentStep === 5) currentStep = 3;
                        else if (currentStep === 3) currentStep = 2;
                        else if (currentStep === 2) currentStep = 1;
                    } else {
                        // Regular organization flow: 6 -> 4 -> 2 -> 1
                        if (currentStep === 6) currentStep = 4;
                        else if (currentStep === 4) currentStep = 2;
                        else if (currentStep === 2) currentStep = 1;
                    }
                    showStep(currentStep);
                };
            }
            
            // Verify button (proceed to step 6)
            const verifyBtn = document.getElementById('verifyBtn');
            if (verifyBtn) {
                verifyBtn.onclick = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (validateAllSteps()) {
                        // Update email displays for verification step
                        updateVerificationEmails();
                        currentStep = 6;
                        showStep(6);
                    }
                };
            }
        }
        
        // Update email displays in verification step
        function updateVerificationEmails() {
            let presidentEmail, adviserEmail;
            
            if (isStudentCouncil) {
                presidentEmail = document.getElementById('council_president_email')?.value || '';
                adviserEmail = document.getElementById('council_adviser_email')?.value || '';
            } else {
                presidentEmail = document.getElementById('president_email')?.value || '';
                adviserEmail = document.getElementById('adviser_email')?.value || '';
            }
            
            const presidentDisplay = document.getElementById('verify_president_email_display');
            const adviserDisplay = document.getElementById('verify_adviser_email_display');
            
            if (presidentDisplay) presidentDisplay.textContent = presidentEmail;
            if (adviserDisplay) adviserDisplay.textContent = adviserEmail;
        }

        function showStep(step) {
            // Hide all sections
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });

            // Show current section
            const currentSection = document.getElementById('step' + step);
            if (currentSection) {
                currentSection.classList.add('active');
            }

            // Update step indicators
            for (let i = 1; i <= totalSteps; i++) {
                const indicator = document.getElementById('step-indicator-' + i);
                if (indicator) {
                    indicator.classList.remove('active', 'completed');
                    if (i < step) {
                        indicator.classList.add('completed');
                    } else if (i === step) {
                        indicator.classList.add('active');
                    }
                }
            }

            // Update navigation buttons based on current step and organization type
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const verifyBtn = document.getElementById('verifyBtn');
            const submitBtn = document.getElementById('submitBtn');

            if (prevBtn) prevBtn.style.display = step > 1 ? 'block' : 'none';
            
            // Determine step type
            let isFormStep = false; // Steps where user fills details (4 or 5)
            let isVerificationStep = step === 6;
            
            if (isStudentCouncil) {
                isFormStep = step === 5; // Council details
            } else {
                isFormStep = step === 4; // Organization details
            }
            
            // Show/hide buttons based on step
            if (nextBtn) nextBtn.style.display = (!isFormStep && !isVerificationStep) ? 'block' : 'none';
            if (verifyBtn) verifyBtn.style.display = isFormStep ? 'block' : 'none';
            if (submitBtn) submitBtn.style.display = isVerificationStep ? 'block' : 'none';
        }

        function validateCurrentStep() {
            const currentSection = document.getElementById('step' + currentStep);
            if (!currentSection) return false;
            
            const requiredFields = currentSection.querySelectorAll('[required]');
            let isValid = true;
            
            // Special validation for council college selection
            if (currentStep === 3 && isStudentCouncil) {
                const collegeSelect = document.getElementById('council_college_id');
                if (collegeSelect && !collegeSelect.value) {
                    collegeSelect.classList.add('is-invalid');
                    isValid = false;
                } else if (collegeSelect) {
                    collegeSelect.classList.remove('is-invalid');
                }
                
                // Check if there's a council error
                const councilError = document.getElementById('council-error');
                if (councilError) {
                    isValid = false;
                }
            }
            
            for (let field of requiredFields) {
                if (field.type === 'radio') {
                    // Check if any radio button in the group is selected
                    const radioGroup = currentSection.querySelectorAll(`input[name="${field.name}"]`);
                    const isGroupSelected = Array.from(radioGroup).some(radio => radio.checked);
                    
                    if (!isGroupSelected) {
                        radioGroup.forEach(radio => {
                            radio.classList.add('is-invalid');
                        });
                        isValid = false;
                    } else {
                        radioGroup.forEach(radio => {
                            radio.classList.remove('is-invalid');
                        });
                    }
                } else if (field.type === 'email') {
                    const emailValue = field.value.trim();
                    if (!emailValue) {
                        field.classList.add('is-invalid');
                        isValid = false;
                        showEmailError(field, 'Email address is required');
                    } else if (!emailValue.endsWith('@cvsu.edu.ph')) {
                        field.classList.add('is-invalid');
                        isValid = false;
                        showEmailError(field, 'Email must end with @cvsu.edu.ph');
                    } else {
                        field.classList.remove('is-invalid');
                        hideEmailError(field);
                    }
                } else {
                    if (!field.value.trim()) {
                        field.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        field.classList.remove('is-invalid');
                    }
                }
            }
            return isValid;
        }

        // Comprehensive validation for all steps
        function validateAllSteps() {
            let allValid = true;
            let validationErrors = [];
            
            // Validate organization type selection
            const orgType = document.querySelector('input[name="org_type"]:checked');
            if (!orgType || orgType.value !== 'academic') {
                allValid = false;
                validationErrors.push('Organization type not selected or not academic');
            }
            
            // Validate organization classification
            const isStudentCouncilRadio = document.querySelector('input[name="is_student_council"]:checked');
            if (!isStudentCouncilRadio) {
                allValid = false;
                validationErrors.push('Organization classification not selected');
            }
            
            // Use the same logic as getFormData for consistency
            const isStudentCouncilValue = isStudentCouncilRadio && isStudentCouncilRadio.value === 'yes';
            if (isStudentCouncilValue) {
                // Validate council-specific fields ONLY
                const councilCollege = document.getElementById('council_college_id');
                const councilPresidentName = document.getElementById('council_president_name');
                const councilPresidentEmail = document.getElementById('council_president_email');
                const councilAdviserName = document.getElementById('council_adviser_name');
                const councilAdviserEmail = document.getElementById('council_adviser_email');
                
                if (!councilCollege || !councilCollege.value) {
                    allValid = false;
                    validationErrors.push('Council college not selected');
                }
                if (!councilPresidentName || !councilPresidentName.value.trim()) {
                    allValid = false;
                    validationErrors.push('Council president name is required');
                }
                if (!councilPresidentEmail || !councilPresidentEmail.value.trim() || !councilPresidentEmail.value.endsWith('@cvsu.edu.ph')) {
                    allValid = false;
                    validationErrors.push('Council president email is required and must end with @cvsu.edu.ph');
                }
                if (!councilAdviserName || !councilAdviserName.value.trim()) {
                    allValid = false;
                    validationErrors.push('Council adviser name is required');
                }
                if (!councilAdviserEmail || !councilAdviserEmail.value.trim() || !councilAdviserEmail.value.endsWith('@cvsu.edu.ph')) {
                    allValid = false;
                    validationErrors.push('Council adviser email is required and must end with @cvsu.edu.ph');
                }
            } else {
                // Validate regular organization fields ONLY
                const orgCode = document.getElementById('org_code');
                const orgName = document.getElementById('org_name');
                const college = document.getElementById('college_id');
                const course = document.getElementById('course_id');
                const presidentName = document.getElementById('president_name');
                const presidentEmail = document.getElementById('president_email');
                const adviserName = document.getElementById('adviser_name');
                const adviserEmail = document.getElementById('adviser_email');
                
                if (!orgCode || !orgCode.value.trim()) {
                    allValid = false;
                    validationErrors.push('Organization code is required');
                }
                if (!orgName || !orgName.value.trim()) {
                    allValid = false;
                    validationErrors.push('Organization name is required');
                }
                if (!college || !college.value) {
                    allValid = false;
                    validationErrors.push('College is required');
                }
                if (!course || !course.value) {
                    allValid = false;
                    validationErrors.push('Course is required');
                }
                if (!presidentName || !presidentName.value.trim()) {
                    allValid = false;
                    validationErrors.push('President name is required');
                }
                if (!presidentEmail || !presidentEmail.value.trim() || !presidentEmail.value.endsWith('@cvsu.edu.ph')) {
                    allValid = false;
                    validationErrors.push('President email is required and must end with @cvsu.edu.ph');
                }
                if (!adviserName || !adviserName.value.trim()) {
                    allValid = false;
                    validationErrors.push('Adviser name is required');
                }
                if (!adviserEmail || !adviserEmail.value.trim() || !adviserEmail.value.endsWith('@cvsu.edu.ph')) {
                    allValid = false;
                    validationErrors.push('Adviser email is required and must end with @cvsu.edu.ph');
                }
            }
            
            // Show detailed error message if validation fails
            if (!allValid) {
                console.log('Validation failed. Errors:', validationErrors);
                console.log('Form type:', isStudentCouncilValue ? 'Council' : 'Organization');
                
                // Create formatted error message
                let errorHtml = '<div class="text-start"><h6 class="text-danger mb-3">Please complete the following required fields:</h6><ul class="mb-0">';
                validationErrors.forEach(error => {
                    errorHtml += '<li class="mb-2">' + error + '</li>';
                });
                errorHtml += '</ul></div>';
                
                // Show error modal
                const errorModalBody = document.getElementById('errorModalBody');
                errorModalBody.innerHTML = errorHtml;
                const errorModal = new bootstrap.Modal(document.getElementById('errorModal'));
                errorModal.show();
            }
            
            return allValid;
        }


        // Get form data for preview and submission
        function getFormData() {
            const formData = {
                orgType: document.querySelector('input[name="org_type"]:checked')?.value || '',
                isStudentCouncil: document.querySelector('input[name="is_student_council"]:checked')?.value === 'yes'
            };
            
            if (formData.isStudentCouncil) {
                const councilCollegeSelect = document.getElementById('council_college_id');
                const selectedOption = councilCollegeSelect?.options[councilCollegeSelect.selectedIndex];
                const collegeName = selectedOption ? selectedOption.textContent.split(' (')[0] : '';
                
                formData.councilCode = document.getElementById('council_code')?.value || '';
                formData.councilName = document.getElementById('council_name')?.value || '';
                formData.presidentName = document.getElementById('council_president_name')?.value || '';
                formData.presidentEmail = document.getElementById('council_president_email')?.value || '';
                formData.adviserName = document.getElementById('council_adviser_name')?.value || '';
                formData.adviserEmail = document.getElementById('council_adviser_email')?.value || '';
                formData.collegeId = councilCollegeSelect?.value || '';
                formData.collegeName = collegeName;
            } else {
                const collegeSelect = document.getElementById('college_id');
                const courseSelect = document.getElementById('course_id');
                const selectedCollegeOption = collegeSelect?.options[collegeSelect.selectedIndex];
                const selectedCourseOption = courseSelect?.options[courseSelect.selectedIndex];
                
                formData.orgCode = document.getElementById('org_code')?.value || '';
                formData.orgName = document.getElementById('org_name')?.value || '';
                formData.collegeId = collegeSelect?.value || '';
                formData.collegeName = selectedCollegeOption ? selectedCollegeOption.textContent.split(' (')[0] : '';
                formData.courseId = courseSelect?.value || '';
                formData.courseName = selectedCourseOption ? selectedCourseOption.textContent : '';
                formData.presidentName = document.getElementById('president_name')?.value || '';
                formData.presidentEmail = document.getElementById('president_email')?.value || '';
                formData.adviserName = document.getElementById('adviser_name')?.value || '';
                formData.adviserEmail = document.getElementById('adviser_email')?.value || '';
            }
            
            return formData;
        }


        // Reset all form fields
        function resetAllFields() {
            const form = document.getElementById('orgForm');
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                // Skip org_type and is_student_council radio buttons
                if (input.name === 'org_type' || input.name === 'is_student_council') {
                    return; // Don't reset these
                }
                
                if (input.type === 'radio' || input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                }
                // Remove validation classes
                input.classList.remove('is-invalid');
            });
            
            // Reset course dropdown
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                courseSelect.disabled = true;
                courseSelect.innerHTML = '<option value="">Select Course</option>';
            }
            
            // Reset council fields
            const councilCodeInput = document.getElementById('council_code');
            const councilNameInput = document.getElementById('council_name');
            if (councilCodeInput) councilCodeInput.value = '';
            if (councilNameInput) councilNameInput.value = '';
            
            // Clear any error messages
            hideCouncilError();
            document.querySelectorAll('.invalid-feedback').forEach(error => error.remove());
            
            showResetFeedback('Form has been reset for new selection.');
        }

        // Reset organization-specific fields
        function resetOrganizationFields() {
            const orgFields = [
                'org_code', 'org_name', 'college_id', 'course_id',
                'president_name', 'president_email', 'adviser_name', 'adviser_email'
            ];
            
            orgFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.value = '';
                    field.classList.remove('is-invalid');
                }
            });
            
            // Reset course dropdown
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                courseSelect.disabled = true;
                courseSelect.innerHTML = '<option value="">Select Course</option>';
            }
            
            // Clear any error messages
            document.querySelectorAll('.invalid-feedback').forEach(error => error.remove());
            
            showResetFeedback('Organization fields have been reset.');
        }

        // Reset council-specific fields
        function resetCouncilFields() {
            const councilFields = [
                'council_college_id', 'council_code', 'council_name',
                'council_president_name', 'council_president_email', 'council_adviser_name', 'council_adviser_email'
            ];
            
            councilFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.value = '';
                    field.classList.remove('is-invalid');
                }
            });
            
            // Clear any error messages
            hideCouncilError();
            document.querySelectorAll('.invalid-feedback').forEach(error => error.remove());
            
            showResetFeedback('Council fields have been reset.');
        }

        // Comprehensive reset function for all form fields
        function resetAllFormFields() {
            // Reset all input fields EXCEPT org_type and is_student_council
            const allInputs = document.querySelectorAll('#orgForm input, #orgForm select, #orgForm textarea');
            allInputs.forEach(input => {
                // Skip org_type and is_student_council radio buttons
                if (input.name === 'org_type' || input.name === 'is_student_council') {
                    return; // Don't reset these
                }
                
                if (input.type === 'radio' || input.type === 'checkbox') {
                    input.checked = false;
                } else {
                    input.value = '';
                    input.classList.remove('is-invalid');
                }
            });
            
            // Reset course dropdown
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                courseSelect.disabled = true;
                courseSelect.innerHTML = '<option value="">Select Course</option>';
            }
            
            // Reset college dropdowns
            const collegeSelect = document.getElementById('college_id');
            const councilCollegeSelect = document.getElementById('council_college_id');
            if (collegeSelect) collegeSelect.value = '';
            if (councilCollegeSelect) councilCollegeSelect.value = '';
            
            // Reset auto-generated fields
            const councilCodeInput = document.getElementById('council_code');
            const councilNameInput = document.getElementById('council_name');
            if (councilCodeInput) councilCodeInput.value = '';
            if (councilNameInput) councilNameInput.value = '';
            
            // Clear all error messages and validation states
            hideCouncilError();
            document.querySelectorAll('.invalid-feedback').forEach(error => error.remove());
            document.querySelectorAll('.alert-danger').forEach(alert => {
                if (alert.id !== 'council-error') alert.remove();
            });
            
            // Reset form check validation states
            document.querySelectorAll('.form-check').forEach(check => {
                check.classList.remove('border-danger');
            });
            
            // Reset step indicators to show clean state
            for (let i = 2; i <= totalSteps; i++) {
                const indicator = document.getElementById('step-indicator-' + i);
                if (indicator) {
                    indicator.classList.remove('active', 'completed');
                }
            }
        }


        // Show visual feedback for form resets
        function showResetFeedback(message) {
            // Create or update feedback element
            let feedback = document.getElementById('reset-feedback');
            if (!feedback) {
                feedback = document.createElement('div');
                feedback.id = 'reset-feedback';
                feedback.className = 'alert alert-info mt-3';
                feedback.style.display = 'none';
                document.querySelector('.card-body').insertBefore(feedback, document.querySelector('.step-indicator'));
            }
            
            feedback.innerHTML = '<i class="bi bi-info-circle me-2"></i>' + message;
            feedback.style.display = 'block';
            
            // Hide after 3 seconds
            setTimeout(() => {
                feedback.style.display = 'none';
            }, 3000);
        }


        // Show email validation error
        function showEmailError(field, message) {
            // Remove existing error message
            hideEmailError(field);
            
            // Create error message element
            const errorDiv = document.createElement('div');
            errorDiv.className = 'invalid-feedback';
            errorDiv.textContent = message;
            
            // Insert after the field
            field.parentNode.appendChild(errorDiv);
        }

        // Hide email validation error
        function hideEmailError(field) {
            const existingError = field.parentNode.querySelector('.invalid-feedback');
            if (existingError) {
                existingError.remove();
            }
        }

        // Validate organization code
        function validateOrganizationCode(orgCode) {
            fetch('check_org_code_exists.php?org_code=' + encodeURIComponent(orgCode))
                .then(response => response.json())
                .then(data => {
                    const orgCodeField = document.getElementById('org_code');
                    if (data.exists) {
                        orgCodeField.classList.add('is-invalid');
                        showOrgCodeError('Organization code "' + orgCode + '" already exists for: ' + data.org_name + '. Please choose a different code.');
                    } else {
                        orgCodeField.classList.remove('is-invalid');
                        hideOrgCodeError();
                    }
                })
                .catch(error => {
                    console.error('Error validating organization code:', error);
                });
        }

        // Show organization code validation error
        function showOrgCodeError(message) {
            hideOrgCodeError(); // Remove any existing error
            
            const errorDiv = document.createElement('div');
            errorDiv.id = 'org-code-error';
            errorDiv.className = 'invalid-feedback';
            errorDiv.textContent = message;
            
            const orgCodeField = document.getElementById('org_code');
            if (orgCodeField) {
                orgCodeField.parentNode.appendChild(errorDiv);
            }
        }

        // Hide organization code validation error
        function hideOrgCodeError() {
            const existingError = document.getElementById('org-code-error');
            if (existingError) {
                existingError.remove();
            }
        }

        // Check if organization already exists for course
        function checkCourseOrganizationExists(courseId, courseName) {
            fetch('check_course_organization_exists.php?course_id=' + courseId)
                .then(response => response.json())
                .then(data => {
                    const courseSelect = document.getElementById('course_id');
                    if (data.exists) {
                        courseSelect.classList.add('is-invalid');
                        showCourseError('There is already an existing organization for this course: ' + data.org_name + '. Each course can only have one organization.');
                    } else {
                        courseSelect.classList.remove('is-invalid');
                        hideCourseError();
                    }
                })
                .catch(error => {
                    console.error('Error checking course organization existence:', error);
                });
        }

        // Show course organization error
        function showCourseError(message) {
            hideCourseError(); // Remove any existing error
            
            const errorDiv = document.createElement('div');
            errorDiv.id = 'course-error';
            errorDiv.className = 'invalid-feedback';
            errorDiv.textContent = message;
            
            const courseSelect = document.getElementById('course_id');
            if (courseSelect) {
                // Insert the error message right after the course select field
                courseSelect.parentNode.appendChild(errorDiv);
            }
        }

        // Hide course organization error
        function hideCourseError() {
            const existingError = document.getElementById('course-error');
            if (existingError) {
                existingError.remove();
            }
        }

        // Show council error message
        function showCouncilError(message) {
            hideCouncilError(); // Remove any existing error
            
            const errorDiv = document.createElement('div');
            errorDiv.id = 'council-error';
            errorDiv.className = 'invalid-feedback';
            errorDiv.textContent = message;
            
            const collegeSelect = document.getElementById('council_college_id');
            if (collegeSelect) {
                // Insert the error message right after the college select field
                collegeSelect.parentNode.appendChild(errorDiv);
            }
        }

        // Hide council error message
        function hideCouncilError() {
            const existingError = document.getElementById('council-error');
            if (existingError) {
                existingError.remove();
            }
        }


        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initButtons();
            showStep(1);
            
            // Organization type selection
            document.querySelectorAll('input[name="org_type"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'academic') {
                        resetAllFormFields();
                        isStudentCouncil = false;
                        // Move to next step (organization classification)
                        currentStep = 2;
                        showStep(2);
                    }
                });
            });

            // Student council selection
            document.querySelectorAll('input[name="is_student_council"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    // Reset all form fields when switching classification
                    resetAllFormFields();
                    
                    if (this.value === 'yes') {
                        isStudentCouncil = true;
                        // Go to council college selection (step 3)
                        currentStep = 3;
                        showStep(3);
                    } else if (this.value === 'no') {
                        isStudentCouncil = false;
                        // Go to regular organization details (step 4)
                        currentStep = 4;
                        showStep(4);
                    }
                });
            });


            // Council college selection - auto-generate code and name
            const councilCollegeSelect = document.getElementById('council_college_id');
            const councilCodeInput = document.getElementById('council_code');
            const councilNameInput = document.getElementById('council_name');

            if (councilCollegeSelect && councilCodeInput && councilNameInput) {
                councilCollegeSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const collegeName = selectedOption.textContent.split(' (')[0];
                        const collegeCode = selectedOption.textContent.match(/\(([^)]+)\)/)[1];
                        
                        // Check if council already exists for this college
                        fetch('check_council_exists.php?college_id=' + selectedOption.value)
                            .then(response => response.json())
                            .then(data => {
                                if (data.exists) {
                                    // Show error message immediately
                                    councilCollegeSelect.classList.add('is-invalid');
                                    showCouncilError('A student council already exists for ' + collegeName + ': ' + data.council_name + '. Each college can only have one council.');
                                    councilCodeInput.value = '';
                                    councilNameInput.value = '';
                                } else {
                                    // Clear any existing error
                                    councilCollegeSelect.classList.remove('is-invalid');
                                    hideCouncilError();
                                    // Generate council code and name
                                    councilCodeInput.value = collegeCode + '-SC';
                                    councilNameInput.value = collegeName + ' Student Council';
                                }
                            })
                            .catch(error => {
                                console.error('Error checking council existence:', error);
                                // Fallback: generate code and name anyway
                                councilCodeInput.value = collegeCode + 'SC';
                                councilNameInput.value = collegeName + ' Student Council';
                            });
                    } else {
                        councilCodeInput.value = '';
                        councilNameInput.value = '';
                        councilCollegeSelect.classList.remove('is-invalid');
                        hideCouncilError();
                    }
                });
            }

            // Organization college selection - load courses
            const collegeSelect = document.getElementById('college_id');
            const courseSelect = document.getElementById('course_id');

            if (collegeSelect && courseSelect) {
                collegeSelect.addEventListener('change', function() {
                    const collegeId = this.value;
                    
                    // Clear any existing course errors when college changes
                    hideCourseError();
                    courseSelect.classList.remove('is-invalid');
                    
                    if (!collegeId) {
                        courseSelect.disabled = true;
                        courseSelect.innerHTML = '<option value="">Select Course</option>';
                        return;
                    }
                    
                    courseSelect.disabled = false;
                    courseSelect.innerHTML = '<option value="">Loading courses...</option>';
                    
                    fetch('get_courses_by_college.php?college_id=' + collegeId)
                        .then(response => response.json())
                        .then(data => {
                            courseSelect.innerHTML = '<option value="">Select Course</option>';
                            data.forEach(course => {
                                const option = document.createElement('option');
                                option.value = course.id;
                                option.textContent = course.name;
                                courseSelect.appendChild(option);
                            });
                        })
                        .catch(error => {
                            console.error('Error fetching courses:', error);
                            courseSelect.innerHTML = '<option value="">Error loading courses</option>';
                        });
                });
                
                // Add course selection handler for immediate validation
                courseSelect.addEventListener('change', function() {
                    const courseId = this.value;
                    const selectedOption = this.options[this.selectedIndex];
                    const courseName = selectedOption ? selectedOption.textContent : '';
                    
                    if (courseId) {
                        // Check if organization already exists for this course
                        checkCourseOrganizationExists(courseId, courseName);
                    } else {
                        // Clear any existing course error
                        hideCourseError();
                    }
                });
            }

            // Add real-time email validation
            const emailFields = [
                'president_email', 'adviser_email', 
                'council_president_email', 'council_adviser_email'
            ];
            
            emailFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', function() {
                        const emailValue = this.value.trim();
                        if (emailValue) {
                            if (emailValue.endsWith('@cvsu.edu.ph')) {
                                this.classList.remove('is-invalid');
                                hideEmailError(this);
                            } else {
                                this.classList.add('is-invalid');
                                showEmailError(this, 'Email must end with @cvsu.edu.ph');
                            }
                        } else {
                            this.classList.remove('is-invalid');
                            hideEmailError(this);
                        }
                    });
                }
            });

            // Add real-time organization code validation
            const orgCodeField = document.getElementById('org_code');
            if (orgCodeField) {
                let validationTimeout;
                orgCodeField.addEventListener('input', function() {
                    clearTimeout(validationTimeout);
                    const orgCode = this.value.trim();
                    
                    if (orgCode) {
                        // Debounce validation to avoid too many requests
                        validationTimeout = setTimeout(() => {
                            validateOrganizationCode(orgCode);
                        }, 500);
                    } else {
                        this.classList.remove('is-invalid');
                        hideOrgCodeError();
                    }
                });
            }

            // Auto-capitalize name fields
            const nameFields = [
                'president_name', 'adviser_name',
                'council_president_name', 'council_adviser_name'
            ];

            nameFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', function() {
                        this.value = this.value.toUpperCase();
                    });
                }
            });

            // Uppercase for organization name
            const orgNameField = document.getElementById('org_name');
            if (orgNameField) {
                orgNameField.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            }

            // Handle Send OTP button
            const sendOtpBtn = document.getElementById('sendOtpBtn');
            if (sendOtpBtn) {
                sendOtpBtn.addEventListener('click', sendOTP);
            }
            
            // Handle Resend OTP button
            const resendOtpBtn = document.getElementById('resendOtpBtn');
            if (resendOtpBtn) {
                resendOtpBtn.addEventListener('click', sendOTP);
            }
            
            // Handle Submit button (verify OTP and submit)
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.addEventListener('click', verifyAndSubmit);
            }
            
            // Initialize OTP input handlers
            initOtpInputs();

        });

        // Fallback initialization
        window.addEventListener('load', function() {
            initButtons();
        });
        
        // Initialize OTP input field handlers
        function initOtpInputs() {
            const otpInputs = document.querySelectorAll('.otp-input');
            
            otpInputs.forEach((input, index) => {
                // Handle input
                input.addEventListener('input', function(e) {
                    const value = this.value.replace(/[^0-9]/g, '');
                    this.value = value;
                    
                    if (value) {
                        this.classList.add('filled');
                        this.classList.remove('error');
                        // Move to next input
                        if (index < otpInputs.length - 1) {
                            otpInputs[index + 1].focus();
                        }
                    } else {
                        this.classList.remove('filled');
                    }
                    
                    // Update hidden field with full OTP
                    updateFullOtp();
                });
                
                // Handle backspace
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.value && index > 0) {
                        otpInputs[index - 1].focus();
                    }
                });
                
                // Handle paste
                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text');
                    const digits = pastedData.replace(/[^0-9]/g, '').slice(0, 6);
                    
                    digits.split('').forEach((digit, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = digit;
                            otpInputs[i].classList.add('filled');
                        }
                    });
                    
                    updateFullOtp();
                    
                    // Focus last filled or next empty
                    const nextIndex = Math.min(digits.length, otpInputs.length - 1);
                    otpInputs[nextIndex].focus();
                });
            });
        }
        
        // Update hidden OTP field
        function updateFullOtp() {
            const otpInputs = document.querySelectorAll('.otp-input');
            const fullOtpEl = document.getElementById('full_otp_code');
            let fullOtp = '';
            otpInputs.forEach(input => {
                fullOtp += input.value;
            });
            if (fullOtpEl) fullOtpEl.value = fullOtp;
        }
        
        // Send OTP to selected email
        async function sendOTP() {
            const sendBtn = document.getElementById('sendOtpBtn');
            const resendBtn = document.getElementById('resendOtpBtn');
            const verifyByEl = document.querySelector('input[name="verify_by"]:checked');
            const verifyBy = verifyByEl ? verifyByEl.value : 'president';
            
            // Disable button and show loading
            if (sendBtn) {
                sendBtn.disabled = true;
                sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
            }
            
            // Prepare form data
            const formData = getFormDataForOTP();
            formData.verified_by = verifyBy;
            
            try {
                const response = await fetch('send_application_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    otpSent = true;
                    otpEmail = result.actual_email; // Store the actual email used
                    
                    // Show OTP verification section
                    const sendSection = document.getElementById('otp-send-section');
                    const verifySection = document.getElementById('otp-verify-section');
                    const sentEmailEl = document.getElementById('otp-sent-email');
                    const verifiedEmailEl = document.getElementById('otp_verified_email');
                    const firstOtpInput = document.querySelector('.otp-input');
                    
                    if (sendSection) sendSection.style.display = 'none';
                    if (verifySection) verifySection.style.display = 'block';
                    if (sentEmailEl) sentEmailEl.textContent = result.email; // Display masked email
                    if (verifiedEmailEl) verifiedEmailEl.value = result.actual_email; // Store actual email for verification
                    
                    // Focus first OTP input
                    if (firstOtpInput) firstOtpInput.focus();
                    
                    // Start expiry countdown (3 minutes)
                    startExpiryCountdown(result.expires_in || 180);
                    
                    // Start resend cooldown (30 seconds)
                    startResendCooldown(30);
                    
                } else {
                    showErrorModal(result.message || 'Failed to send verification code');
                    if (sendBtn) {
                        sendBtn.disabled = false;
                        sendBtn.innerHTML = '<i class="bi bi-envelope me-2"></i>Send Verification Code';
                    }
                }
                
            } catch (error) {
                console.error('OTP Send Error:', error);
                showErrorModal('Failed to send verification code. Please try again.');
                if (sendBtn) {
                    sendBtn.disabled = false;
                    sendBtn.innerHTML = '<i class="bi bi-envelope me-2"></i>Send Verification Code';
                }
            }
        }
        
        // Get form data for OTP request
        function getFormDataForOTP() {
            const isStudentCouncilValue = document.querySelector('input[name="is_student_council"]:checked')?.value === 'yes';
            
            const data = {
                application_type: isStudentCouncilValue ? 'council' : 'organization'
            };
            
            if (isStudentCouncilValue) {
                const collegeSelect = document.getElementById('council_college_id');
                const selectedOption = collegeSelect.options[collegeSelect.selectedIndex];
                
                data.college_id = collegeSelect.value;
                data.council_name = document.getElementById('council_name').value;
                data.president_name = document.getElementById('council_president_name').value;
                data.president_email = document.getElementById('council_president_email').value;
                data.adviser_name = document.getElementById('council_adviser_name').value;
                data.adviser_email = document.getElementById('council_adviser_email').value;
            } else {
                data.org_code = document.getElementById('org_code').value;
                data.org_name = document.getElementById('org_name').value;
                data.college_id = document.getElementById('college_id').value;
                data.course_id = document.getElementById('course_id').value;
                data.president_name = document.getElementById('president_name').value;
                data.president_email = document.getElementById('president_email').value;
                data.adviser_name = document.getElementById('adviser_name').value;
                data.adviser_email = document.getElementById('adviser_email').value;
            }
            
            return data;
        }
        
        // Start OTP expiry countdown
        function startExpiryCountdown(seconds) {
            const timerEl = document.getElementById('otp-expiry-timer');
            const otpErrorEl = document.getElementById('otp-error');
            let remaining = seconds;
            otpExpired = false; // Reset expired flag
            
            if (otpExpiryInterval) clearInterval(otpExpiryInterval);
            
            otpExpiryInterval = setInterval(() => {
                remaining--;
                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                if (timerEl) timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
                
                if (remaining <= 0) {
                    clearInterval(otpExpiryInterval);
                    otpExpired = true; // Mark as expired
                    if (timerEl) {
                        timerEl.textContent = 'Expired';
                        timerEl.classList.add('text-danger');
                    }
                    if (otpErrorEl) {
                        otpErrorEl.textContent = 'Verification code has expired. Please request a new code.';
                        otpErrorEl.style.display = 'block';
                    }
                    // Disable submit button when expired
                    const submitBtn = document.getElementById('submitBtn');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                    }
                }
            }, 1000);
        }
        
        // Start resend cooldown
        function startResendCooldown(seconds) {
            const resendBtn = document.getElementById('resendOtpBtn');
            const countdownEl = document.getElementById('otp-countdown');
            const timerEl = document.getElementById('countdown-timer');
            let remaining = seconds;
            
            if (resendBtn) resendBtn.disabled = true;
            if (countdownEl) countdownEl.style.display = 'block';
            
            if (resendCooldownInterval) clearInterval(resendCooldownInterval);
            
            // Update display immediately
            updateResendTimerDisplay(timerEl, remaining);
            
            resendCooldownInterval = setInterval(() => {
                remaining--;
                updateResendTimerDisplay(timerEl, remaining);
                
                if (remaining <= 0) {
                    clearInterval(resendCooldownInterval);
                    if (countdownEl) countdownEl.style.display = 'none';
                    if (resendBtn) resendBtn.disabled = false;
                }
            }, 1000);
        }
        
        // Helper function to update resend timer display
        function updateResendTimerDisplay(timerEl, remainingSeconds) {
            if (!timerEl) return;
            // Show seconds if less than 60, otherwise show minutes
            const countdownEl = document.getElementById('otp-countdown');
            if (remainingSeconds < 60) {
                timerEl.textContent = remainingSeconds;
                // Update the label text
                if (countdownEl) {
                    const label = countdownEl.querySelector('small');
                    if (label) {
                        label.innerHTML = `Resend code in <span id="countdown-timer">${remainingSeconds}</span> second(s)`;
                    }
                }
            } else {
                const minutes = Math.ceil(remainingSeconds / 60);
                timerEl.textContent = minutes;
                // Update the label text
                if (countdownEl) {
                    const label = countdownEl.querySelector('small');
                    if (label) {
                        label.innerHTML = `Resend code in <span id="countdown-timer">${minutes}</span> minute(s)`;
                    }
                }
            }
        }
        
        // Verify OTP and submit application
        async function verifyAndSubmit() {
            const submitBtn = document.getElementById('submitBtn');
            const fullOtpEl = document.getElementById('full_otp_code');
            const otpCode = fullOtpEl ? fullOtpEl.value : '';
            const otpErrorEl = document.getElementById('otp-error');
            
            // Get the email from the hidden field (set when OTP was sent)
            const verifiedEmailEl = document.getElementById('otp_verified_email');
            const email = verifiedEmailEl ? verifiedEmailEl.value : '';
            
            // Check if OTP has expired before submitting
            if (otpExpired) {
                if (otpErrorEl) {
                    otpErrorEl.textContent = 'Verification code has expired. Please request a new code.';
                    otpErrorEl.style.display = 'block';
                }
                showErrorModal('Verification code has expired. Please request a new code.');
                document.querySelectorAll('.otp-input').forEach(input => input.classList.add('error'));
                return;
            }
            
            // Validate OTP
            if (!otpCode || otpCode.length !== 6) {
                if (otpErrorEl) {
                    otpErrorEl.textContent = 'Please enter the complete 6-digit verification code.';
                    otpErrorEl.style.display = 'block';
                }
                document.querySelectorAll('.otp-input').forEach(input => input.classList.add('error'));
                return;
            }
            
            if (!email) {
                if (otpErrorEl) {
                    otpErrorEl.textContent = 'Email not found. Please send the verification code first.';
                    otpErrorEl.style.display = 'block';
                }
                return;
            }
            
            // Disable button and show loading
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Verifying...';
            }
            if (otpErrorEl) otpErrorEl.style.display = 'none';
            
            try {
                // Send as URL parameters to survive server redirects
                const params = new URLSearchParams();
                params.append('otp_code', otpCode);
                params.append('email', email);
                
                const response = await fetch('verify_application_otp.php?' + params.toString(), {
                    method: 'GET'
                });
                
                const result = await response.json();
                
                console.log('Verification response:', result);
                
                if (result.success) {
                    // Clear intervals
                    if (otpExpiryInterval) clearInterval(otpExpiryInterval);
                    if (resendCooldownInterval) clearInterval(resendCooldownInterval);
                    
                    // Show success modal
                    showSuccessModal();
                    
                } else {
                    // Show error in alert box
                    if (otpErrorEl) {
                        otpErrorEl.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + (result.message || 'Verification failed. Please try again.');
                        otpErrorEl.style.display = 'block';
                    }
                    
                    // Also show modal for critical errors
                    showErrorModal(result.message || 'Verification failed. Please try again.');
                    
                    // Mark OTP inputs as error
                    document.querySelectorAll('.otp-input').forEach(input => input.classList.add('error'));
                    
                    // Scroll to error
                    if (otpErrorEl) {
                        otpErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Verify & Submit Application';
                    }
                }
                
            } catch (error) {
                console.error('Verification Error:', error);
                const errorMessage = 'Network error. Please check your connection and try again.';
                
                if (otpErrorEl) {
                    otpErrorEl.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + errorMessage;
                    otpErrorEl.style.display = 'block';
                }
                
                showErrorModal(errorMessage);
                
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-check-circle me-2"></i>Verify & Submit Application';
                }
            }
        }
    </script>
</body>
</html>

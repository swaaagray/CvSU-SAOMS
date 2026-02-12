<?php
/**
 * Verify OTP and Submit Organization/Council Application
 * 
 * This API verifies the OTP code and creates the application record
 * for OSAS review.
 */

require_once 'config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get input - try multiple sources
$otpCodeInput = '';
$emailInput = '';

// Try JSON body first
$jsonInput = json_decode(file_get_contents('php://input'), true);
if ($jsonInput) {
    $otpCodeInput = $jsonInput['otp_code'] ?? '';
    $emailInput = $jsonInput['email'] ?? '';
}

// If not found in JSON, try $_POST (FormData)
if (empty($otpCodeInput)) {
    $otpCodeInput = $_POST['otp_code'] ?? '';
}
if (empty($emailInput)) {
    $emailInput = $_POST['email'] ?? '';
}

// If still not found, try $_GET (query string)
if (empty($otpCodeInput)) {
    $otpCodeInput = $_GET['otp_code'] ?? '';
}
if (empty($emailInput)) {
    $emailInput = $_GET['email'] ?? '';
}

$input = [
    'otp_code' => $otpCodeInput,
    'email' => $emailInput
];

if (empty($input['otp_code']) || empty($input['email'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'OTP code and email are required',
        'debug' => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'post' => !empty($_POST) ? 'has data' : 'empty',
            'get' => !empty($_GET) ? 'has data' : 'empty'
        ]
    ]);
    exit;
}

$otpCode = trim($input['otp_code']);
$email = trim($input['email']);

// Validate OTP format
if (!preg_match('/^\d{6}$/', $otpCode)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid OTP format. Please enter a 6-digit code.']);
    exit;
}

// Session check is optional - the database verification is the primary security
// This helps in cases where sessions might not persist properly
if (isset($_SESSION['otp_email']) && $_SESSION['otp_email'] !== $email) {
    // Log warning but don't block - the OTP itself is the verification
    error_log("OTP verification: Session email mismatch. Session: " . ($_SESSION['otp_email'] ?? 'not set') . ", Request: " . $email);
}

// Find and validate OTP
$stmt = $conn->prepare("
    SELECT id, form_data, verified_by, expires_at 
    FROM email_verifications 
    WHERE email = ? AND otp_code = ? AND expires_at > NOW()
    LIMIT 1
");
$stmt->bind_param("ss", $email, $otpCode);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Check if OTP exists but expired
    $stmt2 = $conn->prepare("SELECT id FROM email_verifications WHERE email = ? AND otp_code = ? LIMIT 1");
    $stmt2->bind_param("ss", $email, $otpCode);
    $stmt2->execute();
    $expiredResult = $stmt2->get_result();
    $stmt2->close();
    
    if ($expiredResult->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Verification code has expired. Please request a new code.']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid verification code. Please check and try again.']);
    }
    $stmt->close();
    exit;
}

$verification = $result->fetch_assoc();
$stmt->close();

// Parse form data
$formData = json_decode($verification['form_data'], true);
if (!$formData) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Invalid form data. Please start a new application.']);
    exit;
}

// Check if emails are already registered in the system
$presidentEmail = $formData['president_email'] ?? '';
$adviserEmail = $formData['adviser_email'] ?? '';

// Check president email
if ($presidentEmail) {
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $presidentEmail);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existingUser) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => "The president's email ({$presidentEmail}) is already registered in the system. Please use a different email address."
        ]);
        exit;
    }
}

// Check adviser email
if ($adviserEmail) {
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE email = ?");
    $stmt->bind_param("s", $adviserEmail);
    $stmt->execute();
    $existingUser = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($existingUser) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => "The adviser's email ({$adviserEmail}) is already registered in the system. Please use a different email address."
        ]);
        exit;
    }
}

// Start transaction
$conn->begin_transaction();

try {
    // Check for duplicate applications (pending or approved)
    if ($formData['application_type'] === 'organization') {
        // Check if org_code already exists in organizations table
        $stmt = $conn->prepare("SELECT id FROM organizations WHERE code = ?");
        $stmt->bind_param("s", $formData['org_code']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("An organization with code '{$formData['org_code']}' already exists.");
        }
        $stmt->close();
        
        // Check if there's already an organization for this course
        $stmt = $conn->prepare("SELECT id FROM organizations WHERE course_id = ?");
        $stmt->bind_param("i", $formData['course_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("An organization already exists for this course.");
        }
        $stmt->close();
        
        // Check pending applications
        $stmt = $conn->prepare("SELECT id FROM organization_applications WHERE org_code = ? AND status = 'pending_review'");
        $stmt->bind_param("s", $formData['org_code']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("A pending application with this organization code already exists.");
        }
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT id FROM organization_applications WHERE course_id = ? AND status = 'pending_review'");
        $stmt->bind_param("i", $formData['course_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("A pending application for this course already exists.");
        }
        $stmt->close();
    } else {
        // Check if council already exists for this college
        $stmt = $conn->prepare("SELECT id FROM council WHERE college_id = ?");
        $stmt->bind_param("i", $formData['college_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("A student council already exists for this college.");
        }
        $stmt->close();
        
        // Check pending council applications
        $stmt = $conn->prepare("SELECT id FROM organization_applications WHERE college_id = ? AND application_type = 'council' AND status = 'pending_review'");
        $stmt->bind_param("i", $formData['college_id']);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            throw new Exception("A pending council application for this college already exists.");
        }
        $stmt->close();
    }
    
    // Insert application record
    if ($formData['application_type'] === 'organization') {
        $stmt = $conn->prepare("
            INSERT INTO organization_applications 
            (application_type, org_code, org_name, college_id, course_id, president_name, president_email, adviser_name, adviser_email, verified_by, verified_email, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review')
        ");
        $stmt->bind_param("sssiissssss",
            $formData['application_type'],
            $formData['org_code'],
            $formData['org_name'],
            $formData['college_id'],
            $formData['course_id'],
            $formData['president_name'],
            $formData['president_email'],
            $formData['adviser_name'],
            $formData['adviser_email'],
            $verification['verified_by'],
            $email
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO organization_applications 
            (application_type, college_id, president_name, president_email, adviser_name, adviser_email, verified_by, verified_email, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_review')
        ");
        $stmt->bind_param("sissssss",
            $formData['application_type'],
            $formData['college_id'],
            $formData['president_name'],
            $formData['president_email'],
            $formData['adviser_name'],
            $formData['adviser_email'],
            $verification['verified_by'],
            $email
        );
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to submit application. Please try again.");
    }
    $applicationId = $stmt->insert_id;
    $stmt->close();
    
    // Delete the used verification record
    $stmt = $conn->prepare("DELETE FROM email_verifications WHERE id = ?");
    $stmt->bind_param("i", $verification['id']);
    $stmt->execute();
    $stmt->close();
    
    // Clear session
    unset($_SESSION['otp_verification_id']);
    unset($_SESSION['otp_email']);
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Application submitted successfully! OSAS will review your application.',
        'application_id' => $applicationId
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


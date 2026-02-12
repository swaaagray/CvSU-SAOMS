<?php
/**
 * Send OTP for Organization/Council Application Verification
 * 
 * This API sends a 6-digit OTP to the selected email (president or adviser)
 * for verifying the application before submission to OSAS.
 */

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, but log them
ini_set('log_errors', 1);

// Set headers first to ensure JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request early
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handler to catch fatal errors
function handleFatalError() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal Error in send_application_otp.php: " . print_r($error, true));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error occurred. Please check server logs.',
            'error' => $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
}
register_shutdown_function('handleFatalError');

// Try to load required files with error handling
try {
    if (!file_exists('config/database.php')) {
        throw new Exception('Database configuration file not found');
    }
    require_once 'config/database.php';
    
    if (!file_exists('config/email.php')) {
        throw new Exception('Email configuration file not found');
    }
    require_once 'config/email.php';
    
    if (!file_exists('phpmailer/src/PHPMailer.php')) {
        throw new Exception('PHPMailer library not found');
    }
    require_once 'phpmailer/src/PHPMailer.php';
    require_once 'phpmailer/src/SMTP.php';
    require_once 'phpmailer/src/Exception.php';
    
    // Check if database connection exists
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection not available');
    }
    
} catch (Exception $e) {
    error_log("Error loading required files: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server configuration error. Please contact administrator.',
        'error' => $e->getMessage()
    ]);
    exit;
}

// Use statements must be at top level, after require statements
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
    exit;
}

// Validate required fields
$requiredFields = ['verified_by', 'application_type', 'college_id', 'president_name', 'president_email', 'adviser_name', 'adviser_email'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

// Additional validation for organization type
if ($input['application_type'] === 'organization') {
    if (empty($input['org_code']) || empty($input['org_name']) || empty($input['course_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Organization code, name, and course are required']);
        exit;
    }
}

// Validate email format
$verifiedBy = $input['verified_by'];
$email = $verifiedBy === 'president' ? $input['president_email'] : $input['adviser_email'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@cvsu\.edu\.ph$/', $email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email must be a valid @cvsu.edu.ph address']);
    exit;
}

// Verify database connection is available
if (!isset($conn) || !$conn) {
    error_log("Database connection not available in send_application_otp.php");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection error. Please contact administrator.']);
    exit;
}

// Check for existing pending verification (rate limiting - 30 second cooldown)
// Only check for records created within the last 30 seconds to avoid timezone issues
$stmt = $conn->prepare("
    SELECT id, 
           TIMESTAMPDIFF(SECOND, created_at, NOW()) as seconds_ago
    FROM email_verifications 
    WHERE email = ? 
      AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)
    ORDER BY created_at DESC 
    LIMIT 1
");
if (!$stmt) {
    error_log("Database prepare error: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}
$stmt->bind_param("s", $email);
if (!$stmt->execute()) {
    error_log("Database execute error: " . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $secondsAgo = (int)$row['seconds_ago'];
    $remainingSeconds = 30 - $secondsAgo;
    
    if ($remainingSeconds > 0) {
        http_response_code(429);
        echo json_encode([
            'success' => false, 
            'message' => "Please wait $remainingSeconds second(s) before requesting a new code",
            'cooldown' => $remainingSeconds
        ]);
        $stmt->close();
        exit;
    }
}
$stmt->close();

// Generate 6-digit OTP
$otpCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

// Prepare form data for storage
$formData = [
    'application_type' => $input['application_type'],
    'college_id' => intval($input['college_id']),
    'president_name' => strtoupper(trim($input['president_name'])),
    'president_email' => trim($input['president_email']),
    'adviser_name' => strtoupper(trim($input['adviser_name'])),
    'adviser_email' => trim($input['adviser_email'])
];

// Add organization-specific fields
if ($input['application_type'] === 'organization') {
    $formData['org_code'] = trim($input['org_code']);
    $formData['org_name'] = ucwords(strtolower(trim($input['org_name'])));
    $formData['course_id'] = intval($input['course_id']);
}

// Get organization/council name for email
$orgName = $input['application_type'] === 'council' 
    ? ($input['council_name'] ?? 'Student Council')
    : ($input['org_name'] ?? 'Organization');

// Delete any existing OTP for this email
$stmt = $conn->prepare("DELETE FROM email_verifications WHERE email = ?");
if ($stmt) {
    $stmt->bind_param("s", $email);
    $stmt->execute(); // Don't fail if this doesn't work, just log it
    $stmt->close();
}

// Store OTP with 10-minute expiration (use MySQL's time to avoid timezone issues)
$formDataJson = json_encode($formData);

$stmt = $conn->prepare("INSERT INTO email_verifications (email, otp_code, form_data, verified_by, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
if (!$stmt) {
    error_log("Database prepare error: " . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate verification code. Database error.']);
    exit;
}
$stmt->bind_param("ssss", $email, $otpCode, $formDataJson, $verifiedBy);

if (!$stmt->execute()) {
    error_log("Database insert error: " . $stmt->error);
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate verification code. Please try again.']);
    exit;
}
$verificationId = $stmt->insert_id;
$stmt->close();

// Send OTP email
$mail = null;
try {
    // Validate email configuration constants exist
    if (!defined('SMTP_HOST') || !defined('SMTP_PORT') || !defined('SMTP_USERNAME') || !defined('SMTP_PASSWORD') || !defined('SMTP_FROM_EMAIL') || !defined('SMTP_FROM_NAME')) {
        throw new Exception('Email configuration is incomplete. Please check config/email.php');
    }
    
    $mail = new PHPMailer(true);
    
    // Enable verbose debug output (set to 0 for production, 2 for detailed debugging)
    // $mail->SMTPDebug = 2;
    // $mail->Debugoutput = function($str, $level) {
    //     error_log("PHPMailer Debug ($level): $str");
    // };
    
    // Server settings
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USERNAME;
    $mail->Password = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = SMTP_PORT;
    
    // Additional SMTP options for better reliability
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );
    
    // Timeout settings
    $mail->Timeout = 30;
    $mail->SMTPKeepAlive = false;
    
    // Recipients
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email);
    
    // Content
    $emailTemplate = getApplicationOTPEmailTemplate($otpCode, $input['application_type'], $orgName, $verifiedBy);
    $mail->isHTML(true);
    $mail->Subject = $emailTemplate['subject'];
    $mail->Body = $emailTemplate['body'];
    $mail->AltBody = "Your verification code is: $otpCode. This code will expire in 3 minutes.";
    
    $mail->send();
    
    // Store verification ID in session for security
    $_SESSION['otp_verification_id'] = $verificationId;
    $_SESSION['otp_email'] = $email;
    
    echo json_encode([
        'success' => true, 
        'message' => 'Verification code sent successfully',
        'email' => substr($email, 0, 3) . '***@cvsu.edu.ph', // Masked email for display
        'actual_email' => $email, // Actual email for verification
        'expires_in' => 180 // 3 minutes in seconds (matching the updated expiry)
    ]);
    
} catch (Exception $e) {
    // Delete the verification record if email fails
    if (isset($verificationId)) {
        $stmt = $conn->prepare("DELETE FROM email_verifications WHERE id = ?");
        $stmt->bind_param("i", $verificationId);
        $stmt->execute();
        $stmt->close();
    }
    
    // Get detailed error information
    $errorMessage = $e->getMessage();
    $phpmailerError = '';
    
    if ($mail && method_exists($mail, 'ErrorInfo')) {
        $phpmailerError = $mail->ErrorInfo;
    }
    
    // Log comprehensive error details
    $errorDetails = [
        'Exception Message' => $errorMessage,
        'PHPMailer Error' => $phpmailerError,
        'Email' => $email,
        'SMTP Host' => defined('SMTP_HOST') ? SMTP_HOST : 'Not defined',
        'SMTP Port' => defined('SMTP_PORT') ? SMTP_PORT : 'Not defined',
        'SMTP Username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : 'Not defined'
    ];
    
    error_log("OTP Email Error Details: " . json_encode($errorDetails));
    
    // Determine user-friendly error message
    $userMessage = 'Failed to send verification email. Please try again.';
    
    // Provide more specific error messages for common issues
    if (stripos($errorMessage, 'SMTP connect()') !== false || stripos($phpmailerError, 'SMTP connect()') !== false) {
        $userMessage = 'Unable to connect to email server. Please check your internet connection and try again.';
    } elseif (stripos($errorMessage, 'authentication') !== false || stripos($phpmailerError, 'authentication') !== false) {
        $userMessage = 'Email authentication failed. Please contact the system administrator.';
    } elseif (stripos($errorMessage, 'timeout') !== false || stripos($phpmailerError, 'timeout') !== false) {
        $userMessage = 'Email server connection timed out. Please try again in a moment.';
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $userMessage,
        // Include error details in development mode (remove in production)
        'error' => (defined('DEBUG_MODE') && DEBUG_MODE) ? $errorMessage : null
    ]);
}
?>


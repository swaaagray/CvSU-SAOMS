<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'includes/email_helper_advanced.php';

requireLogin();

$role = getUserRole();
$userId = getCurrentUserId();

// Only allow org_adviser, org_president, council_president, council_adviser, mis_coordinator, and osas
if (!in_array($role, ['org_adviser', 'org_president', 'council_president', 'council_adviser', 'mis_coordinator', 'osas'])) {
    header('Location: unauthorized.php');
    exit();
}

// Get success/error messages from session and clear them
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trim all password inputs to remove any whitespace
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $_SESSION['error'] = 'Please fill in all fields.';
        header('Location: change_password.php');
        exit();
    } elseif (strlen($new_password) < 8) {
        $_SESSION['error'] = 'New password must be at least 8 characters long.';
        header('Location: change_password.php');
        exit();
    } elseif ($new_password !== $confirm_password) {
        $_SESSION['error'] = 'New password and confirmation do not match.';
        header('Location: change_password.php');
        exit();
    }
    
    // Verify current password and get user details for email notification
    $stmt = $conn->prepare("SELECT password, email, username FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        // Check if user has a password set (not NULL and not empty)
        $stored_password = $user['password'] ?? null;
        
        if (empty($stored_password) || is_null($stored_password)) {
            $_SESSION['error'] = 'No password is set for this account. Please contact administrator.';
            error_log("Password change attempted for user ID: " . $userId . " but no password is set in database");
        } elseif (password_verify($current_password, $stored_password)) {
            // Check if new password is the same as current password
            if (password_verify($new_password, $stored_password)) {
                $_SESSION['error'] = 'New password cannot be the same as your current password.';
                error_log("Password change attempted for user ID: " . $userId . " but new password matches current password");
            } else {
                // Update password only if verification succeeds and new password is different
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $userId);
                
                if ($update_stmt->execute()) {
                    $_SESSION['success'] = 'Password successfully changed!';
                    error_log("Password changed successfully for user ID: " . $userId);
                    
                    // Get user email for notification (using the same query data)
                    $user_email = $user['email'] ?? '';
                    $user_username = $user['username'] ?? '';
                    
                    // Send email notification using advanced PHPMailer
                    if (!empty($user_email)) {
                        $email_sent = sendPasswordChangeNotificationAdvanced($user_email, $user_username, $role);
                        if ($email_sent) {
                            $_SESSION['success'] .= ' A confirmation email has been sent to your email address.';
                            error_log("Password change notification email sent to: " . $user_email);
                        } else {
                            error_log("Failed to send password change notification email to: " . $user_email);
                            // Don't show error to user since password change was successful
                        }
                    }
                } else {
                    $_SESSION['error'] = 'Error updating password. Please try again.';
                    error_log("Password update failed for user ID: " . $userId . " - " . $update_stmt->error);
                }
                $update_stmt->close();
            }
        } else {
            $_SESSION['error'] = 'Current password is incorrect.';
            error_log("Password verification failed for user ID: " . $userId . " - stored hash length: " . strlen($stored_password));
        }
    } else {
        $_SESSION['error'] = 'User not found.';
        error_log("User not found for password change - user ID: " . $userId);
    }
    $stmt->close();
    
    // Redirect to prevent form resubmission
    header('Location: change_password.php');
    exit();
}

// Get user info for display
$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Don't close connection here - navbar.php needs it
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - CvSU Academic Organizations</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        /* Monochrome Theme Variables */
        :root {
            --primary-dark: #1a1a1a;
            --secondary-dark: #2d2d2d;
            --accent-gray: #4a4a4a;
            --light-gray: #6a6a6a;
            --lighter-gray: #8a8a8a;
            --background-gray: #f5f5f5;
            --white: #ffffff;
            --shadow: rgba(0, 0, 0, 0.1);
            --shadow-heavy: rgba(0, 0, 0, 0.15);
        }

        body {
            background: linear-gradient(135deg, var(--background-gray) 0%, #e9e9e9 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .password-change-container {
            min-height: calc(100vh - 56px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin-left: 250px;
            margin-top: 56px;
            transition: margin-left 0.3s;
        }
        
        .password-change-container.expanded {
            margin-left: 0;
        }
        
        .password-card {
            max-width: 500px;
            width: 100%;
            background: var(--white);
            border-radius: 15px;
            box-shadow: 0 8px 32px var(--shadow);
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .password-card:hover {
            box-shadow: 0 12px 40px var(--shadow-heavy);
            transform: translateY(-2px);
        }
        
        .card-body {
            padding: 2rem 1.5rem;
        }
        
        .form-floating > .form-control {
            border-radius: 8px;
            border: 2px solid #e0e0e0;
            min-height: 48px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            padding: 0.75rem;
            width: 100%;
        }
        
        .form-floating > label {
            color: var(--accent-gray);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .form-floating > .form-control:focus {
            border-color: var(--primary-dark);
            box-shadow: 0 0 0 0.2rem rgba(26, 26, 26, 0.15);
        }
        
        .form-floating > .form-control:focus ~ label {
            color: var(--primary-dark);
        }
        
        .btn-primary {
            background: var(--primary-dark) !important;
            color: var(--white) !important;
            border: 2px solid var(--primary-dark) !important;
            padding: 12px 20px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
            width: 85%;
            margin: 0 auto;
        }
        
        .btn-primary:hover {
            background: transparent !important;
            color: var(--primary-dark) !important;
            border-color: var(--primary-dark) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        
        .btn-primary:focus, .btn-primary:active {
            background: var(--primary-dark) !important;
            color: var(--white) !important;
            border-color: var(--primary-dark) !important;
            box-shadow: 0 0 0 0.2rem rgba(26, 26, 26, 0.25) !important;
        }
        
        .btn-secondary {
            background: transparent;
            color: var(--accent-gray);
            border: 2px solid var(--accent-gray);
            padding: 10px 16px;
            font-weight: 500;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }
        
        .btn-secondary:hover {
            background: var(--accent-gray);
            color: var(--white);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        
        .password-requirements {
            background: rgba(26, 26, 26, 0.03);
            border: 1px solid rgba(26, 26, 26, 0.1);
            border-left: 4px solid var(--primary-dark);
            padding: 18px;
            margin: 20px auto;
            border-radius: 8px;
            width: 85%;
        }
        
        .password-requirements h6 {
            color: var(--primary-dark);
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        
        .password-requirements small {
            color: var(--accent-gray);
            line-height: 1.6;
            font-size: 0.85rem;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            z-index: 10;
            color: var(--light-gray);
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary-dark);
        }
        
        .position-relative {
            position: relative;
        }
        
        .alert-success {
            background-color: rgba(26, 26, 26, 0.05);
            border: 1px solid rgba(26, 26, 26, 0.2);
            color: var(--primary-dark);
            border-radius: 8px;
            padding: 15px 40px 15px 15px;
            width: 85%;
            margin: 0 auto 20px auto;
            text-align: center;
            position: relative;
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #721c24;
            border-radius: 8px;
            padding: 15px 40px 15px 15px;
            width: 85%;
            margin: 0 auto 20px auto;
            text-align: center;
            position: relative;
        }
        
        .alert-success .btn-close,
        .alert-danger .btn-close {
            position: absolute;
            right: 12px;
            top: 12px;
            padding: 0.5rem;
            margin: 0;
        }
        
        .password-card {
            max-width: 480px;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(2px);
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .loading-spinner-container {
            background: var(--white);
            padding: 2rem 3rem;
            border-radius: 15px;
            box-shadow: 0 8px 32px var(--shadow-heavy);
            text-align: center;
            min-width: 250px;
        }
        
        .loading-spinner {
            border: 4px solid rgba(26, 26, 26, 0.1);
            border-top: 4px solid var(--primary-dark);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            color: var(--primary-dark);
            font-weight: 500;
            font-size: 1rem;
            margin: 0;
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .btn-primary .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.15em;
            margin-right: 0.5rem;
            border-color: rgba(255, 255, 255, 0.3);
            border-top-color: var(--white);
            vertical-align: middle;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .password-change-container {
                min-height: calc(100vh - 56px);
                margin-left: 0;
                margin-top: 56px;
                padding: 10px;
            }
            .password-card {
                margin: 0;
                max-width: 100%;
            }
            .card-body {
                padding: 1.2rem;
            }
            .loading-spinner-container {
                padding: 1.5rem 2rem;
                min-width: 200px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="password-change-container">
        <div class="password-card">
            <div class="card-body">
                <!-- Success/Error Messages -->
                <div class="mt-3 d-flex justify-content-center">
                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show position-relative" role="alert" style="width: 85%;">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show position-relative" role="alert" style="width: 85%;">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Password Requirements -->
                <div class="password-requirements">
                    <h6 class="mb-2"><i class="bi bi-info-circle me-2"></i>Password Requirements</h6>
                    <small class="text-muted">
                        • Minimum 8 characters
                    </small>
                </div>

                <!-- Password Change Form -->
                <form method="POST" action="" id="passwordForm">
                    <div class="mb-3 d-flex justify-content-center">
                        <div class="form-floating position-relative" style="width: 85%;">
                            <input type="password" class="form-control" id="current_password" name="current_password" required placeholder=" ">
                            <label for="current_password">Current Password</label>
                            <i class="bi bi-eye password-toggle" onclick="togglePassword('current_password')" id="toggle-current"></i>
                        </div>
                    </div>

                    <div class="mb-3 d-flex justify-content-center">
                        <div class="form-floating position-relative" style="width: 85%;">
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" placeholder=" ">
                            <label for="new_password">New Password</label>
                            <i class="bi bi-eye password-toggle" onclick="togglePassword('new_password')" id="toggle-new"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center">
                        <div id="password-strength-message" class="text-danger small mt-2" style="display: none; width: 85%;">
                            <i class="bi bi-exclamation-circle me-1"></i>Password must be at least 8 characters long
                        </div>
                    </div>

                    <div class="mb-3 d-flex justify-content-center">
                        <div class="form-floating position-relative" style="width: 85%;">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" placeholder=" ">
                            <label for="confirm_password">Confirm New Password</label>
                            <i class="bi bi-eye password-toggle" onclick="togglePassword('confirm_password')" id="toggle-confirm"></i>
                        </div>
                    </div>
                    <div class="d-flex justify-content-center">
                        <div id="password-match-message" class="text-danger small mt-2" style="display: none; width: 85%;">
                            <i class="bi bi-exclamation-circle me-1"></i>Passwords do not match
                        </div>
                    </div>

                    <div class="d-grid gap-3 mt-2 mb-3">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <span id="submitBtnText">
                                <i class="bi bi-shield-check me-2"></i>Change Password
                            </span>
                            <span id="submitBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;">
                                <span class="visually-hidden">Loading...</span>
                            </span>
                        </button>
                        <div class="d-flex justify-content-center gap-2 mb-2">
                            <a href="profile.php" class="btn btn-secondary">
                                <i class="bi bi-person-circle me-1"></i>Profile
                            </a>
                            <a href="dashboard.php" class="btn btn-secondary">
                                <i class="bi bi-speedometer2 me-1"></i>Dashboard
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner-container">
            <div class="loading-spinner"></div>
            <p class="loading-text">Changing password...</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password visibility toggle
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggleIcon = document.getElementById('toggle-' + fieldId.split('_')[0]);
            
            if (field.type === 'password') {
                field.type = 'text';
                toggleIcon.classList.remove('bi-eye');
                toggleIcon.classList.add('bi-eye-slash');
            } else {
                field.type = 'password';
                toggleIcon.classList.remove('bi-eye-slash');
                toggleIcon.classList.add('bi-eye');
            }
        }

        // Password validation
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            const matchMessage = document.getElementById('password-match-message');
            const strengthMessage = document.getElementById('password-strength-message');
            const submitBtn = document.getElementById('submitBtn');
            const submitBtnText = document.getElementById('submitBtnText');
            const submitBtnSpinner = document.getElementById('submitBtnSpinner');
            const loadingOverlay = document.getElementById('loadingOverlay');
            const passwordForm = document.getElementById('passwordForm');

            function checkPasswordStrength(password) {
                const minLength = password.length >= 8;
                
                return {
                    valid: minLength,
                    minLength: minLength
                };
            }

            function validatePassword() {
                const password = newPassword.value;
                const confirmPass = confirmPassword.value;
                let isValid = true;

                // Check password strength
                if (password) {
                    const strength = checkPasswordStrength(password);
                    if (!strength.valid) {
                        strengthMessage.style.display = 'block';
                        newPassword.classList.add('is-invalid');
                        isValid = false;
                    } else {
                        strengthMessage.style.display = 'none';
                        newPassword.classList.remove('is-invalid');
                    }
                } else {
                    strengthMessage.style.display = 'none';
                    newPassword.classList.remove('is-invalid');
                }

                // Check password match
                if (confirmPass && password !== confirmPass) {
                    matchMessage.style.display = 'block';
                    confirmPassword.classList.add('is-invalid');
                    isValid = false;
                } else {
                    matchMessage.style.display = 'none';
                    confirmPassword.classList.remove('is-invalid');
                }

                submitBtn.disabled = !isValid;
            }

            newPassword.addEventListener('input', validatePassword);
            confirmPassword.addEventListener('input', validatePassword);

            // Show loading indicator on form submit
            let isSubmitting = false;
            passwordForm.addEventListener('submit', function(e) {
                // Only show loading if form validation passes and not already submitting
                if (!submitBtn.disabled && !isSubmitting) {
                    isSubmitting = true;
                    
                    // Show loading overlay
                    loadingOverlay.classList.add('show');
                    
                    // Update button state
                    submitBtn.disabled = true;
                    submitBtnText.style.display = 'none';
                    submitBtnSpinner.style.display = 'inline-block';
                } else if (isSubmitting) {
                    // Prevent double submission
                    e.preventDefault();
                    return false;
                }
            });

            // Auto-hide success message after 5 seconds
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.transition = 'opacity 0.5s';
                    successAlert.style.opacity = '0';
                    setTimeout(() => {
                        successAlert.remove();
                    }, 500);
                }, 5000);
            }

            // Sidebar toggle is handled by navbar.php
            // The navbar script will handle the sidebar toggle for all pages
        });
    </script>
</body>
</html>
<?php
// Close database connection after all output
$conn->close();
?>
<?php
require_once 'config/database.php';
require_once 'config/oauth.php';
require_once 'includes/session.php';
require_once 'includes/academic_year_archiver.php';
require_once 'includes/hybrid_status_checker.php';
require_once 'includes/semester_status_updater.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

$error = '';

// Function to check if there's an active academic year
function hasActiveAcademicYear($conn) {
    // Check if database connection exists
    if (!$conn) {
        error_log('hasActiveAcademicYear: Database connection is null');
        return false;
    }
    
    // Check for connection errors (mysqli objects have connect_error property)
    if (isset($conn->connect_error) && $conn->connect_error) {
        error_log('hasActiveAcademicYear: Database connection error - ' . $conn->connect_error);
        return false;
    }
    
    // Check if table exists (in case of fresh setup)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'academic_terms'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        error_log('hasActiveAcademicYear: academic_terms table does not exist');
        return false;
    }
    
    $stmt = $conn->prepare("SELECT id FROM academic_terms WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    
    if (!$stmt) {
        error_log('hasActiveAcademicYear: Prepare failed - ' . $conn->error);
        return false;
    }
    
    if (!$stmt->execute()) {
        error_log('hasActiveAcademicYear: Execute failed - ' . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $hasActive = $result->num_rows > 0;
    $stmt->close();
    
    return $hasActive;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            // Check if user has a password set (not NULL and not empty)
            $stored_password = $user['password'] ?? null;
            
            if (!empty($stored_password) && !is_null($stored_password) && password_verify($password, $stored_password)) {
                // Check if there's an active academic year before allowing login
                // OSAS users are never restricted by academic year status
                // Only presidents, advisers, and MIS users are blocked when no academic year is active
                if (!hasActiveAcademicYear($conn) && $user['role'] !== 'osas') {
                    $_SESSION['error'] = 'There is currently no active academic year set in the system. Please check back on another day once the academic year has been activated.';
                    header('Location: login.php');
                    exit();
                }
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['osas_approved'] = $user['osas_approved'] ?? true;
                
                // Get organization ID if user is org_adviser or org_president
                if (in_array($user['role'], ['org_adviser', 'org_president'])) {
                    $stmt = $conn->prepare("SELECT id FROM organizations WHERE " . 
                        ($user['role'] === 'org_adviser' ? 'adviser_id' : 'president_id') . " = ?");
                    $stmt->bind_param("i", $user['id']);
                    $stmt->execute();
                    $org = $stmt->get_result()->fetch_assoc();
                    if ($org) {
                        $_SESSION['organization_id'] = $org['id'];
                    }
                }
                
                // Run simplified semester status update on every successful login
                try {
                    $semesterResults = updateSemesterStatusesOnLogin();
                    if ($semesterResults['semesters_archived'] > 0 || $semesterResults['semesters_activated'] > 0) {
                        error_log("Login semester update: {$semesterResults['semesters_archived']} archived, {$semesterResults['semesters_activated']} activated");
                    }
                    if (!empty($semesterResults['errors'])) {
                        error_log("Semester update errors: " . implode(', ', $semesterResults['errors']));
                    }
                } catch (Exception $e) {
                    error_log('Post-login semester status update failed: ' . $e->getMessage());
                }
                
                // Run comprehensive hybrid status check on every successful login
                try {
                    $statusCheckResults = runHybridStatusCheck();
                    if ($statusCheckResults['academic_years_archived'] > 0 || $statusCheckResults['semesters_archived'] > 0) {
                        error_log("Login status check: {$statusCheckResults['academic_years_archived']} years archived, {$statusCheckResults['semesters_archived']} semesters archived");
                    }
                } catch (Exception $e) {
                    error_log('Post-login hybrid status check failed: ' . $e->getMessage());
                }
                
                // Enforce immediate student_data and notification cleanup on login
                try {
                    if (function_exists('enforceStudentDataCleanup')) {
                        enforceStudentDataCleanup();
                    }
                } catch (Exception $e) {
                    error_log('Post-login student_data cleanup failed: ' . $e->getMessage());
                }
                try {
                    if (function_exists('enforceNotificationCleanup')) {
                        enforceNotificationCleanup();
                    }
                } catch (Exception $e) {
                    error_log('Post-login notification cleanup failed: ' . $e->getMessage());
                }
                try {
                    if (function_exists('enforceEventApprovalsCleanup')) {
                        enforceEventApprovalsCleanup();
                    }
                } catch (Exception $e) {
                    error_log('Post-login event_approvals cleanup failed: ' . $e->getMessage());
                }
                
                // Also run the existing president-specific check
                try {
                    runArchiveCheckOnPresidentLogin();
                } catch (Exception $e) {
                    error_log('Post-login president archive check failed: ' . $e->getMessage());
                }
                
                // Clean up expired notifications for the user on login
                try {
                    require_once 'includes/notification_helper.php';
                    $cleanup_result = cleanupUserExpiredNotifications($user['id']);
                    if ($cleanup_result['success'] && $cleanup_result['deleted_count'] > 0) {
                        error_log("Login cleanup for user {$user['id']}: Deleted {$cleanup_result['deleted_count']} expired notifications");
                    }
                } catch (Exception $e) {
                    error_log('Post-login notification cleanup failed: ' . $e->getMessage());
                }
                
                // Redirect to new term info collection if names/emails were cleared on rollover
                try {
                    $needsNewTermUpdate = false;
                    $role = $user['role'];
                    $userId = (int)$user['id'];

                    if (in_array($role, ['org_adviser', 'org_president'])) {
                        $col = $role === 'org_adviser' ? 'adviser' : 'president';
                        $stmtCheck = $conn->prepare("SELECT id, {$col}_name FROM organizations WHERE {$col}_id = ? LIMIT 1");
                        if ($stmtCheck) {
                            $stmtCheck->bind_param("i", $userId);
                            $stmtCheck->execute();
                            $row = $stmtCheck->get_result()->fetch_assoc();
                            if ($row && (is_null($row[$col . '_name']) || $row[$col . '_name'] === '')) {
                                $needsNewTermUpdate = true;
                            }
                        }
                    } elseif (in_array($role, ['council_adviser', 'council_president'])) {
                        $col = $role === 'council_adviser' ? 'adviser' : 'president';
                        $stmtCheck = $conn->prepare("SELECT id, {$col}_name FROM council WHERE {$col}_id = ? LIMIT 1");
                        if ($stmtCheck) {
                            $stmtCheck->bind_param("i", $userId);
                            $stmtCheck->execute();
                            $row = $stmtCheck->get_result()->fetch_assoc();
                            if ($row && (is_null($row[$col . '_name']) || $row[$col . '_name'] === '')) {
                                $needsNewTermUpdate = true;
                            }
                        }
                    } elseif ($role === 'mis_coordinator') {
                        $stmtCheck = $conn->prepare("SELECT id, coordinator_name FROM mis_coordinators WHERE user_id = ? LIMIT 1");
                        if ($stmtCheck) {
                            $stmtCheck->bind_param("i", $userId);
                            $stmtCheck->execute();
                            $row = $stmtCheck->get_result()->fetch_assoc();
                            if ($row && (is_null($row['coordinator_name']) || $row['coordinator_name'] === '')) {
                                $needsNewTermUpdate = true;
                            }
                        }
                    }

                    if ($needsNewTermUpdate) {
                        header('Location: newTerm.php');
                        exit();
                    }
                } catch (Exception $e) {
                    error_log('New term redirect check failed: ' . $e->getMessage());
                }

                header('Location: dashboard.php');
                exit();
            }
        }
        $_SESSION['error'] = 'Invalid username or password';
        header('Location: login.php');
        exit();
    } else {
        $_SESSION['error'] = 'Please fill in all fields';
        header('Location: login.php');
        exit();
    }
}

// Get error message from session and clear it
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

// Get OAuth authentication error messages from session and clear them
$oauth_error = $_SESSION['oauth_error'] ?? '';
unset($_SESSION['oauth_error']);



// Generate Google OAuth URL
$googleAuthUrl = getGoogleOAuthURL();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-container {
            max-width: 500px;
            width: 100%;
            padding: 2rem;
        }
        .card {
            background: #ffffff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        .card-body {
            padding: 3rem;
        }
        .brand-logo {
            width: 90px;
            height: 90px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            border-radius: 12px;
            padding: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        .brand-logo img {
            max-width: 100%;
            max-height: 100%;
        }
        .form-control {
            background: #ffffff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 0.75rem 1rem;
            color: #212529;
        }
        .form-control:focus {
            background: #ffffff;
            border-color: #212529;
            box-shadow: 0 0 0 0.2rem rgba(33, 37, 41, 0.1);
            color: #212529;
        }
        .form-control::placeholder {
            color: #6c757d;
        }
        .btn-primary {
            background: #212529;
            border: none;
            padding: 0.75rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: #343a40;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(33, 37, 41, 0.15);
        }
        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-right: none;
            color: #6c757d;
        }
        .password-toggle {
            cursor: pointer;
            color: #6c757d;
            transition: color 0.3s ease;
        }
        .password-toggle:hover {
            color: #212529;
        }
        .form-label {
            color: #495057;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        h2 {
            color: #212529;
            font-weight: 600;
            font-size: 1.5rem;
        }
        p {
            color: #6c757d;
        }
        .alert-danger {
            background: rgba(220, 53, 69, 0.05);
            border: 1px solid rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border: 1px solid #f0ad4e;
            color: #856404;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(240, 173, 78, 0.1);
        }
        .alert-warning .btn-close {
            filter: invert(1);
        }
        .alert-warning i {
            color: #f39c12;
        }

        .btn-cvsu {
            background: white;
            color: #333;
            border: 1px solid #dadce0;
            padding: 0.75rem 1rem;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .btn-cvsu:hover {
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            border-color: #c6c8ca;
        }
        .btn-cvsu:focus {
            background: #f8f9fa;
            color: #333;
            box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.3);
            border-color: #4285f4;
        }
        .btn-google {
            background: white;
            color: #333;
            border: 1px solid #dadce0;
            padding: 0.75rem 1rem;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 0.5rem;
        }
        .btn-google:hover {
            background: #f8f9fa;
            color: #333;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            border-color: #c6c8ca;
        }
        .btn-google:focus {
            background: #f8f9fa;
            color: #333;
            box-shadow: 0 0 0 2px rgba(66, 133, 244, 0.3);
            border-color: #4285f4;
        }
        .google-logo {
            width: 18px;
            height: 18px;
            margin-right: 12px;
        }

    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="login-container">
                <div class="card">
                    <div class="card-body">
                        <div class="brand-logo">
                            <img src="assets/img/SAOMS-LOGO-WHITE.png" alt="CvSU SAOMS Logo">
                        </div>
                        <h2 class="text-center mb-3">SAOMS User Login</h2>
                        <p class="text-center text-muted mb-4">Enter your credentials to continue</p>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <div class="d-flex align-items-start">
                                    <div class="me-3">
                                        <i class="fas fa-info-circle" style="font-size: 1.2rem;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="alert-heading mb-2">Authentication Notice</h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if ($oauth_error): ?>
                            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                <div class="d-flex align-items-start">
                                    <div class="me-3">
                                        <i class="fas fa-info-circle" style="font-size: 1.2rem;"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="alert-heading mb-2">Authentication Notice</h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($oauth_error); ?></p>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>



                        <form method="POST" action="">
                            <div class="mb-4">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="username" name="username" placeholder="Enter admin username" required>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter admin password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword()">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-sign-in-alt me-2"></i>Log in to Dashboard
                            </button>
                        </form>

                        <div class="text-center my-3">
                            <span class="text-muted">or</span>
                        </div>

                        <a href="<?php echo htmlspecialchars($googleAuthUrl); ?>" class="btn btn-google w-100 mb-3">
                            <svg class="google-logo" viewBox="0 0 24 24">
                                <path fill="#4285f4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="#34a853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="#fbbc05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                <path fill="#ea4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                            </svg>
                            Continue with CvSU Account
                        </a>

                        <div class="text-center">
                            <a href="index.php" class="text-decoration-none text-dark">
                                <i class="fas fa-arrow-left me-1"></i>
                                Back to Home
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = document.querySelector('.password-toggle i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html> 
<?php
require_once 'config/database.php';
require_once 'config/oauth.php';
require_once 'includes/session.php';
require_once 'includes/hybrid_status_checker.php';
require_once 'includes/semester_status_updater.php';

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

// Handle OAuth callback
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Exchange code for access token
    $tokenData = getGoogleAccessToken($code);
    
    if ($tokenData && isset($tokenData['access_token'])) {
        // Get user info from Google
        $userInfo = getGoogleUserInfo($tokenData['access_token']);
        
        if ($userInfo) {
            $googleId = $userInfo['id'];
            $email = $userInfo['email'];
            $name = $userInfo['name'];
            $picture = $userInfo['picture'] ?? '';
            $emailVerified = $userInfo['verified_email'] ?? false;
            
            // Check if this is a CvSU email
            $isCvSUEmail = isCvSUEmail($email);
            
            // For CvSU emails: Only OSAS-registered accounts allowed (no additional approval needed)
            // For regular Gmail: Standard check
            if ($isCvSUEmail) {
                // CvSU accounts just need to exist (registered by OSAS)
                $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
            } else {
                // Regular Gmail accounts just need to exist
                $stmt = $conn->prepare("SELECT * FROM users WHERE google_id = ? OR email = ?");
            }
            $stmt->bind_param("ss", $googleId, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            if ($user) {
                // Check if there's an active academic year before allowing login
                // OSAS users are never restricted by academic year status
                // Only presidents, advisers, and MIS users are blocked when no academic year is active
                if (!hasActiveAcademicYear($conn) && $user['role'] !== 'osas') {
                    $_SESSION['oauth_error'] = 'There is currently no active academic year set in the system. Please check back on another day once the academic year has been activated.';
                    header('Location: login.php');
                    exit();
                }
                
                // User exists and is approved (if CvSU email), update their info
                if (empty($user['google_id'])) {
                    $provider = $isCvSUEmail ? 'cvsu' : 'google';
                    
                    $updateStmt = $conn->prepare("UPDATE users SET google_id = ?, oauth_provider = ? WHERE id = ?");
                    $updateStmt->bind_param("ssi", $googleId, $provider, $user['id']);
                    $updateStmt->execute();
                }
                
                // Log user in
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['oauth_provider'] = $isCvSUEmail ? 'cvsu' : 'google';
                $_SESSION['osas_approved'] = $isCvSUEmail ? true : ($user['osas_approved'] ?? true);
                
                // Get organization ID if user is org_adviser or org_president
                if (in_array($user['role'], ['org_adviser', 'org_president'])) {
                    $orgStmt = $conn->prepare("SELECT id FROM organizations WHERE " . 
                        ($user['role'] === 'org_adviser' ? 'adviser_id' : 'president_id') . " = ?");
                    $orgStmt->bind_param("i", $user['id']);
                    $orgStmt->execute();
                    $org = $orgStmt->get_result()->fetch_assoc();
                    if ($org) {
                        $_SESSION['organization_id'] = $org['id'];
                    }
                }
                
                // Run simplified semester status update on OAuth login
                try {
                    $semesterResults = updateSemesterStatusesOnLogin();
                    if ($semesterResults['semesters_archived'] > 0 || $semesterResults['semesters_activated'] > 0) {
                        error_log("OAuth login semester update: {$semesterResults['semesters_archived']} archived, {$semesterResults['semesters_activated']} activated");
                    }
                    if (!empty($semesterResults['errors'])) {
                        error_log("OAuth semester update errors: " . implode(', ', $semesterResults['errors']));
                    }
                } catch (Exception $e) {
                    error_log('Post-OAuth login semester status update failed: ' . $e->getMessage());
                }
                
                // Run comprehensive hybrid status check on OAuth login
                try {
                    $statusCheckResults = runHybridStatusCheck();
                    if ($statusCheckResults['academic_years_archived'] > 0 || $statusCheckResults['semesters_archived'] > 0) {
                        error_log("OAuth login status check: {$statusCheckResults['academic_years_archived']} years archived, {$statusCheckResults['semesters_archived']} semesters archived");
                    }
                } catch (Exception $e) {
                    error_log('Post-OAuth login hybrid status check failed: ' . $e->getMessage());
                }
                
                // Clean up expired notifications for the user on OAuth login
                try {
                    require_once 'includes/notification_helper.php';
                    $cleanup_result = cleanupUserExpiredNotifications($user['id']);
                    if ($cleanup_result['success'] && $cleanup_result['deleted_count'] > 0) {
                        error_log("OAuth login cleanup for user {$user['id']}: Deleted {$cleanup_result['deleted_count']} expired notifications");
                    }
                } catch (Exception $e) {
                    error_log('Post-OAuth login notification cleanup failed: ' . $e->getMessage());
                }
                
                // Redirect to new term info collection if names were cleared on rollover
                try {
                    $needsNewTermUpdate = false;
                    $role = $user['role'];
                    $userId = (int)$user['id'];

                    if (in_array($role, ['org_adviser', 'org_president'])) {
                        $col = $role === 'org_adviser' ? 'adviser' : 'president';
                        $orgStmt = $conn->prepare("SELECT id, {$col}_name FROM organizations WHERE {$col}_id = ? LIMIT 1");
                        if ($orgStmt) {
                            $orgStmt->bind_param("i", $userId);
                            $orgStmt->execute();
                            $row = $orgStmt->get_result()->fetch_assoc();
                            if ($row && (is_null($row[$col . '_name']) || $row[$col . '_name'] === '')) {
                                $needsNewTermUpdate = true;
                            }
                        }
                    } elseif (in_array($role, ['council_adviser', 'council_president'])) {
                        $col = $role === 'council_adviser' ? 'adviser' : 'president';
                        $cStmt = $conn->prepare("SELECT id, {$col}_name FROM council WHERE {$col}_id = ? LIMIT 1");
                        if ($cStmt) {
                            $cStmt->bind_param("i", $userId);
                            $cStmt->execute();
                            $row = $cStmt->get_result()->fetch_assoc();
                            if ($row && (is_null($row[$col . '_name']) || $row[$col . '_name'] === '')) {
                                $needsNewTermUpdate = true;
                            }
                        }
                    } elseif ($role === 'mis_coordinator') {
                        $mStmt = $conn->prepare("SELECT id, coordinator_name FROM mis_coordinators WHERE user_id = ? LIMIT 1");
                        if ($mStmt) {
                            $mStmt->bind_param("i", $userId);
                            $mStmt->execute();
                            $row = $mStmt->get_result()->fetch_assoc();
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
                    error_log('New term redirect check (OAuth) failed: ' . $e->getMessage());
                }

                header('Location: dashboard.php');
                exit();
            } else {
                // Handle different error cases based on email domain
                if ($isCvSUEmail) {
                    $_SESSION['oauth_error'] = 'Access Restricted: Your CvSU account is not registered in our Academic Organization Management System. Only OSAS-registered accounts are authorized to access this platform. Please contact OSAS to request account registration.';
                } else {
                    // Regular Gmail error
                    $_SESSION['oauth_error'] = 'Your Gmail account is not registered in our system. Please contact the administrator to create an account for you.';
                }
                header('Location: login.php');
                exit();
            }
        } else {
            $_SESSION['oauth_error'] = 'Failed to get user information from Google.';
            header('Location: login.php');
            exit();
        }
    } else {
        $_SESSION['oauth_error'] = 'Failed to authenticate with Google.';
        header('Location: login.php');
        exit();
    }
} else if (isset($_GET['error'])) {
    $_SESSION['oauth_error'] = 'Authentication was cancelled or failed.';
    header('Location: login.php');
    exit();
} else {
    // No code or error parameter
    header('Location: login.php');
    exit();
}
?>
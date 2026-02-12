<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireLogin();

$userId = getCurrentUserId();
$role = getUserRole();

// Get error messages from session and clear them
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error']);
unset($_SESSION['success']);

// For sticky form values and per-field errors - restore from session if available
$values = $_SESSION['form_values'] ?? [
    'full_name' => '',
    'username' => $_SESSION['username'] ?? '',
    'email' => $_SESSION['email'] ?? ''
];
$fieldErrors = $_SESSION['field_errors'] ?? [
    'full_name' => '',
    'username' => '',
    'email' => '',
    'password' => '',
    'confirm_password' => ''
];
unset($_SESSION['form_values']);
unset($_SESSION['field_errors']);

function isCvSUEmailValid($email) {
    return (bool)preg_match('/^[^@\s]+@cvsu\.edu\.ph$/i', $email);
}

// Detect related record and current cleared-name state
$needsUpdate = false;
$targetTable = null;
$targetId = null;
$targetNameColumn = null;

if (in_array($role, ['org_adviser', 'org_president'])) {
    $col = $role === 'org_adviser' ? 'adviser' : 'president';
    $stmt = $conn->prepare("SELECT id, {$col}_name FROM organizations WHERE {$col}_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $targetTable = 'organizations';
            $targetId = (int)$row['id'];
            $targetNameColumn = $col . '_name';
            $needsUpdate = (is_null($row[$targetNameColumn]) || $row[$targetNameColumn] === '');
        }
    }
} elseif (in_array($role, ['council_adviser', 'council_president'])) {
    $col = $role === 'council_adviser' ? 'adviser' : 'president';
    $stmt = $conn->prepare("SELECT id, {$col}_name FROM council WHERE {$col}_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $targetTable = 'council';
            $targetId = (int)$row['id'];
            $targetNameColumn = $col . '_name';
            $needsUpdate = (is_null($row[$targetNameColumn]) || $row[$targetNameColumn] === '');
        }
    }
} elseif ($role === 'mis_coordinator') {
    $stmt = $conn->prepare("SELECT id, coordinator_name FROM mis_coordinators WHERE user_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $targetTable = 'mis_coordinators';
            $targetId = (int)$row['id'];
            $targetNameColumn = 'coordinator_name';
            $needsUpdate = (is_null($row[$targetNameColumn]) || $row[$targetNameColumn] === '');
        }
    }
}

// If user does not require update, go to dashboard
if (!$needsUpdate) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = strtoupper(trim($_POST['full_name'] ?? ''));
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Persist user-entered values for sticky form
    $values['full_name'] = $full_name;
    $values['username'] = $username;
    $values['email'] = $email;

    // Field-level validations
    if (!$full_name) {
        $fieldErrors['full_name'] = 'Full name is required';
    }
    if (!$username) {
        $fieldErrors['username'] = 'Username is required';
    }
    if (!$email) {
        $fieldErrors['email'] = 'Email is required';
    } elseif (!isCvSUEmailValid($email)) {
        $fieldErrors['email'] = 'Institutional email must end with @cvsu.edu.ph';
    }
    if (!$password) {
        $fieldErrors['password'] = 'Password is required';
    }
    if (!$confirm_password) {
        $fieldErrors['confirm_password'] = 'Please confirm your password';
    }
    if (!$fieldErrors['password'] && !$fieldErrors['confirm_password'] && $password !== $confirm_password) {
        $fieldErrors['password'] = 'Passwords do not match';
        $fieldErrors['confirm_password'] = 'Passwords do not match';
    }

    // If no field errors so far, check username uniqueness and perform update
    $hasErrors = false;
    foreach ($fieldErrors as $fe) { if (!empty($fe)) { $hasErrors = true; break; } }

    if (!$hasErrors) {
        // Check username uniqueness for other users
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
        $stmt->bind_param('si', $username, $userId);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $fieldErrors['username'] = 'Username is already taken';
            $hasErrors = true;
        }
    }

    if (!$hasErrors) {
        // Update users table
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $u = $conn->prepare('UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?');
        $u->bind_param('sssi', $username, $email, $hash, $userId);
        if ($u->execute()) {
            // Update related name field
            if ($targetTable === 'organizations' || $targetTable === 'council') {
                $sql = "UPDATE {$targetTable} SET {$targetNameColumn} = ? WHERE id = ?";
                $s = $conn->prepare($sql);
                $s->bind_param('si', $full_name, $targetId);
                $s->execute();
            } elseif ($targetTable === 'mis_coordinators') {
                $s = $conn->prepare('UPDATE mis_coordinators SET coordinator_name = ? WHERE id = ?');
                $s->bind_param('si', $full_name, $targetId);
                $s->execute();
            }

            // Refresh session username/email
            $_SESSION['username'] = $username;
            $_SESSION['email'] = $email;

            // Redirect to dashboard after success
            header('Location: dashboard.php');
            exit();
        } else {
            $_SESSION['error'] = 'Failed to update account. Please try again.';
            $_SESSION['form_values'] = $values;
            $_SESSION['field_errors'] = $fieldErrors;
            header('Location: newTerm.php');
            exit();
        }
    } else {
        $_SESSION['error'] = 'Please correct the highlighted fields.';
        $_SESSION['form_values'] = $values;
        $_SESSION['field_errors'] = $fieldErrors;
        header('Location: newTerm.php');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Academic Year - Update Your Details</title>
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
            max-width: 600px;
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
            width: 70px;
            height: 70px;
            margin: 0 auto 1.5rem;
            background: #212529;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.8rem;
            box-shadow: 0 4px 12px rgba(33, 37, 41, 0.15);
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
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="login-container">
                <div class="card">
                    <div class="card-body">
                        <div class="brand-logo">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h2 class="text-center mb-2">Welcome to the New Academic Year</h2>
                        <p class="text-center text-muted mb-4">Please verify and update your information to continue.</p>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-id-card"></i>
                                    </span>
                                    <input type="text" id="full_name" name="full_name" class="form-control<?php echo $fieldErrors['full_name'] ? ' is-invalid' : ''; ?>" placeholder="FIRSTNAME MIDDLE INITIAL LASTNAME" value="<?php echo htmlspecialchars($values['full_name']); ?>" required>
                                    <?php if ($fieldErrors['full_name']): ?>
                                        <div class="invalid-feedback">
                                            <?php echo htmlspecialchars($fieldErrors['full_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" id="username" name="username" class="form-control<?php echo $fieldErrors['username'] ? ' is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($values['username']); ?>" placeholder="Enter username" required>
                                    <?php if ($fieldErrors['username']): ?>
                                        <div class="invalid-feedback">
                                            <?php echo htmlspecialchars($fieldErrors['username']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="email" class="form-label">Institutional Email (@cvsu.edu.ph)</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" id="email" name="email" class="form-control<?php echo $fieldErrors['email'] ? ' is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($values['email']); ?>" placeholder="name@cvsu.edu.ph" required>
                                    <?php if ($fieldErrors['email']): ?>
                                        <div class="invalid-feedback">
                                            <?php echo htmlspecialchars($fieldErrors['email']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" id="password" name="password" class="form-control<?php echo $fieldErrors['password'] ? ' is-invalid' : ''; ?>" placeholder="Enter new password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('password', this)">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                    <?php if ($fieldErrors['password']): ?>
                                        <div class="invalid-feedback">
                                            <?php echo htmlspecialchars($fieldErrors['password']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-lock"></i>
                                    </span>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control<?php echo $fieldErrors['confirm_password'] ? ' is-invalid' : ''; ?>" placeholder="Re-enter new password" required>
                                    <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password', this)">
                                        <i class="fas fa-eye"></i>
                                    </span>
                                    <?php if ($fieldErrors['confirm_password']): ?>
                                        <div class="invalid-feedback">
                                            <?php echo htmlspecialchars($fieldErrors['confirm_password']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-arrow-right-to-bracket me-2"></i>Continue to Dashboard
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, toggleEl) {
            const input = document.getElementById(inputId);
            const icon = toggleEl.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>



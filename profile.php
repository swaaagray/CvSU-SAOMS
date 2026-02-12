<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireLogin();

$userId = getCurrentUserId();
$role = getUserRole();

// Handle edit requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if ($_POST['action'] === 'update_name' && isset($_POST['name'])) {
        $new_name = trim($_POST['name']);
        if (empty($new_name)) {
            $response['message'] = 'Name cannot be empty';
        } else {
            // Update name in appropriate table
            if (in_array($role, ['org_adviser', 'org_president'])) {
                $name_field = $role === 'org_adviser' ? 'adviser_name' : 'president_name';
                $stmt = $conn->prepare("UPDATE organizations SET $name_field = ? WHERE " . 
                    ($role === 'org_adviser' ? 'adviser_id' : 'president_id') . " = ?");
                $stmt->bind_param("si", $new_name, $userId);
            } elseif (in_array($role, ['council_adviser', 'council_president'])) {
                $name_field = $role === 'council_adviser' ? 'adviser_name' : 'president_name';
                $stmt = $conn->prepare("UPDATE council SET $name_field = ? WHERE " . 
                    ($role === 'council_adviser' ? 'adviser_id' : 'president_id') . " = ?");
                $stmt->bind_param("si", $new_name, $userId);
            } elseif ($role === 'mis_coordinator') {
                $stmt = $conn->prepare("UPDATE mis_coordinators SET coordinator_name = ? WHERE user_id = ?");
                $stmt->bind_param("si", $new_name, $userId);
            } else {
                $response['message'] = 'Invalid role for name update';
                echo json_encode($response);
                exit();
            }
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Name updated successfully';
            } else {
                $response['message'] = 'Failed to update name';
            }
            $stmt->close();
        }
        echo json_encode($response);
        exit();
    }
    
    if ($_POST['action'] === 'update_email' && isset($_POST['email'])) {
        $new_email = trim($_POST['email']);
        if (empty($new_email)) {
            $response['message'] = 'Email address cannot be empty';
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $response['message'] = 'Please enter a valid email address';
        } elseif (!preg_match('/@cvsu\.edu\.ph$/i', $new_email)) {
            $response['message'] = 'Email must end with @cvsu.edu.ph';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param("si", $new_email, $userId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $response['message'] = 'Email address already exists';
            } else {
                $stmt->close();
                // Update email in users table
                $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                $stmt->bind_param("si", $new_email, $userId);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Email updated successfully';
                } else {
                    $response['message'] = 'Failed to update email';
                }
            }
            $stmt->close();
        }
        echo json_encode($response);
        exit();
    }
    
    if ($_POST['action'] === 'update_username' && isset($_POST['username'])) {
        $new_username = trim($_POST['username']);
        if (empty($new_username)) {
            $response['message'] = 'Username cannot be empty';
        } else {
            // Check if username already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $new_username, $userId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $response['message'] = 'Username already exists';
            } else {
                $stmt->close();
                // Update username in users table
                $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmt->bind_param("si", $new_username, $userId);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = 'Username updated successfully';
                    // Update session username
                    $_SESSION['username'] = $new_username;
                } else {
                    $response['message'] = 'Failed to update username';
                }
            }
            $stmt->close();
        }
        echo json_encode($response);
        exit();
    }
}

// Get user information
$stmt = $conn->prepare("SELECT id, username, email, role, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    header('Location: dashboard.php');
    exit();
}

// Get the actual name (president_name or adviser_name) if user is org_adviser, org_president, council_adviser, or council_president
$actual_name = null;
if (in_array($role, ['org_adviser', 'org_president'])) {
    $name_field = $role === 'org_adviser' ? 'adviser_name' : 'president_name';
    $stmt = $conn->prepare("SELECT $name_field as name FROM organizations WHERE " . 
        ($role === 'org_adviser' ? 'adviser_id' : 'president_id') . " = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $actual_name = $result['name'];
    }
    $stmt->close();
} elseif (in_array($role, ['council_adviser', 'council_president'])) {
    $name_field = $role === 'council_adviser' ? 'adviser_name' : 'president_name';
    $stmt = $conn->prepare("SELECT $name_field as name FROM council WHERE " . 
        ($role === 'council_adviser' ? 'adviser_id' : 'president_id') . " = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $actual_name = $result['name'];
    }
    $stmt->close();
} elseif ($role === 'mis_coordinator') {
    $stmt = $conn->prepare("SELECT coordinator_name as name FROM mis_coordinators WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if ($result) {
        $actual_name = $result['name'];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - CvSU Academic Organizations</title>
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        main {
            margin-left: 250px;
            margin-top: 56px;
            padding: 15px 20px 20px 20px;
            transition: margin-left 0.3s;
        }
        
        main.expanded {
            margin-left: 0;
        }
        
        .profile-card {
            background: var(--white);
            border-radius: 15px;
            box-shadow: 0 8px 32px var(--shadow);
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .profile-card:hover {
            box-shadow: 0 12px 40px var(--shadow-heavy);
            transform: translateY(-2px);
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%);
            color: var(--white);
            padding: 30px 25px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="0.5" fill="white" opacity="0.1"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 2.5rem;
            position: relative;
            z-index: 1;
            transition: all 0.3s ease;
        }
        
        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.5);
        }
        
        .profile-name {
            position: relative;
            z-index: 1;
            font-weight: 600;
            margin-bottom: 5px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .profile-username {
            position: relative;
            z-index: 1;
            opacity: 0.8;
            font-weight: 300;
            margin-bottom: 12px;
        }
        
        .role-badge {
            background: rgba(255,255,255,0.2);
            color: var(--white);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1;
        }
        
        .info-row {
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s ease;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-row:hover {
            background-color: #fafafa;
        }
        
        .info-label {
            color: var(--accent-gray);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;
        }
        
        .info-value {
            color: var(--primary-dark);
            font-weight: 400;
            font-size: 1rem;
        }
        
        .info-icon {
            color: var(--light-gray);
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }
        
        .actions-card .card-header {
            background: var(--secondary-dark);
            color: var(--white);
            border: none;
            padding: 15px 20px;
        }
        
        .btn-monochrome {
            background: var(--primary-dark);
            color: var(--white);
            border: 2px solid var(--primary-dark);
            padding: 12px 20px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-monochrome:hover {
            background: transparent;
            color: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        
        .btn-outline-monochrome {
            background: transparent;
            color: var(--accent-gray);
            border: 2px solid var(--accent-gray);
            padding: 12px 20px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-outline-monochrome:hover {
            background: var(--accent-gray);
            color: var(--white);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        
        h2 {
            color: var(--primary-dark);
            font-weight: 600;
            margin-bottom: 15px;
            margin-top: 0;
        }
        
        .page-title-icon {
            color: var(--accent-gray);
            margin-right: 10px;
        }
        
        @media (max-width: 768px) {
            main { 
                margin-left: 0;
                margin-top: 56px;
                padding: 10px 15px 15px 15px; 
            }
            .profile-header {
                padding: 25px 15px;
            }
            .profile-avatar {
                width: 70px;
                height: 70px;
                font-size: 2.2rem;
            }
            .info-label {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <main>
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Profile</h2>
                    <p class="page-subtitle">View and manage your personal information</p>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <div class="profile-card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <i class="bi bi-person-circle"></i>
                            </div>
                            <?php if ($actual_name): ?>
                            <h3 class="profile-name"><?php echo htmlspecialchars($actual_name); ?></h3>
                            <p class="profile-username">@<?php echo htmlspecialchars($user['username']); ?></p>
                            <?php else: ?>
                            <h3 class="profile-name"><?php echo htmlspecialchars($user['username']); ?></h3>
                            <?php endif; ?>
                            <span class="role-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))); ?></span>
                        </div>
                        
                        <div class="card-body px-4">
                            <?php if ($actual_name): ?>
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-person-badge info-icon"></i>
                                    Full Name
                                </div>
                                <div class="info-value d-flex align-items-center justify-content-between">
                                    <span id="display-name"><?php echo htmlspecialchars($actual_name); ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-btn" data-field="name" title="Edit name">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-at info-icon"></i>
                                    Username
                                </div>
                                <div class="info-value d-flex align-items-center justify-content-between">
                                    <span id="display-username"><?php echo htmlspecialchars($user['username']); ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-btn" data-field="username" title="Edit username">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-envelope info-icon"></i>
                                    Email Address
                                </div>
                                <div class="info-value d-flex align-items-center justify-content-between">
                                    <span id="display-email"><?php echo htmlspecialchars($user['email']); ?></span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-btn" data-field="email" title="Edit email">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <?php if (!in_array($role, ['org_adviser', 'org_president', 'council_adviser', 'council_president', 'mis_coordinator'])): ?>
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-shield-check info-icon"></i>
                                    Role & Permissions
                                </div>
                                <div class="info-value"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!in_array($role, ['org_adviser', 'org_president', 'council_adviser', 'council_president', 'mis_coordinator', 'osas'])): ?>
                            <div class="info-row">
                                <div class="info-label">
                                    <i class="bi bi-calendar-plus info-icon"></i>
                                    Member Since
                                </div>
                                <div class="info-value"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="profile-card actions-card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-lightning-charge me-2"></i>Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-3">
                                <?php if (in_array($role, ['org_adviser', 'org_president'])): ?>
                                <a href="organization_settings.php" class="btn btn-monochrome">
                                    <i class="bi bi-gear me-2"></i>Organization Settings
                                </a>
                                <?php elseif (in_array($role, ['council_president', 'council_adviser'])): ?>
                                <a href="council_settings.php" class="btn btn-monochrome">
                                    <i class="bi bi-gear me-2"></i>Council Settings
                                </a>
                                <?php endif; ?>
                                
                                <?php if (in_array($role, ['org_adviser', 'org_president', 'council_president', 'council_adviser', 'mis_coordinator', 'osas'])): ?>
                                <a href="change_password.php" class="btn btn-outline-monochrome">
                                    <i class="bi bi-shield-lock me-2"></i>Change Password
                                </a>
                                <?php endif; ?>
                                
                                <a href="dashboard.php" class="btn btn-outline-monochrome">
                                    <i class="bi bi-speedometer2 me-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle is handled by navbar.php
            
            // Edit functionality
            const editButtons = document.querySelectorAll('.edit-btn');
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const editButton = this; // Store reference to the edit button
                    const field = this.getAttribute('data-field');
                    const displayElement = document.getElementById(`display-${field}`);
                    const currentValue = displayElement.textContent.trim();
                    
                    // Create input field
                    const input = document.createElement('input');
                    input.type = field === 'email' ? 'email' : 'text';
                    input.value = currentValue;
                    input.className = 'form-control form-control-sm';
                    input.style.width = '200px';
                    
                    // Create save and cancel buttons
                    const saveBtn = document.createElement('button');
                    saveBtn.type = 'button';
                    saveBtn.className = 'btn btn-sm btn-success ms-2';
                    saveBtn.innerHTML = '<i class="bi bi-check"></i>';
                    saveBtn.title = 'Save';
                    
                    const cancelBtn = document.createElement('button');
                    cancelBtn.type = 'button';
                    cancelBtn.className = 'btn btn-sm btn-secondary ms-1';
                    cancelBtn.innerHTML = '<i class="bi bi-x"></i>';
                    cancelBtn.title = 'Cancel';
                    
                    // Replace display element with input and buttons
                    const container = displayElement.parentElement;
                    container.innerHTML = '';
                    
                    // Create wrapper div for input and error message
                    const inputWrapper = document.createElement('div');
                    inputWrapper.style.width = '200px';
                    inputWrapper.style.position = 'relative';
                    
                    container.appendChild(inputWrapper);
                    inputWrapper.appendChild(input);
                    
                    // Create error message div (initially hidden)
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'text-danger small mt-1';
                    errorDiv.style.display = 'none';
                    errorDiv.style.fontSize = '0.875rem';
                    errorDiv.style.whiteSpace = 'nowrap';
                    errorDiv.id = `error-${field}`;
                    inputWrapper.appendChild(errorDiv);
                    
                    // Create button container
                    const buttonContainer = document.createElement('div');
                    buttonContainer.style.display = 'flex';
                    buttonContainer.style.gap = '5px';
                    buttonContainer.style.marginLeft = '10px';
                    container.appendChild(buttonContainer);
                    buttonContainer.appendChild(saveBtn);
                    buttonContainer.appendChild(cancelBtn);
                    
                    // Function to show error message
                    function showError(message) {
                        errorDiv.textContent = message;
                        errorDiv.style.display = 'block';
                        input.classList.add('is-invalid');
                        input.style.borderColor = '#dc3545';
                    }
                    
                    // Function to hide error message
                    function hideError() {
                        errorDiv.style.display = 'none';
                        input.classList.remove('is-invalid');
                        input.style.borderColor = '';
                    }
                    
                    // Cancel functionality
                    function cancelEdit() {
                        container.innerHTML = '';
                        container.appendChild(displayElement);
                        container.appendChild(editButton);
                    }
                    
                    // Focus on input
                    input.focus();
                    input.select();
                    
                    // Clear error on input change
                    input.addEventListener('input', function() {
                        hideError();
                    });
                    
                    // Save functionality
                    saveBtn.addEventListener('click', function() {
                        const newValue = input.value.trim();
                        if (newValue === currentValue) {
                            // No change, just cancel
                            cancelEdit();
                            return;
                        }
                        
                        if (newValue === '') {
                            showError('Value cannot be empty');
                            input.focus();
                            return;
                        }
                        
                        // Additional validation for email field
                        if (field === 'email') {
                            const emailLower = newValue.toLowerCase();
                            if (!emailLower.endsWith('@cvsu.edu.ph')) {
                                showError('Email must end with @cvsu.edu.ph');
                                input.focus();
                                return;
                            }
                        }
                        
                        // Hide any existing errors before submitting
                        hideError();
                        
                        // Send update request
                        const formData = new FormData();
                        formData.append('action', `update_${field}`);
                        formData.append(field, newValue);
                        
                        fetch('profile.php', {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Update display
                                displayElement.textContent = newValue;
                                
                                // If updating name, also update the profile header name if it exists
                                if (field === 'name') {
                                    const profileNameElement = document.querySelector('.profile-name');
                                    if (profileNameElement) {
                                        profileNameElement.textContent = newValue;
                                    }
                                }
                                
                                // If updating username, also update the profile header username if it exists
                                if (field === 'username') {
                                    const profileUsernameElement = document.querySelector('.profile-username');
                                    if (profileUsernameElement) {
                                        profileUsernameElement.textContent = '@' + newValue;
                                    }
                                }
                                
                                cancelEdit();
                            } else {
                                // Show error message from server
                                showError(data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showError('An error occurred while updating');
                        });
                    });
                    
                    cancelBtn.addEventListener('click', cancelEdit);
                    
                    // Handle Enter key
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            saveBtn.click();
                        }
                    });
                    
                    // Handle Escape key
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Escape') {
                            cancelEdit();
                        }
                    });
                });
            });
        });
    </script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>
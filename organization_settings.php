<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'org_adviser']);

$userId = getCurrentUserId();
$role = getUserRole();
$orgId = getCurrentOrganizationId();

// Get organization details
$stmt = $conn->prepare("
    SELECT o.*, c.name as college_name, co.name as course_name 
    FROM organizations o
    LEFT JOIN colleges c ON o.college_id = c.id
    LEFT JOIN courses co ON o.course_id = co.id
    WHERE o.id = ?
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$organization = $stmt->get_result()->fetch_assoc();

if (!$organization) {
    header('Location: dashboard.php');
    exit();
}

// Get success/error messages from session and clear them
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

// Handle form submission - only allow presidents to make changes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'org_president') {
    $description = trim($_POST['description'] ?? '');
    $facebook_link = trim($_POST['facebook_link'] ?? '');
    
    // Validate Facebook link if provided
    if (!empty($facebook_link) && !filter_var($facebook_link, FILTER_VALIDATE_URL)) {
        $_SESSION['error_message'] = 'Please enter a valid Facebook URL';
        header('Location: organization_settings.php');
        exit();
    }
    
    $logo_path = $organization['logo_path']; // Keep existing logo by default
    
    // Handle logo upload
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/svg+xml'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'svg'];
        $file_type = $_FILES['logo']['type'];
        $file_size = $_FILES['logo']['size'];
        $file_name = $_FILES['logo']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validate file extension
        if (!in_array($file_ext, $allowed_extensions)) {
            $_SESSION['error_message'] = 'Only JPG, PNG, and SVG files are allowed';
            header('Location: organization_settings.php');
            exit();
        }
        
        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $_FILES['logo']['tmp_name']) : '';
        if ($finfo) { finfo_close($finfo); }
        
        if (!in_array($mime, $allowed_types)) {
            $_SESSION['error_message'] = 'Invalid file type detected. Please upload a valid image file.';
            header('Location: organization_settings.php');
            exit();
        }
        
        // Validate file size (2MB max)
        if ($file_size > 2 * 1024 * 1024) {
            $_SESSION['error_message'] = 'File size must be less than 2MB';
            header('Location: organization_settings.php');
            exit();
        }
        
        // Create upload directory if it doesn't exist
        $upload_dir = 'uploads/logos/organizations/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
        $new_filename = 'org_' . $orgId . '_' . time() . '.' . $file_extension;
        $upload_path = $upload_dir . $new_filename;
        
        // Move uploaded file
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
            // Delete old logo if it exists
            if (!empty($organization['logo_path']) && file_exists($organization['logo_path'])) {
                unlink($organization['logo_path']);
            }
            $logo_path = $upload_path;
        } else {
            $_SESSION['error_message'] = 'Failed to upload logo';
            header('Location: organization_settings.php');
            exit();
        }
    }
    
    // Update database
    $stmt = $conn->prepare("
        UPDATE organizations 
        SET description = ?, facebook_link = ?, logo_path = ?
        WHERE id = ?
    ");
    $stmt->bind_param("sssi", $description, $facebook_link, $logo_path, $orgId);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Organization profile updated successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to update organization profile';
    }
    
    // Redirect to prevent form resubmission
    header('Location: organization_settings.php');
    exit();
}

// Refresh organization data with college and course info for display
$stmt = $conn->prepare("
    SELECT o.*, c.name as college_name, co.name as course_name 
    FROM organizations o
    LEFT JOIN colleges c ON o.college_id = c.id
    LEFT JOIN courses co ON o.course_id = co.id
    WHERE o.id = ?
");
$stmt->bind_param("i", $orgId);
$stmt->execute();
$organization = $stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization Settings - CvSU Academic Organizations</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #1a1a1a;
            --secondary-dark: #2d2d2d;
            --accent-gray: #4a4a4a;
            --light-gray: #6a6a6a;
            --background-gray: #f5f5f5;
            --white: #ffffff;
            --shadow: rgba(0, 0, 0, 0.1);
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
        
        .settings-card {
            background: var(--white);
            border-radius: 15px;
            box-shadow: 0 8px 32px var(--shadow);
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
        }
        
        .settings-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-dark) 100%);
            color: var(--white);
            padding: 20px 25px;
            border-radius: 15px 15px 0 0;
        }
        
        .logo-preview {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e0e0e0;
        }
        
        .logo-placeholder {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px dashed #ccc;
        }
        
        .logo-placeholder i {
            font-size: 3rem;
            color: #aaa;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--accent-gray);
            margin-bottom: 8px;
        }
        
        .btn-save {
            background: var(--primary-dark);
            color: var(--white);
            border: none;
            padding: 12px 30px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            background: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        
        .char-counter {
            font-size: 0.875rem;
            color: var(--light-gray);
        }
        
        /* View-only interface styles */
        .view-only-content {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            min-height: 60px;
        }
        
        .description-display {
            background-color: var(--white);
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 16px;
            line-height: 1.7;
            font-size: 0.95rem;
            color: #495057;
            text-align: left;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .no-content-message {
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }
        
        .view-only-info {
            padding: 10px 0;
        }
        
        .view-only-info p {
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        @media (max-width: 768px) {
            main {
                margin-left: 0;
                margin-top: 56px;
                padding: 10px 15px 15px 15px;
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
                    <h2 class="page-title">Organization Settings</h2>
                    <p class="page-subtitle">Configure your organization details and preferences</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="settings-card">
                        <div class="settings-header">
                            <h4 class="mb-1">
                                <i class="bi bi-building me-2"></i>
                                <?php echo htmlspecialchars($organization['org_name']); ?>
                            </h4>
                            <p class="mb-0 opacity-75">
                                <?php echo htmlspecialchars($organization['college_name'] ?? 'N/A'); ?>
                                <?php if (!empty($organization['course_name'])): ?>
                                    - <?php echo htmlspecialchars($organization['course_name']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div class="card-body p-4">
                            <?php if ($role === 'org_adviser'): ?>
                                <!-- View-Only Interface for Advisers -->
                                <div class="alert alert-info mb-4" role="alert">
                                    <i class="bi bi-eye me-2"></i>
                                    <strong>View Only Mode:</strong> You are viewing the organization settings in read-only mode. Only the organization president can modify these settings.
                                </div>

                                <!-- Logo Display Section -->
                                <div class="mb-4">
                                    <h6 class="form-label mb-3 text-center">
                                        <i class="bi bi-image me-2"></i>Organization Logo
                                    </h6>
                                    <div class="text-center">
                                        <div class="mb-3">
                                            <?php if (!empty($organization['logo_path']) && file_exists($organization['logo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($organization['logo_path']); ?>" 
                                                     alt="Organization Logo" 
                                                     class="logo-preview">
                                            <?php else: ?>
                                                <div class="logo-placeholder mx-auto">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="view-only-info">
                                            <?php if (!empty($organization['logo_path']) && file_exists($organization['logo_path'])): ?>
                                                <p class="mb-1 text-success">
                                                    <i class="bi bi-check-circle me-1"></i>Logo is set
                                                </p>
                                                <small class="text-muted">Current logo is displayed</small>
                                            <?php else: ?>
                                                <p class="mb-1 text-muted">
                                                    <i class="bi bi-dash-circle me-1"></i>No logo uploaded
                                                </p>
                                                <small class="text-muted">Organization has no logo set</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Description Display Section -->
                                <div class="mb-4">
                                    <h6 class="form-label mb-3">
                                        <i class="bi bi-text-paragraph me-2"></i>Organization Description
                                    </h6>
                                    <?php if (!empty($organization['description'])): ?>
                                        <div class="description-display">
                                            <?php echo nl2br(htmlspecialchars($organization['description'])); ?>
                                        </div>
                                        <small class="text-muted mt-2 d-block">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Character count: <?php echo strlen($organization['description']); ?>/1000
                                        </small>
                                    <?php else: ?>
                                        <div class="no-content-message">
                                            <i class="bi bi-dash-circle me-2"></i>No description provided
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Facebook Link Display Section -->
                                <div class="mb-4">
                                    <h6 class="form-label mb-3">
                                        <i class="bi bi-facebook me-2"></i>Facebook Page Link
                                    </h6>
                                    <div class="view-only-content">
                                        <?php if (!empty($organization['facebook_link'])): ?>
                                            <div class="d-flex align-items-center gap-2">
                                                <a href="<?php echo htmlspecialchars($organization['facebook_link']); ?>" 
                                                   target="_blank" 
                                                   class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-facebook me-1"></i>Visit Facebook Page
                                                </a>
                                                <span class="text-muted small">
                                                    <?php echo htmlspecialchars($organization['facebook_link']); ?>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-muted fst-italic">
                                                <i class="bi bi-dash-circle me-1"></i>No Facebook page linked
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Public Profile Link -->
                                <div class="mb-4">
                                    <h6 class="form-label mb-3">
                                        <i class="bi bi-link-45deg me-2"></i>Public Profile
                                    </h6>
                                    <div class="view-only-content">
                                        <div class="d-flex align-items-center gap-2">
                                            <button class="btn btn-outline-secondary btn-sm" 
                                                    onclick="window.open('organization_profile.php?id=<?php echo $orgId; ?>', '_blank')">
                                                <i class="bi bi-eye me-1"></i>View Public Profile
                                            </button>
                                            <span class="text-muted small">
                                                <?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/organization_profile.php?id=' . $orgId; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                            <?php else: ?>
                                <!-- Editable Interface for Presidents -->
                                <form method="POST" enctype="multipart/form-data">
                                    <!-- Logo Upload Section -->
                                    <div class="mb-4">
                                        <label class="form-label">
                                            <i class="bi bi-image me-2"></i>Organization Logo
                                        </label>
                                        <div class="d-flex align-items-center gap-4">
                                            <div>
                                                <?php if (!empty($organization['logo_path']) && file_exists($organization['logo_path'])): ?>
                                                    <img src="<?php echo htmlspecialchars($organization['logo_path']); ?>" 
                                                         alt="Organization Logo" 
                                                         class="logo-preview"
                                                         id="logoPreview">
                                                <?php else: ?>
                                                    <div class="logo-placeholder" id="logoPlaceholder">
                                                        <i class="bi bi-image"></i>
                                                    </div>
                                                    <img src="" alt="Logo Preview" class="logo-preview d-none" id="logoPreview">
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <input type="file" 
                                                       class="form-control" 
                                                       id="logoInput" 
                                                       name="logo" 
                                                       accept="image/jpeg,image/png,image/jpg,image/svg+xml">
                                                <small class="text-muted d-block mt-2">
                                                    Accepted formats: JPG, PNG, SVG (Max 2MB)
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Description Section -->
                                    <div class="mb-4">
                                        <label for="description" class="form-label">
                                            <i class="bi bi-text-paragraph me-2"></i>Organization Description
                                        </label>
                                        <textarea class="form-control" 
                                                  id="description" 
                                                  name="description" 
                                                  rows="5" 
                                                  maxlength="1000"
                                                  placeholder="Enter a brief description of your organization..."><?php echo htmlspecialchars($organization['description'] ?? ''); ?></textarea>
                                        <div class="d-flex justify-content-between mt-2">
                                            <small class="text-muted">
                                                This will be displayed on your public organization profile
                                            </small>
                                            <span class="char-counter">
                                                <span id="charCount"><?php echo strlen($organization['description'] ?? ''); ?></span>/1000
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Facebook Link Section -->
                                    <div class="mb-4">
                                        <label for="facebook_link" class="form-label">
                                            <i class="bi bi-facebook me-2"></i>Facebook Page Link
                                        </label>
                                        <input type="url" 
                                               class="form-control" 
                                               id="facebook_link" 
                                               name="facebook_link" 
                                               value="<?php echo htmlspecialchars($organization['facebook_link'] ?? ''); ?>"
                                               placeholder="https://facebook.com/yourpage">
                                        <small class="text-muted d-block mt-2">
                                            Enter the full URL to your organization's Facebook page
                                        </small>
                                    </div>

                                    <!-- Public Profile Link -->
                                    <div class="mb-4">
                                        <label class="form-label">
                                            <i class="bi bi-link-45deg me-2"></i>Public Profile
                                        </label>
                                        <div class="input-group">
                                            <input type="text" 
                                                   class="form-control" 
                                                   value="<?php echo (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/organization_profile.php?id=' . $orgId; ?>" 
                                                   readonly>
                                            <button class="btn btn-outline-secondary" 
                                                    type="button" 
                                                    onclick="window.open('organization_profile.php?id=<?php echo $orgId; ?>', '_blank')">
                                                <i class="bi bi-eye me-1"></i>View
                                            </button>
                                        </div>
                                        <small class="text-muted d-block mt-2">
                                            This is your organization's public profile page
                                        </small>
                                    </div>

                                    <!-- Save Button -->
                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <button type="submit" class="btn btn-save">
                                            <i class="bi bi-check-circle me-2"></i>Save Changes
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
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
            // Only initialize form functionality for presidents
            <?php if ($role === 'org_president'): ?>
            // Character counter for description
            const descriptionInput = document.getElementById('description');
            const charCount = document.getElementById('charCount');
            
            if (descriptionInput && charCount) {
                descriptionInput.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }

            // Logo preview
            const logoInput = document.getElementById('logoInput');
            const logoPreview = document.getElementById('logoPreview');
            const logoPlaceholder = document.getElementById('logoPlaceholder');
            
            if (logoInput && logoPreview) {
                logoInput.addEventListener('change', function(e) {
                    const file = e.target.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            logoPreview.src = e.target.result;
                            logoPreview.classList.remove('d-none');
                            if (logoPlaceholder) {
                                logoPlaceholder.classList.add('d-none');
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>


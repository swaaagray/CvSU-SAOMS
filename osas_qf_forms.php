<?php
require_once 'config/database.php';
require_once 'includes/session.php';
require_once 'phpspreadsheet/vendor/autoload.php';
require_once 'includes/qf_templates.php';
require_once 'includes/settings_helper.php';

use Mpdf\Mpdf;

requireRole(['osas']);

$currentUserId = getCurrentUserId();
$message = '';
$error = '';

// Get current officials settings
$officials = getActivityPermitOfficials($conn);

// Get user's display name
$stmt = $conn->prepare("SELECT COALESCE(NULLIF(username, ''), email) as full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $currentUserId);
$stmt->execute();
$userResult = $stmt->get_result();
$userName = $userResult->fetch_assoc()['full_name'] ?? 'Unknown User';

// Fetch all organizations for dropdown
$organizations = [];
$orgResult = $conn->query("SELECT id, org_name FROM organizations ORDER BY org_name");
if ($orgResult) {
    while ($row = $orgResult->fetch_assoc()) {
        $organizations[] = $row;
    }
}

// Fetch all councils for dropdown
$councils = [];
$councilResult = $conn->query("SELECT id, council_name FROM council ORDER BY council_name");
if ($councilResult) {
    while ($row = $councilResult->fetch_assoc()) {
        $councils[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'open_preview_modal':
            case 'download_pdf':
                $targetType = $_POST['target_type'] ?? 'organization';
                $targetId = (int)($_POST['target_id'] ?? 0);
                
                // Get target name
                $targetName = '';
                if ($targetType === 'organization' && $targetId > 0) {
                    $stmt = $conn->prepare("SELECT org_name FROM organizations WHERE id = ?");
                    $stmt->bind_param("i", $targetId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $targetName = $result->fetch_assoc()['org_name'] ?? '';
                } elseif ($targetType === 'council' && $targetId > 0) {
                    $stmt = $conn->prepare("SELECT council_name FROM council WHERE id = ?");
                    $stmt->bind_param("i", $targetId);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $targetName = $result->fetch_assoc()['council_name'] ?? '';
                }
                
                // Normalize Activity Type when 'Other' selected
                $activityType = trim($_POST['activity_type'] ?? '');
                if ($activityType === 'Other') {
                    $customType = trim($_POST['activity_type_other'] ?? '');
                    if ($customType !== '' && preg_match('/^[A-Za-z ]+$/', $customType)) {
                        $activityType = $customType;
                    }
                }
                
                $formData = [
                    'form_type' => 'activity_permit',
                    'target_type' => $targetType,
                    'organization_id' => $targetType === 'organization' ? $targetId : null,
                    'council_id' => $targetType === 'council' ? $targetId : null,
                    'organization_name' => $targetType === 'organization' ? $targetName : '',
                    'council_name' => $targetType === 'council' ? $targetName : '',
                    'activity_name' => $_POST['activity_name'] ?? '',
                    'activity_type' => $activityType,
                    'activity_date_start' => $_POST['activity_date_start'] ?? '',
                    'activity_date_end' => $_POST['activity_date_end'] ?? '',
                    'activity_time_start' => $_POST['activity_time_start'] ?? '',
                    'activity_time_end' => $_POST['activity_time_end'] ?? '',
                    'activity_venue' => $_POST['activity_venue'] ?? '',
                    'student_count' => $_POST['student_count'] ?? ''
                ];
                
                $_SESSION['qf_form_preview'] = $formData;
                
                if ($_POST['action'] === 'download_pdf') {
                    header('Location: generate_qf_pdf.php?preview=1');
                    exit;
                } else {
                    $_SESSION['show_qf_modal'] = true;
                    header('Location: osas_qf_forms.php?show_modal=1');
                    exit;
                }
                break;
                
            case 'save_settings':
                // Save Activity Permit officials settings
                $recommendingName = trim($_POST['recommending_name'] ?? '');
                $recommendingPosition = trim($_POST['recommending_position'] ?? '');
                $approvedName = trim($_POST['approved_name'] ?? '');
                $approvedPosition = trim($_POST['approved_position'] ?? '');
                
                $success = true;
                $success = $success && setSetting($conn, 'permit_recommending_name', $recommendingName);
                $success = $success && setSetting($conn, 'permit_recommending_position', $recommendingPosition);
                $success = $success && setSetting($conn, 'permit_approved_name', $approvedName);
                $success = $success && setSetting($conn, 'permit_approved_position', $approvedPosition);
                
                if ($success) {
                    $_SESSION['message'] = 'Activity Permit officials settings saved successfully!';
                } else {
                    $_SESSION['error'] = 'Error saving settings. Please try again.';
                }
                header('Location: osas_qf_forms.php');
                exit;
                break;
        }
    }
}

// Display messages
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Check if modal should be shown (only from session flag to prevent resubmission on refresh)
$showModal = !empty($_SESSION['show_qf_modal']);

// Clear the session flag immediately after checking
if ($showModal) {
    unset($_SESSION['show_qf_modal']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Permit - OSAS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <link href="assets/css/pdf-viewer.css" rel="stylesheet">
    <!-- Select2 for searchable dropdowns -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <style>
        .form-container {
            background: white;
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 15px rgba(0,0,0,0.1);
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .form-title {
            color: #212529;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
            border-bottom: 3px solid #495057;
            padding-bottom: 1rem;
        }
        
        .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057);
            border: none;
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(73, 80, 87, 0.35);
            color: #fff;
        }
        
        .btn:focus,
        .btn:active,
        .btn:focus-visible,
        .btn-primary:focus,
        .btn-primary:active,
        .btn-secondary:focus,
        .btn-secondary:active {
            outline: none !important;
            box-shadow: none !important;
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5c636a, #6c757d);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(108, 117, 125, 0.35);
            color: #fff;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
            transform: translateY(-1px);
        }
        
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .page-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0.5rem 0 0 0;
        }
        
        /* Select2 custom styling to match form */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 8px 12px;
            min-height: 50px;
            transition: all 0.3s ease;
        }
        
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding: 0;
            line-height: 1.5;
        }
        
        .select2-container--bootstrap-5.select2-container--focus .select2-selection,
        .select2-container--bootstrap-5.select2-container--open .select2-selection {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
        
        .select2-container--bootstrap-5 .select2-dropdown {
            border-radius: 10px;
            border: 2px solid #495057;
        }
        
        .select2-container--bootstrap-5 .select2-results__option--highlighted {
            background-color: #495057;
        }
        
        .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 8px 12px;
        }
        
        .select2-container--bootstrap-5 .select2-search--dropdown .select2-search__field:focus {
            border-color: #495057;
            box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25);
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/navbar.php'; ?>

    <main>
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="page-title">Activity Permit</h2>
                <p class="page-subtitle">Generate activity permits for organizations and councils</p>
            </div>
            <div>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#settingsModal">
                    <i class="bi bi-gear me-2"></i>Settings
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="form-container">
            <h3 class="form-title">
                <i class="bi bi-calendar-event me-2"></i>
                Activity Permit
            </h3>
            
            <form method="POST" id="qfForm">
                <input type="hidden" name="form_type" value="activity_permit">
                
                <!-- Target Selection (Organization or Council) -->
                <div class="mb-3">
                    <label class="form-label">Permit For *</label>
                    <div class="d-flex gap-3 mb-2">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="target_type" id="targetOrg" value="organization" checked>
                            <label class="form-check-label" for="targetOrg">Organization</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="target_type" id="targetCouncil" value="council">
                            <label class="form-check-label" for="targetCouncil">Council</label>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3" id="orgSelectGroup">
                    <label for="org_id" class="form-label">Select Organization *</label>
                    <select class="form-select" id="org_id" name="target_id" required>
                        <option value="">Select an organization...</option>
                        <?php foreach ($organizations as $org): ?>
                            <option value="<?php echo $org['id']; ?>"><?php echo htmlspecialchars($org['org_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3" id="councilSelectGroup" style="display: none;">
                    <label for="council_id" class="form-label">Select Council *</label>
                    <select class="form-select" id="council_id" name="target_id_council">
                        <option value="">Select a council...</option>
                        <?php foreach ($councils as $council): ?>
                            <option value="<?php echo $council['id']; ?>"><?php echo htmlspecialchars($council['council_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="activity_type" class="form-label">Activity Type *</label>
                    <select class="form-select" id="activity_type" name="activity_type" required>
                        <option value="">Select activity type...</option>
                        <option value="Seminar">Seminar</option>
                        <option value="Workshop">Workshop</option>
                        <option value="Training">Training</option>
                        <option value="Meeting">Meeting</option>
                        <option value="Event">Event</option>
                        <option value="Other">Other</option>
                    </select>
                    <div id="activity_type_other_group" class="mt-2" style="display:none;">
                        <input type="text" class="form-control" id="activity_type_other" name="activity_type_other" placeholder="Specify activity type" pattern="^[A-Za-z ]+$" maxlength="100">
                        <div class="form-text">Only letters and spaces are allowed.</div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="activity_date_start" class="form-label">Start Date *</label>
                            <input type="date" class="form-control" id="activity_date_start" name="activity_date_start" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="activity_date_end" class="form-label">End Date *</label>
                            <input type="date" class="form-control" id="activity_date_end" name="activity_date_end" required>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="activity_time_start" class="form-label">Start Time *</label>
                            <select class="form-select" id="activity_time_start" name="activity_time_start" required>
                                <option value="">Select start time...</option>
                                <optgroup label="Morning (7:00 AM - 11:00 AM)">
                                    <option value="07:00">7:00 AM</option>
                                    <option value="07:30">7:30 AM</option>
                                    <option value="08:00">8:00 AM</option>
                                    <option value="08:30">8:30 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="09:30">9:30 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                </optgroup>
                                <optgroup label="Afternoon (1:00 PM - 5:00 PM)">
                                    <option value="13:00">1:00 PM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="14:30">2:30 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="15:30">3:30 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="16:30">4:30 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="activity_time_end" class="form-label">End Time *</label>
                            <select class="form-select" id="activity_time_end" name="activity_time_end" required>
                                <option value="">Select end time...</option>
                                <optgroup label="Morning (7:00 AM - 11:00 AM)">
                                    <option value="07:00">7:00 AM</option>
                                    <option value="07:30">7:30 AM</option>
                                    <option value="08:00">8:00 AM</option>
                                    <option value="08:30">8:30 AM</option>
                                    <option value="09:00">9:00 AM</option>
                                    <option value="09:30">9:30 AM</option>
                                    <option value="10:00">10:00 AM</option>
                                    <option value="10:30">10:30 AM</option>
                                    <option value="11:00">11:00 AM</option>
                                </optgroup>
                                <optgroup label="Afternoon (1:00 PM - 5:00 PM)">
                                    <option value="13:00">1:00 PM</option>
                                    <option value="13:30">1:30 PM</option>
                                    <option value="14:00">2:00 PM</option>
                                    <option value="14:30">2:30 PM</option>
                                    <option value="15:00">3:00 PM</option>
                                    <option value="15:30">3:30 PM</option>
                                    <option value="16:00">4:00 PM</option>
                                    <option value="16:30">4:30 PM</option>
                                    <option value="17:00">5:00 PM</option>
                                </optgroup>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="activity_venue" class="form-label">Venue *</label>
                    <input type="text" class="form-control" id="activity_venue" name="activity_venue" required>
                </div>
                
                <div class="mb-3">
                    <label for="student_count" class="form-label">Number of Students *</label>
                    <input type="number" class="form-control" id="student_count" name="student_count" min="1" required>
                </div>

                <div class="d-flex gap-2 justify-content-center">
                    <button type="submit" name="action" value="open_preview_modal" class="btn btn-primary">
                        <i class="bi bi-eye me-2"></i>Preview & Download
                    </button>
                    <button type="reset" class="btn btn-secondary">Reset</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Preview Modal -->
    <div class="modal fade" id="submissionPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Activity Permit Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="height: 80vh; padding: 0;">
                    <div class="pdf-container" style="height:100%; margin:0; border-radius:0;">
                        <div id="qf-pdf-viewer" class="pdf-viewer-container" style="height:100%;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="generate_qf_pdf.php?preview=1&download=1" class="btn btn-success">
                        <i class="bi bi-download me-2"></i>Download PDF
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Settings Modal -->
    <div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="modal-header">
                        <h5 class="modal-title" id="settingsModalLabel">
                            <i class="bi bi-gear me-2"></i>Activity Permit Settings
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-4">Configure the officials' names and positions that appear on the Activity Permit PDF.</p>
                        
                        <h6 class="fw-bold mb-3"><i class="bi bi-person-check me-2"></i>Recommending Approval</h6>
                        <div class="mb-3">
                            <label for="recommending_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="recommending_name" name="recommending_name" 
                                   value="<?php echo htmlspecialchars($officials['recommending_name']); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label for="recommending_position" class="form-label">Position</label>
                            <input type="text" class="form-control" id="recommending_position" name="recommending_position" 
                                   value="<?php echo htmlspecialchars($officials['recommending_position']); ?>" required>
                        </div>
                        
                        <h6 class="fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Approved By</h6>
                        <div class="mb-3">
                            <label for="approved_name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="approved_name" name="approved_name" 
                                   value="<?php echo htmlspecialchars($officials['approved_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="approved_position" class="form-label">Position</label>
                            <input type="text" class="form-control" id="approved_position" name="approved_position" 
                                   value="<?php echo htmlspecialchars($officials['approved_position']); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/js/pdf-viewer.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const orgSelectGroup = document.getElementById('orgSelectGroup');
            const councilSelectGroup = document.getElementById('councilSelectGroup');
            const orgSelect = document.getElementById('org_id');
            const councilSelect = document.getElementById('council_id');
            
            // Initialize Select2 on organization and council dropdowns
            $('#org_id').select2({
                theme: 'bootstrap-5',
                placeholder: 'Search for an organization...',
                allowClear: true,
                width: '100%'
            });
            
            $('#council_id').select2({
                theme: 'bootstrap-5',
                placeholder: 'Search for a council...',
                allowClear: true,
                width: '100%'
            });
            
            // Toggle between organization and council selection
            document.querySelectorAll('input[name="target_type"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    if (this.value === 'organization') {
                        orgSelectGroup.style.display = 'block';
                        councilSelectGroup.style.display = 'none';
                        orgSelect.name = 'target_id';
                        orgSelect.required = true;
                        councilSelect.name = 'target_id_council';
                        councilSelect.required = false;
                        // Clear the council selection when switching
                        $('#council_id').val('').trigger('change');
                    } else {
                        orgSelectGroup.style.display = 'none';
                        councilSelectGroup.style.display = 'block';
                        councilSelect.name = 'target_id';
                        councilSelect.required = true;
                        orgSelect.name = 'target_id_org';
                        orgSelect.required = false;
                        // Clear the organization selection when switching
                        $('#org_id').val('').trigger('change');
                    }
                });
            });
            
            // Handle Activity Type 'Other' toggle
            const activityTypeSelect = document.getElementById('activity_type');
            const activityTypeOtherGroup = document.getElementById('activity_type_other_group');
            const activityTypeOtherInput = document.getElementById('activity_type_other');
            
            activityTypeSelect.addEventListener('change', function() {
                if (this.value === 'Other') {
                    activityTypeOtherGroup.style.display = 'block';
                    activityTypeOtherInput.required = true;
                } else {
                    activityTypeOtherGroup.style.display = 'none';
                    activityTypeOtherInput.required = false;
                    activityTypeOtherInput.value = '';
                }
            });
            
            // Date range validation - End date must be >= Start date
            const startDateInput = document.getElementById('activity_date_start');
            const endDateInput = document.getElementById('activity_date_end');
            
            // Set minimum date to today (using user's local timezone)
            const today = new Date();
            const year = today.getFullYear();
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const day = String(today.getDate()).padStart(2, '0');
            const todayStr = `${year}-${month}-${day}`;
            startDateInput.min = todayStr;
            endDateInput.min = todayStr;
            
            startDateInput.addEventListener('change', function() {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            });
            
            // Capitalize each word function
            function capitalizeWords(str) {
                return str.replace(/\b\w/g, char => char.toUpperCase());
            }
            
            // Auto-capitalize Venue field
            const venueInput = document.getElementById('activity_venue');
            venueInput.addEventListener('input', function() {
                const cursorPos = this.selectionStart;
                this.value = capitalizeWords(this.value);
                this.setSelectionRange(cursorPos, cursorPos);
            });
            
            // Auto-capitalize Activity Type Other field
            activityTypeOtherInput.addEventListener('input', function() {
                const cursorPos = this.selectionStart;
                this.value = capitalizeWords(this.value);
                this.setSelectionRange(cursorPos, cursorPos);
            });
            
            // Auto-uppercase Settings name fields (all letters capitalized)
            const recommendingNameInput = document.getElementById('recommending_name');
            const approvedNameInput = document.getElementById('approved_name');
            
            recommendingNameInput.addEventListener('input', function() {
                const cursorPos = this.selectionStart;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(cursorPos, cursorPos);
            });
            
            approvedNameInput.addEventListener('input', function() {
                const cursorPos = this.selectionStart;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(cursorPos, cursorPos);
            });
            
            // Show modal if flag is set
            <?php if ($showModal): ?>
            const modal = new bootstrap.Modal(document.getElementById('submissionPreviewModal'));
            modal.show();
            
            // Load PDF preview
            const container = document.getElementById('qf-pdf-viewer');
            if (container) {
                const viewer = new CustomPDFViewer(container, {
                    showToolbar: true,
                    enableZoom: true,
                    enableDownload: true,
                    enablePrint: true
                });
                viewer.loadDocument('generate_qf_pdf.php?preview=1');
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>


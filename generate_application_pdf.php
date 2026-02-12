<?php
require_once 'config/database.php';
require_once 'phpspreadsheet/vendor/autoload.php';

use Mpdf\Mpdf;

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if this is a preview request
if (isset($_GET['preview']) && $_GET['preview'] == '1') {
    if (!isset($_SESSION['application_form_preview'])) {
        // Log the error for debugging
        error_log('Application PDF Generation Error: No preview data available in session');
        die('No preview data available');
    }
    
    $formData = $_SESSION['application_form_preview'];
} else {
    // Check for form type parameter
    $formType = $_GET['type'] ?? '';
    
    if ($formType === 'council') {
        if (!isset($_SESSION['council_form_data'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No council form data found. Please complete the council form first.']);
            exit();
        }
        $formData = $_SESSION['council_form_data'];
    } elseif ($formType === 'organization') {
        if (!isset($_SESSION['organization_form_data'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No organization form data found. Please complete the organization form first.']);
            exit();
        }
        $formData = $_SESSION['organization_form_data'];
    } else {
        // Legacy support - check old session key
        if (!isset($_SESSION['application_form_preview'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No application form data found. Please complete the form first.']);
            exit();
        }
        $formData = $_SESSION['application_form_preview'];
    }
}

// Create mPDF instance
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 20,
    'margin_right' => 20,
    'margin_top' => 20,
    'margin_bottom' => 20
]);

// Set document properties
$mpdf->SetTitle('Organization Registration Application Form');
$mpdf->SetAuthor('Academic Organization System');
$mpdf->SetCreator('CvSU Academic Organization System');

// Generate HTML content based on form type
$html = '';

if ($formData['form_type'] === 'council_registration') {
    $html = generateCouncilRegistrationHTML($formData);
} elseif ($formData['form_type'] === 'organization_registration') {
    $html = generateOrganizationRegistrationHTML($formData);
}

// Write HTML to PDF
$mpdf->WriteHTML($html);

// Generate filename based on form type
if ($formData['form_type'] === 'council_registration') {
    $formTypeName = 'Council';
    $specificName = str_replace(' ', '_', $formData['council_code'] ?? 'Council');
    $filename = 'Council_Registration_Application_' . $specificName . '_' . date('Y-m-d_H-i-s') . '.pdf';
} else {
    $formTypeName = 'Organization';
    $specificName = str_replace(' ', '_', $formData['org_code'] ?? 'Organization');
    $filename = 'Organization_Registration_Application_' . $specificName . '_' . date('Y-m-d_H-i-s') . '.pdf';
}

// Output PDF
if (isset($_GET['preview']) && $_GET['preview'] == '1') {
    $mpdf->Output($filename, 'I'); // inline for modal preview
} else {
    $mpdf->Output($filename, 'D'); // download
    // Clear appropriate session data after download
    if ($formType === 'council') {
        unset($_SESSION['council_form_data']);
    } elseif ($formType === 'organization') {
        unset($_SESSION['organization_form_data']);
    } else {
        unset($_SESSION['application_form_preview']); // legacy
    }
}

function generateCouncilRegistrationHTML($formData) {
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                font-size: 11px;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }
        .header {
            text-align: center;
            margin-bottom: 18px;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
        }
            .logo {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 6px;
            }
            .title {
                font-size: 14px;
                font-weight: bold;
                margin-bottom: 4px;
            }
            .subtitle {
                font-size: 12px;
                margin-bottom: 6px;
            }
            .form-section {
                margin-bottom: 15px;
            }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            background-color: #f5f5f5;
            padding: 8px 10px;
            margin-bottom: 10px;
            border-left: 4px solid #000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
            .form-row {
                display: flex;
                margin-bottom: 10px;
            }
            .form-group {
                flex: 1;
                margin-right: 15px;
            }
            .form-group:last-child {
                margin-right: 0;
            }
            .label {
                font-weight: bold;
                margin-bottom: 4px;
                display: block;
                font-size: 10px;
            }
            .value {
                border-bottom: 1px solid #000;
                padding: 4px 0;
                min-height: 18px;
            }
            .signature-section {
                margin-top: 25px;
                display: flex;
                justify-content: space-between;
            }
            .signature-box {
                width: 180px;
                text-align: center;
            }
            .signature-line {
                border-bottom: 1px solid #000;
                height: 32px;
                margin-bottom: 6px;
            }
            .date-section {
                margin-top: 18px;
                text-align: right;
            }
            .footer {
                margin-top: 25px;
                text-align: center;
                font-size: 9px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo">
                <img src="assets/img/cvsu_logo.png" alt="CvSU Logo" style="height: 50px; width: auto;">
            </div>
            <div class="title">CAVITE STATE UNIVERSITY</div>
            <div class="subtitle">STUDENT COUNCIL REGISTRATION APPLICATION</div>
            <div class="subtitle">Office of Student Affairs and Services (OSAS)</div>
        </div>

        <div class="form-section">
            <div class="section-title">COUNCIL INFORMATION</div>
            
            <div class="form-row">
                <div class="form-group">
                    <span class="label">Council Code:</span>
                    <div class="value">' . htmlspecialchars($formData['council_code']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">Council Name:</span>
                    <div class="value">' . htmlspecialchars($formData['council_name']) . '</div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title">COUNCIL OFFICERS</div>
            
            <div class="form-row">
                <div class="form-group">
                    <span class="label">President Name:</span>
                    <div class="value">' . htmlspecialchars($formData['president_name']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">President Email:</span>
                    <div class="value">' . htmlspecialchars($formData['president_email']) . '</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <span class="label">Adviser Name:</span>
                    <div class="value">' . htmlspecialchars($formData['adviser_name']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">Adviser Email:</span>
                    <div class="value">' . htmlspecialchars($formData['adviser_email']) . '</div>
                </div>
            </div>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <div class="value">' . htmlspecialchars($formData['president_name']) . '</div>
                <div>President Signature</div>
            </div>
            <br>
            <div class="signature-box">
                <div class="value">' . htmlspecialchars($formData['adviser_name']) . '</div>
                <div>Adviser Signature</div>
            </div>
        </div>

        <div class="date-section">
            <div class="form-group">
                <span class="label">Date Generated:</span>
                <div class="value">' . date('F d, Y') . '</div>
            </div>
        </div>

        <div class="footer">
            <p>This application form must be printed and submitted to the Office of Student Affairs and Services (OSAS) for processing.</p>
            <p>For inquiries, please contact OSAS at osas@cvsu.edu.ph</p>
        </div>
    </body>
    </html>';
}

function generateOrganizationRegistrationHTML($formData) {
    // Get college and course names
    global $conn;
    
    $collegeStmt = $conn->prepare('SELECT name FROM colleges WHERE id = ?');
    $collegeStmt->bind_param('i', $formData['college_id']);
    $collegeStmt->execute();
    $collegeResult = $collegeStmt->get_result();
    $college = $collegeResult->fetch_assoc();
    
    $courseStmt = $conn->prepare('SELECT name FROM courses WHERE id = ?');
    $courseStmt->bind_param('i', $formData['course_id']);
    $courseStmt->execute();
    $courseResult = $courseStmt->get_result();
    $course = $courseResult->fetch_assoc();

    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body {
                font-family: Arial, sans-serif;
                font-size: 11px;
                line-height: 1.4;
                margin: 0;
                padding: 0;
            }
        .header {
            text-align: center;
            margin-bottom: 18px;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
        }
            .logo {
                font-size: 16px;
                font-weight: bold;
                margin-bottom: 6px;
            }
            .title {
                font-size: 14px;
                font-weight: bold;
                margin-bottom: 4px;
            }
            .subtitle {
                font-size: 12px;
                margin-bottom: 6px;
            }
            .form-section {
                margin-bottom: 15px;
            }
        .section-title {
            font-size: 13px;
            font-weight: bold;
            background-color: #f5f5f5;
            padding: 8px 10px;
            margin-bottom: 10px;
            border-left: 4px solid #000;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
            .form-row {
                display: flex;
                margin-bottom: 10px;
            }
            .form-group {
                flex: 1;
                margin-right: 15px;
            }
            .form-group:last-child {
                margin-right: 0;
            }
            .label {
                font-weight: bold;
                margin-bottom: 4px;
                display: block;
                font-size: 10px;
            }
            .value {
                border-bottom: 1px solid #000;
                padding: 4px 0;
                min-height: 18px;
            }
            .signature-section {
                margin-top: 25px;
                display: flex;
                justify-content: space-between;
            }
            .signature-box {
                width: 180px;
                text-align: center;
            }
            .signature-line {
                border-bottom: 1px solid #000;
                height: 32px;
                margin-bottom: 6px;
            }
            .date-section {
                margin-top: 18px;
                text-align: right;
            }
            .footer {
                margin-top: 25px;
                text-align: center;
                font-size: 9px;
                color: #666;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo">
                <img src="assets/img/cvsu_logo.png" alt="CvSU Logo" style="height: 50px; width: auto;">
            </div>
            <div class="title">CAVITE STATE UNIVERSITY</div>
            <div class="subtitle">ACADEMIC ORGANIZATION REGISTRATION APPLICATION</div>
            <div class="subtitle">Office of Student Affairs and Services (OSAS)</div>
        </div>

        <div class="form-section">
            <div class="section-title">ORGANIZATION INFORMATION</div>
            
            <div class="form-row">
                <div class="form-group">
                    <span class="label">Organization Code:</span>
                    <div class="value">' . htmlspecialchars($formData['org_code']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">Organization Name:</span>
                    <div class="value">' . htmlspecialchars($formData['org_name']) . '</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <span class="label">College:</span>
                    <div class="value">' . htmlspecialchars($college['name']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">Course:</span>
                    <div class="value">' . htmlspecialchars($course['name']) . '</div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="section-title">ORGANIZATION OFFICERS</div>
            
            <div class="form-row">
                <div class="form-group">
                    <span class="label">President Name:</span>
                    <div class="value">' . htmlspecialchars($formData['president_name']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">President Email:</span>
                    <div class="value">' . htmlspecialchars($formData['president_email']) . '</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <span class="label">Adviser Name:</span>
                    <div class="value">' . htmlspecialchars($formData['adviser_name']) . '</div>
                </div>
                <div class="form-group">
                    <span class="label">Adviser Email:</span>
                    <div class="value">' . htmlspecialchars($formData['adviser_email']) . '</div>
                </div>
            </div>
        </div>

        <div class="signature-section">
            <div class="signature-box">
                <div class="value">' . htmlspecialchars($formData['president_name']) . '</div>
                <div>President Signature</div>
            </div>
            <br>
            <div class="signature-box">
                <div class="value">' . htmlspecialchars($formData['adviser_name']) . '</div>
                <div>Adviser Signature</div>
            </div>
        </div>

        <div class="date-section">
            <div class="form-group">
                <span class="label">Date Generated:</span>
                <div class="value">' . date('F d, Y') . '</div>
            </div>
        </div>

        <div class="footer">
            <p>This application form must be printed and submitted to the Office of Student Affairs and Services (OSAS) for processing.</p>
            <p>For inquiries, please contact OSAS at osas@cvsu.edu.ph</p>
        </div>
    </body>
    </html>';
}
?>
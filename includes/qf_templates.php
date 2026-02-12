<?php
require_once __DIR__ . '/settings_helper.php';

function generateAcceptanceLetterHTML($formData, $userName, $ownerName) {
    $academicYear = date('Y') . '-' . (date('Y') + 1);
    $logoPath = 'assets/img/cvsu_logo.png';
    
    // Use adviser name from form data (already retrieved based on current user's role)
    $adviserName = $formData['adviser_name'] ?? '';
    
    $adviserUpper = strtoupper(trim($adviserName));
    $orgName = trim($formData['organization_name'] ?? $ownerName ?? '');
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 2.0; margin: 30px; }
            .top-code { position: absolute; top: 20px; right: 30px; font-size: 10pt; font-family: "Times New Roman", serif; }
            /* Header with floating logo - logo floats left, text remains centered */
            .header { 
                margin-top: 20px; 
                position: relative;
                min-height: 90px;
            }
            .logo { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 120px;
                height: 90px;
                margin-left: 11.5%;
                z-index: 1;
            }
            .header-text { 
                text-align: center; 
                line-height: 1.2;
                /* Ensure text is not affected by logo positioning */
                position: relative;
                margin-top: -11.5%;
                z-index: 2;
            }
            .rp { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
            .uni { font-weight: bold; font-size: 12pt; font-family: "Bookman Old Style", serif; }
            .campus { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
            .campus-bold { font-weight: bold; }
            .title { text-align: center; font-weight: bold; margin: 25px 0 3px 0; text-transform: uppercase; font-size: 14pt; font-family: "Times New Roman", serif; }
            .content { text-align: justify; font-family: "Times New Roman", serif; line-height: 2.5; }
            .indent { text-indent: 36px; }
            .line-long { display: inline-block; border-bottom: 1px solid #000; width: 220px; height: 14px; vertical-align: bottom; }
            .line-org { display: inline-block; border-bottom: 1px solid #000; width: 360px; height: 14px; vertical-align: bottom; }
            .micro { display: inline; font-size: 12pt; font-weight: bold; text-decoration: underline; font-family: "Times New Roman", serif; }
            .sig { margin-top: 42px; font-family: "Times New Roman", serif; }
            .sig-line { width: 215px; border-bottom: 1px solid #000000; height: 0px; }
            .sig-name { text-align: left; font-size: 12pt; font-weight: bold; margin: 6px 0 0px 0px; font-family: "Times New Roman", serif; }
            .sig-label { font-size: 12pt; margin: 0px 0 0px 0; font-family: "Times New Roman", serif; }
            .footer-ver { position: fixed; bottom: 20px; right: 30px; font-size: 8pt; font-family: "Times New Roman", serif; }
        </style>
    </head>
    <body>
        <div class="top-code">OSAS-QF- 21</div>
        <div class="header">
            <img src="' . $logoPath . '" class="logo" />
            <div class="header-text">
                <div class="rp">Republic of the Philippines</div>
                <div class="uni">CAVITE STATE UNIVERSITY</div>
                <div class="campus"><span class="campus-bold">Don Severino delas Alas Campus</span><br/>Indang, Cavite</div>
            </div>
        </div>
        <div class="title">ACCEPTANCE LETTER</div>
        <div class="content">
            <p class="indent">I, <span class="micro">' . htmlspecialchars($adviserUpper) . '</span>, hereby signify my willingness to serve the studentry as Adviser of <span class="micro">' . htmlspecialchars($orgName) . '</span> for the academic year <span class="micro">' . htmlspecialchars($academicYear) . '</span>. I fully understand my responsibility as adviser to be actively involved in the - preparation of activities and be present in all approved activities of the organization; I will be accountable for any violation committed by the organization; and I will perform other tasks as may be required by the Dean of Student Affairs and Services and the President of this University.</p>
        </div>
        <div class="sig">
            <div class="sig-name">' . htmlspecialchars($adviserUpper) . '</div>
            <div class="sig-line"></div>
            <div class="sig-label">Signature over printed name</div>
        </div>
        <div class="footer-ver">vxx-yyyy-mm-dd</div>
    </body>
    </html>';
}

function generateActivityPermitHTML($formData, $userName, $ownerName, $conn = null) {
    $activityName = $formData['activity_name'] ?? '';
    $activityType = $formData['activity_type'] ?? '';
    $activityDateStart = $formData['activity_date_start'] ?? '';
    $activityDateEnd = $formData['activity_date_end'] ?? '';
    $activityTimeStart = $formData['activity_time_start'] ?? '';
    $activityTimeEnd = $formData['activity_time_end'] ?? '';
    $activityVenue = $formData['activity_venue'] ?? '';
    $studentCount = $formData['student_count'] ?? '';
    $logoPath = 'assets/img/cvsu_logo.png';
    
    // Get officials from database (or use defaults if no connection)
    if ($conn) {
        $officials = getActivityPermitOfficials($conn);
    } else {
        $officials = [
            'recommending_name' => 'DENMARK A. GARCIA',
            'recommending_position' => 'Head, SDS',
            'approved_name' => 'SHARON M. ISIP',
            'approved_position' => 'Dean, OSAS'
        ];
    }
    
    // Format date range
    if ($activityDateStart && $activityDateEnd) {
        if ($activityDateStart === $activityDateEnd) {
            $formattedDate = date('F d, Y', strtotime($activityDateStart));
        } else {
            $formattedDate = date('F d, Y', strtotime($activityDateStart)) . ' - ' . date('F d, Y', strtotime($activityDateEnd));
        }
    } else {
        $formattedDate = $activityDateStart ? date('F d, Y', strtotime($activityDateStart)) : '';
    }
    
    // Format time range
    if ($activityTimeStart && $activityTimeEnd) {
        $formattedTime = date('g:i A', strtotime($activityTimeStart)) . ' - ' . date('g:i A', strtotime($activityTimeEnd));
    } else {
        $formattedTime = $activityTimeStart ? date('g:i A', strtotime($activityTimeStart)) : '';
    }
    
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; font-size: 11pt; line-height: 2.0; margin: 30px; }
            .top-code { position: absolute; top: 20px; right: 30px; font-size: 10pt; font-family: Arial, sans-serif; }
            /* Header with floating logo - logo floats left, text remains centered */
            .header { 
                margin-top: 20px; 
                position: relative;
                min-height: 90px;
            }
            .logo { 
                position: absolute; 
                left: 0; 
                top: 0; 
                width: 120px;
                height: 90px;
                margin-left: 11.5%;
                z-index: 1;
            }
            .header-text { 
                text-align: center; 
                line-height: 1.2;
                /* Ensure text is not affected by logo positioning */
                position: relative;
                margin-top: -11.5%;
                z-index: 2;
            }
            /* Match Acceptance Letter header typography */
            .rp { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
            .uni { font-weight: bold; font-size: 12pt; font-family: "Bookman Old Style", serif; }
            .campus { font-size: 10pt; font-family: "Century Gothic", sans-serif; }
            .campus-bold { font-weight: bold; }
            /* From title down to end: Times New Roman, size 12, line-height 2.5 */
            .title { text-align: center; font-weight: bold; margin: 22px 0 6px 0; text-transform: uppercase; font-size: 14pt; font-family: "Times New Roman", serif; }
            .date-table-wrap { text-align: right; margin: 6px 0 12px 0; }
            .date-table { border-collapse: collapse; display: inline-table; float: right; margin-left: auto; }
            .date-table td { font-family: "Times New Roman", serif; font-size: 12pt; line-height: 0; padding: 0; text-align: center; }
            .date-underline { display: inline-block; min-width: 160px; border-bottom: 1px solid #000; padding-bottom: 2px; }
            .content { text-align: justify; font-family: "Times New Roman", serif; line-height: 2.0; font-size: 12pt; }
            .indent { text-indent: 36px; }
            .long-line { display: inline-block; border-bottom: 1px solid #000; width: 420px; height: 14px; vertical-align: bottom; }
            .details { margin: 18px 0; font-family: "Times New Roman", serif; }
            .detail-item { margin-left: 54px; margin-top: 8px; }
            .dlabel { display: inline-block; width: 180px; font-family: "Times New Roman", serif; }
            .dline { display: inline-block; border-bottom: 1px solid #000; width: 200px; height: 14px; vertical-align: bottom; }
            .approvals { display: flex; justify-content: space-between; margin-top: 36px; font-family: "Times New Roman", serif; }
            .box { width: 48%; }
            .small { font-size: 10pt; font-family: "Times New Roman", serif; }
            .name { margin-top: 26px; font-weight: bold; font-family: "Times New Roman", serif; }
            .pos { font-size: 10pt; font-family: "Times New Roman", serif; }
            .footer-ver { position: fixed; bottom: 20px; right: 30px; font-size: 8pt; font-family: "Times New Roman", serif; }
            .approvals-table { width: 100%; margin-top: 36px; border-collapse: collapse; }
            .approvals-table td { width: 50%; vertical-align: top; padding: 4px 8px; font-family: "Times New Roman", serif; font-size: 12pt; line-height: 0; }
        </style>
    </head>
    <body>
        <div class="top-code">OSAS-QF- 24</div>
        <div class="header">
            <img src="' . $logoPath . '" class="logo" />
            <div class="header-text">
                <div class="rp">Republic of the Philippines</div>
                <div class="uni">CAVITE STATE UNIVERSITY</div>
                <div class="campus"><span class="campus-bold">Don Severino delas Alas Campus</span><br/>Indang, Cavite</div>
            </div>
        </div>
        <div class="title">ACTIVITY PERMIT</div>
        <div class="date-table-wrap">
            <table class="date-table">
                <tr><td><span class="date-underline">' . htmlspecialchars($formattedDate) . '</span></td></tr>
                <tr><td>Date</td></tr>
            </table>
        </div>
        <div class="content">
            <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
            <p class="indent">This is to authorize <span class="long-line">' . htmlspecialchars($ownerName) . '</span> to conduct a/an/the <span class="long-line">' . htmlspecialchars($activityType) . '</span>.</p>
            <div class="details">
                <div>Details of the said activity as approved are as follows:</div>
                <div class="detail-item"><span class="dlabel">Date(s):</span> <span class="dline">' . htmlspecialchars($formattedDate) . '</span></div>
                <div class="detail-item"><span class="dlabel">Time:</span> <span class="dline">' . htmlspecialchars($formattedTime) . '</span></div>
                <div class="detail-item"><span class="dlabel">Venue(s):</span> <span class="dline">' . htmlspecialchars($activityVenue) . '</span></div>
                <div class="detail-item"><span class="dlabel">No. of Students involved:</span> <span class="dline">' . htmlspecialchars($studentCount) . '</span></div>
            </div>
            <p class="indent">As a matter of policy, the unit organization class concerned is required to submit to this office an <strong>Activity Accomplishment</strong> or <strong>Financial Report</strong> of the said activity within one week after the activity. Request for approval of another activity shall only be granted upon submission of the paper reports specified above.</p>
            <p class="indent">This permit is valid up to date indicated above. In case of postponement, the requesting party must inform the office for the issuance of a new permit.</p>
        </div>
        <table class="approvals-table">
            <tr>
                <td><div class="small">Recommending Approval:</div></td>
                <td><div class="small">Approved:</div></td>
            </tr>
            <tr>
                <td style="height: 40px;"></td>
                <td style="height: 40px;"></td>
            </tr>
            <tr>
                <td><div class="name">' . htmlspecialchars($officials['recommending_name']) . '</div></td>
                <td><div class="name">' . htmlspecialchars($officials['approved_name']) . '</div></td>
            </tr>
            <tr>
                <td><div class="pos">' . htmlspecialchars($officials['recommending_position']) . '</div></td>
                <td><div class="pos">' . htmlspecialchars($officials['approved_position']) . '</div></td>
            </tr>
        </table>
        <div class="footer-ver">vxx-yyyy-mm-dd</div>
    </body>
    </html>';
}

?>


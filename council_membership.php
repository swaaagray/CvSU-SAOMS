<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_president']);

$userRole = getUserRole();
$collegeId = null;

// For Council President, get the college ID from their council
$councilId = getCurrentCouncilId();
if ($councilId) {
    $stmt = $conn->prepare("SELECT college_id FROM council WHERE id = ?");
    $stmt->bind_param("i", $councilId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $collegeId = $row['college_id'];
    }
    $stmt->close();
}

if (!$collegeId) {
    die('College not found');
}

// Get selected filters from GET
$selected_course = $_GET['course'] ?? '';
$selected_section = $_GET['section'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Fetch unique courses for dropdown - only for council
$courseSql = "SELECT DISTINCT course FROM student_data sd 
              JOIN organizations o ON sd.organization_id = o.id 
              WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member') ORDER BY course ASC";
$courseStmt = $conn->prepare($courseSql);
$courseStmt->bind_param("i", $collegeId);
$courseStmt->execute();
$courseResult = $courseStmt->get_result();
$courses = [];
while ($row = $courseResult->fetch_assoc()) {
    $courses[] = $row['course'];
}
$courseStmt->close();

// Fetch unique sections for dropdown - only for council
// If a specific course is selected, only show sections for that course
$sectionSql = "SELECT DISTINCT section FROM student_data sd 
               JOIN organizations o ON sd.organization_id = o.id 
               WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member')";
$sectionParams = [$collegeId];
$sectionTypes = "i";

if ($selected_course !== '' && $selected_course !== 'All') {
    $sectionSql .= " AND sd.course = ?";
    $sectionParams[] = $selected_course;
    $sectionTypes .= "s";
}

$sectionSql .= " ORDER BY section ASC";

$sectionStmt = $conn->prepare($sectionSql);
$sectionStmt->bind_param($sectionTypes, ...$sectionParams);
$sectionStmt->execute();
$sectionResult = $sectionStmt->get_result();
$sections = [];
while ($row = $sectionResult->fetch_assoc()) {
    $sections[] = $row['section'];
}
$sectionStmt->close();

// Reset section to "All" if course changed and current section is not available in new course
if ($selected_course !== '' && $selected_course !== 'All' && $selected_section !== '' && $selected_section !== 'All') {
    $checkSectionSql = "SELECT COUNT(*) FROM student_data sd 
                       JOIN organizations o ON sd.organization_id = o.id 
                       WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member') 
                       AND sd.course = ? AND sd.section = ?";
    $checkStmt = $conn->prepare($checkSectionSql);
    $checkStmt->bind_param("iss", $collegeId, $selected_course, $selected_section);
    $checkStmt->execute();
    $checkStmt->bind_result($sectionExists);
    $checkStmt->fetch();
    $checkStmt->close();
    
    if ($sectionExists == 0) {
        $selected_section = 'All';
    }
}

// Build query with filters - only council logic
$query = "SELECT sd.id, sd.student_name, sd.student_number, sd.course, sd.sex, sd.section, sd.council_status as status 
          FROM student_data sd 
          JOIN organizations o ON sd.organization_id = o.id 
          WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member')";
$params = [$collegeId];
$types = "i";

if ($selected_section !== '' && $selected_section !== 'All') {
    $query .= " AND sd.section = ?";
    $params[] = $selected_section;
    $types .= "s";
}
if ($selected_course !== '' && $selected_course !== 'All') {
    $query .= " AND sd.course = ?";
    $params[] = $selected_course;
    $types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $query .= " AND sd.council_status = ?";
    $params[] = $selected_status;
    $types .= "s";
}
if ($search !== '') {
    $query .= " AND (sd.student_number LIKE ? OR sd.student_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}
$query .= " ORDER BY sd.student_name ASC";

// Pagination setup
$per_page = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Count total students for pagination (with filters) - only council logic
$count_query = "SELECT COUNT(*) FROM student_data sd 
                JOIN organizations o ON sd.organization_id = o.id 
                WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member')";
$count_params = [$collegeId];
$count_types = "i";

if ($selected_section !== '' && $selected_section !== 'All') {
    $count_query .= " AND sd.section = ?";
    $count_params[] = $selected_section;
    $count_types .= "s";
}
if ($selected_course !== '' && $selected_course !== 'All') {
    $count_query .= " AND sd.course = ?";
    $count_params[] = $selected_course;
    $count_types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $count_query .= " AND sd.council_status = ?";
    $count_params[] = $selected_status;
    $count_types .= "s";
}
if ($search !== '') {
    $count_query .= " AND (sd.student_number LIKE ? OR sd.student_name LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_types .= "ss";
}
$count_stmt = $conn->prepare($count_query);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$count_stmt->bind_result($total_students_all);
$count_stmt->fetch();
$count_stmt->close();

$offset = ($page - 1) * $per_page;

// Fetch students for current page
$query .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_pages = ceil($total_students_all / $per_page);

// Count totals for each status (for all filtered students, not just current page) - only council logic
$all_filtered_query = "SELECT sd.council_status FROM student_data sd 
                       JOIN organizations o ON sd.organization_id = o.id 
                       WHERE o.college_id = ? AND sd.council_status IN ('Member', 'Non-Member')";
$all_filtered_params = [$collegeId];
$all_filtered_types = "i";

if ($selected_section !== '' && $selected_section !== 'All') {
    $all_filtered_query .= " AND sd.section = ?";
    $all_filtered_params[] = $selected_section;
    $all_filtered_types .= "s";
}
if ($selected_course !== '' && $selected_course !== 'All') {
    $all_filtered_query .= " AND sd.course = ?";
    $all_filtered_params[] = $selected_course;
    $all_filtered_types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $all_filtered_query .= " AND sd.council_status = ?";
    $all_filtered_params[] = $selected_status;
    $all_filtered_types .= "s";
}
if ($search !== '') {
    $all_filtered_query .= " AND (sd.student_number LIKE ? OR sd.student_name LIKE ?)";
    $all_filtered_params[] = "%$search%";
    $all_filtered_params[] = "%$search%";
    $all_filtered_types .= "ss";
}
$all_filtered_stmt = $conn->prepare($all_filtered_query);
$all_filtered_stmt->bind_param($all_filtered_types, ...$all_filtered_params);
$all_filtered_stmt->execute();
$all_filtered_result = $all_filtered_stmt->get_result();
$total_member = 0;
$total_nonmember = 0;
while ($row = $all_filtered_result->fetch_assoc()) {
    if ($row['council_status'] === 'Member') $total_member++;
    if ($row['council_status'] === 'Non-Member') $total_nonmember++;
}
$all_filtered_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Council Membership Status</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
.status-dot {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    vertical-align: middle;
    margin-bottom: 2px;
}
.bg-success { background-color: #28a745 !important; }
.bg-danger { background-color: #dc3545 !important; }
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-title">Council Membership Status</h2>
            <p class="page-subtitle">Manage and track member and non-member students</p>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-center mb-0">
                <div class="col-auto">
                    <label for="course" class="form-label mb-0">Course:</label>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="course" name="course" onchange="this.form.submit()">
                        <option value="All"<?= ($selected_course === 'All' || $selected_course === '') ? ' selected' : '' ?>>All</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>"<?= ($selected_course === $course) ? ' selected' : '' ?>><?= htmlspecialchars($course) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label for="section" class="form-label mb-0">Section:</label>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="section" name="section" onchange="this.form.submit()">
                        <option value="All"<?= ($selected_section === 'All' || $selected_section === '') ? ' selected' : '' ?>>All</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= htmlspecialchars($section) ?>"<?= ($selected_section === $section) ? ' selected' : '' ?>><?= htmlspecialchars($section) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label for="status" class="form-label mb-0">Status:</label>
                </div>
                <div class="col-auto">
                    <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                        <option value="All"<?= ($selected_status === 'All' || $selected_status === '') ? ' selected' : '' ?>>All</option>
                        <option value="Member"<?= ($selected_status === 'Member') ? ' selected' : '' ?>>Member</option>
                        <option value="Non-Member"<?= ($selected_status === 'Non-Member') ? ' selected' : '' ?>>Non-Member</option>
                    </select>
                </div>
                <!-- Single search bar aligned right -->
                <div class="col-auto ms-auto d-flex align-items-center">
                    <label for="search" class="form-label mb-0 me-2">Search:</label>
                    <input type="text" class="form-control mb-1" name="search" id="search" placeholder="Student Name or Number" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3" id="membership-totals">
                <div>
                    <form method="post" action="export_council_membership.php" target="_blank" style="display: inline;">
                        <input type="hidden" name="format" value="csv">
                        <input type="hidden" name="section" value="<?= htmlspecialchars($selected_section) ?>">
                        <input type="hidden" name="course" value="<?= htmlspecialchars($selected_course) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($selected_status) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
                    </form>
                    <form method="post" action="export_council_membership.php" target="_blank" style="display: inline;">
                        <input type="hidden" name="format" value="excel">
                        <input type="hidden" name="section" value="<?= htmlspecialchars($selected_section) ?>">
                        <input type="hidden" name="course" value="<?= htmlspecialchars($selected_course) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($selected_status) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm me-1"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                    </form>
                    <form method="post" action="export_council_membership.php" target="_blank" style="display: inline;">
                        <input type="hidden" name="format" value="pdf">
                        <input type="hidden" name="section" value="<?= htmlspecialchars($selected_section) ?>">
                        <input type="hidden" name="course" value="<?= htmlspecialchars($selected_course) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($selected_status) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                    </form>
                </div>
                <div class="ms-3">
                    <strong id="membership-summary">
                        Total: <span id="total-count"><?= $total_students_all ?></span> Students | 
                        <span class="status-dot bg-success"></span>
                        Member: <span id="member-count"><?= $total_member ?></span> | 
                        <span class="status-dot bg-danger"></span>
                        Non-Member: <span id="nonmember-count"><?= $total_nonmember ?></span>
                    </strong>
                </div>
            </div>
            <div id="table-container">
                <?php include 'council_membership_ajax.php'; ?>
            </div>
        </div>
    </div>
</div>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPage = 1;

function updateStatusCounts() {
    let member = 0, nonmember = 0;
    document.querySelectorAll('.status-radio:checked').forEach(function(radio) {
        if (radio.value === 'Member') member++;
        if (radio.value === 'Non-Member') nonmember++;
    });
    const total = member + nonmember;
    document.getElementById('membership-summary').innerHTML =
        'Total: <span id="total-count">' + total + '</span> Students | ' +
        '<span class="status-dot bg-success"></span> ' +
        'Member: <span id="member-count">' + member + '</span> | ' +
        '<span class="status-dot bg-danger"></span> ' +
        'Non-Member: <span id="nonmember-count">' + nonmember + '</span>';
}

function attachStatusRadioListeners() {
    document.querySelectorAll('.status-radio').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var studentId = this.getAttribute('data-id');
            var status = this.value;
            
            // Route to council endpoint
            fetch('update_council_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(studentId) + '&status=' + encodeURIComponent(status)
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Failed to update status: ' + (data.message || 'Unknown error'));
                } else {
                    updateStatusCounts();
                    // Reload the table while staying on current page
                    loadTable(currentPage);
                }
            })
            .catch(() => {
                alert('Failed to update status.');
            });
        });
    });
}

function updateTotalsFromAjax() {
    const totals = document.getElementById('ajax-totals');
    if (totals) {
        document.getElementById('membership-summary').innerHTML =
            'Total: <span id="total-count">' + totals.getAttribute('data-total') + '</span> Students | ' +
            '<span class="status-dot bg-success"></span> ' +
            'Member: <span id="member-count">' + totals.getAttribute('data-member') + '</span> | ' +
            '<span class="status-dot bg-danger"></span> ' +
            'Non-Member: <span id="nonmember-count">' + totals.getAttribute('data-nonmember') + '</span>';
    }
}

function loadTable(page = 1) {
    currentPage = page;
    const section = document.getElementById('section').value;
    const course = document.getElementById('course').value;
    const status = document.getElementById('status').value;
    const search = document.getElementById('search').value;
    const params = new URLSearchParams({
        section: section,
        course: course,
        status: status,
        page: page,
        search: search
    });
    fetch('council_membership_ajax.php?' + params.toString())
        .then(response => response.text())
        .then(html => {
            document.getElementById('table-container').innerHTML = html;
            attachAjaxPagination();
            attachStatusRadioListeners();
            updateStatusCounts();
            updateTotalsFromAjax();
        });
}

function attachAjaxPagination() {
    document.querySelectorAll('.ajax-page-link').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.parentElement.classList.contains('disabled') || this.parentElement.classList.contains('active')) return;
            const page = this.getAttribute('data-page');
            loadTable(page);
        });
    });
}

document.getElementById('section').addEventListener('change', function() {
    loadTable(1);
});
document.getElementById('course').addEventListener('change', function() {
    const selectedCourse = this.value;
    
    // Fetch sections for the selected course
    fetch('get_sections_by_course.php?course=' + encodeURIComponent(selectedCourse))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const sectionSelect = document.getElementById('section');
                const currentSection = sectionSelect.value;
                
                // Clear existing options except "All"
                sectionSelect.innerHTML = '<option value="All">All</option>';
                
                // Add new sections
                data.sections.forEach(function(section) {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    if (section === currentSection) {
                        option.selected = true;
                    }
                    sectionSelect.appendChild(option);
                });
                
                // If current section is not available in new course, reset to "All"
                if (currentSection !== 'All' && !data.sections.includes(currentSection)) {
                    sectionSelect.value = 'All';
                }
            }
            
            // Reload table with new filters
            loadTable(1);
        })
        .catch(error => {
            console.error('Error fetching sections:', error);
            // Reset section to "All" and reload table
            document.getElementById('section').value = 'All';
            loadTable(1);
        });
});
document.getElementById('status').addEventListener('change', function() {
    loadTable(1);
});
attachAjaxPagination();
attachStatusRadioListeners();

// In JS, add debounce and auto-search on input
let searchTimeout;
document.getElementById('search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        loadTable(1);
    }, 300); // 300ms debounce
});
</script>
</body>
</html>
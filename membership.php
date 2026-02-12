<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president']);

$orgId = getCurrentOrganizationId();

// Fetch unique sections for dropdown
$sectionSql = "SELECT DISTINCT section FROM student_data WHERE organization_id = ? ORDER BY section ASC";
$sectionStmt = $conn->prepare($sectionSql);
$sectionStmt->bind_param("i", $orgId);
$sectionStmt->execute();
$sectionResult = $sectionStmt->get_result();
$sections = [];
while ($row = $sectionResult->fetch_assoc()) {
    $sections[] = $row['section'];
}
$sectionStmt->close();

// Get selected filters from GET
$selected_section = $_GET['section'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query with filters
$query = "SELECT id, student_name, student_number, course, sex, section, org_status FROM student_data WHERE organization_id = ?";
$params = [$orgId];
$types = "i";
if ($selected_section !== '' && $selected_section !== 'All') {
    $query .= " AND section = ?";
    $params[] = $selected_section;
    $types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $query .= " AND org_status = ?";
    $params[] = $selected_status;
    $types .= "s";
}
if ($search !== '') {
    $query .= " AND (student_number LIKE ? OR student_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}
$query .= " ORDER BY student_name ASC";

// Pagination setup
$per_page = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Count total students for pagination (with filters)
$count_query = "SELECT COUNT(*) FROM student_data WHERE organization_id = ?";
$count_params = [$orgId];
$count_types = "i";
if ($selected_section !== '' && $selected_section !== 'All') {
    $count_query .= " AND section = ?";
    $count_params[] = $selected_section;
    $count_types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $count_query .= " AND org_status = ?";
    $count_params[] = $selected_status;
    $count_types .= "s";
}
if ($search !== '') {
    $count_query .= " AND (student_number LIKE ? OR student_name LIKE ?)";
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

// Count totals for each status (for all filtered students, not just current page)
$all_filtered_query = "SELECT org_status FROM student_data WHERE organization_id = ?";
$all_filtered_params = [$orgId];
$all_filtered_types = "i";
if ($selected_section !== '' && $selected_section !== 'All') {
    $all_filtered_query .= " AND section = ?";
    $all_filtered_params[] = $selected_section;
    $all_filtered_types .= "s";
}
if ($selected_status !== '' && $selected_status !== 'All') {
    $all_filtered_query .= " AND org_status = ?";
    $all_filtered_params[] = $selected_status;
    $all_filtered_types .= "s";
}
if ($search !== '') {
    $all_filtered_query .= " AND (student_number LIKE ? OR student_name LIKE ?)";
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
    if ($row['org_status'] === 'Member') $total_member++;
    if ($row['org_status'] === 'Non-Member') $total_nonmember++;
}
$all_filtered_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organization Membership Status</title>
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

/* Override pagination blue colors to gray */
.pagination .page-link {
    color: #6c757d !important;
    border-color: #dee2e6 !important;
    background-color: #fff !important;
}

.pagination .page-link:hover {
    color: #495057 !important;
    background-color: #e9ecef !important;
    border-color: #dee2e6 !important;
}

.pagination .page-item.active .page-link {
    background-color: #6c757d !important;
    border-color: #6c757d !important;
    color: #fff !important;
}

.pagination .page-item.disabled .page-link {
    color: #adb5bd !important;
    background-color: #fff !important;
    border-color: #dee2e6 !important;
    cursor: not-allowed;
}

.pagination .page-link:focus {
    box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
}

/* Override radio button blue colors to gray */
.status-radio {
    width: 18px !important;
    height: 18px !important;
    cursor: pointer;
    margin-right: 6px;
    accent-color: #6c757d !important;
    border: 2px solid #adb5bd !important;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    border-radius: 50% !important;
    background-color: #ffffff !important;
    position: relative;
    transition: all 0.2s ease;
}

.status-radio:not(:checked) {
    background-color: #ffffff !important;
    border-color: #adb5bd !important;
}

.status-radio:checked {
    accent-color: #6c757d !important;
    background-color: #6c757d !important;
    border-color: #6c757d !important;
}

.status-radio:hover:not(:checked) {
    border-color: #6c757d !important;
    background-color: #f8f9fa !important;
    transform: scale(1.05);
}

.status-radio:hover:checked {
    border-color: #495057 !important;
    background-color: #495057 !important;
    transform: scale(1.05);
}

.status-radio:focus {
    outline: none !important;
    box-shadow: 0 0 0 0.2rem rgba(108, 117, 125, 0.25) !important;
}
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="page-title">Organization Membership Status</h2>
            <p class="page-subtitle">Manage and track member and non-member students</p>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-center mb-0">
                <div class="col-12 col-md-auto">
                    <label for="section" class="form-label mb-0">Section:</label>
                </div>
                <div class="col-12 col-md-auto">
                    <select class="form-select" id="section" name="section" onchange="this.form.submit()">
                        <option value="All"<?= ($selected_section === 'All' || $selected_section === '') ? ' selected' : '' ?>>All</option>
                        <?php foreach ($sections as $section): ?>
                            <option value="<?= htmlspecialchars($section) ?>"<?= ($selected_section === $section) ? ' selected' : '' ?>><?= htmlspecialchars($section) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <label for="status" class="form-label mb-0">Status:</label>
                </div>
                <div class="col-12 col-md-auto">
                    <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                        <option value="All"<?= ($selected_status === 'All' || $selected_status === '') ? ' selected' : '' ?>>All</option>
                        <option value="Member"<?= ($selected_status === 'Member') ? ' selected' : '' ?>>Member</option>
                        <option value="Non-Member"<?= ($selected_status === 'Non-Member') ? ' selected' : '' ?>>Non-Member</option>
                    </select>
                </div>
                <!-- Single search bar aligned right -->
                <div class="col-12 col-md-auto ms-md-auto d-flex align-items-center">
                    <label for="search" class="form-label mb-0 me-2">Search:</label>
                    <input type="text" class="form-control mb-1" name="search" id="search" placeholder="Student Name or Number" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                </div>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 gap-2" id="membership-totals">
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary btn-sm" id="export-csv"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
                    <button class="btn btn-outline-secondary btn-sm" id="export-excel"><i class="bi bi-file-earmark-excel"></i> Excel</button>
                    <button class="btn btn-outline-secondary btn-sm" id="export-pdf"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
                </div>
                <div class="ms-md-3">
                    <strong id="membership-summary" class="d-block d-md-inline text-center text-md-start">
                        Total: <span id="total-count"><?= $total_students_all ?></span> Students | 
                        <span class="status-dot bg-success"></span>
                        Member: <span id="member-count"><?= $total_member ?></span> | 
                        <span class="status-dot bg-danger"></span>
                        Non-Member: <span id="nonmember-count"><?= $total_nonmember ?></span>
                    </strong>
                </div>
            </div>
            <div id="table-container">
                <?php include 'membership_ajax.php'; ?>
            </div>
        </div>
    </div>
</div>

</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Track current ajax page (initialize from URL or fallback to server-side page)
let currentPage = parseInt(new URLSearchParams(window.location.search).get('page')) || <?= $page ?> || 1;

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
            fetch('update_membership_status.php', {
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
                    // Reload the current ajax page (do not jump to page 1)
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

function loadTable(page = currentPage) {
    currentPage = parseInt(page) || 1;
    const section = document.getElementById('section').value;
    const status = document.getElementById('status').value;
    const search = document.getElementById('search').value;
    const params = new URLSearchParams({
        section: section,
        status: status,
        page: page,
        search: search
    });
    fetch('membership_ajax.php?' + params.toString())
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
            currentPage = parseInt(page) || 1;
            loadTable(currentPage);
        });
    });
}

document.getElementById('section').addEventListener('change', function() {
    loadTable(1);
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
        // keep user on current page when searching? reset to first page makes sense
        loadTable(1);
    }, 300); // 300ms debounce
});
document.getElementById('export-csv').addEventListener('click', function() {
    window.open('export_membership.php?format=csv' + window.location.search.replace(/^\?/, '&'), '_blank');
});
document.getElementById('export-excel').addEventListener('click', function() {
    window.open('export_membership.php?format=excel' + window.location.search.replace(/^\?/, '&'), '_blank');
});
document.getElementById('export-pdf').addEventListener('click', function() {
    window.open('export_membership.php?format=pdf' + window.location.search.replace(/^\?/, '&'), '_blank');
});
</script>
</body>
</html>
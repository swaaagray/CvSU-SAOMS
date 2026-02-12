<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['osas']);

// Fetch all colleges, courses, and organizations (show all colleges/courses even if no orgs)
// Only show recognized organizations
$sql = "SELECT 
            colleges.code AS college_code, 
            colleges.name AS college_name, 
            courses.code AS course_code, 
            courses.name AS course_name, 
            organizations.code AS org_code, 
            organizations.org_name AS org_name,
            organizations.president_name AS president_name,
            organizations.adviser_name AS adviser_name
        FROM colleges
        LEFT JOIN courses ON courses.college_id = colleges.id
        LEFT JOIN organizations ON organizations.course_id = courses.id AND organizations.status = 'recognized'
        ORDER BY colleges.name ASC, courses.name ASC, organizations.org_name ASC";
$result = $conn->query($sql);

// Group data: $colleges[college_code]['name'], ['courses'][course_code]['name'], ['organizations'][]
$colleges = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $college_code = $row['college_code'];
        $course_code = $row['course_code'];
        if (!isset($colleges[$college_code])) {
            $colleges[$college_code] = [
                'name' => $row['college_name'],
                'courses' => []
            ];
        }
        if (!isset($colleges[$college_code]['courses'][$course_code])) {
            $colleges[$college_code]['courses'][$course_code] = [
                'name' => $row['course_name'],
                'organizations' => []
            ];
        }
        // Only add organization if it exists
        if (!empty($row['org_code'])) {
            $colleges[$college_code]['courses'][$course_code]['organizations'][] = [
                'code' => $row['org_code'],
                'name' => $row['org_name'],
                'president_name' => $row['president_name'],
                'adviser_name' => $row['adviser_name']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Academic Management - OSAS</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/custom.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        main {
            margin-left: 250px;
            margin-top: 56px;
            padding: 20px;
            transition: all 0.3s;
        }
        main.expanded {
            margin-left: 0;
        }
        .container {
            margin-top: 0;
            margin-left: 0;
        }
        .card {
            border-radius: 1rem;
        }
        .table thead th {
            background: linear-gradient(90deg, #343a40, #495057) !important; /* monochrome header */
            color: #fff !important;
            vertical-align: middle;
            border: none;
        }
        .table-striped > tbody > tr:nth-of-type(odd) {
            background-color: #f2f6fa;
        }
        .table-hover tbody tr:hover {
            background-color: #e2e6ea;
        }
        .page-title { font-weight: 700; color: #1f2937; }
        .desc { color: #6b7280; }
        .desc {
            color: #6c757d;
        }
        .table td, .table th {
            word-break: break-word;
            max-width: 220px;
            vertical-align: middle;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            min-width: 900px;
            width: 100%;
        }
        .college-row { cursor: pointer; background-color: #f3f4f6; transition: all 0.3s ease; position: relative; }
        .college-row:hover { background-color: #e5e7eb; }
        .college-row.selected { background-color: #d1d5db; box-shadow: 0 2px 8px rgba(0,0,0,0.12); border-left: 4px solid #4b5563; }
        .college-row::after {
            content: '\f282';
            font-family: 'bootstrap-icons';
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-size: 14px;
        }
        .college-row.selected::after {
            transform: translateY(-50%) rotate(180deg);
            color: #f57c00;
        }
        .collapse-inner-table th { 
            background: linear-gradient(90deg, #343a40, #495057) !important; 
            color: #fff !important; 
            border: none;
        }
        .collapse-inner-table td { background-color: #f8f9fa; transition: background-color 0.2s ease; }
        .collapse-inner-table tbody tr:hover td { background-color: #edf2f7 !important; }
        .collapse-inner-table {
            border-radius: 0 0 8px 8px;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
        }
        .collapse-row {
            border: none;
        }
        .collapse-row td {
            padding: 0;
            border: none;
        }
        .collapse {
            transition: all 0.3s ease;
        }
        .search-bar {
            max-width: 400px;
            margin: 0 auto 24px auto;
        }
        @media (max-width: 991.98px) {
            main {
                margin-left: 0;
                padding-left: 10px;
                padding-right: 10px;
            }
            .container {
                max-width: 100vw;
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main>
<div class="container py-2">
    <div class="mb-4">
        <h2 class="page-title mb-2">Registered Colleges, Courses & Organizations</h2>
        <div class="desc">Click a college row to view its courses and organizations. Sidebar is dynamic.</div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <button class="btn btn-outline-secondary btn-sm me-1" id="export-csv"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
            <button class="btn btn-outline-secondary btn-sm me-1" id="export-excel"><i class="bi bi-file-earmark-excel"></i> Excel</button>
            <button class="btn btn-outline-secondary btn-sm" id="export-pdf"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
        </div>
        <div class="d-flex align-items-center">
            <label for="globalSearch" class="form-label mb-0 me-2">Search:</label>
            <input type="text" id="globalSearch" class="form-control" placeholder="College Name, Course Code, Organization Code, President Name, Adviser Name" style="width: 300px;">
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-lg-12">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover align-middle mb-0" id="orgTable">
                            <thead>
                                <tr>
                                    <th>College Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($colleges)): ?>
                                    <?php foreach ($colleges as $college_code => $college): ?>
                                        <tr class="college-row" data-bs-toggle="collapse" data-bs-target="#collapse-<?= htmlspecialchars($college_code) ?>" aria-expanded="false" aria-controls="collapse-<?= htmlspecialchars($college_code) ?>">
                                            <td><?= htmlspecialchars($college['name'] . ' - ' . $college_code) ?></td>
                                        </tr>
                                        <tr class="collapse-row">
                                            <td class="p-0">
                                                <div class="collapse" id="collapse-<?= htmlspecialchars($college_code) ?>">
                                                    <table class="table table-bordered mb-0 collapse-inner-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Course Code</th>
                                                                <th>Course Name</th>
                                                                <th>Organization Code</th>
                                                                <th>Organization Name</th>
                                                                <th>President</th>
                                                                <th>Adviser</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                        <?php foreach ($college['courses'] as $course_code => $course): ?>
                                                            <?php $orgs = $course['organizations']; $org_count = count($orgs); ?>
                                                            <?php if ($org_count > 0): ?>
                                                                <?php foreach ($orgs as $i => $org): ?>
                                                                <tr class="org-row">
                                                                    <?php if ($i === 0): ?>
                                                                        <td rowspan="<?= $org_count ?>"><?= htmlspecialchars($course_code) ?></td>
                                                                        <td rowspan="<?= $org_count ?>"><?= htmlspecialchars($course['name']) ?></td>
                                                                    <?php endif; ?>
                                                                    <td><?= htmlspecialchars($org['code']) ?></td>
                                                                    <td><?= htmlspecialchars($org['name']) ?></td>
                                                                    <td><?= htmlspecialchars($org['president_name']) ?></td>
                                                                    <td><?= htmlspecialchars($org['adviser_name']) ?></td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <tr class="org-row">
                                                                    <td><?= htmlspecialchars($course_code) ?></td>
                                                                    <td><?= htmlspecialchars($course['name']) ?></td>
                                                                    <td colspan="4" class="text-center text-muted">No organization registered</td>
                                                                </tr>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td class="text-center text-muted">No colleges/courses found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.7.0/jspdf.plugin.autotable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

    const searchInput = document.getElementById('globalSearch');
    const table = document.getElementById('orgTable');
    
    // Handle college row selection and collapse
    function handleCollegeRowClick() {
        const collegeRows = table.querySelectorAll('tbody > tr.college-row');
        collegeRows.forEach(function(collegeRow) {
            collegeRow.addEventListener('click', function() {
                const collapseRow = this.nextElementSibling;
                const collapseDiv = collapseRow.querySelector('.collapse');
                const isCurrentlySelected = this.classList.contains('selected');

                // Close all other dropdowns and deselect all
                collegeRows.forEach(function(row) {
                    row.classList.remove('selected');
                    const otherCollapseRow = row.nextElementSibling;
                    const otherCollapseDiv = otherCollapseRow.querySelector('.collapse');
                    if (otherCollapseDiv && otherCollapseDiv.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(otherCollapseDiv).hide();
                    }
                });

                // If it was already selected, just close it (done above)
                if (isCurrentlySelected) {
                    return;
                }

                // Otherwise, open and select this one
                this.classList.add('selected');
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    bootstrap.Collapse.getOrCreateInstance(collapseDiv).show();
                }
            });
        });
    }
    
    // Initialize college row click handlers
    handleCollegeRowClick();
    
    searchInput.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        const collegeRows = table.querySelectorAll('tbody > tr.college-row');
        let anyCollegeVisible = false;
        collegeRows.forEach(function(collegeRow) {
            const collapseRow = collegeRow.nextElementSibling;
            const collapseDiv = collapseRow.querySelector('.collapse');
            let collegeText = collegeRow.textContent.toLowerCase();
            let courseOrgRows = collapseRow.querySelectorAll('.collapse-inner-table tbody tr');
            let anyCourseOrgVisible = false;
            // Hide all course/org rows initially
            courseOrgRows.forEach(function(row) {
                row.style.display = 'none';
            });
            // Show only matching course/org rows
            courseOrgRows.forEach(function(row) {
                if (row.textContent.toLowerCase().indexOf(query) !== -1 || collegeText.indexOf(query) !== -1) {
                    row.style.display = '';
                    anyCourseOrgVisible = true;
                }
            });
            // Show/hide the college row and expand/collapse
            if (anyCourseOrgVisible || collegeText.indexOf(query) !== -1) {
                collegeRow.style.display = '';
                collapseRow.style.display = '';
                // Expand if there is a match
                if (collapseDiv && !collapseDiv.classList.contains('show')) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseDiv, {toggle: false});
                    bsCollapse.show();
                    collegeRow.classList.add('selected');
                }
                anyCollegeVisible = true;
            } else {
                collegeRow.style.display = 'none';
                collapseRow.style.display = 'none';
                // Collapse if not matching
                if (collapseDiv && collapseDiv.classList.contains('show')) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseDiv, {toggle: false});
                    bsCollapse.hide();
                    collegeRow.classList.remove('selected');
                }
            }
        });
        // If no colleges are visible, show a message
        const noDataRow = table.querySelector('tbody > tr.no-data-row');
        if (!anyCollegeVisible) {
            if (!noDataRow) {
                const tr = document.createElement('tr');
                tr.className = 'no-data-row';
                tr.innerHTML = '<td colspan="2" class="text-center text-muted">No results found.</td>';
                table.querySelector('tbody').appendChild(tr);
            }
        } else {
            if (noDataRow) noDataRow.remove();
        }
    });
});

// Export button event listeners
document.getElementById('export-csv').addEventListener('click', function() {
    window.open('export_colleges.php?format=csv', '_blank');
});
document.getElementById('export-excel').addEventListener('click', function() {
    window.open('export_colleges.php?format=excel', '_blank');
});
document.getElementById('export-pdf').addEventListener('click', function() {
    window.open('export_colleges.php?format=pdf', '_blank');
});
</script>
</body>
</html> 

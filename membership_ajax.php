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

// Fetch unique statuses for dropdown
$statusSql = "SELECT DISTINCT org_status FROM student_data WHERE organization_id = ? ORDER BY org_status ASC";
$statusStmt = $conn->prepare($statusSql);
$statusStmt->bind_param("i", $orgId);
$statusStmt->execute();
$statusResult = $statusStmt->get_result();
$statuses = [];
while ($row = $statusResult->fetch_assoc()) {
    $statuses[] = $row['org_status'];
}
$statusStmt->close();

// Get selected filters from GET
$selected_section = $_GET['section'] ?? '';
$selected_status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query with filters
$query = "SELECT sd.id, sd.student_name, sd.student_number, sd.course, sd.sex, sd.section, sd.org_status as status FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active'";
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
$count_query = "SELECT COUNT(*) FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active'";
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
$all_filtered_query = "SELECT sd.org_status FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active'";
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
<div id="ajax-totals" data-total="<?php echo $total_students_all; ?>" data-member="<?php echo $total_member; ?>" data-nonmember="<?php echo $total_nonmember; ?>" style="display:none"></div>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>Student Name</th>
                <th>Student Number</th>
                <th>Course</th>
                <th>Sex</th>
                <th class="text-center">Section</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($students)): ?>
                <tr><td colspan="6" class="text-center">No students found.</td></tr>
            <?php else: ?>
                <?php foreach ($students as $student): ?>
                    <tr>
                        <td><?= htmlspecialchars($student['student_name']) ?></td>
                        <td><?= htmlspecialchars($student['student_number']) ?></td>
                        <td><?= htmlspecialchars($student['course']) ?></td>
                        <td><?= htmlspecialchars($student['sex']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($student['section']) ?></td>
                        <td class="text-center">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input status-radio" type="radio" name="status_<?= $student['id'] ?>" value="Member" data-id="<?= $student['id'] ?>" <?= $student['status'] === 'Member' ? 'checked' : '' ?>>
                                <label class="form-check-label">Member</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input status-radio" type="radio" name="status_<?= $student['id'] ?>" value="Non-Member" data-id="<?= $student['id'] ?>" <?= $student['status'] === 'Non-Member' ? 'checked' : '' ?>>
                                <label class="form-check-label">Non-Member</label>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php if ($total_pages > 1): ?>
<nav>
  <ul class="pagination justify-content-center mt-3">
    <li class="page-item<?= $page == 1 ? ' disabled' : '' ?>">
      <a class="page-link ajax-page-link" href="#" data-page="<?= $page-1 ?>">Previous</a>
    </li>
    <?php
    $window = 5;
    $half = floor($window / 2);
    $start = max(2, $page - $half);
    $end = min($total_pages - 1, $page + $half);
    if ($page <= $half) {
        $start = 2;
        $end = min($total_pages - 1, $window + 1);
    }
    if ($page > $total_pages - $half) {
        $end = $total_pages - 1;
        $start = max(2, $total_pages - $window);
    }
    // Always show first page
    echo '<li class="page-item'.($page == 1 ? ' active' : '').'"><a class="page-link ajax-page-link" href="#" data-page="1">1</a></li>';
    // Ellipsis if needed before window
    if ($start > 2) {
        echo '<li class="page-item"><span class="page-link disabled-ellipsis">...</span></li>';
    }
    // Window of 5 pages
    for ($i = $start; $i <= $end; $i++) {
        echo '<li class="page-item'.($i == $page ? ' active' : '').'"><a class="page-link ajax-page-link" href="#" data-page="'.$i.'">'.$i.'</a></li>';
    }
    // Ellipsis if needed after window
    if ($end < $total_pages - 1) {
        echo '<li class="page-item"><span class="page-link disabled-ellipsis">...</span></li>';
    }
    // Always show last page if more than 1
    if ($total_pages > 1) {
        echo '<li class="page-item'.($page == $total_pages ? ' active' : '').'"><a class="page-link ajax-page-link" href="#" data-page="'.$total_pages.'">'.$total_pages.'</a></li>';
    }
    ?>
    <li class="page-item<?= $page == $total_pages ? ' disabled' : '' ?>">
      <a class="page-link ajax-page-link" href="#" data-page="<?= $page+1 ?>">Next</a>
    </li>
  </ul>
</nav>
<?php endif; ?>
<script>
// Re-attach event listeners for status radios after AJAX update
if (typeof updateStatusCounts === 'function') updateStatusCounts();
document.querySelectorAll('.status-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var studentId = this.getAttribute('data-id');
        var status = this.value;
        
        // Route to organization endpoint
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
                if (typeof updateStatusCounts === 'function') updateStatusCounts();
                // Reload the table to update counts and ensure data consistency
                if (typeof loadTable === 'function') {
                    loadTable();
                }
            }
        })
        .catch(() => {
            alert('Failed to update status.');
        });
    });
});
// Handle ellipsis click for pagination
var ellipsisLinks = document.querySelectorAll('.pagination-ellipsis');
ellipsisLinks.forEach(function(link) {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        var currentPage = <?= $page ?>;
        var totalPages = <?= $total_pages ?>;
        var window = 5;
        var newPage = 1;
        if (this.getAttribute('data-page') === 'ellipsis-prev') {
            newPage = Math.max(1, currentPage - window);
        } else if (this.getAttribute('data-page') === 'ellipsis-next') {
            newPage = Math.min(totalPages, currentPage + window);
        }
        loadTable(newPage);
    });
});
</script> 
<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president']);

$presidentId = $_SESSION['user_id'];
$orgId = null;
$stmt = $conn->prepare("SELECT id, course_id FROM organizations WHERE president_id = ?");
$stmt->bind_param("i", $presidentId);
$stmt->execute();
$stmt->bind_result($orgId, $orgCourseId);
$stmt->fetch();
$stmt->close();

if (!$orgId || !$orgCourseId) {
    die("No organization or course found for this president.");
}

$course_code = '';
$stmt = $conn->prepare("SELECT code FROM courses WHERE id = ?");
$stmt->bind_param("i", $orgCourseId);
$stmt->execute();
$stmt->bind_result($course_code);
$stmt->fetch();
$stmt->close();

// Fetch unique sections for dropdown
$sections_db = [];
$sectionSql = "SELECT DISTINCT sd.section FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active' ORDER BY sd.section ASC";
$sectionStmt = $conn->prepare($sectionSql);
$sectionStmt->bind_param("i", $orgId);
$sectionStmt->execute();
$sectionResult = $sectionStmt->get_result();
while ($row = $sectionResult->fetch_assoc()) {
    $sections_db[] = $row['section'];
}
$sectionStmt->close();

$selected_section = $_GET['section_filter'] ?? 'All';
$per_page = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

$count_sql = "SELECT COUNT(*) FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active'";
if ($selected_section !== 'All') {
    $count_sql .= " AND section = ?";
    $countStmt = $conn->prepare($count_sql);
    $countStmt->bind_param("is", $orgId, $selected_section);
    $countStmt->execute();
    $total_students_all = $countStmt->get_result()->fetch_row()[0];
    $countStmt->close();
} else {
    $countStmt = $conn->prepare($count_sql);
    $countStmt->bind_param("i", $orgId);
    $countStmt->execute();
    $total_students_all = $countStmt->get_result()->fetch_row()[0];
    $countStmt->close();
}

$offset = ($page - 1) * $per_page;

$sql = "SELECT sd.student_name, sd.student_number, sd.course, sd.sex, sd.section FROM student_data sd JOIN academic_semesters s ON sd.semester_id = s.id JOIN academic_terms at ON s.academic_term_id = at.id WHERE sd.organization_id = ? AND s.status = 'active' AND at.status = 'active'";
if ($selected_section !== 'All') {
    $sql .= " AND section = ?";
    $sql .= " LIMIT ? OFFSET ?";
    $studentStmt = $conn->prepare($sql);
    $studentStmt->bind_param("isii", $orgId, $selected_section, $per_page, $offset);
} else {
    $sql .= " LIMIT ? OFFSET ?";
    $studentStmt = $conn->prepare($sql);
    $studentStmt->bind_param("iii", $orgId, $per_page, $offset);
}
$studentStmt->execute();
$studentResult = $studentStmt->get_result();
$students_db = [];
while ($row = $studentResult->fetch_assoc()) {
    $students_db[] = [
        'name' => $row['student_name'],
        'number' => $row['student_number'],
        'course' => $row['course'],
        'sex' => $row['sex'],
        'section' => $row['section'],
    ];
}
$studentStmt->close();
$total_pages = ceil($total_students_all / $per_page);
?>
<div class="mb-2 fw-bold">Total <?php echo $total_students_all; ?> Student<?php echo $total_students_all !== 1 ? 's' : ''; ?>.</div>
<div class="table-responsive">
    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>Student Name</th>
                <th>Student Number</th>
                <th>Course</th>
                <th>Sex</th>
                <th class="text-center">Section</th>
                <th class="text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($students_db as $student): ?>
            <tr>
                <td><?php echo htmlspecialchars($student['name']); ?></td>
                <td><?php echo htmlspecialchars($student['number']); ?></td>
                <td><?php echo htmlspecialchars($student['course']); ?></td>
                <td><?php echo htmlspecialchars($student['sex']); ?></td>
                <td class="text-center"><?php echo htmlspecialchars($course_code . ' ' . $student['section']); ?></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-warning edit-student-btn" 
                        data-name="<?php echo htmlspecialchars($student['name']); ?>"
                        data-number="<?php echo htmlspecialchars($student['number']); ?>"
                        data-sex="<?php echo htmlspecialchars($student['sex']); ?>"
                        data-section="<?php echo htmlspecialchars($student['section']); ?>"
                        title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-danger delete-student-btn" 
                        data-number="<?php echo htmlspecialchars($student['number']); ?>"
                        title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="editStudentForm">
        <div class="modal-header">
          <h5 class="modal-title" id="editStudentModalLabel">Edit Student</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="editStudentName" class="form-label">Student Name</label>
            <input type="text" class="form-control" id="editStudentName" name="student_name" required placeholder="e.g. LAST NAME, FIRST NAME, M.I">
          </div>
          <div class="mb-3">
            <label for="editStudentNumber" class="form-label">Student Number</label>
            <input type="text" class="form-control" id="editStudentNumber" name="student_number" required maxlength="9">
            <input type="hidden" id="editOriginalStudentNumber" name="original_student_number">
          </div>
          <div class="mb-3">
            <label class="form-label">Sex</label><br>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="sex" id="editSexMale" value="MALE" required>
              <label class="form-check-label" for="editSexMale">MALE</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="sex" id="editSexFemale" value="FEMALE" required>
              <label class="form-check-label" for="editSexFemale">FEMALE</label>
            </div>
          </div>
          <div class="mb-3">
            <label for="editStudentSection" class="form-label">Section</label>
            <input type="text" class="form-control" id="editStudentSection" name="section" placeholder="e.g. 3-2" required>
          </div>
          <div id="editStudentError" class="alert alert-danger d-none"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
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
        loadRegistryTable(newPage);
    });
});
</script> 
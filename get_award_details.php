<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : 0;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : 0;
$ownerId = $orgId ?: $councilId;

$awardId = $_GET['award_id'] ?? 0;
$format = $_GET['format'] ?? 'json';

if (!$awardId) {
    if ($format === 'json') {
        echo json_encode(['error' => 'Invalid award ID.']);
    } else {
        echo '<div class="alert alert-danger">Invalid award ID.</div>';
    }
    exit;
}

// Get award details based on role
if ($role === 'council_president') {
    // For council presidents, get awards where council_id matches
    $stmt = $conn->prepare("
        SELECT a.*, c.council_name as organization_name
        FROM awards a
        JOIN council c ON a.council_id = c.id
        WHERE a.id = ? AND a.council_id = ?
    ");
    $stmt->bind_param("ii", $awardId, $councilId);
} else {
    // For organization presidents, get awards where organization_id matches
    $stmt = $conn->prepare("
        SELECT a.*, o.org_name as organization_name
        FROM awards a
        JOIN organizations o ON a.organization_id = o.id
        WHERE a.id = ? AND a.organization_id = ?
    ");
    $stmt->bind_param("ii", $awardId, $orgId);
}
$stmt->execute();
$award = $stmt->get_result()->fetch_assoc();

if (!$award) {
    if ($format === 'json') {
        echo json_encode(['error' => 'Award not found.']);
    } else {
        echo '<div class="alert alert-danger">Award not found.</div>';
    }
    exit;
}

// Get award images
$stmt = $conn->prepare("
    SELECT *
    FROM award_images
    WHERE award_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $awardId);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get participants
$stmt = $conn->prepare("
    SELECT id, award_id, name, course, yearSection
    FROM award_participants
    WHERE award_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("i", $awardId);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($format === 'json') {
    // Map participants to include derived year_level and section for UI compatibility
    $participantsMapped = array_map(function($p) {
        $ys = $p['yearSection'] ?? '';
        $year_level = '';
        $section = '';
        if ($ys !== '') {
            $parts = explode('-', $ys, 2);
            $year_level = trim($parts[0]);
            if (count($parts) > 1) {
                $section = trim($parts[1]);
            }
        }
        return [
            'id' => $p['id'],
            'award_id' => $p['award_id'],
            'name' => $p['name'],
            'course' => $p['course'],
            'year_level' => $year_level,
            'section' => $section,
            'yearSection' => $ys, // Return original yearSection directly as-is
        ];
    }, $participants);

    // Return JSON format for edit modal
    echo json_encode([
        'award' => $award,
        'images' => $images,
        'participants' => $participantsMapped
    ]);
} else {
    // Return HTML format for view modal
    ?>
    <div class="award-details">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo htmlspecialchars($award['title']); ?></h4>
                <p class="text-muted mb-3"><?php echo htmlspecialchars($award['description']); ?></p>
                
                <div class="mb-3">
                    <strong>Award Date:</strong> 
                    <span class="text-muted"><?php echo date('F d, Y', strtotime($award['award_date'])); ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Organization:</strong> 
                    <span class="text-muted"><?php echo htmlspecialchars($award['organization_name']); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($images)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <h5>Award Images</h5>
                <div class="row g-3">
                    <?php 
                    $totalImages = count($images);
                    $displayImages = array_slice($images, 0, 3); // Show only first 3 images
                    
                    foreach ($displayImages as $index => $image): 
                        $isLastImage = ($index == 2 && $totalImages > 3);
                    ?>
                    <div class="col-md-4">
                        <div class="card position-relative">
                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" 
                                 class="card-img-top" 
                                 alt="Award Image"
                                 style="height: 200px; object-fit: cover; cursor: pointer;"
                                 onclick="openImageGallery(<?php echo $awardId; ?>, <?php echo $index; ?>)">
                            
                            <?php if ($isLastImage): ?>
                            <!-- Facebook-style "See more" overlay -->
                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" 
                                 style="background: rgba(0, 0, 0, 0.7); cursor: pointer; border-radius: 0.375rem;"
                                 onclick="openImageGallery(<?php echo $awardId; ?>, <?php echo $index; ?>)">
                                <div class="text-center text-white">
                                    <i class="bi bi-images" style="font-size: 2rem;"></i>
                                    <div style="font-size: 1.2rem; font-weight: 600; margin-top: 0.5rem;">
                                        +<?php echo $totalImages - 3; ?> more
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($participants)): ?>
        <div class="row">
            <div class="col-md-12">
                <h5>Participants</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Course</th>
                                <th>Year - Section</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($participants as $participant): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($participant['name']); ?></td>
                                <td><?php echo htmlspecialchars($participant['course']); ?></td>
                                <td><?php echo htmlspecialchars($participant['yearSection'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="row">
            <div class="col-md-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No participants recorded for this award.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?> 
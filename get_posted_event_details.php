<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president']);

// Determine ownership context (organization vs council)
$role = getUserRole();
$orgId = ($role === 'org_president') ? getCurrentOrganizationId() : 0;
$councilId = ($role === 'council_president') ? getCurrentCouncilId() : 0;
$ownerId = $orgId ?: $councilId;

$eventId = $_GET['event_id'] ?? 0;
$format = $_GET['format'] ?? 'json';

if (!$eventId) {
    if ($format === 'json') {
        echo json_encode(['error' => 'Invalid event ID.']);
    } else {
        echo '<div class="alert alert-danger">Invalid event ID.</div>';
    }
    exit;
}

// Get event details based on role
if ($role === 'council_president') {
    // For council presidents, get events where council_id matches
    $stmt = $conn->prepare("
        SELECT e.*, c.council_name as organization_name
        FROM events e
        JOIN council c ON e.council_id = c.id
        WHERE e.id = ? AND e.council_id = ?
    ");
    $stmt->bind_param("ii", $eventId, $councilId);
} else {
    // For organization presidents, get events where organization_id matches
    $stmt = $conn->prepare("
        SELECT e.*, o.org_name as organization_name
        FROM events e
        JOIN organizations o ON e.organization_id = o.id
        WHERE e.id = ? AND e.organization_id = ?
    ");
    $stmt->bind_param("ii", $eventId, $orgId);
}
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

if (!$event) {
    if ($format === 'json') {
        echo json_encode(['error' => 'Event not found.']);
    } else {
        echo '<div class="alert alert-danger">Event not found.</div>';
    }
    exit;
}

// Get event images
$stmt = $conn->prepare("
    SELECT *
    FROM event_images
    WHERE event_id = ?
    ORDER BY id ASC
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get participants
$stmt = $conn->prepare("
    SELECT *
    FROM event_participants
    WHERE event_id = ?
    ORDER BY name ASC
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$participants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if ($format === 'json') {
    // Format the date properly for the JSON response
    if ($event['event_date'] && $event['event_date'] !== '0000-00-00 00:00:00') {
        $event['event_date'] = date('Y-m-d H:i:s', strtotime($event['event_date']));
    } else {
        // Set a default date if the date is invalid
        $event['event_date'] = date('Y-m-d H:i:s');
    }
    
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
            'event_id' => $p['event_id'],
            'name' => $p['name'],
            'course' => $p['course'],
            'year_level' => $year_level,
            'section' => $section,
            'yearSection' => $ys, // Return original yearSection directly as-is
        ];
    }, $participants);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'event' => $event,
        'images' => $images,
        'participants' => $participantsMapped
    ]);
} else {
    // Return HTML format for view modal
    ?>
    <div class="event-details">
        <div class="row">
            <div class="col-md-12">
                <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                <p class="text-muted mb-3"><?php echo htmlspecialchars($event['description']); ?></p>
                
                <div class="mb-3">
                    <strong>Event Date:</strong> 
                    <span class="text-muted"><?php echo date('F d, Y h:i A', strtotime($event['event_date'])); ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Venue:</strong> 
                    <span class="text-muted"><?php echo htmlspecialchars($event['venue']); ?></span>
                </div>
                
                <div class="mb-3">
                    <strong>Organization:</strong> 
                    <span class="text-muted"><?php echo htmlspecialchars($event['organization_name']); ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($images)): ?>
        <div class="row mb-4">
            <div class="col-md-12">
                <h5>Event Images</h5>
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
                                 alt="Event Image"
                                 style="height: 200px; object-fit: cover; cursor: pointer;"
                                 onclick="openEventImageGallery(<?php echo $eventId; ?>, <?php echo $index; ?>)">
                            
                            <?php if ($isLastImage): ?>
                            <!-- Facebook-style "See more" overlay -->
                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" 
                                 style="background: rgba(0, 0, 0, 0.7); cursor: pointer; border-radius: 0.375rem;"
                                 onclick="openEventImageGallery(<?php echo $eventId; ?>, <?php echo $index; ?>)">
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
                                <td><?php echo htmlspecialchars($participant['yearSection']); ?></td>
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
                    <i class="bi bi-info-circle me-2"></i>No participants recorded for this event.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
?> 
<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

if (!isset($_GET['approval_id'])) {
    echo '<div class="alert alert-danger">Event ID is required.</div>';
    exit;
}

$eventId = (int)$_GET['approval_id'];

// Get event details
$stmt = $conn->prepare("
    SELECT e.*, c.council_name 
    FROM events e 
    LEFT JOIN council c ON e.council_id = c.id 
    WHERE e.id = ?
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    echo '<div class="alert alert-danger">Event not found.</div>';
    exit;
}

// Get event documents
$stmt = $conn->prepare("
    SELECT ed.*, edt.document_type_name, edt.required,
           ed.resubmission_deadline, ed.deadline_set_by, ed.deadline_set_at
    FROM event_documents ed
    LEFT JOIN event_document_types edt ON ed.document_type_id = edt.id
    WHERE ed.event_id = ?
    ORDER BY edt.required DESC, edt.document_type_name ASC
");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get event images
$stmt = $conn->prepare("SELECT image_path FROM event_images WHERE event_id = ?");
$stmt->bind_param("i", $eventId);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!-- Event Proposal Details Section -->
<div class="mb-4">
    <h6 class="fw-bold text-dark mb-3">Event Proposal Details</h6>
    <div class="bg-light p-3 rounded">
        <div class="row">
            <div class="col-md-6">
                <p class="mb-2"><strong>Title:</strong> <?php echo htmlspecialchars($event['title']); ?></p>
                <p class="mb-2"><strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($event['created_at'])); ?></p>
            </div>
            <div class="col-md-6">
                <p class="mb-2"><strong>Venue:</strong> <?php echo htmlspecialchars($event['venue']); ?></p>
                <p class="mb-0"><strong>Council:</strong> <?php echo htmlspecialchars($event['council_name']); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Documents Section -->
<div class="mb-4">
    <h6 class="fw-bold text-dark mb-3">Documents</h6>
    <?php if (empty($documents)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            No documents have been submitted for this event yet.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Document Type</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                        <tr data-doc-id="<?php echo $doc['id']; ?>" data-file-path="<?php echo htmlspecialchars($doc['file_path']); ?>" data-doc-title="<?php echo htmlspecialchars($doc['document_type_name']); ?>">
                            <td>
                                <?php echo htmlspecialchars($doc['document_type_name']); ?>
                                <?php if ($doc['required']): ?>
                                    <span class="text-danger">*</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status = $doc['status'] ?? 'pending';
                                $statusClass = '';
                                $statusText = '';
                                
                                switch ($status) {
                                    case 'approved':
                                        $statusClass = 'bg-success';
                                        $statusText = 'APPROVED';
                                        break;
                                    case 'rejected':
                                        $statusClass = 'bg-danger text-white';
                                        $statusText = 'REJECTED';
                                        break;
                                    default:
                                        $statusClass = 'bg-warning text-dark';
                                        $statusText = 'PENDING';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                <?php if ($status === 'rejected' && !empty($doc['resubmission_deadline'])): ?>
                                    <br><small class="text-warning mt-1">
                                        <i class="bi bi-clock me-1"></i>Deadline: <?php echo date('M d, Y g:i A', strtotime($doc['resubmission_deadline'])); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>Organization President</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" onclick="viewFileModal('./<?php echo addslashes($doc['file_path']); ?>', '<?php echo addslashes($doc['document_type_name']); ?>')">
                                    <i class="bi bi-eye"></i> View
                                </button>
                                
                                <?php if ($status === 'pending'): ?>
                                    <button type="button" class="btn btn-sm btn-success ms-1" data-bs-target="#approveEventDocModal" data-doc-id="<?php echo $doc['id']; ?>">
                                        <i class="bi bi-check-circle"></i> Approve
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger ms-1" data-bs-target="#rejectEventDocModal" data-doc-id="<?php echo $doc['id']; ?>">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Event Images Section -->
<?php if (!empty($images)): ?>
<div class="mb-4">
    <h6 class="fw-bold text-dark mb-3">Event Images</h6>
    <div class="row g-2">
        <?php foreach ($images as $index => $image): ?>
            <div class="col-md-3 col-6">
                <div class="position-relative">
                    <img src="<?php echo htmlspecialchars($image['image_path']); ?>" 
                         class="img-fluid rounded" 
                         alt="Event Image <?php echo $index + 1; ?>" 
                         style="height: 120px; object-fit: cover; width: 100%; cursor: pointer;"
                         onclick="viewFileModal('./<?php echo htmlspecialchars($image['image_path']); ?>', 'Event Image <?php echo $index + 1; ?>')">
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

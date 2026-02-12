<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['osas', 'mis_coordinator']);

$approvalId = $_GET['approval_id'] ?? 0;

if (!$approvalId) {
    echo '<div class="alert alert-danger">Invalid proposal ID</div>';
    exit;
}

// Get proposal details
$stmt = $conn->prepare("SELECT * FROM event_approvals WHERE id = ?");
$stmt->bind_param("i", $approvalId);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();

if (!$proposal) {
    echo '<div class="alert alert-danger">Proposal not found</div>';
    exit;
}

// Get documents with approval status
$stmt = $conn->prepare("
    SELECT d.*, 
           u.username as submitted_by_name,
           CASE 
               WHEN adviser.role = 'org_adviser' THEN 'Organization Adviser'
               WHEN adviser.role = 'osas' THEN 'OSAS Personnel'
               ELSE 'Adviser'
           END as adviser_name,
           'OSAS Personnel' as osas_name,
           d.adviser_approved_at, d.adviser_rejected_at, d.osas_approved_at, d.osas_rejected_at,
           d.approved_by_adviser, d.approved_by_osas
    FROM event_documents d
    LEFT JOIN users u ON d.submitted_by = u.id
    LEFT JOIN users adviser ON d.approved_by_adviser = adviser.id
    LEFT JOIN users osas ON d.approved_by_osas = osas.id
    WHERE d.event_approval_id = ? AND d.status != 'pending'
    ORDER BY d.submitted_at DESC
");
$stmt->bind_param("i", $approvalId);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Document type labels
$documentTypes = [
    'activity_proposal' => 'Activity Proposal',
    'resolution_budget_approval' => 'Resolution for Budget Approval',
    'letter_venue_equipment' => 'Letters for Venue/Equipment',
    'cv_speakers' => 'CV of Speakers',
];
?>

<div class="row">
    <div class="col-md-12">
        <h5 class="mb-3">Event Proposal Details</h5>
        
        <div class="card mb-3">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <strong>Title:</strong> <?php echo htmlspecialchars($proposal['title']); ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Venue:</strong> <?php echo htmlspecialchars($proposal['venue']); ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-6">
                        <strong>Submitted:</strong> <?php echo date('M d, Y H:i', strtotime($proposal['created_at'])); ?>
                    </div>
                </div>
            </div>
        </div>

        <h6 class="mb-3">Documents</h6>
        
        <?php if (empty($documents)): ?>
            <div class="alert alert-info">No documents uploaded for this proposal</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Document Type</th>
                            <th class="text-center">Status</th>
                            <th>Submitted By</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr data-doc-id="<?php echo $document['id']; ?>"
                                data-file-path="<?php echo htmlspecialchars($document['file_path']); ?>"
                                data-doc-title="<?php echo addslashes($documentTypes[$document['document_type']] ?? $document['document_type']); ?>">
                                <td><?php echo htmlspecialchars($documentTypes[$document['document_type']] ?? $document['document_type']); ?></td>
                                <td class="text-center">
                                    <?php
                                    $statusText = '';
                                    $statusClass = '';
                                    if (!empty($document['osas_approved_at'])) {
                                        $statusText = 'APPROVED';
                                        $statusClass = 'bg-success';
                                    } elseif (!empty($document['osas_rejected_at'])) {
                                        $statusText = 'REJECTED';
                                        $statusClass = 'bg-danger text-white';
                                    } else {
                                        $statusText = 'PENDING';
                                        $statusClass = 'bg-warning text-dark';
                                    }
                                    ?>
                                    <span class="badge <?php echo $statusClass; ?>">
                                        <?php echo $statusText; ?>
                                    </span>
                                </td>
                                <td>
                                    Organization President
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-info" 
                                            onclick="viewFileModal('./<?php echo addslashes($document['file_path']); ?>', '<?php echo addslashes($documentTypes[$document['document_type']] ?? $document['document_type']); ?>')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php if (empty($document['osas_approved_at']) && empty($document['osas_rejected_at'])): ?>
                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveEventDocModal" data-doc-id="<?php echo $document['id']; ?>">
                                                <i class="bi bi-check-circle"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectEventDocModal" data-doc-id="<?php echo $document['id']; ?>">
                                                <i class="bi bi-x-circle"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div> 
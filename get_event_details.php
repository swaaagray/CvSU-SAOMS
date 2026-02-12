<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['org_president', 'council_president', 'org_adviser', 'council_adviser']);

$approvalId = $_GET['approval_id'] ?? 0;
$role = getUserRole();

if (!$approvalId) {
    echo '<div class="alert alert-danger">Invalid proposal ID</div>';
    exit;
}

// Get proposal details, scoped by owner depending on role
$proposal = null;
if (in_array($role, ['org_president', 'org_adviser'])) {
    // Organization users - scope by organization_id
    $orgId = getCurrentOrganizationId();
    if ($orgId) {
        $stmt = $conn->prepare("SELECT * FROM event_approvals WHERE id = ? AND organization_id = ? AND organization_id IS NOT NULL");
        $stmt->bind_param("ii", $approvalId, $orgId);
        $stmt->execute();
        $proposal = $stmt->get_result()->fetch_assoc();
    }
} elseif (in_array($role, ['council_president', 'council_adviser'])) {
    // Council users - scope by council_id
    $councilId = getCurrentCouncilId();
    if ($councilId) {
        $stmt = $conn->prepare("SELECT * FROM event_approvals WHERE id = ? AND council_id = ? AND council_id IS NOT NULL");
        $stmt->bind_param("ii", $approvalId, $councilId);
        $stmt->execute();
        $proposal = $stmt->get_result()->fetch_assoc();
    }
}

if (!$proposal) {
    echo '<div class="alert alert-danger">Proposal not found or access denied</div>';
    exit;
}

// Get documents with approval status
$stmt = $conn->prepare("
    SELECT d.*, 
           u.username as submitted_by_name,
           CASE 
               WHEN adviser.role = 'org_adviser' THEN 'Organization Adviser'
               WHEN adviser.role = 'council_adviser' THEN 'Council Adviser'
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
    WHERE d.event_approval_id = ?
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
                <table class="table table-striped modal-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Document Type</th>
                            <th style="width: 25%;" class="text-center">Status</th>
                            <?php if ($_SESSION['role'] === 'org_adviser' || $_SESSION['role'] === 'council_adviser'): ?>
                                <th style="width: 25%;">Submitted By</th>
                            <?php else: ?>
                                <th style="width: 25%;">Processed By</th>
                            <?php endif; ?>
                            <th style="width: 20%;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($documentTypes[$document['document_type']] ?? $document['document_type']); ?>">
                                    <?php echo htmlspecialchars($documentTypes[$document['document_type']] ?? $document['document_type']); ?>
                                </td>
                                <td class="text-center align-middle">
                                    <div class="d-flex flex-column align-items-center">
                                        <?php
                                        require_once 'includes/status_helper.php';
                                        echo getDocumentStatusBadge($document, $_SESSION['role']);
                                        ?>
                                        <?php if (!empty($document['osas_approved_at']) || !empty($document['osas_rejected_at'])): ?>
                                            <small class="text-muted mt-1" style="font-size: 0.7rem;">
                                                <?php 
                                                if (!empty($document['osas_approved_at'])) {
                                                    echo 'OSAS approved on ' . date('M d, Y', strtotime($document['osas_approved_at']));
                                                } elseif (!empty($document['osas_rejected_at'])) {
                                                    echo 'OSAS rejected on ' . date('M d, Y', strtotime($document['osas_rejected_at']));
                                                }
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-truncate align-middle" style="max-width: 150px;">
                                    <?php if ($_SESSION['role'] === 'org_adviser'): ?>
                                        <span title="Organization President">Organization President</span>
                                    <?php elseif ($_SESSION['role'] === 'council_adviser'): ?>
                                        <span title="Council President">Council President</span>
                                    <?php else: ?>
                                        <?php
                                        $mostRecentProcessor = '';
                                        
                                        // Check current approval/rejection status first
                                        if (!empty($document['osas_approved_at'])) {
                                            $mostRecentProcessor = htmlspecialchars($document['osas_name']);
                                        } elseif (!empty($document['osas_rejected_at'])) {
                                            $mostRecentProcessor = htmlspecialchars($document['osas_name']);
                                        } elseif (!empty($document['adviser_approved_at'])) {
                                            $mostRecentProcessor = htmlspecialchars($document['adviser_name']);
                                        } elseif (!empty($document['adviser_rejected_at'])) {
                                            $mostRecentProcessor = htmlspecialchars($document['adviser_name']);
                                        }
                                        
                                        if (empty($mostRecentProcessor)) {
                                            echo '<span class="text-muted">No processing yet</span>';
                                        } else {
                                            if (($_SESSION['role'] === 'org_adviser' || $_SESSION['role'] === 'council_adviser') && $document['status'] === 'rejected') {
                                                echo '<span title="' . htmlspecialchars($mostRecentProcessor . ' (View only)') . '">' . $mostRecentProcessor . ' <small class="text-muted">(View only)</small></span>';
                                            } else {
                                                echo '<span title="' . htmlspecialchars($mostRecentProcessor) . '">' . $mostRecentProcessor . '</span>';
                                            }
                                        }
                                        ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center align-middle">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-info" onclick="viewEventDoc('<?php echo htmlspecialchars($document['file_path']); ?>', '<?php echo addslashes($documentTypes[$document['document_type']] ?? $document['document_type']); ?>')">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <?php if (($_SESSION['role'] === 'org_adviser' || $_SESSION['role'] === 'council_adviser') && $document['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-sm btn-success" 
                                                onclick="approveDocument(<?php echo $document['id']; ?>)">
                                                <i class="bi bi-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                onclick="rejectDocument(<?php echo $document['id']; ?>)">
                                                <i class="bi bi-x"></i> Reject
                                            </button>
                                        <?php elseif (($_SESSION['role'] !== 'org_adviser' && $_SESSION['role'] !== 'council_adviser') && $document['status'] === 'rejected'): ?>
                                            <button type="button" class="btn btn-sm btn-primary resubmit-btn" 
                                                data-document-id="<?php echo $document['id']; ?>" 
                                                data-rejection-reason="<?php echo htmlspecialchars($document['rejection_reason'] ?? '', ENT_QUOTES); ?>" 
                                                data-file-path="<?php echo htmlspecialchars($document['file_path'], ENT_QUOTES); ?>" 
                                                data-document-type="<?php echo htmlspecialchars($document['document_type'], ENT_QUOTES); ?>">
                                                <i class="bi bi-arrow-clockwise"></i> Resubmit
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

<script>

// Function to approve document
function approveDocument(documentId) {
    if (confirm('Are you sure you want to approve this document? All documents must be approved before sending to OSAS.')) {
        // Find the approve button and add loading effect
        const approveButton = document.querySelector(`button[onclick="approveDocument(${documentId})"]`);
        let originalText = '';
        
        if (approveButton) {
            originalText = approveButton.innerHTML;
            approveButton.disabled = true;
            approveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Approving...';
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        // Determine the correct action URL based on user role
        const userRole = '<?php echo $_SESSION['role']; ?>';
        const actionUrl = userRole === 'council_adviser' ? 'council_adviser_view_event_files.php' : 'adviser_view_event_files.php';
        form.action = actionUrl;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve';
        
        const documentInput = document.createElement('input');
        documentInput.type = 'hidden';
        documentInput.name = 'document_id';
        documentInput.value = documentId;
        
        form.appendChild(actionInput);
        form.appendChild(documentInput);
        document.body.appendChild(form);
        
        // Submit form and handle response
        fetch(actionUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
        .then(response => response.text())
        .then(text => {
            if (text.includes('successfully')) {
                // Show success message
                alert('Document approved successfully!');
                // Reload the page to show updated status
                location.reload();
            } else {
                alert('Error: ' + text);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            if (approveButton) {
                approveButton.disabled = false;
                approveButton.innerHTML = originalText;
            }
        });
        
        // Remove the form from DOM
        document.body.removeChild(form);
    }
}

// Function to reject document
function rejectDocument(documentId) {
    const reason = prompt('Please provide a reason for rejecting this document:');
    if (reason && reason.trim() !== '') {
        // Find the reject button and add loading effect
        const rejectButton = document.querySelector(`button[onclick="rejectDocument(${documentId})"]`);
        let originalText = '';
        
        if (rejectButton) {
            originalText = rejectButton.innerHTML;
            rejectButton.disabled = true;
            rejectButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Rejecting...';
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        // Determine the correct action URL based on user role
        const userRole = '<?php echo $_SESSION['role']; ?>';
        const actionUrl = userRole === 'council_adviser' ? 'council_adviser_view_event_files.php' : 'adviser_view_event_files.php';
        form.action = actionUrl;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'reject';
        
        const documentInput = document.createElement('input');
        documentInput.type = 'hidden';
        documentInput.name = 'document_id';
        documentInput.value = documentId;
        
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'rejection_reason';
        reasonInput.value = reason.trim();
        
        form.appendChild(actionInput);
        form.appendChild(documentInput);
        form.appendChild(reasonInput);
        document.body.appendChild(form);
        
        // Submit form and handle response
        fetch(actionUrl, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
        .then(response => response.text())
        .then(text => {
            if (text.includes('successfully')) {
                // Show success message
                alert('Document rejected successfully!');
                // Reload the page to show updated status
                location.reload();
            } else {
                alert('Error: ' + text);
            }
        })
        .catch(error => {
            alert('Error: ' + error.message);
        })
        .finally(() => {
            // Reset button state
            if (rejectButton) {
                rejectButton.disabled = false;
                rejectButton.innerHTML = originalText;
            }
        });
        
        // Remove the form from DOM
        document.body.removeChild(form);
    } else if (reason !== null) {
        alert('Please provide a rejection reason.');
    }
}
// Inline viewer using existing parent modal if present
function viewEventDoc(filePath, docTitle) {
    // Create a temporary modal if not already present in parent page
    let modal = document.getElementById('fileViewerModal');
    let created = false;
    if (!modal) {
        created = true;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <div class="modal fade" id="fileViewerModal" tabindex="-1">
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="fileViewerTitle">View Document</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="fileViewerBody" style="min-height:500px; display:flex; align-items:center; justify-content:center; background:#f8f9fa;"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(wrapper.firstElementChild);
        modal = document.getElementById('fileViewerModal');
    }
    const body = document.getElementById('fileViewerBody');
    const title = document.getElementById('fileViewerTitle');
    if (title) title.textContent = docTitle || 'View Document';
    if (body) body.innerHTML = '<div class="w-100 text-center text-muted">Loading...</div>';

    const ext = (filePath.split('.').pop() || '').toLowerCase();
    if (["jpg","jpeg","png","gif","bmp","webp"].includes(ext)) {
        setTimeout(function(){
            body.innerHTML = '<img src="' + filePath + '" alt="Document Image" style="max-width:100%; max-height:70vh; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,0.12);">';
        }, 200);
    } else if (ext === 'pdf') {
        // Ensure pdf viewer assets exist on parent
        if (typeof CustomPDFViewer === 'undefined') {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'assets/css/pdf-viewer.css';
            document.head.appendChild(link);
            const script = document.createElement('script');
            script.src = 'assets/js/pdf-viewer.js';
            script.onload = function(){
                const containerId = 'pdf-viewer-' + Math.random().toString(36).substring(2);
                body.innerHTML = '<div id="' + containerId + '" class="pdf-viewer-container" style="width:100%; height:70vh;"></div>';
                const container = document.getElementById(containerId);
                const viewer = new CustomPDFViewer(container, { showToolbar: true, enableZoom: true, enableDownload: true, enablePrint: true });
                viewer.loadDocument(filePath);
            };
            document.body.appendChild(script);
        } else {
            const containerId = 'pdf-viewer-' + Math.random().toString(36).substring(2);
            body.innerHTML = '<div id="' + containerId + '" class="pdf-viewer-container" style="width:100%; height:70vh;"></div>';
            const container = document.getElementById(containerId);
            const viewer = new CustomPDFViewer(container, { showToolbar: true, enableZoom: true, enableDownload: true, enablePrint: true });
            viewer.loadDocument(filePath);
        }
    } else {
        setTimeout(function(){
            body.innerHTML = '<div class="alert alert-info w-100">Cannot preview this file type. <a href="' + filePath + '" target="_blank">Download/View in new tab</a></div>';
        }, 200);
    }

    const bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}
</script>
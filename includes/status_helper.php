<?php
/**
 * Status Helper Functions for Event Documents
 * Standardized status logic across President, Adviser, and OSAS roles
 */

/**
 * Determine overall event status for President side (Council and Organization)
 * Rules:
 * - Approved: if all documents are approved by OSAS
 * - In Review: if all documents are approved by Adviser
 * - Rejected: if all documents are rejected by OSAS or Adviser
 * - Pending: if documents are mixed (some in review, some rejected, or some with no action)
 */
function getPresidentEventStatus($totalDocs, $approvedDocs, $rejectedDocs, $sentDocs, $pendingDocs) {
    if ($totalDocs == 0) {
        return 'no_documents';
    }
    
    // All documents approved by OSAS
    if ($approvedDocs == $totalDocs && $totalDocs > 0) {
        return 'approved';
    }
    
    // All documents rejected by OSAS or Adviser
    if ($rejectedDocs == $totalDocs && $totalDocs > 0) {
        return 'rejected';
    }
    
    // All documents approved by Adviser (sent to OSAS)
    if ($sentDocs == $totalDocs && $totalDocs > 0) {
        return 'in_review';
    }
    
    // Mixed status - some approved, some rejected, some pending
    if (($approvedDocs > 0 && $rejectedDocs > 0) || 
        ($rejectedDocs > 0 && $pendingDocs > 0) || 
        ($approvedDocs > 0 && $pendingDocs > 0) ||
        ($approvedDocs > 0 && $rejectedDocs > 0 && $pendingDocs > 0)) {
        return 'pending';
    }
    
    // Default to pending for any other mixed state
    return 'pending';
}

/**
 * Determine overall event status for Adviser side (Council and Organization)
 * Rules:
 * - Sent to OSAS: if ALL documents are approved by Adviser (sent to OSAS)
 * - Approved: if all documents are approved by OSAS
 * - Rejected: if all documents are rejected by OSAS
 * - Pending: if mixed or not all documents approved by adviser
 */
function getAdviserEventStatus($totalDocs, $approvedDocs, $rejectedDocs, $sentDocs, $pendingDocs) {
    if ($totalDocs == 0) {
        return 'no_documents';
    }
    
    // All documents approved by OSAS
    if ($approvedDocs == $totalDocs && $totalDocs > 0) {
        return 'approved';
    }
    
    // All documents rejected by OSAS
    if ($rejectedDocs == $totalDocs && $totalDocs > 0) {
        return 'rejected';
    }
    
    // ALL documents approved by Adviser (sent to OSAS)
    if ($sentDocs == $totalDocs && $totalDocs > 0) {
        return 'sent_to_osas';
    }
    
    // If we have some documents in different states, it's pending
    if ($approvedDocs > 0 || $rejectedDocs > 0 || $sentDocs > 0 || $pendingDocs > 0) {
        return 'pending';
    }
    
    // Default to pending for any other state
    return 'pending';
}

/**
 * Determine overall event status for OSAS side
 * Rules:
 * - Approved: if all documents are approved by OSAS
 * - Rejected: if all documents are rejected by OSAS
 * - Pending: if mixed
 */
function getOsasEventStatus($totalDocs, $approvedDocs, $rejectedDocs) {
    if ($totalDocs == 0) {
        return 'no_documents';
    }
    
    // All documents approved by OSAS
    if ($approvedDocs == $totalDocs && $totalDocs > 0) {
        return 'approved';
    }
    
    // All documents rejected by OSAS
    if ($rejectedDocs == $totalDocs && $totalDocs > 0) {
        return 'rejected';
    }
    
    // Mixed status - some approved, some rejected
    return 'pending';
}

/**
 * Get status badge HTML for President side
 */
function getPresidentStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge bg-success">APPROVED</span>';
        case 'in_review':
            return '<span class="badge bg-primary">IN REVIEW</span>';
        case 'rejected':
            return '<span class="badge bg-danger">REJECTED</span>';
        case 'pending':
            return '<span class="badge bg-warning text-dark">PENDING</span>';
        case 'no_documents':
            return '<span class="badge bg-warning">No Documents</span>';
        default:
            return '<span class="badge bg-warning text-dark">PENDING</span>';
    }
}

/**
 * Get status badge HTML for Adviser side
 */
function getAdviserStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge bg-success">APPROVED</span>';
        case 'sent_to_osas':
            return '<span class="badge bg-primary">SENT TO OSAS</span>';
        case 'rejected':
            return '<span class="badge bg-danger">REJECTED</span>';
        case 'pending':
            return '<span class="badge bg-warning text-dark">PENDING</span>';
        case 'no_documents':
            return '<span class="badge bg-warning">No Documents</span>';
        default:
            return '<span class="badge bg-warning text-dark">PENDING</span>';
    }
}

/**
 * Get status badge HTML for OSAS side
 */
function getOsasStatusBadge($status) {
    switch ($status) {
        case 'approved':
            return '<span class="badge bg-success">Approved</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';
        case 'pending':
            return '<span class="badge bg-warning text-dark">Pending Review</span>';
        case 'no_documents':
            return '<span class="badge bg-warning">No Documents</span>';
        default:
            return '<span class="badge bg-warning text-dark">Pending Review</span>';
    }
}

/**
 * Check if an event should be visible to OSAS
 * Events are only visible when ALL documents are approved by adviser AND no mixed states exist
 * IMPORTANT: Events with resubmitted documents and previously rejected documents should not be visible
 */
function shouldEventBeVisibleToOsas($totalDocs, $adviserApprovedDocs, $osasApprovedDocs, $osasRejectedDocs) {
    // Event is visible ONLY when ALL documents are approved by adviser (ready for OSAS review)
    // AND no documents have been rejected by OSAS without being resubmitted
    return ($adviserApprovedDocs == $totalDocs && $totalDocs > 0 && $osasRejectedDocs == 0);
}

/**
 * Check if an event has resubmitted documents that require adviser re-review
 * This function helps identify events that should be hidden from OSAS due to resubmissions
 */
function hasResubmittedDocuments($totalDocs, $adviserApprovedDocs, $osasApprovedDocs, $osasRejectedDocs) {
    // If we have OSAS actions but not all documents are adviser-approved, 
    // it means some documents were resubmitted and are pending adviser review
    if (($osasApprovedDocs > 0 || $osasRejectedDocs > 0) && $adviserApprovedDocs < $totalDocs) {
        return true;
    }
    
    return false;
}

/**
 * Check if an event has mixed states that should not be visible to OSAS
 * Mixed states include: resubmitted documents + previously rejected documents
 * This function helps identify events that need complete cleanup before OSAS visibility
 */
function hasMixedDocumentStates($totalDocs, $adviserApprovedDocs, $osasApprovedDocs, $osasRejectedDocs) {
    // Mixed state exists if:
    // 1. Some documents are adviser-approved (resubmitted and re-approved)
    // 2. AND some documents are still OSAS-rejected (not yet resubmitted)
    // 3. AND not all documents are adviser-approved
    if ($adviserApprovedDocs > 0 && $osasRejectedDocs > 0 && $adviserApprovedDocs < $totalDocs) {
        return true;
    }
    
    return false;
}

/**
 * Get per-document status for display
 * Standardized across all roles - no more "Awaiting for Resubmission"
 * Key change: Adviser-approved documents show as "SENT TO OSAS", not "APPROVED"
 */
function getDocumentStatusBadge($document, $userRole) {
    $status = $document['status'];
    $osasApproved = !empty($document['osas_approved_at']);
    $osasRejected = !empty($document['osas_rejected_at']);
    $adviserApproved = !empty($document['adviser_approved_at']);
    
    if ($osasApproved) {
        return '<span class="badge bg-success">APPROVED</span>';
    } elseif ($osasRejected) {
        return '<span class="badge bg-danger text-white">REJECTED</span>';
    } elseif ($adviserApproved && !$osasApproved && !$osasRejected) {
        // Document is approved by adviser and sent to OSAS (not yet processed by OSAS)
        if (in_array($userRole, ['org_adviser', 'council_adviser'])) {
            return '<span class="badge bg-info text-white">SENT TO OSAS</span>';
        } else {
            return '<span class="badge bg-info">IN REVIEW</span>';
        }
    } elseif ($status === 'rejected') {
        return '<span class="badge bg-danger text-white">REJECTED</span>';
    } else {
        return '<span class="badge bg-warning text-dark">PENDING</span>';
    }
}
?>

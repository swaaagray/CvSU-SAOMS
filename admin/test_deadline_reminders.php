<?php
/**
 * Test Deadline Reminders
 * 
 * This script allows administrators to manually test the deadline reminder system.
 * It can be accessed via web browser to trigger deadline reminders for testing.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/deadline_reminder_helper.php';

// Ensure user is logged in and has admin privileges
requireLogin();

// Check if user has OSAS role
if (!isOSASUser()) {
    header('Location: ../unauthorized.php');
    exit;
}

$message = '';
$results = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'test_reminders':
            try {
                $results = checkAndSendDeadlineReminders();
                $message = "Deadline reminder test completed successfully!";
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
            }
            break;
            
        case 'get_statistics':
            try {
                $results = getDeadlineStatistics();
                $message = "Deadline statistics retrieved successfully!";
            } catch (Exception $e) {
                $message = "Error: " . $e->getMessage();
            }
            break;
    }
}

// Get current deadline statistics
$currentStats = getDeadlineStatistics();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Deadline Reminders - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-clock-history"></i> Test Deadline Reminders</h1>
                    <a href="../dashboard.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Current Statistics -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Current Deadline Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-primary"><?php echo $currentStats['total_rejected_with_deadlines']; ?></h3>
                                    <p class="text-muted">Total Rejected with Deadlines</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-warning"><?php echo $currentStats['deadlines_today']; ?></h3>
                                    <p class="text-muted">Deadlines Today</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-info"><?php echo $currentStats['deadlines_tomorrow']; ?></h3>
                                    <p class="text-muted">Deadlines Tomorrow</p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="text-center">
                                    <h3 class="text-danger"><?php echo $currentStats['overdue_deadlines']; ?></h3>
                                    <p class="text-muted">Overdue Deadlines</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Actions -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-play-circle"></i> Test Deadline Reminders</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    This will check for documents with deadlines approaching in the next hour 
                                    and send reminder notifications to presidents and advisers.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="action" value="test_reminders">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-send"></i> Run Deadline Reminder Test
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Get Detailed Statistics</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    Get detailed statistics about deadlines including upcoming and overdue deadlines.
                                </p>
                                <form method="POST">
                                    <input type="hidden" name="action" value="get_statistics">
                                    <button type="submit" class="btn btn-info">
                                        <i class="bi bi-graph-up"></i> Get Statistics
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Results Display -->
                <?php if ($results): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list-check"></i> Test Results</h5>
                        </div>
                        <div class="card-body">
                            <pre class="bg-light p-3 rounded"><?php echo json_encode($results, JSON_PRETTY_PRINT); ?></pre>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Information -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> How It Works</h5>
                    </div>
                    <div class="card-body">
                        <h6>Deadline Reminder System:</h6>
                        <ul>
                            <li><strong>Automatic Check:</strong> The system checks every 5 minutes for documents with deadlines approaching in the next hour</li>
                            <li><strong>Notification Types:</strong> Both in-app notifications and email reminders are sent</li>
                            <li><strong>Recipients:</strong> Both the organization/council president and adviser receive reminders</li>
                            <li><strong>Compliance Check:</strong> Only sends reminders for documents that haven't been resubmitted yet</li>
                            <li><strong>Document Types:</strong> Covers organization documents, event documents, and council documents</li>
                        </ul>
                        
                        <h6>Cron Job Setup:</h6>
                        <p>To enable automatic reminders, set up a cron job to run every 5 minutes:</p>
                        <code>*/5 * * * * php /path/to/your/project/cron/deadline_reminder_cron.php</code>
                        
                        <h6>Windows Task Scheduler:</h6>
                        <p>For Windows servers, create a scheduled task to run the cron script every 5 minutes.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

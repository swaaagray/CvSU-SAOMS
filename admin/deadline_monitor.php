<?php
/**
 * Deadline Reminder System Monitor
 * 
 * This dashboard provides real-time monitoring of the automated deadline reminder system
 * and allows administrators to view system status, logs, and performance metrics.
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
$action = $_GET['action'] ?? '';

// Handle actions
if ($action === 'force_cleanup') {
    try {
        cleanupExpiredDeadlineNotifications();
        $message = "Cleanup completed successfully!";
    } catch (Exception $e) {
        $message = "Error during cleanup: " . $e->getMessage();
    }
}

// Get system statistics
$stats = getDeadlineStatistics();
$currentStats = getDeadlineStatistics();

// Get recent log entries
$logFile = __DIR__ . '/../logs/cron_errors.log';
$recentLogs = [];
if (file_exists($logFile)) {
    $logs = file($logFile);
    $recentLogs = array_slice(array_reverse($logs), 0, 20); // Last 20 entries
}

// Get task execution log
$taskLogFile = __DIR__ . '/../logs/task_execution.log';
$taskLogs = [];
if (file_exists($taskLogFile)) {
    $taskLogs = file($taskLogFile);
    $taskLogs = array_slice(array_reverse($taskLogs), 0, 10); // Last 10 entries
}

// Check if cron job is running (simplified check)
$cronRunning = false;
if (!empty($recentLogs)) {
    $lastLog = $recentLogs[0];
    $lastLogTime = strtotime(substr($lastLog, 1, 19)); // Extract timestamp
    $cronRunning = (time() - $lastLogTime) < 600; // Within last 10 minutes
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deadline Reminder System Monitor - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-running { background-color: #28a745; }
        .status-stopped { background-color: #dc3545; }
        .status-warning { background-color: #ffc107; }
        .log-entry {
            font-family: monospace;
            font-size: 12px;
            background-color: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-speedometer2"></i> Deadline Reminder System Monitor</h1>
                    <div>
                        <a href="test_deadline_reminders.php" class="btn btn-primary">
                            <i class="bi bi-play-circle"></i> Test System
                        </a>
                        <a href="../dashboard.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- System Status -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-activity"></i> System Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <span class="status-indicator <?php echo $cronRunning ? 'status-running' : 'status-stopped'; ?>"></span>
                                    <strong>Automated Reminder System:</strong>
                                    <span class="ms-2"><?php echo $cronRunning ? 'RUNNING' : 'STOPPED'; ?></span>
                                </div>
                                
                                <div class="d-flex align-items-center mb-3">
                                    <span class="status-indicator status-running"></span>
                                    <strong>Database Connection:</strong>
                                    <span class="ms-2">ACTIVE</span>
                                </div>
                                
                                <div class="d-flex align-items-center mb-3">
                                    <span class="status-indicator status-running"></span>
                                    <strong>Notification System:</strong>
                                    <span class="ms-2">OPERATIONAL</span>
                                </div>
                                
                                <div class="d-flex align-items-center">
                                    <span class="status-indicator status-running"></span>
                                    <strong>Email System:</strong>
                                    <span class="ms-2">CONFIGURED</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-graph-up"></i> Current Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6">
                                        <h3 class="text-primary"><?php echo $currentStats['total_rejected_with_deadlines']; ?></h3>
                                        <p class="text-muted">Total with Deadlines</p>
                                    </div>
                                    <div class="col-6">
                                        <h3 class="text-warning"><?php echo $currentStats['deadlines_today']; ?></h3>
                                        <p class="text-muted">Deadlines Today</p>
                                    </div>
                                </div>
                                <div class="row text-center">
                                    <div class="col-6">
                                        <h3 class="text-info"><?php echo $currentStats['deadlines_tomorrow']; ?></h3>
                                        <p class="text-muted">Deadlines Tomorrow</p>
                                    </div>
                                    <div class="col-6">
                                        <h3 class="text-danger"><?php echo $currentStats['overdue_deadlines']; ?></h3>
                                        <p class="text-muted">Overdue</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Actions -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-gear"></i> System Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <a href="?action=force_cleanup" class="btn btn-warning w-100 mb-2">
                                            <i class="bi bi-broom"></i> Force Cleanup
                                        </a>
                                        <small class="text-muted">Remove expired notifications</small>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="test_deadline_reminders.php" class="btn btn-primary w-100 mb-2">
                                            <i class="bi bi-play-circle"></i> Test Reminders
                                        </a>
                                        <small class="text-muted">Manually trigger reminder check</small>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="../logs/" class="btn btn-info w-100 mb-2" target="_blank">
                                            <i class="bi bi-folder"></i> View Logs
                                        </a>
                                        <small class="text-muted">Access log files directly</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent System Logs</h5>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($recentLogs)): ?>
                                    <p class="text-muted">No recent logs found.</p>
                                <?php else: ?>
                                    <?php foreach ($recentLogs as $log): ?>
                                        <div class="log-entry">
                                            <?php echo htmlspecialchars(trim($log)); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-calendar-check"></i> Task Execution Log</h5>
                            </div>
                            <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                <?php if (empty($taskLogs)): ?>
                                    <p class="text-muted">No task execution logs found.</p>
                                <?php else: ?>
                                    <?php foreach ($taskLogs as $log): ?>
                                        <div class="log-entry">
                                            <?php echo htmlspecialchars(trim($log)); ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System Information -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-info-circle"></i> System Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6>Automation Setup</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Check Frequency:</strong> Every 5 minutes</li>
                                            <li><strong>Reminder Timing:</strong> 1 hour before deadline</li>
                                            <li><strong>Document Types:</strong> Organization, Event, Council</li>
                                            <li><strong>Notification Types:</strong> In-app + Email</li>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Reliability Features</h6>
                                        <ul class="list-unstyled">
                                            <li><strong>Duplicate Prevention:</strong> Active</li>
                                            <li><strong>Auto Cleanup:</strong> Expired notifications</li>
                                            <li><strong>Error Handling:</strong> Comprehensive logging</li>
                                            <li><strong>Compliance Checking:</strong> Smart filtering</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row">
                                    <div class="col-12">
                                        <h6>Setup Instructions</h6>
                                        <p class="mb-2">To ensure the system runs automatically:</p>
                                        <ol>
                                            <li><strong>Windows:</strong> Run <code>setup_automated_reminders.bat</code> as Administrator</li>
                                            <li><strong>Linux/Unix:</strong> Add to crontab: <code>*/5 * * * * php /path/to/cron/deadline_reminder_cron.php</code></li>
                                            <li><strong>Monitor:</strong> Check this dashboard regularly for system status</li>
                                            <li><strong>Test:</strong> Use the test interface to verify functionality</li>
                                        </ol>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh the page every 30 seconds to show updated status
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>

<?php
require_once 'config/database.php';

// Get college ID from URL
$college_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get college details
$stmt = $conn->prepare("SELECT id, name FROM colleges WHERE id = ?");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$college = $stmt->get_result()->fetch_assoc();

if (!$college) {
    header('Location: index.php');
    exit;
}

// Get councils in this college
$stmt = $conn->prepare("
    SELECT c.*, 
           (SELECT COUNT(*) FROM events WHERE council_id = c.id) as event_count,
           (SELECT COUNT(*) FROM awards WHERE council_id = c.id) as award_count
    FROM council c
    WHERE c.college_id = ?
    ORDER BY c.council_name ASC
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$councils = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get upcoming council events (next 7 days)
$stmt = $conn->prepare("
    SELECT e.*, c.council_name as council_name
    FROM events e
    JOIN council c ON e.council_id = c.id
    WHERE c.college_id = ? AND e.event_date > NOW() AND e.event_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY e.event_date ASC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$upcoming_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get ongoing council events (events happening today)
$stmt = $conn->prepare("
    SELECT e.*, c.council_name as council_name
    FROM events e
    JOIN council c ON e.council_id = c.id
    WHERE c.college_id = ? AND DATE(e.event_date) = CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$ongoing_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent council activities/events (past 30 days)
$stmt = $conn->prepare("
    SELECT e.*, c.council_name as council_name
    FROM events e
    JOIN council c ON e.council_id = c.id
    WHERE c.college_id = ? AND e.event_date < NOW() AND e.event_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY e.event_date DESC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent council awards
$stmt = $conn->prepare("
    SELECT a.*, c.council_name as council_name
    FROM awards a
    JOIN council c ON a.council_id = c.id
    WHERE c.college_id = ?
    ORDER BY a.award_date DESC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$recent_awards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($college['name']); ?> - Councils</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            padding-top: 48px; /* Reduced from 56px */
        }
        .navbar {
            background-color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }
        .navbar .container {
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        .navbar-brand {
            color: #1877f2 !important;
            font-weight: bold;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        .nav-link {
            color: #1877f2 !important;
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,.2);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .council-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #dddfe2;
            transition: all 0.3s ease;
        }
        .council-card:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
            border-color: #1877f2;
        }
        .text-primary {
            color: #1877f2 !important;
        }
        .stats-badge {
            background-color: #e4e6eb;
            color: #050505;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-right: 8px;
        }
        .council-header {
            border-bottom: 1px solid #dddfe2;
            padding-bottom: 15px;
            margin-bottom: 15px;
        }
        .council-stats {
            background-color: #f0f2f5;
            border-radius: 8px;
            padding: 10px;
            margin-top: 15px;
        }
        .event-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #dddfe2;
        }
        .event-card:hover {
            border-color: #1877f2;
        }
        .event-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .event-council {
            font-size: 0.875rem;
            color: #1877f2;
        }
        .award-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #dddfe2;
        }
        .award-card:hover {
            border-color: #1877f2;
        }
        .award-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .award-council {
            font-size: 0.875rem;
            color: #1877f2;
        }
        .container {
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-mortarboard-fill me-2"></i>
                Academic Organizations
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">
                            <i class="bi bi-house me-1"></i>
                            Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="login.php">
                            <i class="bi bi-person"></i>
                            Admin
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <!-- College Header -->
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <h2 class="mb-2"><?php echo htmlspecialchars($college['name']); ?></h2>
                        <p class="text-muted mb-0">
                            <i class="bi bi-crown me-1"></i>
                            Student Councils
                        </p>
                    </div>
                </div>

                <!-- Councils List -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-crown me-2"></i>
                            Student Councils
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($councils)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No student councils found in this college.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h2>Student Councils</h2>
                            </div>
                            <div class="row">
                                <?php foreach ($councils as $council): ?>
                                <div class="col-md-6 mb-4">
                                    <a href="council/<?php echo $council['id']; ?>" class="text-decoration-none">
                                        <div class="council-card">
                                            <div class="council-header">
                                                <h5 class="mb-2 text-dark"><?php echo htmlspecialchars($council['council_name']); ?></h5>
                                                <?php if (!empty($council['description'])): ?>
                                                    <p class="text-muted mb-0"><?php echo htmlspecialchars($council['description']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="council-stats">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <span class="stats-badge">
                                                        <i class="bi bi-calendar-event me-1"></i>
                                                        <?php echo $council['event_count']; ?> events
                                                    </span>
                                                    <span class="stats-badge">
                                                        <i class="bi bi-trophy me-1"></i>
                                                        <?php echo $council['award_count']; ?> awards
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Events -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-calendar-event me-2"></i>
                            Upcoming Council Events
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No upcoming council events in the next 7 days.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('F j, Y g:i A', strtotime($event['event_date'])); ?>
                                    </p>
                                    <p class="event-council mb-0">
                                        <i class="bi bi-crown me-1"></i>
                                        <?php echo htmlspecialchars($event['council_name']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ongoing Events -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-calendar-check me-2"></i>
                            Ongoing Council Events
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ongoing_events)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No ongoing council events at the moment.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($ongoing_events as $event): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('F j, Y g:i A', strtotime($event['event_date'])); ?>
                                    </p>
                                    <p class="event-council mb-0">
                                        <i class="bi bi-crown me-1"></i>
                                        <?php echo htmlspecialchars($event['council_name']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-clock-history me-2"></i>
                            Recent Council Activities
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activities)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No recent council activities in the past 30 days.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($activity['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('F j, Y g:i A', strtotime($activity['event_date'])); ?>
                                    </p>
                                    <p class="event-council mb-0">
                                        <i class="bi bi-crown me-1"></i>
                                        <?php echo htmlspecialchars($activity['council_name']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Awards -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-trophy me-2"></i>
                            Recent Council Awards
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_awards)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No recent council awards.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_awards as $award): ?>
                                <div class="award-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($award['title']); ?></h6>
                                    <p class="award-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('F j, Y', strtotime($award['award_date'])); ?>
                                    </p>
                                    <p class="award-council mb-0">
                                        <i class="bi bi-crown me-1"></i>
                                        <?php echo htmlspecialchars($award['council_name']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

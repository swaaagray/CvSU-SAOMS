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

// Get organizations in this college
$stmt = $conn->prepare("
    SELECT o.*, 
           (SELECT COUNT(*) FROM events WHERE organization_id = o.id) as event_count,
           (SELECT COUNT(*) FROM awards WHERE organization_id = o.id) as award_count
    FROM organizations o
    WHERE o.college_id = ?
    ORDER BY o.org_name ASC
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get upcoming events (next 7 days)
$stmt = $conn->prepare("
    SELECT e.*, o.org_name as org_name
    FROM events e
    JOIN organizations o ON e.organization_id = o.id
    WHERE o.college_id = ? AND e.event_date > NOW() AND e.event_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY e.event_date ASC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$upcoming_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get ongoing events (events happening today)
$stmt = $conn->prepare("
    SELECT e.*, o.org_name as org_name
    FROM events e
    JOIN organizations o ON e.organization_id = o.id
    WHERE o.college_id = ? AND DATE(e.event_date) = CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$ongoing_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent activities/events (past 30 days)
$stmt = $conn->prepare("
    SELECT e.*, o.org_name as org_name
    FROM events e
    JOIN organizations o ON e.organization_id = o.id
    WHERE o.college_id = ? AND e.event_date < NOW() AND e.event_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY e.event_date DESC
    LIMIT 3
");
$stmt->bind_param("i", $college_id);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get recent awards
$stmt = $conn->prepare("
    SELECT a.*, o.org_name as org_name
    FROM awards a
    JOIN organizations o ON a.organization_id = o.id
    WHERE o.college_id = ?
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
    <title><?php echo htmlspecialchars($college['name']); ?> - Organizations</title>
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
        :root {
            --cvsu-green: #0b5d1e;
            --cvsu-green-600: #0f7a2a;
            --cvsu-light: #e8f5ed;
            --cvsu-gold: #ffb400;
        }
        .navbar-brand {
            color: var(--cvsu-green) !important;
            font-weight: bold;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
        }
        .nav-link {
            color: var(--cvsu-green) !important;
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }
        .brand-logo {
            height: 36px;
            width: auto;
            margin-right: 0.5rem;
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
        .org-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-green);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 180px;
        }
        .org-card:hover {
            background-color: var(--cvsu-light);
            box-shadow: 0 6px 18px rgba(11, 93, 30, 0.12);
            transform: translateY(-2px);
        }
        .text-primary {
            color: var(--cvsu-green) !important;
        }
        .stats-badge {
            background-color: #e4e6eb;
            color: #050505;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-right: 8px;
        }
        .org-header {
            border-bottom: 1px solid #dddfe2;
            padding-bottom: 15px;
            margin-bottom: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .org-header h5 {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: 10px;
        }
        .org-description {
            color: #65676b;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0;
        }
        .org-description-text {
            color: #65676b;
        }
        .org-more-link {
            color: var(--cvsu-green);
            font-weight: 600;
            text-decoration: none;
            margin-left: 4px;
        }
        .org-more-link:hover {
            color: var(--cvsu-green-600);
            text-decoration: underline;
        }
        .org-stats {
            background-color: var(--cvsu-light);
            border-radius: 8px;
            padding: 10px;
            margin-top: 15px;
        }
        .row > [class*='col-'] {
            display: flex;
            flex-direction: column;
        }
        .event-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-green);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
        }
        .event-card:hover {
            background-color: var(--cvsu-light);
            box-shadow: 0 6px 18px rgba(11, 93, 30, 0.12);
            transform: translateY(-2px);
        }
        .event-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .event-org {
            font-size: 0.875rem;
            color: var(--cvsu-green);
        }
        .award-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-gold);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
        }
        .award-card:hover { background-color: #fff6e0; box-shadow: 0 6px 18px rgba(122, 90, 0, 0.12); }
        .award-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .award-org {
            font-size: 0.875rem;
            color: var(--cvsu-green);
        }
        /* Icon-text alignment helper */
        .icon-text { display: flex; align-items: flex-start; }
        .icon-text .icon { width: 1.25rem; flex: 0 0 1.25rem; }
        .icon-text .text { flex: 1 1 auto; }
        .container {
            padding-top: 20px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/SAOMS-LOGO-GREEN.png" alt="CvSU SAOMS Logo" class="brand-logo">
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
                            Log In
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
                            <i class="bi bi-building me-1"></i>
                            College
                        </p>
                    </div>
                </div>

                <!-- Organizations List -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-people me-2"></i>
                            Organizations
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($organizations)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No organizations found in this college.</p>
                            </div>
                        <?php else: ?>
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h2>Organizations</h2>
                            </div>
                            <div class="row">
                                <?php foreach ($organizations as $org): ?>
                                <div class="col-md-6 mb-4">
                                    <a href="organization_profile.php?id=<?php echo $org['id']; ?>" class="text-decoration-none">
                                        <div class="org-card">
                                            <div class="org-header">
                                                <h5 class="mb-2 text-dark"><?php echo htmlspecialchars($org['org_name']); ?></h5>
                                                <?php if (!empty($org['description'])): ?>
                                                    <?php 
                                                    $description = htmlspecialchars($org['description']);
                                                    $max_length = 100; // Adjust this value to control truncation length
                                                    if (strlen($description) > $max_length) {
                                                        $truncated = substr($description, 0, $max_length);
                                                        // Make sure we don't cut in the middle of a word
                                                        $last_space = strrpos($truncated, ' ');
                                                        if ($last_space !== false) {
                                                            $truncated = substr($truncated, 0, $last_space);
                                                        }
                                                        echo '<p class="org-description mb-0"><span class="org-description-text">' . $truncated . '...</span> <span class="org-more-link">More</span></p>';
                                                    } else {
                                                        echo '<p class="org-description mb-0"><span class="org-description-text">' . $description . '</span></p>';
                                                    }
                                                    ?>
                                                <?php else: ?>
                                                    <p class="text-muted mb-0">No description available.</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="org-stats">
                                                <div class="d-flex align-items-center justify-content-between">
                                                    <span class="stats-badge">
                                                        <i class="bi bi-calendar-event me-1"></i>
                                                        <?php echo $org['event_count']; ?> events
                                                    </span>
                                                    <span class="stats-badge">
                                                        <i class="bi bi-trophy me-1"></i>
                                                        <?php echo $org['award_count']; ?> awards
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
                            Upcoming Events
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No upcoming events in the next 7 days.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('M d, Y g:i A', strtotime($event['event_date'])); ?>
                                    </p>
                                    <p class="event-org mb-0 icon-text">
                                        <i class="bi <?php echo (stripos($event['org_name'] ?? '', 'council') !== false) ? 'bi-award' : 'bi-people'; ?> icon"></i>
                                        <span class="text"><strong><?php echo htmlspecialchars($event['org_name']); ?></strong></span>
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
                            Ongoing Events
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($ongoing_events)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No ongoing events at the moment.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($ongoing_events as $event): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($event['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('M d, Y g:i A', strtotime($event['event_date'])); ?>
                                    </p>
                                    <p class="event-org mb-0 icon-text">
                                        <i class="bi <?php echo (stripos($event['org_name'] ?? '', 'council') !== false) ? 'bi-award' : 'bi-people'; ?> icon"></i>
                                        <span class="text"><strong><?php echo htmlspecialchars($event['org_name']); ?></strong></span>
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
                            Recent Activities
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_activities)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No recent activities in the past 30 days.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="event-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($activity['title']); ?></h6>
                                    <p class="event-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('M d, Y g:i A', strtotime($activity['event_date'])); ?>
                                    </p>
                                    <p class="event-org mb-0 icon-text">
                                        <i class="bi <?php echo (stripos($activity['org_name'] ?? '', 'council') !== false) ? 'bi-award' : 'bi-people'; ?> icon"></i>
                                        <span class="text"><strong><?php echo htmlspecialchars($activity['org_name']); ?></strong></span>
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
                            Recent Awards
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_awards)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No recent awards.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_awards as $award): ?>
                                <div class="award-card">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($award['title']); ?></h6>
                                    <p class="award-date mb-1">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?php echo date('M d, Y', strtotime($award['award_date'])); ?>
                                    </p>
                                    <p class="award-org mb-0">
                                        <i class="bi bi-people me-1"></i>
                                        <?php echo htmlspecialchars($award['org_name']); ?>
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
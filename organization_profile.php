<?php
require_once 'config/database.php';

// Helper function to get current active academic year ID
function getCurrentAcademicTermId($conn) {
    // Try by explicit active status
    $stmt = $conn->prepare("SELECT id FROM academic_terms WHERE status = 'active' ORDER BY start_date DESC LIMIT 1");
    if ($stmt && $stmt->execute()) {
        $row = $stmt->get_result()->fetch_assoc();
        if (!empty($row['id'])) {
            return (int)$row['id'];
        }
    }
    // Fallback by date range
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT id FROM academic_terms WHERE ? BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            $row = $stmt->get_result()->fetch_assoc();
            if (!empty($row['id'])) {
                return (int)$row['id'];
            }
        }
    }
    return null;
}

// Get current active academic year
$current_academic_year_id = getCurrentAcademicTermId($conn);

// Get organization ID from URL
$org_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get organization details
$stmt = $conn->prepare("
    SELECT o.*, c.name as college_name, c.id as college_id
    FROM organizations o
    JOIN colleges c ON o.college_id = c.id
    WHERE o.id = ?
");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$organization = $stmt->get_result()->fetch_assoc();

if (!$organization) {
    header('Location: index.php');
    exit;
}

// Pagination settings
$items_per_page = 6;
$events_page = isset($_GET['events_page']) ? max(1, (int)$_GET['events_page']) : 1;
$awards_page = isset($_GET['awards_page']) ? max(1, (int)$_GET['awards_page']) : 1;

// Get total count of events
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM events WHERE organization_id = ?");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$total_events = $stmt->get_result()->fetch_assoc()['total'];

// Get total count of awards
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM awards WHERE organization_id = ?");
$stmt->bind_param("i", $org_id);
$stmt->execute();
$total_awards = $stmt->get_result()->fetch_assoc()['total'];

// Calculate pagination for events
$events_offset = ($events_page - 1) * $items_per_page;
$total_events_pages = ceil($total_events / $items_per_page);

// Calculate pagination for awards
$awards_offset = ($awards_page - 1) * $items_per_page;
$total_awards_pages = ceil($total_awards / $items_per_page);

// Get organization's events (paginated)
$stmt = $conn->prepare("
    SELECT e.*, 
           (SELECT COUNT(*) FROM event_images WHERE event_id = e.id) as image_count,
           (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id) as participant_count
    FROM events e
    WHERE e.organization_id = ?
    ORDER BY e.event_date DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $org_id, $items_per_page, $events_offset);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get organization's awards (paginated)
$stmt = $conn->prepare("
    SELECT a.*, 
           (SELECT COUNT(*) FROM award_images WHERE award_id = a.id) as image_count
    FROM awards a
    WHERE a.organization_id = ?
    ORDER BY a.award_date DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $org_id, $items_per_page, $awards_offset);
$stmt->execute();
$awards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get organization's officers including advisers (current active academic year)
$stmt = $conn->prepare("
    SELECT so.name, so.course, so.year_section, so.position, so.picture_path
    FROM student_officials so
    JOIN academic_terms at ON so.academic_year_id = at.id
    WHERE so.organization_id = ? AND at.status = 'active'
    UNION ALL
    SELECT COALESCE(o.adviser_name, u.email) as name, '' as course, '' as year_section,
           COALESCE(ao.position, 'Adviser') as position, ao.picture_path
    FROM adviser_officials ao
    JOIN academic_terms at2 ON ao.academic_year_id = at2.id
    LEFT JOIN users u ON ao.adviser_id = u.id
    LEFT JOIN organizations o ON ao.organization_id = o.id
    WHERE ao.organization_id = ? AND at2.status = 'active'
    ORDER BY 
        CASE LOWER(position)
            WHEN 'adviser' THEN 1
            WHEN 'president' THEN 2
            WHEN 'vice president for internal affairs' THEN 3
            WHEN 'vice president for external affairs' THEN 4
            WHEN 'vice president for records and documentation' THEN 5
            WHEN 'vice president for operations and academic affairs' THEN 6
            WHEN 'vice president for finance and budget management' THEN 7
            WHEN 'vice president for audit' THEN 8
            WHEN 'vice president for logistics and property management' THEN 9
            WHEN 'vice president for public relations and information' THEN 10
            WHEN 'vice president for gender and development' THEN 11
            WHEN '2nd year representative' THEN 12
            WHEN '3rd year representative' THEN 13
            WHEN '4th year representative' THEN 14
            ELSE 15
        END,
        name ASC
");
$stmt->bind_param("ii", $org_id, $org_id);
$stmt->execute();
$members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Prepare asset URL helper for pretty URLs
$appBasePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$toPublicUrl = function($path) use ($appBasePath) {
    if (empty($path)) return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path; // external absolute URL
    if ($path[0] === '/') return $path; // already site-absolute
    return $appBasePath . '/' . ltrim($path, '/');
};
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($organization['org_name']); ?> - Organization Profile</title>
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
        .text-primary {
            color: #1877f2 !important;
        }
        .event-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-green);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .event-card:hover { background-color: var(--cvsu-light); box-shadow: 0 6px 18px rgba(11,93,30,0.12); transform: translateY(-2px); }
        .event-card h6 { color: var(--cvsu-green); font-weight: 600; }
        .event-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .award-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-gold);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .award-card:hover { background-color: #fff6e0; box-shadow: 0 6px 18px rgba(122,90,0,0.12); transform: translateY(-2px); }
        .award-card h6 { color: var(--cvsu-green); font-weight: 600; }
        .award-date {
            font-size: 0.875rem;
            color: #65676b;
        }
        .stats-badge {
            background-color: #e4e6eb;
            color: #050505;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-right: 8px;
        }
        .member-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid #e9ecef;
            transition: transform 0.2s, border-color 0.2s;
            min-height: 260px;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }
        .member-card:hover { background-color: var(--cvsu-light); border-color: var(--cvsu-green); transform: translateY(-2px); }
        .member-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto;
        }
        .member-image-placeholder {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background-color: #e4e6eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .member-image-placeholder i {
            font-size: 3rem;
            color: #65676b;
        }
        .container {
            padding-top: 20px;
        }
        .pagination .page-link {
            color: var(--cvsu-green);
            border-color: #dee2e6;
        }
        .pagination .page-item.active .page-link {
            background-color: var(--cvsu-green);
            border-color: var(--cvsu-green);
            color: white;
        }
        .pagination .page-link:hover {
            color: var(--cvsu-green-600);
            background-color: var(--cvsu-light);
        }
        .pagination .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #fff;
            border-color: #dee2e6;
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
                <!-- Organization Header -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <?php if (!empty($organization['logo_path'])): ?>
                            <div class="col-md-3 text-center mb-3 mb-md-0">
                                <img src="<?php echo htmlspecialchars($toPublicUrl($organization['logo_path'])); ?>" 
                                     alt="<?php echo htmlspecialchars($organization['org_name']); ?> Logo" 
                                     class="img-fluid rounded-circle" 
                                     style="max-width: 150px; height: auto;">
                            </div>
                            <?php endif; ?>
                            <div class="col-md-<?php echo !empty($organization['logo_path']) ? '9' : '12'; ?>">
                                <h2 class="mb-2"><?php echo htmlspecialchars($organization['org_name']); ?></h2>
                                <p class="text-muted mb-2">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo htmlspecialchars($organization['college_name']); ?>
                                </p>
                                <?php if (!empty($organization['description'])): ?>
                                    <p class="mb-2"><?php echo htmlspecialchars($organization['description']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($organization['facebook_link'])): ?>
                                    <a href="<?php echo htmlspecialchars($organization['facebook_link']); ?>" 
                                       target="_blank" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-facebook me-1"></i>
                                        Follow on Facebook
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Organization Stats -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-center">
                            <span class="stats-badge">
                                <i class="bi bi-calendar-event me-1"></i>
                                <?php echo $total_events; ?> events
                            </span>
                            <span class="stats-badge">
                                <i class="bi bi-trophy me-1"></i>
                                <?php echo $total_awards; ?> awards
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Events -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-calendar-event me-2"></i>
                            Events
                        </h5>
                    </div>
                    <div class="card-body" id="events-container">
                        <?php if (empty($events)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No events found for this organization.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($events as $event): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="event-card h-100">
                                        <h6 class="mb-2"><?php echo htmlspecialchars($event['title']); ?></h6>
                                        <p class="event-date mb-2">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?php echo date('M d, Y', strtotime($event['event_date'])); ?>
                                        </p>
                                        <p class="event-date mb-2">
                                            <i class="bi bi-clock me-1"></i>
                                            <?php echo date('g:i A', strtotime($event['event_date'])); ?>
                                        </p>
                                        <?php if (!empty($event['venue'])): ?>
                                            <p class="event-date mb-2">
                                                <i class="bi bi-geo-alt me-1"></i>
                                                <?php echo htmlspecialchars($event['venue']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($event['description'])): ?>
                                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars(substr($event['description'], 0, 150)) . (strlen($event['description']) > 150 ? '...' : ''); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($total_events_pages > 1): ?>
                                <nav aria-label="Events pagination" class="mt-3">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $events_page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $events_page - 1; ?>&awards_page=<?php echo $awards_page; ?>">Previous</a>
                                        </li>
                                        <?php for ($i = 1; $i <= $total_events_pages; $i++): ?>
                                            <li class="page-item <?php echo $events_page == $i ? 'active' : ''; ?>">
                                                <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $i; ?>&awards_page=<?php echo $awards_page; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $events_page >= $total_events_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $events_page + 1; ?>&awards_page=<?php echo $awards_page; ?>">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Awards -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-trophy me-2"></i>
                            Awards
                        </h5>
                    </div>
                    <div class="card-body" id="awards-container">
                        <?php if (empty($awards)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No awards found for this organization.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($awards as $award): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="award-card h-100">
                                        <h6 class="mb-2"><?php echo htmlspecialchars($award['title']); ?></h6>
                                        <p class="award-date mb-2">
                                            <i class="bi bi-calendar me-1"></i>
                                            <?php echo date('M d, Y', strtotime($award['award_date'])); ?>
                                        </p>
                                        <?php if (!empty($award['venue'])): ?>
                                            <p class="award-date mb-2">
                                                <i class="bi bi-geo-alt me-1"></i>
                                                <?php echo htmlspecialchars($award['venue']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($award['description'])): ?>
                                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars(substr($award['description'], 0, 150)) . (strlen($award['description']) > 150 ? '...' : ''); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($total_awards_pages > 1): ?>
                                <nav aria-label="Awards pagination" class="mt-3">
                                    <ul class="pagination justify-content-center mb-0">
                                        <li class="page-item <?php echo $awards_page <= 1 ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $events_page; ?>&awards_page=<?php echo $awards_page - 1; ?>">Previous</a>
                                        </li>
                                        <?php for ($i = 1; $i <= $total_awards_pages; $i++): ?>
                                            <li class="page-item <?php echo $awards_page == $i ? 'active' : ''; ?>">
                                                <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $events_page; ?>&awards_page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        <li class="page-item <?php echo $awards_page >= $total_awards_pages ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?id=<?php echo $org_id; ?>&events_page=<?php echo $events_page; ?>&awards_page=<?php echo $awards_page + 1; ?>">Next</a>
                                        </li>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Members Section (flat list, no hierarchy) -->
                <div class="card mb-4">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-people me-2"></i>
                            Organization Officers
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($members)): ?>
                            <div class="text-center p-4">
                                <p class="text-muted mb-0">No officers found for this organization.</p>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($members as $member): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="member-card">
                                            <?php if (!empty($member['picture_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($toPublicUrl($member['picture_path'])); ?>" 
                                                     alt="<?php echo htmlspecialchars($member['name']); ?>" 
                                                     class="member-image mb-2">
                                            <?php else: ?>
                                                <div class="member-image-placeholder mb-2">
                                                    <i class="bi bi-person-circle"></i>
                                                </div>
                                            <?php endif; ?>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($member['name']); ?></h6>
                                            <?php if (!empty($member['position'])): ?>
                                                <p class="text-muted small mb-1"><?php echo htmlspecialchars($member['position']); ?></p>
                                            <?php endif; ?>
                                        <?php if (!empty($member['course'])): ?>
                                            <p class="text-muted small mb-1">
                                                <?php echo htmlspecialchars($member['course']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <?php if (!empty($member['year_section'])): ?>
                                            <p class="text-muted small mb-0">
                                                <?php echo htmlspecialchars($member['year_section']); ?>
                                            </p>
                                        <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const orgId = <?php echo $org_id; ?>;
            let currentEventsPage = <?php echo $events_page; ?>;
            let currentAwardsPage = <?php echo $awards_page; ?>;
            
            // Handle Events pagination
            function initEventsPagination() {
                const eventsPagination = document.querySelector('nav[aria-label="Events pagination"]');
                if (eventsPagination) {
                    eventsPagination.addEventListener('click', function(e) {
                        e.preventDefault();
                        const link = e.target.closest('a.page-link');
                        if (!link || link.closest('.disabled')) return;
                        
                        const url = new URL(link.href, window.location.origin);
                        const eventsPage = parseInt(url.searchParams.get('events_page')) || 1;
                        const awardsPage = parseInt(url.searchParams.get('awards_page')) || currentAwardsPage;
                        
                        loadEventsPage(eventsPage, awardsPage);
                    });
                }
            }
            
            // Handle Awards pagination
            function initAwardsPagination() {
                const awardsPagination = document.querySelector('nav[aria-label="Awards pagination"]');
                if (awardsPagination) {
                    awardsPagination.addEventListener('click', function(e) {
                        e.preventDefault();
                        const link = e.target.closest('a.page-link');
                        if (!link || link.closest('.disabled')) return;
                        
                        const url = new URL(link.href, window.location.origin);
                        const eventsPage = parseInt(url.searchParams.get('events_page')) || currentEventsPage;
                        const awardsPage = parseInt(url.searchParams.get('awards_page')) || 1;
                        
                        loadAwardsPage(eventsPage, awardsPage);
                    });
                }
            }
            
            function loadEventsPage(eventsPage, awardsPage) {
                const container = document.getElementById('events-container');
                if (!container) return;
                
                container.style.opacity = '0.5';
                container.style.transition = 'opacity 0.3s';
                
                const url = `?id=${orgId}&events_page=${eventsPage}&awards_page=${awardsPage}`;
                
                fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newContainer = doc.getElementById('events-container');
                        const newPagination = doc.querySelector('nav[aria-label="Events pagination"]');
                        
                        if (newContainer) {
                            container.innerHTML = newContainer.innerHTML;
                        }
                        
                        // Update pagination
                        const oldPagination = document.querySelector('nav[aria-label="Events pagination"]');
                        if (newPagination && oldPagination) {
                            oldPagination.outerHTML = newPagination.outerHTML;
                            initEventsPagination();
                        }
                        
                        container.style.opacity = '1';
                        currentEventsPage = eventsPage;
                        currentAwardsPage = awardsPage;
                        
                        // Update URL without reload
                        const newUrl = `?id=${orgId}&events_page=${eventsPage}&awards_page=${awardsPage}`;
                        window.history.pushState({eventsPage, awardsPage}, '', newUrl);
                        
                        // Scroll to events section smoothly
                        const eventsCard = container.closest('.card');
                        if (eventsCard) {
                            eventsCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    })
                    .catch(error => {
                        console.error('Error loading events:', error);
                        container.style.opacity = '1';
                    });
            }
            
            function loadAwardsPage(eventsPage, awardsPage) {
                const container = document.getElementById('awards-container');
                if (!container) return;
                
                container.style.opacity = '0.5';
                container.style.transition = 'opacity 0.3s';
                
                const url = `?id=${orgId}&events_page=${eventsPage}&awards_page=${awardsPage}`;
                
                fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newContainer = doc.getElementById('awards-container');
                        const newPagination = doc.querySelector('nav[aria-label="Awards pagination"]');
                        
                        if (newContainer) {
                            container.innerHTML = newContainer.innerHTML;
                        }
                        
                        // Update pagination
                        const oldPagination = document.querySelector('nav[aria-label="Awards pagination"]');
                        if (newPagination && oldPagination) {
                            oldPagination.outerHTML = newPagination.outerHTML;
                            initAwardsPagination();
                        }
                        
                        container.style.opacity = '1';
                        currentEventsPage = eventsPage;
                        currentAwardsPage = awardsPage;
                        
                        // Update URL without reload
                        const newUrl = `?id=${orgId}&events_page=${eventsPage}&awards_page=${awardsPage}`;
                        window.history.pushState({eventsPage, awardsPage}, '', newUrl);
                        
                        // Scroll to awards section smoothly
                        const awardsCard = container.closest('.card');
                        if (awardsCard) {
                            awardsCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    })
                    .catch(error => {
                        console.error('Error loading awards:', error);
                        container.style.opacity = '1';
                    });
            }
            
            // Initialize pagination listeners
            initEventsPagination();
            initAwardsPagination();
            
            // Handle browser back/forward buttons
            window.addEventListener('popstate', function(e) {
                if (e.state) {
                    const eventsPage = e.state.eventsPage || currentEventsPage;
                    const awardsPage = e.state.awardsPage || currentAwardsPage;
                    
                    if (eventsPage !== currentEventsPage) {
                        loadEventsPage(eventsPage, awardsPage);
                    } else if (awardsPage !== currentAwardsPage) {
                        loadAwardsPage(eventsPage, awardsPage);
                    }
                }
            });
        });
    </script>
</body>
</html> 
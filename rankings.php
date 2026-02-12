<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['mis_coordinator']);

// Get user role and college ID for MIS coordinators
$role = getUserRole();
$userId = getCurrentUserId();
$collegeId = null;

// If MIS coordinator, get their assigned college
if ($role === 'mis_coordinator') {
    $stmt = $conn->prepare("
        SELECT mc.college_id
        FROM mis_coordinators mc
        WHERE mc.user_id = ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $collegeId = $result['college_id'] ?? null;
    $stmt->close();
}

// Get filter parameters
$rankingType = $_GET['ranking_type'] ?? 'events';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// Determine if user is actively filtering (include archived only when filtering)
$isFiltering = !empty($startDate) || !empty($endDate);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

// Build date filter based on date range
$dateFilter = "";
$dateParams = [];
$dateTypes = "";

// Function to get activity rankings by organization (top 5)
function getEventRankings($conn, $startDate, $endDate, $statusCondition, $collegeId = null) {
    $query = "
        SELECT 
            o.id,
            o.org_name as organization_name,
            o.code,
            c.name as college_name,
            COUNT(e.id) as total_activities,
            SUM((SELECT COUNT(*) FROM event_participants WHERE event_id = e.id)) as total_participants,
            SUM((SELECT COUNT(*) FROM event_images WHERE event_id = e.id)) as total_images,
            GROUP_CONCAT(DISTINCT e.title ORDER BY e.event_date DESC SEPARATOR ', ') as recent_activities,
            MAX(e.event_date) as latest_activity_date,
            MIN(e.event_date) as earliest_activity_date
        FROM organizations o
        LEFT JOIN colleges c ON o.college_id = c.id
        LEFT JOIN events e ON e.organization_id = o.id
        JOIN academic_semesters s ON e.semester_id = s.id
        JOIN academic_terms at ON s.academic_term_id = at.id
        WHERE o.status = 'recognized' AND e.id IS NOT NULL AND $statusCondition
    ";
    
    $params = [];
    $types = "";
    
    // Filter by college for MIS coordinators
    if ($collegeId !== null) {
        $query .= " AND o.college_id = ?";
        $params[] = $collegeId;
        $types .= "i";
    }
    
    if ($startDate) {
        $query .= " AND e.event_date >= ?";
        $params[] = $startDate;
        $types .= "s";
    }
    
    if ($endDate) {
        $query .= " AND e.event_date <= ?";
        $params[] = $endDate;
        $types .= "s";
    }
    
    $query .= "
        GROUP BY o.id, o.org_name, o.code, c.name
        ORDER BY total_activities DESC, total_participants DESC, total_images DESC
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Function to get award rankings by organization (top 5)
function getAwardRankings($conn, $startDate, $endDate, $statusCondition, $collegeId = null) {
    $query = "
        SELECT 
            o.id,
            o.org_name as organization_name,
            o.code,
            c.name as college_name,
            COUNT(a.id) as total_awards,
            SUM((SELECT COUNT(*) FROM award_participants WHERE award_id = a.id)) as total_recipients,
            SUM((SELECT COUNT(*) FROM award_images WHERE award_id = a.id)) as total_images,
            GROUP_CONCAT(DISTINCT a.title ORDER BY a.award_date DESC SEPARATOR ', ') as recent_awards,
            MAX(a.award_date) as latest_award_date,
            MIN(a.award_date) as earliest_award_date
        FROM organizations o
        LEFT JOIN colleges c ON o.college_id = c.id
        LEFT JOIN awards a ON a.organization_id = o.id
        JOIN academic_semesters s ON a.semester_id = s.id
        JOIN academic_terms at ON s.academic_term_id = at.id
        WHERE o.status = 'recognized' AND a.id IS NOT NULL AND $statusCondition
    ";
    
    $params = [];
    $types = "";
    
    // Filter by college for MIS coordinators
    if ($collegeId !== null) {
        $query .= " AND o.college_id = ?";
        $params[] = $collegeId;
        $types .= "i";
    }
    
    if ($startDate) {
        $query .= " AND a.award_date >= ?";
        $params[] = $startDate;
        $types .= "s";
    }
    
    if ($endDate) {
        $query .= " AND a.award_date <= ?";
        $params[] = $endDate;
        $types .= "s";
    }
    
    $query .= "
        GROUP BY o.id, o.org_name, o.code, c.name
        ORDER BY total_awards DESC, total_recipients DESC, total_images DESC
        LIMIT 5
    ";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}



// Get rankings based on type
switch ($rankingType) {
    case 'events':
        $rankings = getEventRankings($conn, $startDate, $endDate, $statusCondition, $collegeId);
        break;
    case 'awards':
        $rankings = getAwardRankings($conn, $startDate, $endDate, $statusCondition, $collegeId);
        break;
    default:
        $rankings = getEventRankings($conn, $startDate, $endDate, $statusCondition, $collegeId);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rankings - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Main styling */
        body {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        /* Page header styling */
        .page-header h2 {
            color: #343a40;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-header p {
            color: #6c757d;
            font-size: 1.1rem;
        }
        
        /* Page title and subtitle styling */
        .page-title {
            color: #333333 !important;
            font-weight: 700;
            font-size: 2.00rem;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        
        .page-subtitle {
            color: #666666 !important;
            font-weight: 400;
            font-size: 1rem;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.5;
            margin-bottom: 0;
        }
        
        /* Page header spacing */
        .page-header {
            margin-bottom: 3rem !important;
        }
        
        /* Enhanced card styling */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-left: 5px solid transparent;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        /* Filter section styling */
        .filter-section {
            border-left-color: #6c757d !important;
            background: #ffffff !important;
            margin-bottom: 2.5rem !important;
        }
        
        .filter-section .card-body {
            background: #ffffff !important;
        }
        
        .filter-section .card-header {
            background: linear-gradient(90deg, #343a40, #495057) !important;
            color: white !important;
            border-radius: 15px 15px 0 0 !important;
            font-weight: 600;
        }
        
        /* Ranking cards styling */
        .ranking-card {
            border-left-color: #495057 !important;
            margin-bottom: 1rem;
        }
        
        .ranking-card:nth-child(even) {
            border-left-color: #6c757d !important;
        }
        
        .ranking-card:nth-child(3n) {
            border-left-color: #343a40 !important;
        }
        
        .ranking-position {
            font-size: 2rem;
            font-weight: 700;
            color: #495057;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        
        .ranking-card:nth-child(even) .ranking-position {
            color: #6c757d;
        }
        
        .ranking-card:nth-child(3n) .ranking-position {
            color: #343a40;
        }
        
        .ranking-title {
            color: #343a40;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .ranking-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .stat-item {
            background: rgba(0,0,0,0.05);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .stat-item i {
            margin-right: 0.5rem;
        }
        
        /* Medal styling for top 3 */
        .medal {
            font-size: 1.5rem;
            margin-left: 0.5rem;
        }
        
        .gold { color: #ffd700; }
        .silver { color: #c0c0c0; }
        .bronze { color: #cd7f32; }
        
        /* Button styling */
        .btn-primary {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            border-color: transparent !important;
            font-weight: 600;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(45deg, #212529, #343a40) !important;
            border: none !important;
            border-color: transparent !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3);
        }
        
        .btn-primary:focus,
        .btn-primary:active,
        .btn-primary.active {
            background: linear-gradient(45deg, #343a40, #495057) !important;
            border: none !important;
            border-color: transparent !important;
            box-shadow: 0 4px 12px rgba(52, 58, 64, 0.3) !important;
            outline: none !important;
        }
        
        .btn-outline-primary {
            border: 2px solid #495057 !important;
            color: #495057 !important;
            background: transparent !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background: linear-gradient(45deg, #495057, #6c757d) !important;
            border-color: #495057 !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(45deg, #6c757d, #868e96);
            border: none;
            font-weight: 600;
            border-radius: 8px;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(45deg, #5c636a, #6c757d);
            transform: translateY(-1px);
        }
        
        .btn-outline-secondary {
            border: 2px solid #6c757d !important;
            color: #6c757d !important;
            background: white !important;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-outline-secondary:hover {
            background: linear-gradient(45deg, #6c757d, #868e96) !important;
            border-color: #6c757d !important;
            color: white !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }
        
        /* Tab styling */
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 600;
            padding: 1rem 1.5rem;
            border-radius: 10px 10px 0 0;
            margin-right: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link.active {
            background: linear-gradient(45deg, #343a40, #495057);
            color: white;
            border: none;
        }
        
        .nav-tabs .nav-link:hover:not(.active) {
            background: rgba(73, 80, 87, 0.1);
            color: #495057;
        }
        
        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Chart styling */
        #rankingsChart {
            height: 400px !important;
            max-height: 400px;
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-subtitle {
                font-size: 0.95rem;
            }
        }
    </style>
</head>

<body>
    <?php include 'includes/navbar.php'; ?>

    <main>
        <div class="container-fluid">
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center">
                <div class="page-header">
                    <h2 class="page-title">Rankings Dashboard</h2>
                    <p class="page-subtitle">View rankings and statistics for events, awards, and organizations</p>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="card filter-section">
                <div class="card-header">
                    <h5 class="mb-0">Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="ranking_type" class="form-label">Ranking Type</label>
                            <select class="form-select" id="ranking_type" name="ranking_type">
                                <option value="events" <?php echo $rankingType === 'events' ? 'selected' : ''; ?>>Activities</option>
                                <option value="awards" <?php echo $rankingType === 'awards' ? 'selected' : ''; ?>>Awards</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <div class="input-group">
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <div class="input-group">
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="d-grid gap-2 w-100">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-funnel me-2"></i>Apply Filters
                                </button>
                                <a href="rankings.php" class="btn btn-secondary">
                                    <i class="bi bi-x-circle me-2"></i>Reset
                                </a>
                                <?php
                                    $qs = http_build_query([
                                        'ranking_type' => $rankingType,
                                        'start_date' => $startDate,
                                        'end_date' => $endDate
                                    ]);
                                ?>
                                <a href="export_rankings_csv.php?<?php echo $qs; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-download me-2"></i>Download CSV
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Rankings Content -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-list-ol me-2"></i>
                        <?php 
                        switch ($rankingType) {
                            case 'events':
                                echo 'Top 5 Organizations by Activities Produced';
                                break;
                            case 'awards':
                                echo 'Top 5 Organizations by Awards Received';
                                break;
                        }
                        ?>
                        <small class="text-muted">(Top 5)</small>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($rankings)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <h4>No Data Available</h4>
                            <p>No rankings found for the selected criteria. Try adjusting your filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($rankings as $index => $item): ?>
                                <div class="col-12">
                                    <div class="card ranking-card">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <div class="ranking-position">
                                                        #<?php echo $index + 1; ?>
                                                        <?php if ($index === 0): ?>
                                                            <i class="bi bi-trophy-fill medal gold"></i>
                                                        <?php elseif ($index === 1): ?>
                                                            <i class="bi bi-trophy-fill medal silver"></i>
                                                        <?php elseif ($index === 2): ?>
                                                            <i class="bi bi-trophy-fill medal bronze"></i>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="col">
                                                    <?php if ($rankingType === 'events'): ?>
                                                        <h5 class="ranking-title mb-1"><?php echo htmlspecialchars($item['organization_name']); ?></h5>
                                                        <p class="text-muted mb-2">
                                                            <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($item['code']); ?>
                                                            <?php if ($item['college_name']): ?>
                                                                <span class="ms-3"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars($item['college_name']); ?></span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <?php if ($item['recent_activities']): ?>
                                                            <p class="text-muted mb-2" style="font-size: 0.9rem;">
                                                                <i class="bi bi-list-ul me-1"></i>
                                                                <strong>Recent Activities:</strong> 
                                                                <?php 
                                                                $activities = explode(', ', $item['recent_activities']);
                                                                echo htmlspecialchars(implode(', ', array_slice($activities, 0, 3)));
                                                                if (count($activities) > 3) echo '...';
                                                                ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <div class="ranking-stats">
                                                            <div class="stat-item">
                                                                <i class="bi bi-calendar-event text-primary"></i>
                                                                <?php echo $item['total_activities']; ?> Activities
                                                            </div>
                                                            <div class="stat-item">
                                                                <i class="bi bi-people-fill text-success"></i>
                                                                <?php echo $item['total_participants']; ?> Total Participants
                                                            </div>
                                                            <div class="stat-item">
                                                                <i class="bi bi-images text-info"></i>
                                                                <?php echo $item['total_images']; ?> Total Images
                                                            </div>
                                                            <?php if ($item['latest_activity_date']): ?>
                                                                <div class="stat-item">
                                                                    <i class="bi bi-calendar-check text-warning"></i>
                                                                    Latest: <?php echo date('M d, Y', strtotime($item['latest_activity_date'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php elseif ($rankingType === 'awards'): ?>
                                                        <h5 class="ranking-title mb-1"><?php echo htmlspecialchars($item['organization_name']); ?></h5>
                                                        <p class="text-muted mb-2">
                                                            <i class="bi bi-tag me-1"></i><?php echo htmlspecialchars($item['code']); ?>
                                                            <?php if ($item['college_name']): ?>
                                                                <span class="ms-3"><i class="bi bi-mortarboard me-1"></i><?php echo htmlspecialchars($item['college_name']); ?></span>
                                                            <?php endif; ?>
                                                        </p>
                                                        <?php if ($item['recent_awards']): ?>
                                                            <p class="text-muted mb-2" style="font-size: 0.9rem;">
                                                                <i class="bi bi-trophy me-1"></i>
                                                                <strong>Recent Awards:</strong> 
                                                                <?php 
                                                                $awards = explode(', ', $item['recent_awards']);
                                                                echo htmlspecialchars(implode(', ', array_slice($awards, 0, 3)));
                                                                if (count($awards) > 3) echo '...';
                                                                ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <div class="ranking-stats">
                                                            <div class="stat-item">
                                                                <i class="bi bi-trophy text-warning"></i>
                                                                <?php echo $item['total_awards']; ?> Awards Received
                                                            </div>
                                                            <div class="stat-item">
                                                                <i class="bi bi-person-check text-primary"></i>
                                                                <?php echo $item['total_recipients']; ?> Total Recipients
                                                            </div>
                                                            <div class="stat-item">
                                                                <i class="bi bi-images text-success"></i>
                                                                <?php echo $item['total_images']; ?> Total Images
                                                            </div>
                                                            <?php if ($item['latest_award_date']): ?>
                                                                <div class="stat-item">
                                                                    <i class="bi bi-calendar-check text-info"></i>
                                                                    Latest: <?php echo date('M d, Y', strtotime($item['latest_award_date'])); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-bar-chart me-2"></i>
                        <?php 
                        switch ($rankingType) {
                            case 'events':
                                echo 'Organizations Activities Chart';
                                break;
                            case 'awards':
                                echo 'Organizations Awards Chart ';
                                break;
                        }
                        ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12">
                            <div class="chart-container">
                                <canvas id="rankingsChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-submit form when filters change
        document.getElementById('ranking_type').addEventListener('change', function() {
            this.form.submit();
        });

        // Chart initialization
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('rankingsChart').getContext('2d');
            
            // PHP data for JavaScript
            const rankings = <?php echo json_encode($rankings); ?>;
            const rankingType = '<?php echo $rankingType; ?>';
            
            // Prepare chart data
            let labels = [];
            let data = [];
            let backgroundColor = [];
            let borderColor = [];
            
            if (rankings && rankings.length > 0) {
                rankings.forEach((item, index) => {
                    labels.push(item.organization_name);
                    
                    if (rankingType === 'events') {
                        data.push(item.total_activities || 0);
                    } else {
                        data.push(item.total_awards || 0);
                    }
                    
                    // Color scheme - gradient from gold to bronze for top 3, then consistent colors
                    const colors = [
                        { bg: 'rgba(255, 215, 0, 0.8)', border: 'rgba(255, 215, 0, 1)' }, // Gold
                        { bg: 'rgba(192, 192, 192, 0.8)', border: 'rgba(192, 192, 192, 1)' }, // Silver
                        { bg: 'rgba(205, 127, 50, 0.8)', border: 'rgba(205, 127, 50, 1)' }, // Bronze
                        { bg: 'rgba(253, 126, 20, 0.8)', border: 'rgba(253, 126, 20, 1)' }, // Orange
                        { bg: 'rgba(25, 135, 84, 0.8)', border: 'rgba(25, 135, 84, 1)' }  // Green
                    ];
                    
                    backgroundColor.push(colors[index] ? colors[index].bg : 'rgba(108, 117, 125, 0.8)');
                    borderColor.push(colors[index] ? colors[index].border : 'rgba(108, 117, 125, 1)');
                });
            }
            
            const chartConfig = {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: rankingType === 'events' ? 'Total Activities' : 'Total Awards',
                        data: data,
                        backgroundColor: backgroundColor,
                        borderColor: borderColor,
                        borderWidth: 2,
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                },
                                color: '#343a40'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#fd7e14',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    const value = context.parsed.y;
                                    const label = rankingType === 'events' ? 'Activities' : 'Awards';
                                    return `${label}: ${value}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                color: '#6c757d',
                                font: {
                                    size: 12
                                }
                            },
                            grid: {
                                color: 'rgba(0, 0, 0, 0.1)',
                                drawBorder: false
                            },
                            title: {
                                display: true,
                                text: rankingType === 'events' ? 'Number of Activities' : 'Number of Awards',
                                color: '#343a40',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        },
                        x: {
                            ticks: {
                                color: '#6c757d',
                                font: {
                                    size: 11
                                },
                                maxRotation: 45,
                                minRotation: 0
                            },
                            grid: {
                                display: false
                            },
                            title: {
                                display: true,
                                text: 'Organizations',
                                color: '#343a40',
                                font: {
                                    size: 14,
                                    weight: 'bold'
                                }
                            }
                        }
                    },
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuart'
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            };
            
            // Create the chart
            new Chart(ctx, chartConfig);
        });
    </script>
</body>
</html> 
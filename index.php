<?php
require_once 'config/database.php';

// Get top 5 colleges by awards
$top_colleges = $conn->query("
    SELECT c.id, c.name, COUNT(a.id) as award_count
    FROM colleges c
    LEFT JOIN organizations o ON c.id = o.college_id
    LEFT JOIN awards a ON o.id = a.organization_id
    GROUP BY c.id
    ORDER BY award_count DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get upcoming events (both organization and council events)
$events = $conn->query("
    SELECT e.*, 
           COALESCE(o.org_name, c.council_name) as organization_name,
           COALESCE(co1.name, co2.name) as college_name,
           COALESCE(co1.id, co2.id) as college_id,
           CASE WHEN e.organization_id IS NOT NULL THEN 'organization' ELSE 'council' END as event_type,
           e.organization_id,
           e.council_id,
           (SELECT GROUP_CONCAT(image_path) FROM event_images WHERE event_id = e.id) as images
    FROM events e
    LEFT JOIN organizations o ON e.organization_id = o.id
    LEFT JOIN colleges co1 ON o.college_id = co1.id
    LEFT JOIN council c ON e.council_id = c.id
    LEFT JOIN colleges co2 ON c.college_id = co2.id
    WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 15
")->fetch_all(MYSQLI_ASSOC);

// Get all colleges
$colleges = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM organizations WHERE college_id = c.id) as org_count,
           (SELECT COUNT(*) FROM council WHERE college_id = c.id) as council_count,
           (SELECT id FROM council WHERE college_id = c.id LIMIT 1) as council_id,
           (SELECT status FROM council WHERE college_id = c.id LIMIT 1) as council_status
    FROM colleges c
    ORDER BY name ASC
")->fetch_all(MYSQLI_ASSOC);

// Get top 5 councils by awards
$top_councils = $conn->query("
    SELECT c.id, c.council_name, c.college_id, co.name as college_name, COUNT(a.id) as award_count
    FROM council c
    LEFT JOIN colleges co ON c.college_id = co.id
    LEFT JOIN awards a ON c.id = a.council_id
    GROUP BY c.id
    ORDER BY award_count DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get upcoming council events
$council_events = $conn->query("
    SELECT e.*, c.council_name as organization_name, co.name as college_name,
           (SELECT GROUP_CONCAT(image_path) FROM event_images WHERE event_id = e.id) as images
    FROM events e
    JOIN council c ON e.council_id = c.id
    JOIN colleges co ON c.college_id = co.id
    WHERE e.event_date >= CURDATE() AND e.council_id IS NOT NULL
    ORDER BY e.event_date ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Get filter parameters from GET
$selectedCollege = isset($_GET['college']) ? (int)$_GET['college'] : null;
$selectedOrganization = isset($_GET['organization']) ? $_GET['organization'] : null;

// Get college council if college is selected
$collegeCouncil = null;
if ($selectedCollege) {
    $stmt = $conn->prepare("SELECT id, council_name FROM council WHERE college_id = ? LIMIT 1");
    $stmt->bind_param("i", $selectedCollege);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $collegeCouncil = $result->fetch_assoc();
    }
    $stmt->close();
}

// Get organizations for filter (filtered by college if selected)
$organizations = [];
if ($selectedCollege) {
    $stmt = $conn->prepare("SELECT id, org_name FROM organizations WHERE college_id = ? ORDER BY org_name ASC");
    $stmt->bind_param("i", $selectedCollege);
    $stmt->execute();
    $organizations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Parse organization parameter to handle council selection (format: "council_ID" for councils)
$selectedCouncilId = null;
$selectedOrgId = null;
if ($selectedOrganization) {
    if (strpos($selectedOrganization, 'council_') === 0) {
        // It's a council selection
        $councilIdFromParam = (int)str_replace('council_', '', $selectedOrganization);
        if ($selectedCollege && $collegeCouncil && $collegeCouncil['id'] == $councilIdFromParam) {
            $selectedCouncilId = $councilIdFromParam;
        }
    } else {
        // It's an organization selection
        $orgIdFromParam = (int)$selectedOrganization;
        if ($selectedCollege) {
            $orgFound = false;
            foreach ($organizations as $org) {
                if ($org['id'] == $orgIdFromParam) {
                    $orgFound = true;
                    break;
                }
            }
            if ($orgFound) {
                $selectedOrgId = $orgIdFromParam;
            }
        } else {
            $selectedOrgId = $orgIdFromParam;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Organizations - Events and Activities</title>
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
            color: var(--cvsu-green) !important;
            font-weight: bold;
            padding-top: 0.25rem;
            padding-bottom: 0.25rem;
            display: flex;
            align-items: center;
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
        }
        .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid #dddfe2;
            padding: 12px 16px;
        }
        .list-group-item {
            border: none;
            border-bottom: 1px solid #dddfe2;
            padding: 12px 16px;
        }
        .list-group-item:last-child {
            border-bottom: none;
        }
        .event-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 24px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-green);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
        }
        .event-card:last-child {
            margin-bottom: 0;
        }
        .event-card:hover {
            background-color: var(--cvsu-light);
            box-shadow: 0 6px 18px rgba(11, 93, 30, 0.12);
            transform: translateY(-2px);
        }
        :root {
            --cvsu-green: #0b5d1e; /* CvSU green */
            --cvsu-green-600: #0f7a2a;
            --cvsu-light: #e8f5ed; /* light green tint */
            --cvsu-gold: #ffb400; /* gold accent */
        }

        .college-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid #e9ecef;
            border-left: 5px solid var(--cvsu-green);
            transition: box-shadow 0.2s, transform 0.2s, background-color 0.2s;
        }
        .college-card:hover {
            transform: translateY(-2px);
            background-color: var(--cvsu-light);
            box-shadow: 0 6px 18px rgba(11, 93, 30, 0.12);
        }
        .college-card .badge {
            background-color: #fff6e0 !important;
            color: #7a5a00 !important;
            border-color: #ffe3a1 !important;
        }
        .college-card small {
            color: #2f3e35 !important;
            font-weight: 500;
        }
        .college-card small i {
            color: var(--cvsu-green);
        }

        /* Top Colleges list - CvSU colorway */
        .list-group .list-group-item-action {
            border-left: 4px solid transparent;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .list-group .list-group-item-action:hover {
            background-color: var(--cvsu-light);
            border-left-color: var(--cvsu-green);
        }
        .card .card-header .card-title i {
            color: var(--cvsu-gold);
        }
        .list-group .list-group-item-action h6 {
            color: var(--cvsu-green);
        }
        /* Icon-text alignment: wrap second line under first character, not icon */
        .icon-text { display: flex; align-items: flex-start; }
        .icon-text .icon { width: 1.25rem; /* matches .me-1 spacing */ flex: 0 0 1.25rem; color: inherit; font-size: 1rem; line-height: 1.25rem; }
        .icon-text .text { flex: 1 1 auto; }
        /* Badge color overrides for CvSU theme */
        .badge.bg-info {
            background-color: var(--cvsu-green) !important;
            color: #fff !important;
        }
        .badge.bg-warning {
            background-color: var(--cvsu-gold) !important;
            color: #2f2f2f !important;
        }
        .badge.bg-primary {
            background-color: var(--cvsu-green-600) !important;
        }
        .nav-link {
            color: var(--cvsu-green) !important;
            padding-top: 0.25rem !important;
            padding-bottom: 0.25rem !important;
        }
        .text-primary {
            color: var(--cvsu-green) !important;
        }
        .container {
            padding-top: 20px;
        }
        
        /* Event image gallery styling */
        .event-images {
            margin: 12px -16px;
            position: relative;
        }
        
        .event-images img {
            width: 100%;
            height: 300px;
            object-fit: cover;
            border-radius: 0;
        }
        
        .event-images.multiple {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2px;
        }
        
        .event-images.multiple img {
            height: 200px;
        }
        
        .event-images.multiple img:first-child {
            grid-column: span 2;
            height: 300px;
        }
        
        .event-images.multiple img:nth-child(2),
        .event-images.multiple img:nth-child(3) {
            height: 200px;
        }
        
        .event-images.multiple img:nth-child(4) {
            position: relative;
        }
        
        .event-images.multiple img:nth-child(4)::after {
            content: '+2';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: bold;
        }
        
        /* Image Gallery Modal */
        .image-gallery-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.95);
            z-index: 9999;
            backdrop-filter: blur(10px);
        }
        
        .gallery-modal-content {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .gallery-main-image {
            max-width: 80%;
            max-height: 80%;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        .gallery-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            z-index: 10001;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .gallery-close:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }
        
        .gallery-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.7);
            color: white;
            border: none;
            font-size: 2rem;
            padding: 15px 20px;
            cursor: pointer;
            border-radius: 50%;
            transition: all 0.3s ease;
            z-index: 10001;
        }
        
        .gallery-nav:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-50%) scale(1.1);
        }
        
        .gallery-prev {
            left: 30px;
        }
        
        .gallery-next {
            right: 30px;
        }
        
        .gallery-thumbnails {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
            max-width: 80%;
            overflow-x: auto;
            padding: 10px;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 25px;
            backdrop-filter: blur(10px);
        }
        
        .gallery-thumbnail {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .gallery-thumbnail:hover,
        .gallery-thumbnail.active {
            opacity: 1;
            border-color: #1877f2;
            transform: scale(1.1);
        }
        
        .gallery-info {
            position: absolute;
            top: 20px;
            left: 30px;
            color: white;
            background: rgba(0, 0, 0, 0.7);
            padding: 15px 20px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        
        .gallery-counter {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .gallery-title {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        /* Loading spinner for gallery */
        .gallery-loading {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 2rem;
        }
        
        /* Make event images clickable */
        .event-images img {
            cursor: pointer;
            transition: opacity 0.3s ease;
        }
        
        .event-images img:hover {
            opacity: 0.9;
        }
        
        /* Responsive gallery modal */
        @media (max-width: 768px) {
            .gallery-main-image {
                max-width: 95%;
                max-height: 70%;
            }
            
            .gallery-nav {
                font-size: 1.5rem;
                padding: 10px 15px;
            }
            
            .gallery-prev {
                left: 15px;
            }
            
            .gallery-next {
                right: 15px;
            }
            
            .gallery-close {
                top: 15px;
                right: 15px;
                font-size: 1.5rem;
                width: 40px;
                height: 40px;
            }
            
            .gallery-thumbnails {
                bottom: 15px;
                max-width: 90%;
            }
            
            .gallery-thumbnail {
                width: 50px;
                height: 50px;
            }
            
            .gallery-info {
                top: 15px;
                left: 15px;
                padding: 10px 15px;
            }
        }
        
        .more-images-overlay {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.9rem;
            backdrop-filter: blur(4px);
        }
        /* Text truncation styles */
        .text-truncate-container {
            position: relative;
        }
        .text-truncate-content {
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .text-truncate-content.truncated {
            max-height: 4.5em; /* Approximately 3 lines */
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .text-truncate-toggle {
            color: #0d6efd;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            margin-left: 5px;
        }
        .text-truncate-toggle:hover {
            text-decoration: underline;
            color: #0a58ca;
        }
        
        /* Welcome Banner Styles */
        .welcome-banner {
            background: linear-gradient(135deg, #0b5d1e 0%, #0f7a2a 50%, #166534 100%);
            color: white;
            padding: 60px 20px;
            margin-top: 48px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(11, 93, 30, 0.3);
        }

        .welcome-content {
            position: relative;
            z-index: 2;
            text-align: center;
            max-width: 800px;
            margin: 0 auto;
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            animation: fadeInUp 1s ease-out;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            text-align: center;
        }
        .welcome-logo {
            height: 60px;
            width: auto;
            flex-shrink: 0;
        }
        .welcome-title-text {
            display: inline-flex;
            align-items: center;
            line-height: 1.2;
        }

        .welcome-subtitle {
            font-size: 1.2rem;
            opacity: 0.95;
            animation: fadeInUp 1s ease-out 0.3s both;
        }

        /* Animated Background Elements */
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s infinite linear;
            z-index: 1;
        }

        .welcome-banner::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="rgba(255,255,255,0.3)"/><circle cx="80" cy="40" r="1.5" fill="rgba(255,255,255,0.2)"/><circle cx="40" cy="80" r="1" fill="rgba(255,255,255,0.25)"/><circle cx="90" cy="90" r="2.5" fill="rgba(255,255,255,0.2)"/><circle cx="10" cy="60" r="1.5" fill="rgba(255,255,255,0.3)"/></svg>') repeat;
            animation: drift 15s infinite ease-in-out;
            z-index: 1;
            opacity: 0.6;
        }

        /* Floating decorative elements */
        .floating-elements {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1;
            pointer-events: none;
        }

        .floating-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            animation: floatCircle 8s infinite ease-in-out;
        }

        .floating-circle:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 20%;
            left: 10%;
            animation-delay: 0s;
        }

        .floating-circle:nth-child(2) {
            width: 60px;
            height: 60px;
            top: 60%;
            right: 15%;
            animation-delay: 2s;
        }

        .floating-circle:nth-child(3) {
            width: 100px;
            height: 100px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }

        .floating-circle:nth-child(4) {
            width: 50px;
            height: 50px;
            top: 40%;
            right: 30%;
            animation-delay: 1s;
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            from {
                transform: translate(0, 0);
            }
            to {
                transform: translate(50px, 50px);
            }
        }

        @keyframes drift {
            0%, 100% {
                transform: translate(0, 0);
            }
            50% {
                transform: translate(30px, -30px);
            }
        }

        @keyframes floatCircle {
            0%, 100% {
                transform: translate(0, 0) scale(1);
                opacity: 0.15;
            }
            50% {
                transform: translate(30px, -40px) scale(1.1);
                opacity: 0.25;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .welcome-title {
                font-size: 1.8rem;
                gap: 0.5rem;
            }
            .welcome-subtitle {
                font-size: 1rem;
            }
            .welcome-banner {
                padding: 40px 15px;
            }
            .brand-logo {
                height: 28px;
                margin-right: 0.35rem;
            }
            .welcome-logo {
                height: 48px;
            }
        }
        
        /* Filter Bar Styles */
        .filter-bar {
            background: #ffffff;
            padding: 20px;
            margin-top: 0;
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #f0f0f0;
        }

        .filter-bar .row {
            align-items: flex-end;
        }

        .filter-group {
            margin-bottom: 0;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--cvsu-green);
            font-size: 0.85rem;
            margin-bottom: 5px;
            display: block;
        }

        .filter-bar input[type="text"],
        .filter-bar select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .filter-bar input[type="text"]:focus,
        .filter-bar select:focus {
            outline: none;
            border-color: var(--cvsu-green);
            box-shadow: 0 0 0 0.2rem rgba(11, 93, 30, 0.1);
        }

        .event-type-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: nowrap;
        }

        .event-type-btn {
            padding: 8px 12px;
            border: 2px solid var(--cvsu-green);
            background: white;
            color: var(--cvsu-green);
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
            flex: 1;
        }

        .event-type-btn:hover {
            background: var(--cvsu-light);
        }

        .event-type-btn.active {
            background: var(--cvsu-green);
            color: white;
        }

        .event-type-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .event-type-btn:disabled:hover {
            background: white;
            border-color: var(--cvsu-green);
            color: var(--cvsu-green);
        }

        .filter-reset-btn {
            padding: 8px 16px;
            background: #ffffff;
            color: #212529;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s ease;
            height: 38px; /* Match input height */
        }

        .filter-reset-btn:hover {
            background: #f8f9fa;
            border-color: var(--cvsu-green);
            color: var(--cvsu-green);
        }

        .event-card.hidden {
            display: none;
        }

        .no-events-message {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .no-events-message i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        @media (max-width: 768px) {
            .filter-bar {
                padding: 15px;
            }
            .filter-bar .row {
                flex-direction: column;
            }
            .filter-group {
                margin-bottom: 15px;
                width: 100%;
            }
            .event-type-buttons {
                flex-direction: row;
            }
            .event-type-btn {
                flex: 1;
            }
            .filter-reset-btn {
                width: 100%;
                margin-top: 0;
            }
        }
        
        /* Footer Styles */
        .footer {
            background: linear-gradient(135deg, #0b5d1e 0%, #0f7a2a 50%, #166534 100%);
            color: white;
            padding: 30px 20px;
            margin-top: 40px;
            text-align: center;
            box-shadow: 0 -4px 20px rgba(11, 93, 30, 0.3);
        }

        .footer p {
            margin: 5px 0;
            font-size: 0.95rem;
        }

        .footer p:first-child {
            font-weight: 500;
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .footer {
                padding: 25px 15px;
            }
            
            .footer p {
                font-size: 0.85rem;
            }
            
            .footer p:first-child {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="assets/img/SAOMS-LOGO-GREEN.png" alt="CvSU SAOMS Logo" class="brand-logo">
                Academic Organizations
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="applicationOrg.php">
                            <i class="bi bi-file-text"></i>
                            Start an Organization
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

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="floating-elements">
            <div class="floating-circle"></div>
            <div class="floating-circle"></div>
            <div class="floating-circle"></div>
            <div class="floating-circle"></div>
        </div>
        <div class="welcome-content">
            <h1 class="welcome-title">
                <img src="assets/img/SAOMS-LOGO-WHITE.png" alt="CvSU SAOMS Logo" class="welcome-logo">
                <span class="welcome-title-text">Welcome to CvSU Student Academic Organization</span>
            </h1>
            <p class="welcome-subtitle">
                Discover events, connect with organizations, and explore opportunities across our engaging academic community
            </p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="container mt-4 mb-0">
        <div class="filter-bar">
            <form method="GET" action="index.php" id="filterForm">
                <div class="row align-items-end">
                    <div class="col-md-2 col-lg-2">
                        <div class="filter-group">
                            <label for="searchFilter">
                                <i class="bi bi-search me-1"></i>Search
                            </label>
                            <input type="text" id="searchFilter" name="search" placeholder="Search events..." class="form-control" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <div class="filter-group">
                            <label for="collegeFilter">
                                <i class="bi bi-building me-1"></i>College
                            </label>
                            <select id="collegeFilter" name="college" class="form-control">
                                <option value="">All Colleges</option>
                                <?php foreach ($colleges as $college): ?>
                                    <option value="<?php echo $college['id']; ?>" <?php echo $selectedCollege == $college['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($college['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <div class="filter-group">
                            <label for="organizationFilter">
                                <i class="bi bi-people me-1"></i>Organization
                            </label>
                            <select id="organizationFilter" name="organization" class="form-control" <?php echo !$selectedCollege ? 'disabled' : ''; ?>>
                                <option value="">All Organizations</option>
                                <?php if ($selectedCollege && $collegeCouncil): ?>
                                    <option value="council_<?php echo $collegeCouncil['id']; ?>" <?php echo $selectedCouncilId == $collegeCouncil['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($collegeCouncil['council_name']); ?>
                                    </option>
                                <?php endif; ?>
                                <?php foreach ($organizations as $org): ?>
                                    <option value="<?php echo $org['id']; ?>" <?php echo $selectedOrgId == $org['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($org['org_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <div class="filter-group">
                            <label>
                                <i class="bi bi-tag me-1"></i>Type
                            </label>
                            <div class="event-type-buttons">
                                <button type="button" class="event-type-btn active" data-type="all">All</button>
                                <button type="button" class="event-type-btn" data-type="organization">Org</button>
                                <button type="button" class="event-type-btn" data-type="council">Council</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <div class="filter-group">
                            <label for="dateFilter">
                                <i class="bi bi-calendar me-1"></i>Date
                            </label>
                            <select id="dateFilter" class="form-control">
                                <option value="all">All</option>
                                <option value="01">January</option>
                                <option value="02">February</option>
                                <option value="03">March</option>
                                <option value="04">April</option>
                                <option value="05">May</option>
                                <option value="06">June</option>
                                <option value="07">July</option>
                                <option value="08">August</option>
                                <option value="09">September</option>
                                <option value="10">October</option>
                                <option value="11">November</option>
                                <option value="12">December</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2 col-lg-2">
                        <button type="button" class="filter-reset-btn w-100" id="resetFilters">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Clear Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="container mt-4">
        <div class="row">
            <!-- Left Sidebar - Top Colleges -->
            <div class="col-md-3 d-none d-md-block">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-trophy me-2"></i>
                            Top Colleges
                        </h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($top_colleges as $college): ?>
                        <a href="college_organizations.php?id=<?php echo $college['id']; ?>" class="list-group-item list-group-item-action text-reset text-decoration-none">
                            <h6 class="mb-1"><?php echo htmlspecialchars($college['name']); ?></h6>
                            <small class="text-muted">
                                <i class="bi bi-award me-1"></i>
                                <?php echo $college['award_count']; ?> awards
                            </small>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Main Content - Events Feed -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-calendar-event me-2"></i>
                            Upcoming Events
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div id="noEventsMessage" class="no-events-message" style="display: none;">
                            <i class="bi bi-calendar-x"></i>
                            <h5>No events found</h5>
                            <p>Try adjusting your filters to see more events.</p>
                        </div>
                        <?php foreach ($events as $event): ?>
                        <div class="event-card" 
                             data-event-type="<?php echo htmlspecialchars($event['event_type']); ?>"
                             data-college="<?php echo htmlspecialchars($event['college_name'] ?? ''); ?>"
                             data-college-id="<?php echo htmlspecialchars($event['college_id'] ?? ''); ?>"
                             data-organization-name="<?php echo htmlspecialchars($event['organization_name'] ?? ''); ?>"
                             data-organization-id="<?php echo htmlspecialchars($event['organization_id'] ?? ''); ?>"
                             data-council-id="<?php echo htmlspecialchars($event['council_id'] ?? ''); ?>"
                             data-title="<?php echo htmlspecialchars(strtolower($event['title'])); ?>"
                             data-description="<?php echo htmlspecialchars(strtolower($event['description'] ?? '')); ?>"
                             data-event-date="<?php echo htmlspecialchars($event['event_date']); ?>">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="mb-1"><?php echo htmlspecialchars($event['title']); ?></h5>
                                <div class="d-flex flex-column align-items-end">
                                    <span class="badge <?php echo $event['event_type'] === 'council' ? 'bg-warning' : 'bg-info'; ?>">
                                        <i class="bi <?php echo $event['event_type'] === 'council' ? 'bi-award' : 'bi-people'; ?> me-1"></i>
                                        <?php echo ucfirst($event['event_type']); ?>
                                    </span>
                                </div>
                            </div>
                            <!-- Meta (date & venue) placed before description -->
                            <div class="small text-muted mb-2 d-flex flex-wrap align-items-center gap-3">
                                <span class="d-flex align-items-center">
                                    <i class="bi bi-calendar me-2 text-warning"></i>
                                    <strong><?php echo date('M d, Y', strtotime($event['event_date'])); ?></strong>
                                </span>
                                <?php if (!empty($event['venue'])): ?>
                                <span class="d-flex align-items-center">
                                    <i class="bi bi-geo-alt me-2 text-warning"></i>
                                    <strong><?php echo htmlspecialchars($event['venue']); ?></strong>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="text-truncate-container">
                                <p class="mb-2 text-truncate-content"><?php echo htmlspecialchars($event['description']); ?></p>
                                <a href="#" class="text-truncate-toggle" style="display: none;">More</a>
                            </div>
                            
                            <?php if ($event['images']): ?>
                            <div class="event-images <?php echo substr_count($event['images'], ',') > 0 ? 'multiple' : ''; ?>">
                                <?php 
                                $images = explode(',', $event['images']);
                                $displayImages = array_slice($images, 0, 3);
                                $totalImages = count($images);
                                foreach ($displayImages as $index => $image): 
                                ?>
                                <img src="<?php echo htmlspecialchars($image); ?>" 
                                     alt="Event Image <?php echo $index + 1; ?>"
                                     loading="lazy"
                                     data-total-images="<?php echo $totalImages; ?>"
                                     data-all-images='<?php echo htmlspecialchars(json_encode($images)); ?>'>
                                <?php endforeach; ?>
                                <?php if ($totalImages > 3): ?>
                                <div class="more-images-overlay">
                                    <span>+<?php echo $totalImages - 3; ?> more</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-flex align-items-center text-muted mt-3">
                                <small class="icon-text">
                                    <i class="bi bi-building icon"></i>
                                    <span class="text"><strong><?php echo htmlspecialchars($event['college_name']); ?></strong></span>
                                </small>
                                <small class="ms-3 icon-text">
                                    <i class="bi <?php echo ( ($event['event_type'] === 'council') || (!empty($event['council_id'])) || (stripos($event['organization_name'] ?? '', 'council') !== false) ) ? 'bi-award' : 'bi-people'; ?> icon"></i>
                                    <span class="text">
                                        <a href="<?php echo $event['event_type'] === 'council' ? 'council_profile.php?id=' . $event['council_id'] : 'organization_profile.php?id=' . $event['organization_id']; ?>" 
                                           class="text-decoration-none text-muted">
                                            <strong><?php 
                                                if ($event['event_type'] === 'council' || !empty($event['council_id'])) {
                                                    echo 'Student Council';
                                                } else {
                                                    echo htmlspecialchars($event['organization_name']);
                                                }
                                            ?></strong>
                                        </a>
                                    </span>
                                </small>
                                
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Right Sidebar - Top Councils -->
            <div class="col-md-3 d-none d-md-block">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-crown me-2"></i>
                            Top Councils
                        </h5>
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($top_councils as $council): ?>
                        <a href="council_profile.php?id=<?php echo $council['id']; ?>" class="text-decoration-none">
                            <div class="list-group-item">
                                <h6 class="mb-1 text-dark"><?php echo htmlspecialchars($council['council_name']); ?></h6>
                                <small class="text-muted">
                                    <i class="bi bi-award me-1"></i>
                                    <?php echo $council['award_count']; ?> awards
                                </small>
                                <br>
                                <small class="text-muted">
                                    <i class="bi bi-building me-1"></i>
                                    <?php echo htmlspecialchars($council['college_name']); ?>
                                </small>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Colleges List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-building me-2"></i>
                            Colleges
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($colleges as $college): ?>
                        <a href="college_organizations.php?id=<?php echo $college['id']; ?>" class="text-decoration-none text-reset">
                        <div class="college-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-1 text-dark"><?php echo htmlspecialchars($college['name']); ?></h6>
                                <?php if ($college['council_id'] && $college['council_status'] === 'recognized'): ?>
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Council Active
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted">
                                    <small>
                                        <i class="bi bi-people me-1"></i>
                                        <?php echo $college['org_count']; ?> organizations
                                    </small>
                                </span>
                                <?php if ($college['council_id']): ?>
                                    <a href="council_profile.php?id=<?php echo $college['council_id']; ?>" class="text-decoration-none text-muted">
                                        <small>
                                            <i class="bi bi-check me-1"></i>
                                            <i class="bi bi-crown me-1"></i>
                                            Council
                                        </small>
                                    </a>
                                <?php else: ?>
                                    <span class="text-decoration-none text-muted council-inactive" 
                                          data-college-name="<?php echo htmlspecialchars($college['name']); ?>"
                                          style="cursor: pointer;">
                                        <small>
                                            <i class="bi bi-crown me-1"></i>
                                            Council
                                        </small>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Gallery Modal -->
    <div class="image-gallery-modal" id="imageGalleryModal">
        <div class="gallery-modal-content">
            <div class="gallery-close" id="galleryClose">&times;</div>
            <div class="gallery-info">
                <div class="gallery-counter" id="galleryCounter">1 / 1</div>
                <div class="gallery-title" id="galleryTitle">Event Gallery</div>
            </div>
            <button class="gallery-nav gallery-prev" id="galleryPrev">&#8249;</button>
            <button class="gallery-nav gallery-next" id="galleryNext">&#8250;</button>
            <img class="gallery-main-image" id="galleryMainImage" src="" alt="Gallery Image">
            <div class="gallery-thumbnails" id="galleryThumbnails"></div>
            <div class="gallery-loading" id="galleryLoading" style="display: none;">
                <i class="bi bi-hourglass-split"></i>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <p>&copy; 2025 Cavite State University</p>
        <p>All Rights Reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        class ImageGallery {
            constructor() {
                this.modal = document.getElementById('imageGalleryModal');
                this.mainImage = document.getElementById('galleryMainImage');
                this.counter = document.getElementById('galleryCounter');
                this.title = document.getElementById('galleryTitle');
                this.thumbnails = document.getElementById('galleryThumbnails');
                this.loading = document.getElementById('galleryLoading');
                this.prevBtn = document.getElementById('galleryPrev');
                this.nextBtn = document.getElementById('galleryNext');
                this.closeBtn = document.getElementById('galleryClose');
                
                this.currentIndex = 0;
                this.images = [];
                this.eventTitle = '';
                
                this.initEventListeners();
            }
            
            initEventListeners() {
                // Event image clicks
                document.addEventListener('click', (e) => {
                    const eventCard = e.target.closest('.event-card');
                    if (eventCard) {
                        const clickedImage = e.target.closest('img');
                        if (clickedImage) {
                            const allImages = JSON.parse(clickedImage.dataset.allImages);
                            const startIndex = allImages.indexOf(clickedImage.src);
                            this.openGallery(allImages, eventCard.querySelector('h5').textContent, startIndex);
                        }
                    }
                });
                
                // Navigation
                this.prevBtn.addEventListener('click', () => this.previousImage());
                this.nextBtn.addEventListener('click', () => this.nextImage());
                this.closeBtn.addEventListener('click', () => this.closeGallery());
                
                // Keyboard navigation
                document.addEventListener('keydown', (e) => {
                    if (this.modal.style.display === 'block') {
                        switch(e.key) {
                            case 'ArrowLeft':
                                this.previousImage();
                                break;
                            case 'ArrowRight':
                                this.nextImage();
                                break;
                            case 'Escape':
                                this.closeGallery();
                                break;
                        }
                    }
                });
                
                // Close on background click
                this.modal.addEventListener('click', (e) => {
                    if (e.target === this.modal) {
                        this.closeGallery();
                    }
                });
            }
            
            openGallery(images, eventTitle, startIndex = 0) {
                this.showLoading();
                this.modal.style.display = 'block';
                document.body.style.overflow = 'hidden';
                
                this.images = images;
                this.eventTitle = eventTitle;
                this.currentIndex = startIndex >= 0 ? startIndex : 0;
                
                this.updateDisplay();
                this.createThumbnails();
                this.hideLoading();
            }
            
            updateDisplay() {
                if (this.images.length === 0) return;
                
                this.mainImage.src = this.images[this.currentIndex];
                this.mainImage.alt = `${this.eventTitle} - Image ${this.currentIndex + 1}`;
                
                this.counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;
                this.title.textContent = this.eventTitle;
                
                // Update navigation buttons
                this.prevBtn.style.display = this.images.length > 1 ? 'block' : 'none';
                this.nextBtn.style.display = this.images.length > 1 ? 'block' : 'none';
                
                // Update thumbnails
                this.updateThumbnails();
            }
            
            createThumbnails() {
                this.thumbnails.innerHTML = '';
                
                if (this.images.length <= 1) {
                    this.thumbnails.style.display = 'none';
                    return;
                }
                
                this.thumbnails.style.display = 'flex';
                
                this.images.forEach((image, index) => {
                    const thumb = document.createElement('img');
                    thumb.src = image;
                    thumb.className = 'gallery-thumbnail';
                    thumb.alt = `Thumbnail ${index + 1}`;
                    thumb.addEventListener('click', () => {
                        this.currentIndex = index;
                        this.updateDisplay();
                    });
                    
                    this.thumbnails.appendChild(thumb);
                });
            }
            
            updateThumbnails() {
                const thumbnails = this.thumbnails.querySelectorAll('.gallery-thumbnail');
                thumbnails.forEach((thumb, index) => {
                    thumb.classList.toggle('active', index === this.currentIndex);
                });
                
                // Scroll active thumbnail into view
                const activeThumbnail = thumbnails[this.currentIndex];
                if (activeThumbnail) {
                    activeThumbnail.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                        inline: 'center'
                    });
                }
            }
            
            previousImage() {
                if (this.images.length > 1) {
                    this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length;
                    this.updateDisplay();
                }
            }
            
            nextImage() {
                if (this.images.length > 1) {
                    this.currentIndex = (this.currentIndex + 1) % this.images.length;
                    this.updateDisplay();
                }
            }
            
            closeGallery() {
                this.modal.style.display = 'none';
                document.body.style.overflow = '';
                this.images = [];
                this.currentIndex = 0;
            }
            
            showLoading() {
                this.loading.style.display = 'block';
                this.mainImage.style.display = 'none';
            }
            
            hideLoading() {
                this.loading.style.display = 'none';
                this.mainImage.style.display = 'block';
            }
        }
        
        // Text truncation functionality
        function initTextTruncation() {
            document.querySelectorAll('.text-truncate-container').forEach(container => {
                const content = container.querySelector('.text-truncate-content');
                const toggle = container.querySelector('.text-truncate-toggle');
                
                if (!content || !toggle) return;
                
                // Check if text needs truncation
                const fullHeight = content.scrollHeight;
                const lineHeight = parseInt(window.getComputedStyle(content).lineHeight);
                const maxHeight = lineHeight * 3; // 3 lines
                
                if (fullHeight <= maxHeight) {
                    // Text is short, hide toggle
                    toggle.style.display = 'none';
                    return;
                }
                
                // Add truncated class initially
                content.classList.add('truncated');
                toggle.style.display = 'inline';
                toggle.textContent = 'More';
                
                // Toggle functionality
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (content.classList.contains('truncated')) {
                        content.classList.remove('truncated');
                        toggle.textContent = 'Less';
                    } else {
                        content.classList.add('truncated');
                        toggle.textContent = 'More';
                    }
                });
            });
        }
        
        // Initialize gallery when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new ImageGallery();
            initTextTruncation();
            
            // Handle inactive council clicks
            document.querySelectorAll('.council-inactive').forEach(element => {
                element.addEventListener('click', function(e) {
                    e.preventDefault();
                    const collegeName = this.getAttribute('data-college-name');
                    
                    // Create and show a temporary message
                    const message = document.createElement('div');
                    message.className = 'alert alert-info alert-dismissible fade show position-fixed';
                    message.style.cssText = 'top: 20px; right: 20px; z-index: 9999; max-width: 300px;';
                    message.innerHTML = `
                        <i class="bi bi-info-circle me-2"></i>
                        No active council yet for ${collegeName}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    
                    document.body.appendChild(message);
                    
                    // Auto-remove after 3 seconds
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.remove();
                        }
                    }, 3000);
                });
            });
        });

        // Load all organizations and councils data for client-side filtering
        const organizationsData = <?php
            $allOrgsData = [];
            foreach ($colleges as $college) {
                $collegeId = $college['id'];
                
                // Get council for this college
                $stmt = $conn->prepare("SELECT id, council_name FROM council WHERE college_id = ? LIMIT 1");
                $stmt->bind_param("i", $collegeId);
                $stmt->execute();
                $council = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                // Get organizations for this college
                $stmt = $conn->prepare("SELECT id, org_name FROM organizations WHERE college_id = ? ORDER BY org_name ASC");
                $stmt->bind_param("i", $collegeId);
                $stmt->execute();
                $orgs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                $allOrgsData[$collegeId] = [
                    'council' => $council,
                    'organizations' => $orgs
                ];
            }
            echo json_encode($allOrgsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;

        // Event Filtering Functionality
        class EventFilter {
            constructor() {
                this.searchInput = document.getElementById('searchFilter');
                this.collegeSelect = document.getElementById('collegeFilter');
                this.organizationSelect = document.getElementById('organizationFilter');
                this.dateSelect = document.getElementById('dateFilter');
                this.eventTypeButtons = document.querySelectorAll('.event-type-btn');
                this.resetBtn = document.getElementById('resetFilters');
                this.filterForm = document.getElementById('filterForm');
                this.eventCards = document.querySelectorAll('.event-card');
                this.noEventsMessage = document.getElementById('noEventsMessage');
                
                this.currentEventType = 'all';
                
                this.initEventListeners();
                // Initialize organization dropdown if college is already selected
                if (this.collegeSelect.value) {
                    this.updateOrganizationDropdown();
                }
                // Initialize type filter state
                this.toggleTypeFilter();
                // Initial filter on page load
                this.filterEvents();
            }
            
            initEventListeners() {
                // Search input
                this.searchInput.addEventListener('input', () => this.filterEvents());
                
                // College select - populate organization dropdown and filter
                this.collegeSelect.addEventListener('change', () => {
                    this.updateOrganizationDropdown();
                    this.toggleTypeFilter();
                    this.filterEvents();
                });
                
                // Organization select - filter only
                this.organizationSelect.addEventListener('change', () => {
                    this.filterEvents();
                });
                
                // Date select
                this.dateSelect.addEventListener('change', () => this.filterEvents());
                
                // Event type buttons
                this.eventTypeButtons.forEach(btn => {
                    btn.addEventListener('click', () => {
                        this.eventTypeButtons.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        this.currentEventType = btn.dataset.type;
                        this.filterEvents();
                    });
                });
                
                // Reset button - reset all filters client-side
                this.resetBtn.addEventListener('click', () => this.resetFilters());
                
                // Prevent form submission (all filtering is client-side)
                this.filterForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                });
            }
            
            updateOrganizationDropdown() {
                const selectedCollegeId = this.collegeSelect.value;
                const orgSelect = this.organizationSelect;
                
                // Clear existing options except "All Organizations"
                orgSelect.innerHTML = '<option value="">All Organizations</option>';
                
                if (selectedCollegeId && organizationsData[selectedCollegeId]) {
                    const data = organizationsData[selectedCollegeId];
                    
                    // Enable dropdown
                    orgSelect.disabled = false;
                    
                    // Add council first if exists
                    if (data.council) {
                        const option = document.createElement('option');
                        option.value = 'council_' + data.council.id;
                        option.textContent = data.council.council_name;
                        orgSelect.appendChild(option);
                    }
                    
                    // Add organizations
                    data.organizations.forEach(org => {
                        const option = document.createElement('option');
                        option.value = org.id;
                        option.textContent = org.org_name;
                        orgSelect.appendChild(option);
                    });
                } else {
                    // Disable dropdown if no college selected
                    orgSelect.disabled = true;
                }
            }
            
            toggleTypeFilter() {
                const selectedCollegeId = this.collegeSelect.value;
                const isDisabled = selectedCollegeId !== '';
                
                // Disable/enable all type buttons
                this.eventTypeButtons.forEach(btn => {
                    btn.disabled = isDisabled;
                });
                
                // If disabling, reset to "All"
                if (isDisabled) {
                    this.eventTypeButtons.forEach(btn => {
                        btn.classList.remove('active');
                        if (btn.dataset.type === 'all') {
                            btn.classList.add('active');
                        }
                    });
                    this.currentEventType = 'all';
                }
            }
            
            filterEvents() {
                const searchTerm = this.searchInput.value.toLowerCase().trim();
                const selectedCollegeId = this.collegeSelect.value;
                const selectedOrganization = this.organizationSelect.value;
                const dateFilter = this.dateSelect.value;
                
                let visibleCount = 0;
                
                this.eventCards.forEach(card => {
                    const eventType = card.dataset.eventType;
                    const collegeId = card.dataset.collegeId || '';
                    const organizationId = card.dataset.organizationId || '';
                    const councilId = card.dataset.councilId || '';
                    const title = card.dataset.title || '';
                    const description = card.dataset.description || '';
                    const eventDate = new Date(card.dataset.eventDate);
                    
                    // Check event type filter
                    let matchesType = this.currentEventType === 'all' || eventType === this.currentEventType;
                    
                    // Check search filter
                    let matchesSearch = !searchTerm || 
                        title.includes(searchTerm) || 
                        description.includes(searchTerm);
                    
                    // Check college filter (by ID)
                    let matchesCollege = !selectedCollegeId || collegeId === selectedCollegeId;
                    
                    // Check organization filter
                    let matchesOrganization = true;
                    if (selectedOrganization) {
                        if (selectedOrganization.startsWith('council_')) {
                            // Filtering by council
                            const councilIdFromFilter = selectedOrganization.replace('council_', '');
                            matchesOrganization = councilId === councilIdFromFilter;
                        } else {
                            // Filtering by organization
                            matchesOrganization = organizationId === selectedOrganization;
                        }
                    }
                    
                    // Check date filter
                    let matchesDate = this.matchesDateFilter(eventDate, dateFilter);
                    
                    // Show/hide card based on all filters
                    if (matchesType && matchesSearch && matchesCollege && matchesOrganization && matchesDate) {
                        card.classList.remove('hidden');
                        visibleCount++;
                    } else {
                        card.classList.add('hidden');
                    }
                });
                
                // Show/hide no events message
                if (visibleCount === 0) {
                    this.noEventsMessage.style.display = 'block';
                } else {
                    this.noEventsMessage.style.display = 'none';
                }
            }
            
            matchesDateFilter(eventDate, filter) {
                if (filter === 'all') return true;
                
                // Filter by month (01-12)
                // JavaScript months are 0-indexed (0-11), so add 1 and pad with zero
                const eventMonth = String(eventDate.getMonth() + 1).padStart(2, '0');
                return eventMonth === filter;
            }
            
            resetFilters() {
                // Reset search input
                this.searchInput.value = '';
                
                // Reset college dropdown
                this.collegeSelect.value = '';
                
                // Reset organization dropdown
                this.organizationSelect.innerHTML = '<option value="">All Organizations</option>';
                this.organizationSelect.disabled = true;
                
                // Reset date filter
                this.dateSelect.value = 'all';
                
                // Reset event type buttons
                this.eventTypeButtons.forEach(btn => {
                    btn.classList.remove('active');
                    btn.disabled = false; // Re-enable buttons
                    if (btn.dataset.type === 'all') {
                        btn.classList.add('active');
                    }
                });
                this.currentEventType = 'all';
                
                // Update URL without reload (removes filter parameters)
                const url = new URL(window.location.href);
                url.searchParams.delete('college');
                url.searchParams.delete('organization');
                url.searchParams.delete('search');
                window.history.pushState({}, '', url);
                
                // Filter events to show all
                this.filterEvents();
            }
        }
        
        // Initialize filtering when DOM is loaded
        document.addEventListener('DOMContentLoaded', () => {
            new EventFilter();
        });
    </script>
</body>
</html> 
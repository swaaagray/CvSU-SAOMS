<?php
require_once 'session.php';
require_once __DIR__ . '/../config/database.php';

// Get user role early to avoid undefined variable warnings
$role = getUserRole();

function getNavItems($role) {
    $items = [
        'dashboard' => [
            'icon' => 'bi bi-speedometer2',
            'text' => 'Dashboard',
            'url' => 'dashboard.php',
            'roles' => ['mis_coordinator', 'osas', 'org_adviser', 'org_president', 'council_president', 'council_adviser']
        ],
        'members' => [
            'icon' => 'bi bi-people',
            'text' => 'Officers',
            'url' => 'members.php',
            'roles' => ['org_president', 'council_president']
        ],
        'events' => [
            'icon' => 'bi bi-calendar-event',
            'text' => 'Events',
            'url' => 'events.php',
            'roles' => ['org_president', 'council_president']
        ],
        'view_events' => [
            'icon' => 'bi bi-calendar-check',
            'text' => 'View Events',
            'url' => $role === 'org_adviser' ? 'adviser_view_events.php' : ($role === 'council_adviser' ? 'council_adviser_view_events.php' : ($role === 'osas' ? 'osas_view_events.php' : 'mis_view_events.php')),
            'roles' => ['mis_coordinator', 'osas', 'org_adviser', 'council_adviser']
        ],
        'view_officers' => [
            'icon' => 'bi bi-people',
            'text' => 'Officers',
            'url' => $role === 'org_adviser' ? 'adviser_view_officers.php' : 'council_adviser_view_officers.php',
            'roles' => ['org_adviser', 'council_adviser']
        ],
        'awards' => [
            'icon' => 'bi bi-trophy',
            'text' => 'Awards',
            'url' => 'awards.php',
            'roles' => ['org_president', 'council_president']
        ],
        'qf_forms' => [
            'icon' => 'bi bi-file-earmark-text',
            'text' => 'QF Forms',
            'url' => 'qf_forms.php',
            'roles' => ['org_president', 'council_president']
        ],
        'view_awards' => [
            'icon' => 'bi bi-award',
            'text' => 'View Awards',
            'url' => $role === 'org_adviser' ? 'adviser_view_awards.php' : ($role === 'council_adviser' ? 'council_adviser_view_awards.php' : ($role === 'osas' ? 'osas_view_awards.php' : 'mis_view_awards.php')),
            'roles' => ['mis_coordinator', 'osas', 'org_adviser', 'council_adviser']
        ],
        'financial' => [
            'icon' => 'bi bi-cash-stack',
            'text' => 'Financial',
            'url' => 'financial.php',
            'roles' => ['org_president', 'council_president']
        ],
        'membership_status' => [
            'icon' => 'bi bi-person-check',
            'text' => 'Membership Status',
            'url' => $role === 'council_president' ? 'council_membership.php' : 'membership.php',
            'roles' => ['org_president', 'council_president']
        ],
        'view_financial' => [
            'icon' => 'bi bi-cash',
            'text' => 'View Financial',
            'url' => 'view_financial.php',
            'roles' => ['osas', 'org_adviser', 'council_adviser']
        ],
        'organization_documents' => [
            'icon' => 'bi bi-file-earmark-arrow-up',
            'text' => ($role === 'council_president' ? 'Compliance Documents' : 'Compliance Documents'),
            'url' => '#',
            'roles' => ['org_president', 'council_president'],
            'dropdown' => [
                'organization_documents' => [
                    'icon' => 'bi bi-file-earmark-text',
                    'text' => ($role === 'council_president' ? 'Council Documents' : 'Org Documents'),
                    'url' => ($role === 'council_president' ? 'council_documents.php' : 'organization_documents.php')
                ],
                'event_proposals' => [
                    'icon' => 'bi bi-calendar-event',
                    'text' => 'Event Proposals',
                    'url' => ($role === 'council_president' ? 'event_council_proposals.php' : 'event_proposals.php')
                ]
            ]
        ],

        'bylaws' => [
            'icon' => 'bi bi-file-earmark-text',
            'text' => 'By-Laws',
            'url' => 'bylaws.php',
            'roles' => ['org_president', 'council_president']
        ],
        'osas_documents' => [
            'icon' => 'bi bi-file-earmark-text',
            'text' => 'OSAS Documents',
            'url' => '#',
            'roles' => ['osas'],
            'dropdown' => [
                'organization_documents' => [
                    'icon' => 'bi bi-file-earmark-text',
                    'text' => 'Org Documents',
                    'url' => 'osas_documents.php'
                ],
                'council_documents' => [
                    'icon' => 'bi bi-building',
                    'text' => 'Council Documents',
                    'url' => 'osas_council_documents.php'
                ]
            ]
        ],
        'document_status' => [
            'icon' => 'bi bi-file-earmark-check',
            'text' => 'Document Status',
            'url' => 'osas_document_status.php',
            'roles' => ['osas']
        ],
        'document_progress' => [
            'icon' => 'bi bi-file-earmark-check',
            'text' => 'Document Progress',
            'url' => '#',
            'roles' => ['org_adviser', 'council_adviser'],
            'dropdown' => [
                'organization_files' => [
                    'icon' => 'bi bi-file-earmark-text',
                    'text' => 'Organization Files',
                    'url' => $role === 'org_adviser' ? 'adviser_view_documents.php' : 'council_adviser_view_documents.php'
                ],
                'event_files' => [
                    'icon' => 'bi bi-calendar-event',
                    'text' => 'Event Files',
                    'url' => $role === 'org_adviser' ? 'adviser_view_event_files.php' : 'council_adviser_view_event_files.php'
                ]
            ]
        ],
        'view_bylaws' => [
            'icon' => 'bi bi-file-earmark-text',
            'text' => 'By-Laws',
            'url' => $role === 'org_adviser' ? 'adviser_view_bylaws.php' : 'council_adviser_view_bylaws.php',
            'roles' => ['org_adviser', 'council_adviser']
        ],
        'rankings' => [
            'icon' => 'bi bi-trophy',
            'text' => 'Rankings',
            'url' => 'rankings.php',
            'roles' => ['mis_coordinator']
        ],
        'mis_organizations' => [
            'icon' => 'bi bi-building',
            'text' => 'Organizations',
            'url' => 'mis_organizations.php',
            'roles' => ['mis_coordinator']
        ],
        'registry' => [
            'icon' => 'bi bi-journal-text',
            'text' => 'Registry',
            'url' => 'registry.php',
            'roles' => ['org_president']
        ],
        'organization_registration' => [
            'icon' => 'bi bi-building-add',
            'text' => 'Account Management',
            'url' => 'org_registration.php',
            'roles' => ['osas']
        ]
        ,
        'calendar_management' => [
            'icon' => 'bi bi-calendar3',
            'text' => 'Calendar Management',
            'url' => 'osas_calendar_management.php',
            'roles' => ['osas']
        ],
        'archive_reports' => [
            'icon' => 'bi bi-archive',
            'text' => 'Archive Reports',
            'url' => $role === 'osas' ? 'osas_archive_reports.php' : 
                    ($role === 'mis_coordinator' ? 'mis_archive_reports.php' : 
                    (in_array($role, ['council_president', 'council_adviser']) ? 'council_archive_reports.php' : 'archive_reports.php')),
            'roles' => ['mis_coordinator', 'osas', 'org_adviser', 'org_president', 'council_president', 'council_adviser']
        ],
        'osas_activity_permit' => [
            'icon' => 'bi bi-calendar-event',
            'text' => 'Activity Permit',
            'url' => 'osas_qf_forms.php',
            'roles' => ['osas']
        ]
    ];

    return array_filter($items, function($item) use ($role) {
        return in_array($role, $item['roles']);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
</head>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid d-flex align-items-center">
        <!-- Sidebar Toggle Button -->
        <button class="btn btn-link text-white me-3" id="sidebarToggle">
            <i class="bi bi-list"></i>
        </button>

        <!-- Brand -->
        <a class="navbar-brand d-none d-lg-flex" href="dashboard.php">
            Academic Organization Management System
        </a>

        <!-- Right Side Nav Items -->
        <div class="ms-auto d-flex align-items-center">
            <!-- Notification Bell - For all users except MIS Coordinator -->
            <?php if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mis_coordinator'): ?>
            <div class="dropdown me-3">
                <button class="btn btn-link text-white position-relative" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-bell fs-5"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="notificationBadge" style="display: none;">
                        0
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 notification-dropdown" style="width: 380px; max-width: calc(100vw - 2rem); max-height: 450px; overflow-y: auto; border-radius: 12px;" aria-labelledby="notificationDropdown">
                    <li>
                        <div class="dropdown-header d-flex justify-content-between align-items-center py-3 px-3">
                            <span class="fw-bold text-dark"><i class="bi bi-bell me-2"></i>Notifications</span>
                            <button class="btn btn-sm btn-outline-primary text-decoration-none" id="markAllRead">
                                <small>Mark all read</small>
                            </button>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider my-0"></li>
                    <li>
                        <div id="notificationList" class="p-0">
                            <div class="text-center py-4">
                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                <small class="text-muted d-block mt-2">Loading notifications...</small>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
            
            <!-- User Dropdown -->
            <div class="dropdown">
                <button class="btn btn-link text-white dropdown-toggle d-flex align-items-center gap-2 text-decoration-none" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle fs-5"></i>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userDropdown">
                    <li>
                        <div class="dropdown-header d-flex align-items-center gap-2">
                            <i class="bi bi-person-circle fs-4"></i>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                                <small class="text-muted"><?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?></small>
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <?php if (in_array($role, ['org_president', 'org_adviser'])): ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="organization_settings.php"><i class="bi bi-gear me-2"></i>Organization Settings</a></li>
                    <?php elseif (in_array($role, ['council_president', 'council_adviser'])): ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="council_settings.php"><i class="bi bi-gear me-2"></i>Council Settings</a></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- Sidebar Backdrop (Mobile Only) -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- Sidebar -->
<div class="sidebar bg-dark text-white" id="sidebar">
    <div class="sidebar-header p-3 bg-dark" style="padding-left: 1rem !important;">
        <h5 class="mb-0">Navigation</h5>
    </div>
    <ul class="nav flex-column">
        <?php
        $navItems = getNavItems($role);

        // Determine if navigation should be restricted to Dashboard only
        $disableNonDashboard = false;
        try {
            if ($role === 'org_president') {
                $orgId = function_exists('getCurrentOrganizationId') ? getCurrentOrganizationId() : null;
                if ($orgId) {
                    $stmt = $conn->prepare("SELECT academic_year_id FROM organizations WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("i", $orgId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        if ($result && is_null($result['academic_year_id'])) {
                            $disableNonDashboard = true;
                        }
                    }
                }
            } elseif ($role === 'council_president') {
                $councilId = function_exists('getCurrentCouncilId') ? getCurrentCouncilId() : null;
                if ($councilId) {
                    $stmt = $conn->prepare("SELECT academic_year_id FROM council WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("i", $councilId);
                        $stmt->execute();
                        $result = $stmt->get_result()->fetch_assoc();
                        if ($result && is_null($result['academic_year_id'])) {
                            $disableNonDashboard = true;
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Navbar academic year check error: ' . $e->getMessage());
        }

        if ($disableNonDashboard) {
            // Keep only the Dashboard item
            $navItems = array_filter($navItems, function($item) {
                return isset($item['url']) && $item['url'] === 'dashboard.php';
            });
        }

        foreach ($navItems as $key => $item):
            $isActive = basename($_SERVER['PHP_SELF']) === $item['url'];
            
            // Check if item has dropdown
            if (isset($item['dropdown'])):
                $hasActiveDropdown = false;
                foreach ($item['dropdown'] as $dropdownItem) {
                    if (basename($_SERVER['PHP_SELF']) === $dropdownItem['url']) {
                        $hasActiveDropdown = true;
                        break;
                    }
                }
        ?>
            <li class="nav-item">
                <a class="nav-link dropdown-toggle <?php echo $hasActiveDropdown ? 'active' : ''; ?>" href="#" data-bs-toggle="collapse" data-bs-target="#<?php echo $key; ?>Dropdown" aria-expanded="<?php echo $hasActiveDropdown ? 'true' : 'false'; ?>">
                    <i class="<?php echo $item['icon']; ?> me-2"></i>
                    <?php echo $item['text']; ?>
                </a>
                <div class="collapse <?php echo $hasActiveDropdown ? 'show' : ''; ?>" id="<?php echo $key; ?>Dropdown">
                    <ul class="nav flex-column ms-3">
                        <?php foreach ($item['dropdown'] as $dropdownKey => $dropdownItem): ?>
                            <?php $isDropdownActive = basename($_SERVER['PHP_SELF']) === $dropdownItem['url']; ?>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $isDropdownActive ? 'active' : ''; ?>" href="<?php echo $dropdownItem['url']; ?>">
                                    <i class="<?php echo $dropdownItem['icon']; ?> me-2"></i>
                                    <?php echo $dropdownItem['text']; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>
        <?php else: ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo $item['url']; ?>">
                    <i class="<?php echo $item['icon']; ?> me-2"></i>
                    <?php echo $item['text']; ?>
                </a>
            </li>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'osas'): ?>
            <?php $isActive = basename($_SERVER['PHP_SELF']) === 'osas_manage.php'; ?>
            <li class="nav-item">
                <a class="nav-link<?php echo $isActive ? ' active' : ''; ?>" href="osas_manage.php" style="display: flex; align-items: flex-start;">
                    <i class="bi bi-collection" style="min-width: 1.5em; text-align: center;"></i>
                    <span style="margin-left: 0.5em; display: block; white-space: normal;">Academic Management</span>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</div>

<!-- Add this CSS to your existing styles -->
<style>
.sidebar {
    position: fixed;
    top: 56px; /* Height of navbar */
    left: 0;
    height: calc(100vh - 56px);
    width: 250px;
    transition: all 0.3s ease;
    z-index: 1000;
    overflow-y: auto;
    background: linear-gradient(90deg, #212529 0%, #343a40 100%) !important;
}

/* Adjust sidebar for mobile navbar height */
@media (max-width: 576px) {
    .sidebar {
        top: 48px !important;
        height: calc(100vh - 48px) !important;
    }
}

@media (max-width: 768px) and (min-width: 577px) {
    .sidebar {
        top: 50px !important;
        height: calc(100vh - 50px) !important;
    }
}

/* Adjust sidebar for mobile navbar height */
@media (max-width: 576px) {
    .sidebar {
        top: 48px !important;
        height: calc(100vh - 48px) !important;
    }
}

@media (max-width: 768px) and (min-width: 577px) {
    .sidebar {
        top: 50px !important;
        height: calc(100vh - 50px) !important;
    }
}

.sidebar.collapsed {
    margin-left: -250px;
}

/* Mobile sidebar overlay */
@media (max-width: 991.98px) {
    .sidebar {
        transform: translateX(-100%);
        box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
        visibility: hidden;
        opacity: 0;
        transition: transform 0.3s ease, visibility 0.3s ease, opacity 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
        visibility: visible;
        opacity: 1;
    }
    
    .sidebar.collapsed {
        transform: translateX(-100%);
        margin-left: 0;
        visibility: hidden;
        opacity: 0;
    }
    
    /* Overlay backdrop when sidebar is open */
    .sidebar-backdrop {
        display: none;
        position: fixed;
        top: 56px;
        left: 0;
        width: 100%;
        height: calc(100vh - 56px);
        background: rgba(0, 0, 0, 0.5);
        z-index: 999;
        transition: opacity 0.3s ease;
    }
    
    .sidebar-backdrop.show {
        display: block;
    }
    
    /* Adjust backdrop for mobile navbar height */
    @media (max-width: 576px) {
        .sidebar-backdrop {
            top: 48px !important;
            height: calc(100vh - 48px) !important;
        }
    }
    
    @media (max-width: 768px) and (min-width: 577px) {
        .sidebar-backdrop {
            top: 50px !important;
            height: calc(100vh - 50px) !important;
        }
    }
}

.navbar {
    z-index: 1001;
    background: linear-gradient(90deg, #212529 0%, #343a40 100%) !important;
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    display: flex !important;
}

/* Compact navbar on mobile */
@media (max-width: 576px) {
    .navbar {
        min-height: 48px !important;
        padding-top: 0.25rem !important;
        padding-bottom: 0.25rem !important;
    }
    
    .navbar .container-fluid {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    
    /* Smaller hamburger button - match notification button size */
    #sidebarToggle {
        min-width: 40px;
        min-height: 40px;
        padding: 0.375rem;
        margin-right: 0.5rem !important;
    }
    
    #sidebarToggle i {
        font-size: 1.1rem; /* Match mobile notification icon size */
    }
    
    /* Match notification button size on mobile */
    #notificationDropdown,
    #userDropdown {
        min-width: 40px;
        min-height: 40px;
        padding: 0.375rem;
    }
    
    /* Compact right side buttons */
    .navbar .btn-link {
        padding: 0.25rem 0.375rem !important;
    }
    
    .navbar .btn-link i {
        font-size: 1.1rem !important;
    }
    
    /* Adjust notification badge position */
    #notificationBadge {
        font-size: 0.65rem !important;
        padding: 0.15em 0.4em !important;
    }
}

/* Tablet adjustments */
@media (max-width: 768px) and (min-width: 577px) {
    .navbar {
        min-height: 50px !important;
        padding-top: 0.375rem !important;
        padding-bottom: 0.375rem !important;
    }
}

/* Ensure navbar container uses flexbox for alignment */
.navbar .container-fluid {
    display: flex;
    align-items: center;
}

/* Align hamburger button with notification button */
#sidebarToggle {
    z-index: 1002 !important;
    position: relative;
    cursor: pointer;
    display: flex !important;
    align-items: center;
    justify-content: center;
    padding: 0.5rem;
    border: none;
    background: transparent;
    /* Match notification button styling */
    min-width: 44px;
    min-height: 44px;
}

#sidebarToggle i {
    font-size: 1.25rem; /* Match notification bell icon size */
}

#sidebarToggle:hover {
    background-color: rgba(255, 255, 255, 0.1);
    border-radius: 4px;
}

#sidebarToggle:focus {
    outline: 2px solid rgba(255, 255, 255, 0.5);
    outline-offset: 2px;
}

#sidebarToggle:active {
    background-color: rgba(255, 255, 255, 0.2);
}

/* Ensure notification and user buttons have same height */
#notificationDropdown,
#userDropdown {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 44px;
    min-height: 44px;
}

/* Navbar brand styling */
.navbar-brand {
    display: flex !important;
    align-items: center !important;
    white-space: nowrap;
    overflow: visible;
    flex-shrink: 0;
    min-width: auto;
    text-decoration: none;
}

/* Hide brand on mobile and tablet */
@media (max-width: 991.98px) {
    .navbar-brand {
        display: none !important;
    }
}

.sidebar-header {
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    background: transparent !important;
}

.nav-link {
    color: rgba(255, 255, 255, 0.9) !important;
    padding: 0.8rem 1rem;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    white-space: nowrap;
}

.nav-link:hover {
    color: #fff !important;
    background: rgba(255, 255, 255, 0.15);
}

.nav-link.active {
    color: #fff !important;
    background: rgba(255, 255, 255, 0.25);
    border-left: 3px solid #fff;
}

/* Ensure all icons inherit consistent colors */
.nav-link i {
    color: inherit !important;
}

.nav-link:hover i {
    color: inherit !important;
}

.nav-link.active i {
    color: inherit !important;
}

/* Adjust main content margin */
main {
    margin-left: 250px;
    margin-top: 56px;
    padding: 20px;
    transition: all 0.3s ease;
}

main.expanded {
    margin-left: 0;
}

/* Mobile main content adjustments */
@media (max-width: 991.98px) {
    main {
        margin-left: 0;
        padding: 15px;
        padding-top: 2rem !important; /* Increased from 1.5rem to match dashboard */
    }
    
    main.expanded {
        margin-left: 0;
    }
    
    /* Ensure page headers are visible on mobile/tablet */
    main .page-title {
        margin-top: 0.75rem; /* Increased for consistency */
    }
    
    /* Ensure container-fluid has consistent spacing */
    main > .container-fluid {
        padding-top: 0.75rem !important;
    }
}

/* Fix flex containers to stack on mobile */
@media (max-width: 576px) {
    main .d-flex.justify-content-between {
        flex-direction: column;
        align-items: flex-start !important;
    }
    
    main .d-flex.justify-content-between > div:last-child {
        width: 100%;
        margin-top: 1rem;
    }
}

/* Extra small devices - ensure content is visible */
@media (max-width: 576px) {
    /* Calculate actual navbar height: min-height (48px) + padding-top (0.25rem ≈ 4px) + padding-bottom (0.25rem ≈ 4px) = ~56px */
    main {
        margin-top: 56px !important; /* Full navbar height including padding */
        padding-top: 2rem !important; /* Standardized to match dashboard - increased from 1.5rem */
        padding-left: 10px;
        padding-right: 10px;
        padding-bottom: 10px;
    }
    
    /* Ensure content inside main has proper spacing - consistent for all pages */
    main > .container-fluid {
        padding-top: 1rem !important; /* Consistent spacing */
    }
    
    /* Fix for pages using py-4 class - same as regular main now */
    main.py-4 {
        padding-top: 2rem !important; /* Same as regular main */
    }
    
    /* Ensure page headers have consistent spacing */
    main .page-title,
    main h2.page-title {
        margin-top: 0.75rem; /* Increased from 0.5rem for better spacing */
        padding-top: 0.25rem;
    }
    
    /* Ensure first content element has proper spacing */
    main > .container-fluid > div:first-child,
    main > .container-fluid > .d-flex:first-child {
        margin-top: 0.5rem;
    }
}

/* Tablet adjustments */
@media (max-width: 768px) and (min-width: 577px) {
    main {
        margin-top: 54px !important; /* Increased to account for navbar padding */
        padding-top: 2rem !important; /* Increased to match mobile */
    }
    
    /* Fix for pages using py-4 class on tablet */
    main.py-4 {
        padding-top: 2rem !important; /* Same as regular main */
    }
    
    /* Ensure page headers are visible on tablet */
    main .page-title {
        margin-top: 0.75rem; /* Increased for consistency */
    }
    
    /* Ensure container-fluid has consistent spacing */
    main > .container-fluid {
        padding-top: 0.75rem !important;
    }
}

/* Notification Styles */
.notification-item {
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
    background: transparent;
    position: relative;
}

.notification-item:hover {
    background-color: #f8f9fa !important;
    transform: translateX(2px);
}

.notification-item.unread {
    background-color: #e3f2fd;
    border-left: 4px solid #2196f3;
}

.notification-item.unread:hover {
    background-color: #bbdefb !important;
}

.notification-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background-color: #f8f9fa;
    color: #6c757d;
}

.notification-icon i {
    font-size: 1rem !important;
}

.notification-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: #212529;
    line-height: 1.2;
}

.notification-message {
    font-size: 0.8rem;
    line-height: 1.4;
    color: #6c757d;
}

.notification-time {
    font-size: 0.75rem;
    color: #adb5bd;
    white-space: nowrap;
    text-align: right;
    min-width: fit-content;
}

.unread-indicator {
    width: 8px;
    height: 8px;
    background-color: #2196f3;
    border-radius: 50%;
    position: absolute;
    top: 12px;
    right: 12px;
}

/* Dropdown customization */
.dropdown-menu {
    border: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.dropdown-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
}

#markAllRead {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

#markAllRead:hover {
    background-color: #e3f2fd;
}

/* Mobile notification dropdown adjustments */
@media (max-width: 576px) {
    .notification-dropdown {
        width: calc(100vw - 1rem) !important;
        max-width: calc(100vw - 1rem) !important;
        left: 0.5rem !important;
        right: 0.5rem !important;
        margin-left: 0 !important;
    }
    
    .notification-item {
        padding: 0.75rem !important;
    }
    
    .notification-title {
        font-size: 0.8rem !important;
    }
    
    .notification-message {
        font-size: 0.75rem !important;
    }
    
    .notification-time {
        font-size: 0.7rem !important;
    }
}

/* Additional mobile navbar adjustments */
@media (max-width: 576px) {
    .navbar .dropdown-toggle span {
        display: none;
    }
    
    /* Ensure notification dropdown doesn't overflow */
    .notification-dropdown {
        max-width: calc(100vw - 1rem) !important;
    }
}
</style>

<!-- Add this JavaScript to your existing scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    // Try to find main element first, then fallback to password-change-container
    const mainContent = document.querySelector('main') || document.querySelector('.password-change-container');
    
    // Function to check if mobile
    function isMobile() {
        return window.innerWidth <= 991.98;
    }
    
    // Initialize sidebar state based on screen size
    function initializeSidebar() {
        if (!sidebar) return;
        
        if (isMobile()) {
            // On mobile: sidebar starts hidden
            sidebar.classList.remove('show');
            sidebar.classList.add('collapsed');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.remove('show');
            }
        } else {
            // On desktop: check saved state
            if (mainContent) {
                const sidebarState = localStorage.getItem('sidebarState');
                if (sidebarState === 'collapsed') {
                    sidebar.classList.add('collapsed');
                    mainContent.classList.add('expanded');
                } else {
                    sidebar.classList.remove('collapsed');
                    mainContent.classList.remove('expanded');
                }
            }
        }
    }
    
    // Initialize on load
    initializeSidebar();
    
    if (sidebarToggle && sidebar) {
        // Always attach click handler (works for both mobile and desktop)
        sidebarToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (isMobile()) {
                // Mobile: toggle overlay
                const isShowing = sidebar.classList.contains('show');
                if (isShowing) {
                    sidebar.classList.remove('show');
                    sidebar.classList.add('collapsed');
                    if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
                } else {
                    sidebar.classList.remove('collapsed');
                    sidebar.classList.add('show');
                    if (sidebarBackdrop) sidebarBackdrop.classList.add('show');
                }
            } else {
                // Desktop: toggle collapse
                sidebar.classList.toggle('collapsed');
                if (mainContent) {
                    mainContent.classList.toggle('expanded');
                }
                localStorage.setItem('sidebarState', 
                    sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded'
                );
            }
        });
        
        // Close sidebar when clicking backdrop (mobile only)
        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', function() {
                if (isMobile()) {
                    sidebar.classList.remove('show');
                    sidebar.classList.add('collapsed');
                    sidebarBackdrop.classList.remove('show');
                }
            });
        }
        
        // Close sidebar when clicking nav links (mobile only)
        const navLinks = sidebar.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', function() {
                if (isMobile()) {
                    sidebar.classList.remove('show');
                    sidebar.classList.add('collapsed');
                    if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
                }
            });
        });
        
        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                initializeSidebar();
            }, 250);
        });
    }
    
    // Notification System
    class NotificationSystem {
        constructor() {
            this.badge = document.getElementById('notificationBadge');
            this.list = document.getElementById('notificationList');
            this.markAllReadBtn = document.getElementById('markAllRead');
            this.interval = null;
            
            this.init();
        }
        
        init() {
            this.loadNotificationCount();
            this.loadNotifications();
            this.setupEventListeners();
            this.startAutoRefresh();
        }
        
        async loadNotificationCount() {
            try {
                const response = await fetch('api/notifications_simple.php?action=get_count');
                const data = await response.json();
                
                if (data.success) {
                    this.updateBadge(data.count);
                }
            } catch (error) {
                console.error('Error loading notification count:', error);
            }
        }
        
        async loadNotifications() {
            try {
                console.log('Loading notifications from API...');
                const response = await fetch('api/notifications_simple.php?action=get_notifications&limit=10');
                
                if (!response.ok) {
                    if (response.status === 500) {
                        throw new Error(`Server error (500). Please check server logs for details.`);
                    } else if (response.status === 404) {
                        throw new Error(`API endpoint not found (404). Please check the API file.`);
                    } else {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                }
                
                // Get response text first to check if it's valid JSON
                const responseText = await response.text();
                console.log('Raw API response:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (jsonError) {
                    console.error('JSON parsing error:', jsonError);
                    console.error('Response was not valid JSON:', responseText);
                    throw new Error('Invalid response format from server');
                }
                
                console.log('Parsed API response:', data);
                
                if (data.success) {
                    this.renderNotifications(data.notifications);
                } else {
                    console.error('API returned error:', data.error);
                    this.renderError(data.error || 'Failed to load notifications');
                }
            } catch (error) {
                console.error('Error loading notifications:', error);
                this.renderError(error.message || 'Network error occurred');
            }
        }
        
        updateBadge(count) {
            if (count > 0) {
                this.badge.textContent = count;
                this.badge.style.display = 'block';
            } else {
                this.badge.style.display = 'none';
            }
        }
        
        renderNotifications(notifications) {
            if (notifications.length === 0) {
                this.list.innerHTML = `
                    <div class="text-center py-5">
                        <i class="bi bi-bell-slash text-muted fs-1"></i>
                        <small class="text-muted d-block mt-2">No notifications</small>
                    </div>
                `;
                return;
            }
            
            const html = notifications.map(notification => {
                // Debug: Log each notification being rendered
                console.log('Rendering notification:', {
                    id: notification.id,
                    type: notification.type,
                    related_id: notification.related_id,
                    related_type: notification.related_type,
                    title: notification.title
                });
                
                return `
                <div class="notification-item ${notification.is_read ? '' : 'unread'}" 
                     data-notification-id="${notification.id}"
                     data-notification-type="${notification.type}"
                     data-related-id="${notification.related_id || ''}"
                     data-related-type="${notification.related_type || ''}">
                    <div class="d-flex align-items-start p-3 border-bottom border-light">
                        <div class="flex-shrink-0 me-3">
                            <div class="notification-icon d-flex align-items-center justify-content-center">
                                <i class="${notification.icon} fs-5"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1" style="min-width: 0;">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div class="notification-title" style="flex: 1; margin-right: 10px; overflow: hidden; text-overflow: ellipsis;">${notification.title}</div>
                                <small class="text-muted notification-time" style="white-space: nowrap; flex-shrink: 0;">
                                    <i class="bi bi-clock me-1"></i>${notification.time_ago}
                                </small>
                            </div>
                            <div class="notification-message text-muted small">${notification.message}</div>
                        </div>
                        ${!notification.is_read ? '<div class="unread-indicator"></div>' : ''}
                    </div>
                </div>
            `;
            }).join('');
            
            this.list.innerHTML = html;
            this.setupNotificationClickListeners();
        }
        
        renderError(errorMessage = 'Error loading notifications') {
            this.list.innerHTML = `
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle text-danger fs-1"></i>
                    <small class="text-danger d-block mt-2">${errorMessage}</small>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="notificationSystem.loadNotifications()">
                        <i class="bi bi-arrow-clockwise"></i> Retry
                    </button>
                </div>
            `;
        }
        
        setupEventListeners() {
            // Mark all as read
            this.markAllReadBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                try {
                    const response = await fetch('api/notifications_simple.php?action=mark_all_read', {
                        method: 'POST'
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.loadNotificationCount();
                        this.loadNotifications();
                    }
                } catch (error) {
                    console.error('Error marking all as read:', error);
                }
            });
            
            // Refresh notifications when dropdown opens
            document.getElementById('notificationDropdown').addEventListener('show.bs.dropdown', () => {
                this.loadNotifications();
            });
        }
        
        setupNotificationClickListeners() {
            console.log('setupNotificationClickListeners called');
            const items = document.querySelectorAll('.notification-item');
            console.log(`Found ${items.length} notification items`);
            
            items.forEach((item, index) => {
                console.log(`Setting up click listener for item ${index + 1}`);
                item.addEventListener('click', async (e) => {
                    e.preventDefault();
                    const notificationId = item.dataset.notificationId;
                    const notificationType = item.dataset.notificationType;
                    const relatedId = item.dataset.relatedId;
                    const relatedType = item.dataset.relatedType;
                    
                    // Debug logging
                    console.log('Notification clicked:', {
                        notificationId,
                        notificationType,
                        relatedId,
                        relatedType
                    });
                    
                    // Debug: Log the actual dataset values
                    console.log('Dataset values:', {
                        notificationId: item.dataset.notificationId,
                        notificationType: item.dataset.notificationType,
                        relatedId: item.dataset.relatedId,
                        relatedType: item.dataset.relatedType
                    });
                    
                    // Debug: Check if notificationType is empty
                    if (!notificationType || notificationType === '') {
                        console.error('ERROR: notificationType is empty!');
                        console.log('All dataset attributes:', item.dataset);
                    }
                    
                    // Mark as read
                    try {
                        await fetch('api/notifications_simple.php?action=mark_read', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `notification_id=${notificationId}`
                        });
                        
                        // Update UI
                        item.classList.remove('unread');
                        const unreadIndicator = item.querySelector('.unread-indicator');
                        if (unreadIndicator) {
                            unreadIndicator.remove();
                        }
                        this.loadNotificationCount();
                        
                        // Navigate based on notification type
                        this.navigateToNotificationPage(notificationType, relatedId, relatedType);
                        
                    } catch (error) {
                        console.error('Error marking notification as read:', error);
                    }
                });
            });
        }
        
        navigateToNotificationPage(type, relatedId, relatedType) {
            let url = 'dashboard.php'; // Default fallback
            let params = new URLSearchParams();
            
            // Debug logging
            console.log('navigateToNotificationPage called with:', { type, relatedId, relatedType });
            
            // Add notification context for highlighting
            if (relatedId) {
                params.append('notification_id', relatedId);
                params.append('notification_type', type);
            }
            
            console.log('Navigating notification:', { type, relatedId, relatedType });
            
            // Get current user role to determine appropriate navigation
            const userRole = '<?php echo $_SESSION['role'] ?? ''; ?>';
            console.log('User role:', userRole);
            
            switch (type) {
                case 'document_submitted':
                case 'document_approved':
                case 'document_rejected':
                    // President goes to their document submission page
                    if (userRole === 'org_president') {
                        url = 'organization_documents.php';
                        // No status filter - show all documents with highlighting
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        // OSAS goes to org or council documents depending on related type
                        if (relatedType === 'council_document') {
                            url = 'osas_council_documents.php';
                            params.append('tab', 'council_documents');
                        } else {
                            url = 'osas_documents.php';
                            params.append('tab', 'organization_documents');
                        }
                        // No status filter - show all documents with highlighting
                    } else {
                        // Adviser goes to their view page
                        url = (relatedType === 'council_document') ? 'council_adviser_view_documents.php' : 'adviser_view_documents.php';
                        if (type === 'document_approved') {
                            params.append('status', 'approved');
                        } else if (type === 'document_rejected') {
                            params.append('status', 'rejected');
                        } else {
                            params.append('status', 'pending');
                        }
                    }
                    break;
                    
                case 'event_submitted':
                case 'event_approved':
                case 'event_rejected':
                case 'event_document_approved':
                case 'event_document_rejected':
                    // President goes to their event submission page
                    if (userRole === 'org_president') {
                        url = 'event_proposals.php';
                        // No status filter - show all events with highlighting
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        // OSAS goes to their documents page
                        url = 'osas_documents.php';
                        params.append('tab', 'event_documents');
                        // No status filter - show all events with highlighting
                    } else {
                        // Adviser goes to their view page
                        url = 'adviser_view_event_files.php';
                        if (type.includes('approved')) {
                            params.append('status', 'approved');
                        } else if (type.includes('rejected')) {
                            params.append('status', 'rejected');
                        } else {
                            params.append('status', 'pending_review');
                        }
                    }
                    break;
                    
                case 'documents_for_review':
                case 'event_documents_for_review':
                    url = 'osas_documents.php';
                    // Add tab parameter to show relevant section
                    if (type === 'documents_for_review') {
                        params.append('tab', 'organization_documents');
                    } else {
                        params.append('tab', 'event_documents');
                    }
                    // No status filter - show all documents with highlighting
                    break;
                    
                case 'documents_sent_to_osas':
                case 'event_documents_sent_to_osas':
                    // President goes to their documents/events page to see the status
                    if (userRole === 'org_president') {
                        if (type === 'documents_sent_to_osas') {
                            url = 'organization_documents.php';
                        } else {
                            url = 'event_proposals.php';
                        }
                        // No status filter - show all documents/events with highlighting
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        // OSAS goes to their documents page
                        url = 'osas_documents.php';
                        if (type === 'documents_sent_to_osas') {
                            params.append('tab', 'organization_documents');
                        } else {
                            params.append('tab', 'event_documents');
                        }
                        // No status filter - show all documents with highlighting
                    } else {
                        // Adviser goes to their view page
                        if (type === 'documents_sent_to_osas') {
                            url = 'adviser_view_documents.php';
                        } else {
                            url = 'adviser_view_event_files.php';
                        }
                        // No status filter - show all documents/events with highlighting
                    }
                    break;
                    
                case 'council_documents_for_review':
                case 'council_event_documents_for_review':
                    // OSAS views council documents
                    if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        url = 'osas_council_documents.php';
                        // Add tab parameter to show relevant section
                        if (type === 'council_documents_for_review') {
                            params.append('tab', 'council_documents');
                        } else {
                            params.append('tab', 'council_event_documents');
                        }
                    } else if (userRole === 'council_president') {
                        // Council president goes to their documents page
                        if (type === 'council_documents_for_review') {
                            url = 'council_documents.php';
                        } else {
                            url = 'event_council_proposals.php';
                        }
                    } else {
                        // Council adviser
                        if (type === 'council_documents_for_review') {
                            url = 'council_adviser_view_documents.php';
                        } else {
                            url = 'council_adviser_view_event_files.php';
                        }
                    }
                    break;
                    
                case 'council_documents_sent_to_osas':
                case 'council_event_documents_sent_to_osas':
                    // Council president sees status on their page
                    if (userRole === 'council_president') {
                        if (type === 'council_documents_sent_to_osas') {
                            url = 'council_documents.php';
                        } else {
                            url = 'event_council_proposals.php';
                        }
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        // OSAS goes to council documents page
                        url = 'osas_council_documents.php';
                        if (type === 'council_documents_sent_to_osas') {
                            params.append('tab', 'council_documents');
                        } else {
                            params.append('tab', 'council_event_documents');
                        }
                    } else {
                        // Council adviser
                        if (type === 'council_documents_sent_to_osas') {
                            url = 'council_adviser_view_documents.php';
                        } else {
                            url = 'council_adviser_view_event_files.php';
                        }
                    }
                    break;
                    
                case 'council_document_approved':
                case 'council_document_rejected':
                    // Council president or adviser sees their documents
                    if (userRole === 'council_president') {
                        url = 'council_documents.php';
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        url = 'osas_council_documents.php';
                        params.append('tab', 'council_documents');
                    } else {
                        url = 'council_adviser_view_documents.php';
                    }
                    break;
                    
                case 'council_event_approved':
                case 'council_event_rejected':
                    // Council president or adviser sees their events
                    if (userRole === 'council_president') {
                        url = 'event_council_proposals.php';
                    } else if (userRole === 'osas' || userRole === 'mis_coordinator') {
                        url = 'osas_council_documents.php';
                        params.append('tab', 'council_event_documents');
                    } else {
                        url = 'council_adviser_view_event_files.php';
                    }
                    break;
                    
                default:
                    url = 'dashboard.php';
            }
            
            console.log('Final URL:', url);
            console.log('Parameters:', params.toString());
            
            // Close dropdown
            const dropdown = document.getElementById('notificationDropdown');
            const bsDropdown = bootstrap.Dropdown.getInstance(dropdown);
            if (bsDropdown) {
                bsDropdown.hide();
            }
            
            // Navigate to page with parameters
            const finalUrl = params.toString() ? `${url}?${params.toString()}` : url;
            console.log('Navigating to:', finalUrl);
            window.location.href = finalUrl;
        }
        
        startAutoRefresh() {
            // Refresh notification count every 30 seconds
            this.interval = setInterval(() => {
                this.loadNotificationCount();
            }, 30000);
        }
        
        stopAutoRefresh() {
            if (this.interval) {
                clearInterval(this.interval);
            }
        }
    }
    
    // Initialize notification system - For all users except MIS Coordinator
    <?php if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'mis_coordinator'): ?>
    console.log('Initializing NotificationSystem...');
    const notificationSystem = new NotificationSystem();
    
    // Debug: Check if notification dropdown exists
    const dropdown = document.getElementById('notificationDropdown');
    if (dropdown) {
        console.log('Notification dropdown found');
    } else {
        console.log('ERROR: Notification dropdown not found');
    }
    
    // Debug: Check if notification list exists
    const list = document.getElementById('notificationList');
    if (list) {
        console.log('Notification list found');
    } else {
        console.log('ERROR: Notification list not found');
    }
    
    // Debug: Check if notification badge exists
    const badge = document.getElementById('notificationBadge');
    if (badge) {
        console.log('Notification badge found');
    } else {
        console.log('ERROR: Notification badge not found');
    }
    <?php else: ?>
    console.log('OSAS or MIS user detected - skipping notification system initialization');
    <?php endif; ?>
});
</script> 
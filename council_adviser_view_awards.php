<?php
require_once 'config/database.php';
require_once 'includes/session.php';

requireRole(['council_adviser']);

$userId = getCurrentUserId();
$stmt = $conn->prepare("SELECT id, council_name FROM council WHERE adviser_id = ? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$council = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$council) {
    echo '<div class="alert alert-danger">No council found for this adviser.</div>';
    exit;
}

$councilId = (int)$council['id'];

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$searchTitle = $_GET['search_title'] ?? '';

// Determine if user is actively filtering (only show archived data when filtering)
$isFiltering = !empty($startDate) || !empty($endDate) || !empty($searchTitle);

// Set status condition based on whether user is actively filtering
if ($isFiltering) {
    // User is searching/filtering - include archived data
    $statusCondition = "(s.status IN ('active', 'archived') OR s.status IS NULL) 
                        AND (at.status IN ('active', 'archived') OR at.status IS NULL)";
} else {
    // Default view - only show current/active academic year data
    $statusCondition = "s.status = 'active' AND at.status = 'active'";
}

$query = "
    SELECT a.*, 
           (SELECT COUNT(*) FROM award_images WHERE award_id = a.id) as image_count,
           (SELECT COUNT(*) FROM award_participants WHERE award_id = a.id) as participant_count
    FROM awards a
    JOIN academic_semesters s ON a.semester_id = s.id
    JOIN academic_terms at ON s.academic_term_id = at.id
    WHERE a.council_id = ? AND $statusCondition
";
$params = [$councilId];
$types = 'i';
if ($startDate) { $query .= " AND a.award_date >= ?"; $params[] = $startDate; $types .= 's'; }
if ($endDate) { $query .= " AND a.award_date <= ?"; $params[] = $endDate; $types .= 's'; }
if ($searchTitle) { $query .= " AND a.title LIKE ?"; $params[] = "%$searchTitle%"; $types .= 's'; }
$query .= " ORDER BY a.award_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$awards = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Awards - Council Adviser</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        main { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); min-height: calc(100vh - 56px); }
        .page-header h2 { color: #343a40; font-weight: 700; margin-bottom: 0.5rem; }
        .page-header p { color: #6c757d; font-size: 1.1rem; margin-bottom: 2rem; }
        .card { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%); transition: all 0.3s ease; overflow: hidden; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15); }
        .filter-section { border-left: 5px solid #6c757d; }
        .filter-section .card-header { background: linear-gradient(90deg, #343a40, #495057) !important; color: white; border: none; font-weight: 600; padding: 1rem 1.5rem; }
        .filter-section .card-title { margin: 0; font-size: 1.1rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-section .card-body { padding: 2rem; }
        .col-md-6:nth-child(odd) .card { border-left: 5px solid #495057; }
        .col-md-6:nth-child(even) .card { border-left: 5px solid #6c757d; }
        .card-body { padding: 2rem; }
        .form-label { color: #495057; font-weight: 600; margin-bottom: 0.5rem; }
        .form-control, .form-select { border: 2px solid #e9ecef; border-radius: 8px; transition: all 0.3s ease; font-size: 0.95rem; }
        .form-control:focus, .form-select:focus { border-color: #495057; box-shadow: 0 0 0 0.2rem rgba(73, 80, 87, 0.25); }
        .input-group-text { background: linear-gradient(45deg, #495057, #6c757d); border: 2px solid #495057; color: white; font-weight: 600; }
        .btn-primary { background: linear-gradient(45deg, #495057, #6c757d); border: none; font-weight: 600; border-radius: 8px; transition: all 0.3s ease; padding: 0.6rem 1.5rem; }
        .btn-primary:hover { background: linear-gradient(45deg, #343a40, #495057); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(73, 80, 87, 0.3); }
        .btn-secondary { background: linear-gradient(45deg, #6c757d, #868e96); border: none; font-weight: 600; border-radius: 8px; transition: all 0.3s ease; }
        .btn-secondary:hover { background: linear-gradient(45deg, #5a6268, #5a6268); transform: translateY(-2px); }
        .badge.bg-primary, .badge.bg-success { background: linear-gradient(45deg, #343a40, #495057) !important; font-weight: 600; padding: 0.5rem 1rem; border-radius: 20px; }
        .badge.bg-secondary { background: linear-gradient(45deg, #6c757d, #868e96) !important; font-weight: 600; padding: 0.3rem 0.8rem; border-radius: 15px; }
        .card-title { color: #343a40; font-weight: 700; font-size: 1.2rem; }
        .card-text { color: #6c757d !important; line-height: 1.6; }
        .img-gallery { margin-bottom: 1rem; }
        .img-gallery .col-4 { padding: 0.25rem; }
        .img-gallery img { border-radius: 12px; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); border: 3px solid transparent; cursor: pointer; position: relative; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); }
        .img-gallery img:hover { transform: scale(1.08) rotate(1deg); border-color: #495057; box-shadow: 0 8px 25px rgba(73, 80, 87, 0.4); z-index: 10; }
        .col-md-6:nth-child(even) .img-gallery img:hover { border-color: #6c757d; box-shadow: 0 8px 25px rgba(108, 117, 125, 0.4); transform: scale(1.08) rotate(-1deg); }
        .gallery-item { position: relative; display: block; overflow: hidden; border-radius: 12px; cursor: pointer; }
        .gallery-item::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(45deg, rgba(73, 80, 87, 0.8), rgba(108, 117, 125, 0.8)); opacity: 0; transition: opacity 0.3s ease; z-index: 2; border-radius: 12px; }
        .gallery-item:hover::before { opacity: 0.3; }
        .gallery-item::after { content: '🔍'; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0); font-size: 1.5rem; color: white; z-index: 3; transition: transform 0.3s ease; }
        .gallery-item:hover::after { transform: translate(-50%, -50%) scale(1); }
        .table { border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); }
        .table thead th { background: linear-gradient(90deg, #343a40, #495057); color: white; font-weight: 600; border: none; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .table tbody tr:hover { background: rgba(108, 117, 125, 0.1) !important; }
        @keyframes fadeInUp { from { opacity:0; transform: translateY(30px);} to { opacity:1; transform: translateY(0);} }
        .col-md-6 { animation: fadeInUp 0.6s ease forwards; }
        .col-md-6:nth-child(even) { animation-delay: 0.2s; }
        .col-md-6:nth-child(3n) { animation-delay: 0.4s; }
        @media (max-width: 768px){ .card-body{ padding:1.5rem;} .page-header h2{ font-size:1.5rem;} .img-gallery img{ height:80px !important; } }
        .image-gallery-modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.95); z-index:9999; backdrop-filter: blur(10px); }
        .gallery-modal-content { position:relative; width:100%; height:100%; display:flex; align-items:center; justify-content:center; }
        .gallery-main-image { max-width:80%; max-height:80%; object-fit:contain; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,0.5); }
        .gallery-close { position:absolute; top:20px; right:30px; color:#fff; font-size:2rem; cursor:pointer; z-index:10001; background: rgba(0,0,0,0.5); border-radius:50%; width:50px; height:50px; display:flex; align-items:center; justify-content:center; transition: all .3s ease; }
        .gallery-close:hover { background: rgba(73,80,87,.8); transform: scale(1.1); }
        .gallery-nav { position:absolute; top:50%; transform: translateY(-50%); background: rgba(0,0,0,.7); color:white; border:none; font-size:2rem; padding:15px 20px; cursor:pointer; border-radius:50%; transition: all .3s ease; z-index:10001; }
        .gallery-prev { left:30px; } .gallery-next { right:30px; }
        .gallery-thumbnails { position:absolute; bottom:20px; left:50%; transform: translateX(-50%); display:flex; gap:10px; max-width:80%; overflow-x:auto; padding:10px; background: rgba(0,0,0,.5); border-radius:25px; backdrop-filter: blur(10px); }
        .gallery-thumbnail { width:60px; height:60px; object-fit:cover; border-radius:8px; cursor:pointer; opacity:.6; transition: all .3s ease; border:2px solid transparent; }
        .gallery-thumbnail:hover, .gallery-thumbnail.active { opacity:1; border-color:#495057; transform: scale(1.1); }
        .gallery-info { position:absolute; top:20px; left:30px; color:white; background: rgba(0,0,0,.7); padding:15px 20px; border-radius:12px; backdrop-filter: blur(10px); }
        .gallery-counter { font-size:1.1rem; font-weight:600; margin-bottom:5px; }
        .gallery-title { font-size:.9rem; opacity:.8; }
        .gallery-loading { position:absolute; top:50%; left:50%; transform: translate(-50%,-50%); color:white; font-size:2rem; }
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
    </style>
</head>
<body class="bg-light">
<?php include 'includes/navbar.php'; ?>
<main class="py-4">
    <div class="container-fluid">
        <div class="page-header">
            <h2 class="page-title">Council Awards</h2>
            <p class="page-subtitle">View awards submitted by your council</p>
            <div class="alert alert-info mt-3">
                <i class="bi bi-building me-2"></i>
                <strong>Council:</strong> <?php echo htmlspecialchars($council['council_name']); ?>
            </div>
        </div>
        <div class="card filter-section mb-4">
            <div class="card-header bg-white">
                <h3 class="card-title h5 mb-0">Filters</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="search_title">Search Title</label>
                        <div class="input-group">
                            <input type="text" id="search_title" class="form-control" name="search_title" value="<?php echo htmlspecialchars($searchTitle); ?>" placeholder="Search award title...">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="start_date">Start Date</label>
                        <div class="input-group">
                            <input type="date" id="start_date" class="form-control" name="start_date" value="<?php echo $startDate; ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="end_date">End Date</label>
                        <div class="input-group">
                            <input type="date" id="end_date" class="form-control" name="end_date" value="<?php echo $endDate; ?>">
                        </div>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="d-grid gap-2 w-100">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-2"></i>Apply Filters</button>
                            <a href="council_adviser_view_awards.php" class="btn btn-secondary"><i class="bi bi-x-circle me-2"></i>Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="row">
            <?php foreach ($awards as $award): ?>
            <div class="col-md-6 mb-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($award['title']); ?></h5>
                            <span class="badge bg-primary" style="cursor: pointer;" onclick="showAwardParticipants(<?php echo $award['id']; ?>, '<?php echo htmlspecialchars(addslashes($award['title'])); ?>')" title="Click to view recipients">
                                <?php echo $award['participant_count']; ?> RECIPIENTS
                            </span>
                        </div>
                        <div class="text-truncate-container">
                            <p class="card-text text-muted text-truncate-content"><?php echo htmlspecialchars($award['description']); ?></p>
                            <a href="#" class="text-truncate-toggle" style="display: none;">More</a>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-trophy text-warning me-2"></i>
                                <small class="text-muted" style="white-space: nowrap;">Awarded on <?php echo date('M d, Y', strtotime($award['award_date'])); ?></small>
                            </div>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-geo-alt text-warning me-2"></i>
                                <small class="text-muted"><?php echo htmlspecialchars($award['venue']); ?></small>
                            </div>
                        </div>

                        <?php
                        $imageStmt = $conn->prepare("SELECT image_path FROM award_images WHERE award_id = ?");
                        $imageStmt->bind_param("i", $award['id']);
                        $imageStmt->execute();
                        $images = $imageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $imageStmt->close();
                        ?>
                        <?php if (!empty($images)): ?>
                            <div class="mb-3">
                                <h6 class="mb-2">
                                    <i class="bi bi-images me-2"></i>Award Gallery
                                    <span class="badge bg-secondary ms-2"><?php echo count($images); ?></span>
                                </h6>
                                <div class="row g-2 img-gallery">
                                    <?php 
                                    $totalImages = count($images);
                                    $displayImages = array_slice($images, 0, 3);
                                    foreach ($displayImages as $index => $image):
                                        $isLastImage = ($index == 2 && $totalImages > 3);
                                    ?>
                                    <div class="col-4">
                                        <div class="gallery-item position-relative" data-award-id="<?php echo $award['id']; ?>" data-image-index="<?php echo $index; ?>" title="View full image">
                                            <img src="<?php echo htmlspecialchars($image['image_path']); ?>" class="img-fluid" alt="Award Image <?php echo $index + 1; ?>" style="height: 120px; object-fit: cover; width: 100%; border-radius: 8px;" loading="lazy">
                                            <?php if ($isLastImage): ?>
                                            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center gallery-item" style="background: rgba(0, 0, 0, 0.7); cursor: pointer; border-radius: 8px;" data-award-id="<?php echo $award['id']; ?>" data-image-index="<?php echo $index; ?>">
                                                <div class="text-center text-white">
                                                    <i class="bi bi-camera" style="font-size: 1.5rem;"></i>
                                                    <div style="font-size: 1rem; font-weight: 600; margin-top: 0.25rem;">+<?php echo $totalImages - 3; ?> more</div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="no-images">
                                <i class="bi bi-image"></i>
                                <p class="mb-0">No images available for this award</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<!-- Image Gallery Modal -->
<div class="image-gallery-modal" id="imageGalleryModal">
    <div class="gallery-modal-content">
        <div class="gallery-close" id="galleryClose">&times;</div>
        <div class="gallery-info">
            <div class="gallery-counter" id="galleryCounter">1 / 1</div>
            <div class="gallery-title" id="galleryTitle">Award Gallery</div>
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

<!-- Recipients Modal -->
<div class="modal fade" id="participantsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="participantsModalTitle">Award Recipients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="participantsModalBody">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">Loading recipients...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

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
            this.awardTitle = '';
            this.initEventListeners();
        }
        initEventListeners() {
            document.addEventListener('click', (e) => {
                const galleryItem = e.target.closest('.gallery-item');
                if (galleryItem) {
                    const awardId = galleryItem.dataset.awardId;
                    const imageIndex = parseInt(galleryItem.dataset.imageIndex);
                    this.openGallery(awardId, imageIndex);
                }
            });
            this.prevBtn.addEventListener('click', () => this.previousImage());
            this.nextBtn.addEventListener('click', () => this.nextImage());
            this.closeBtn.addEventListener('click', () => this.closeGallery());
            document.addEventListener('keydown', (e) => {
                if (this.modal.style.display === 'block') {
                    switch(e.key) {
                        case 'ArrowLeft': this.previousImage(); break;
                        case 'ArrowRight': this.nextImage(); break;
                        case 'Escape': this.closeGallery(); break;
                    }
                }
            });
            this.modal.addEventListener('click', (e) => {
                if (e.target === this.modal) { this.closeGallery(); }
            });
        }
        async openGallery(awardId, startIndex = 0) {
            this.showLoading();
            this.modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
            try {
                const response = await fetch(`get_award_images.php?award_id=${awardId}`);
                const data = await response.json();
                if (data.success) {
                    this.images = data.images;
                    this.awardTitle = data.award_title || 'Award Gallery';
                    this.currentIndex = Math.min(startIndex, this.images.length - 1);
                    this.updateDisplay();
                    this.createThumbnails();
                    this.hideLoading();
                } else { throw new Error(data.error || 'Failed to load images'); }
            } catch (error) {
                console.error('Error loading gallery:', error);
                this.hideLoading();
                this.closeGallery();
                alert('Error loading gallery images. Please try again.');
            }
        }
        updateDisplay() {
            if (this.images.length === 0) return;
            const currentImage = this.images[this.currentIndex];
            this.mainImage.src = currentImage.image_path;
            this.mainImage.alt = `${this.awardTitle} - Image ${this.currentIndex + 1}`;
            this.counter.textContent = `${this.currentIndex + 1} / ${this.images.length}`;
            this.title.textContent = this.awardTitle;
            this.prevBtn.style.display = this.images.length > 1 ? 'block' : 'none';
            this.nextBtn.style.display = this.images.length > 1 ? 'block' : 'none';
            this.updateThumbnails();
        }
        createThumbnails() {
            this.thumbnails.innerHTML = '';
            if (this.images.length <= 1) { this.thumbnails.style.display = 'none'; return; }
            this.thumbnails.style.display = 'flex';
            this.images.forEach((image, index) => {
                const thumb = document.createElement('img');
                thumb.src = image.image_path;
                thumb.className = 'gallery-thumbnail';
                thumb.alt = `Thumbnail ${index + 1}`;
                thumb.addEventListener('click', () => { this.currentIndex = index; this.updateDisplay(); });
                this.thumbnails.appendChild(thumb);
            });
        }
        updateThumbnails() {
            const thumbnails = this.thumbnails.querySelectorAll('.gallery-thumbnail');
            thumbnails.forEach((thumb, index) => { thumb.classList.toggle('active', index === this.currentIndex); });
            const activeThumbnail = thumbnails[this.currentIndex];
            if (activeThumbnail) { activeThumbnail.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' }); }
        }
        previousImage() { if (this.images.length > 1) { this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length; this.updateDisplay(); } }
        nextImage() { if (this.images.length > 1) { this.currentIndex = (this.currentIndex + 1) % this.images.length; this.updateDisplay(); } }
        closeGallery() { this.modal.style.display = 'none'; document.body.style.overflow = ''; this.images = []; this.currentIndex = 0; }
        showLoading() { this.loading.style.display = 'block'; this.mainImage.style.display = 'none'; }
        hideLoading() { this.loading.style.display = 'none'; this.mainImage.style.display = 'block'; }
    }
    document.addEventListener('DOMContentLoaded', () => { new ImageGallery(); });
    
    // Function to show award recipients
    function showAwardParticipants(awardId, awardTitle) {
        const modal = new bootstrap.Modal(document.getElementById('participantsModal'));
        const modalTitle = document.getElementById('participantsModalTitle');
        const modalBody = document.getElementById('participantsModalBody');
        
        modalTitle.textContent = `Recipients - ${awardTitle}`;
        modalBody.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div><p class="mt-2">Loading recipients...</p></div>';
        modal.show();
        
        fetch(`get_award_participants.php?award_id=${awardId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.participants && data.participants.length > 0) {
                        let html = `
                            <div class="mb-3">
                                <p class="text-muted mb-0"><strong>Total:</strong> ${data.participants.length} recipient(s)</p>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Name</th>
                                            <th>Course</th>
                                            <th>Year - Section</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        data.participants.forEach((participant, index) => {
                            const yearSection = participant.yearSection || (participant.year_level ? `${participant.year_level}${participant.section ? ' - ' + participant.section : ''}` : '');
                            html += `
                                <tr>
                                    <td>${index + 1}</td>
                                    <td><strong>${participant.name || 'N/A'}</strong></td>
                                    <td>${participant.course || 'N/A'}</td>
                                    <td>${yearSection || 'N/A'}</td>
                                </tr>
                            `;
                        });
                        
                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                        modalBody.innerHTML = html;
                    } else {
                        modalBody.innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No recipients recorded for this award.</div>';
                    }
                } else {
                    modalBody.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${data.error || 'Error loading recipients.'}</div>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                modalBody.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>An error occurred while loading recipients. Please try again.</div>';
            });
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
    
    // Initialize text truncation on page load
    document.addEventListener('DOMContentLoaded', initTextTruncation);
</script>
</body>
</html>



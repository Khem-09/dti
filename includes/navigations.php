<?php
// Determine the current page to highlight the sidebar dynamically
$currentPage = basename($_SERVER['PHP_SELF']);
function getNavStyle($page, $current) {
    if ($page === $current) {
        return 'active py-3 fw-bold" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;';
    }
    return 'py-3 text-white" style="';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTI Region IX - Price Monitoring</title>
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/provincial.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <style>
        /* Global Styles */
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .filter-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .btn-action { transition: all 0.2s ease-in-out; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important; }
        .btn-action i { font-size: 1.05rem; }
        .dropdown-toggle::after { vertical-align: middle; }
        #previewTable th { border: 1px solid #4a5056 !important; }
        .timer-badge { background: #0A0A3A; color: white; padding: 2px 8px; border-radius: 4px; font-family: monospace; font-size: 0.9rem; margin-left: 10px; }
        
        /* Magic SPA Loading Styles */
        #spa-main-content { transition: opacity 0.2s ease-in-out; }
        .spa-loading { opacity: 0.4; pointer-events: none; }
    </style>
</head>
<body style="background-color: #EAEAEA; overflow-x: hidden;">

    <nav class="navbar navbar-light bg-white shadow-sm px-3 px-md-4 d-flex justify-content-between w-100">
        <div class="d-flex align-items-center">
            <button class="btn btn-light d-md-none me-2 border-0 shadow-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
                <i class="bi bi-list fs-4"></i>
            </button>
            <a class="navbar-brand sidebar-brand text-decoration-none d-flex align-items-center" href="dashboard.php">
                <img src="../assets/images/DTI_PH-Logo.png" alt="DTI Logo" class="img-fluid" style="max-height: 40px;">
                <span class="ms-2 fw-bold d-none d-sm-inline" style="color: #0A0A3A; font-size: 1.1rem;">DTI Region IX</span>
            </a>
        </div>
        
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="dropdownUser" data-bs-toggle="dropdown" aria-expanded="false" style="color: inherit;">
                <div class="text-end me-3 d-none d-md-block">
                    <span class="d-block fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($admin_name ?? 'Admin') ?></span>
                    <span class="d-block text-secondary" style="font-size: 0.75rem;"><?= htmlspecialchars($admin_role ?? 'System Administrator') ?></span>
                </div>
                <i class="bi bi-person-circle fs-2 text-secondary"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-3" aria-labelledby="dropdownUser" style="min-width: 240px; border-radius: 8px;">
                <li><h6 class="dropdown-header text-secondary fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">ACCOUNT MANAGEMENT</h6></li>
                <li><a class="dropdown-item py-2 fw-bold text-secondary" href="#" data-bs-toggle="modal" data-bs-target="#adminProfileModal"><i class="bi bi-gear me-2 fs-6"></i> Account Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item py-2 text-danger fw-bold" href="#" onclick="confirmLogout(event)"><i class="bi bi-box-arrow-right me-2 fs-6"></i> Secure Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="offcanvas offcanvas-start d-md-none" tabindex="-1" id="mobileSidebar" style="background-color: #0A0A3A; width: 280px;">
        <div class="offcanvas-header border-bottom border-secondary">
            <h5 class="offcanvas-title text-white fw-bold">Admin Menu</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body px-2 py-4">
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link <?= getNavStyle('dashboard.php', $currentPage) ?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?= getNavStyle('provincial.php', $currentPage) ?>" href="provincial.php"><i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports</a></li>
                <li class="nav-item"><a class="nav-link <?= getNavStyle('regional.php', $currentPage) ?>" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                <li class="nav-item"><a class="nav-link <?= getNavStyle('generated_reports.php', $currentPage) ?>" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                <li class="nav-item"><a class="nav-link <?= getNavStyle('products.php', $currentPage) ?>" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                <li class="nav-item"><a class="nav-link <?= getNavStyle('trends.php', $currentPage) ?>" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
            </ul>
        </div>
    </div>

    <div class="container-fluid p-0">
        <div class="row g-0">
            
            <nav class="col-md-2 d-none d-md-block sidebar py-4" style="min-height: 100vh; background-color: #0A0A3A;">
                <div class="position-sticky">
                    <h5 class="text-white px-3 pb-2 border-bottom border-secondary">Admin Menu</h5>
                    <ul class="nav flex-column mt-3 px-2">
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('dashboard.php', $currentPage) ?>" href="dashboard.php"><i class="bi bi-grid-1x2-fill me-2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('provincial.php', $currentPage) ?>" href="provincial.php"><i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports</a></li>
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('regional.php', $currentPage) ?>" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('generated_reports.php', $currentPage) ?>" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('products.php', $currentPage) ?>" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                        <li class="nav-item"><a class="nav-link <?= getNavStyle('trends.php', $currentPage) ?>" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 content-wrapper p-3 p-md-4" id="spa-main-content">
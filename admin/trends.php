<?php
    session_start();
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: ../login.php");
        exit();
    }
    require_once '../classes/database.php';
    require_once '../classes/admin.php';

    $database = new Database();
    $db = $database->getConnection();
    $admin = new Admin($db);

    $stmtAdmin = $db->prepare("SELECT firstname, lastname, role FROM admin WHERE id = ?");
    $stmtAdmin->execute([$_SESSION['admin_id'] ?? 1]);
    $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
    
    $admin_name = $adminRow ? trim($adminRow['firstname'] . ' ' . $adminRow['lastname']) : 'Admin';
    $admin_role = $adminRow['role'] ?? 'System Administrator';
    $admin_first = $adminRow['firstname'] ?? '';
    $admin_last = $adminRow['lastname'] ?? '';

    $provinces = $admin->getProvinces();
    $availableYears = $admin->getAvailableYears();
    $latest_db_year = (count($availableYears) > 0) ? $availableYears[0]['year'] : date('Y');
    
    $filter_province = isset($_GET['province_id']) ? $_GET['province_id'] : 'All';
    $filter_type = isset($_GET['type']) ? $_GET['type'] : 'All';
    $filter_year = isset($_GET['year']) ? $_GET['year'] : $latest_db_year;
    $filter_month = isset($_GET['month']) ? $_GET['month'] : '';
    
    $availableMonths = $admin->getAvailableMonths($filter_year);
    $productsList = $admin->getAllProductVariants();

    $filter_variant_id = isset($_GET['variant_id']) ? $_GET['variant_id'] : ($productsList[0]['variant_id'] ?? null);

    $selectedProductName = "Select a Product";
    $selectedProductSpecs = "";
    if ($filter_variant_id) {
        foreach ($productsList as $pv) {
            if ($pv['variant_id'] == $filter_variant_id) {
                $selectedProductName = $pv['brand_name'] . " " . $pv['product_name'];
                $selectedProductSpecs = $pv['specifications'];
                break;
            }
        }
    }

    $selectedProvinceName = "Region IX (All Provinces)";
    if ($filter_province != 'All') {
        foreach ($provinces as $p) {
            if ($p['id'] == $filter_province) {
                $selectedProvinceName = $p['province_name'];
                break;
            }
        }
    }

    $trendData = [];
    $marketExtremes = ['lowest' => false, 'highest' => false];
    
    if ($filter_variant_id) {
        $trendData = $admin->getTrendData($filter_variant_id, $filter_year, $filter_month, $filter_province);
        $marketExtremes = $admin->getMarketExtremes($filter_variant_id, $filter_year, $filter_month, $filter_province);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Trends - DTI Region IX</title>
    
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/trends.css">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <style>
        .filter-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        
        /* Unified Outline Button Styling */
        .btn-action { transition: all 0.2s ease-in-out; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important; }
        .btn-action i { font-size: 1.05rem; }
        .dropdown-toggle::after { vertical-align: middle; }

        .info-badge { display: inline-flex; align-items: center; gap: 0.5rem; background-color: #fff; border: 1px solid #dee2e6; padding: 0.4rem 1rem; border-radius: 50px; font-size: 0.9rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .chart-card { background: #fff; border-radius: 12px; border: 1px solid #e5e5e5; box-shadow: 0 8px 16px rgba(0,0,0,0.04); overflow: hidden; }
        .chart-header { background: #fafafa; border-bottom: 1px solid #e5e5e5; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        
        /* Scrollbar styling for the extremes list */
        .store-list-scroll::-webkit-scrollbar { width: 6px; }
        .store-list-scroll::-webkit-scrollbar-track { background: transparent; }
        .store-list-scroll::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
        .store-list-scroll::-webkit-scrollbar-thumb:hover { background: #aaa; }
    </style>
</head>
<body style="background-color: #EAEAEA; overflow-x: hidden;">

   <nav class="navbar navbar-light bg-white shadow-sm px-3 px-md-4 d-flex justify-content-between w-100">
        <div class="d-flex align-items-center">
            <button class="btn btn-light d-md-none me-2 border-0 shadow-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
                <i class="bi bi-list fs-4"></i>
            </button>
            <a class="navbar-brand sidebar-brand text-decoration-none d-flex align-items-center" href="#">
                <img src="../assets/images/DTI_PH-Logo.png" alt="DTI Logo" class="img-fluid" style="max-height: 40px;">
                <span class="ms-2 fw-bold d-none d-sm-inline" style="color: #0A0A3A; font-size: 1.1rem;">DTI Region IX</span>
            </a>
        </div>
        
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-decoration-none dropdown-toggle" id="dropdownUser" data-bs-toggle="dropdown" aria-expanded="false" style="color: inherit;">
                <div class="text-end me-3 d-none d-md-block">
                    <span class="d-block fw-bold text-dark" style="font-size: 0.9rem;"><?= htmlspecialchars($admin_name) ?></span>
                    <span class="d-block text-secondary" style="font-size: 0.75rem;"><?= htmlspecialchars($admin_role) ?></span>
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
                <li class="nav-item"><a class="nav-link py-3 text-white" href="dashboard.php"><i class="bi bi-grid-1x2-fill me-2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="provincial.php"><i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports</a></li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                <li class="nav-item">
                    <a class="nav-link active py-3 fw-bold" href="trends.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                        <i class="bi bi-graph-up me-2"></i> Price Trends
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <div class="container-fluid p-0">
        <div class="row g-0">
            
             <nav class="col-md-2 d-none d-md-block sidebar py-4" style="min-height: 100vh; background-color: #0A0A3A;">
                <div class="position-sticky">
                    <h5 class="text-white px-3 pb-2 border-bottom border-secondary">Admin Menu</h5>
                    <ul class="nav flex-column mt-3 px-2">
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="dashboard.php"><i class="bi bi-grid-1x2-fill me-2"></i> Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="provincial.php"><i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                        <li class="nav-item">
                            <a class="nav-link active py-3 fw-bold" href="trends.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                                <i class="bi bi-graph-up me-2"></i> Price Trends
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 p-3 p-md-4" style="background-color: #EAEAEA;">
                <div class="shadow-sm bg-white p-3 p-md-5" style="min-height: 80vh; border-radius: 0;">
                    
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h2 class="fw-bold m-0" style="color: #0A0A3A; font-size: 26px;">Commodity Price Trends</h2>
                    </div>
                    <div style="height: 2px; background-color: #8B0000; width: 100%; margin-bottom: 30px;"></div>

                    <div class="filter-box d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3">
                        <div class="d-flex align-items-center gap-2 w-100" style="max-width: 350px;">
                            <div class="input-group input-group-sm shadow-sm w-100">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                                <input type="text" id="searchProduct" onkeyup="filterProductDropdown()" class="form-control border-start-0" placeholder="Search product name...">
                            </div>
                        </div>

                        <form method="GET" action="trends.php" class="d-flex flex-wrap gap-2 w-100 justify-content-xl-end m-0" id="filterForm">
                            <select name="type" id="typeFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="handleTypeChange()" style="min-width: 120px; max-width: 140px;">
                                <option value="All" <?= ($filter_type == 'All') ? 'selected' : '' ?>>All Types</option>
                                <option value="BN" <?= ($filter_type == 'BN') ? 'selected' : '' ?>>Basic Necessities</option>
                                <option value="PC" <?= ($filter_type == 'PC') ? 'selected' : '' ?>>Prime Commodities</option>
                            </select>

                            <select name="province_id" id="provinceFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="document.getElementById('filterForm').submit()" style="min-width: 140px; max-width: 160px;">
                                <option value="All" <?= ($filter_province == 'All') ? 'selected' : '' ?>>All Provinces</option>
                                <?php foreach($provinces as $p): ?>
                                    <option value="<?= $p['id'] ?>" <?= ($filter_province == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['province_name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="variant_id" id="productSelect" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="document.getElementById('filterForm').submit()" style="min-width: 200px; max-width: 280px;">
                                <option value="" disabled <?= empty($filter_variant_id) ? 'selected' : '' ?>>Select Product</option>
                                <?php foreach($productsList as $pv): ?>
                                    <option value="<?= $pv['variant_id'] ?>" data-type="<?= htmlspecialchars($pv['type_code']) ?>" <?= ($filter_variant_id == $pv['variant_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pv['brand_name'] . ' - ' . $pv['product_name'] . ' [' . $pv['specifications'] . ']') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="year" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="resetMonthAndSubmit()" style="min-width: 80px; max-width: 100px;">
                                <?php foreach($availableYears as $y): ?>
                                    <option value="<?= $y['year'] ?>" <?= ($filter_year == $y['year']) ? 'selected' : '' ?>><?= $y['year'] ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="month" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="document.getElementById('filterForm').submit()" style="min-width: 120px; max-width: 140px;">
                                <option value="">Yearly View</option>
                                <?php foreach($availableMonths as $m): ?>
                                    <option value="<?= $m['month'] ?>" <?= ($filter_month == $m['month']) ? 'selected' : '' ?>><?= $m['month'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end mb-4 px-2 gap-3">
                        <div>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <div class="info-badge">
                                    <i class="bi bi-geo-alt-fill text-danger"></i>
                                    <span class="text-secondary fw-bold">Location:</span> 
                                    <span class="fw-bold text-dark text-wrap" style="max-width: 180px;"><?= htmlspecialchars($selectedProvinceName) ?></span>
                                </div>
                                <div class="info-badge">
                                    <i class="bi bi-calendar-event text-primary"></i>
                                    <span class="text-secondary fw-bold">Period:</span> 
                                    <span class="fw-bold text-dark">
                                        <?= htmlspecialchars($filter_year) ?> 
                                        <?= !empty($filter_month) ? '- ' . htmlspecialchars($filter_month) : '(12-Month Summary)' ?>
                                    </span>
                                </div>
                            </div>
                            
                            <h3 class="fw-bold m-0" style="color: #0A0A3A; display: flex; align-items: flex-start; gap: 0.5rem; word-break: break-word;">
                                <i class="bi bi-box-seam text-success mt-1"></i> <?= htmlspecialchars($selectedProductName) ?> 
                            </h3>
                            <p class="text-secondary mt-1 mb-0" style="font-size: 1.05rem;">
                                <i class="bi bi-info-circle me-1"></i> Specs: <span class="fw-bold"><?= htmlspecialchars($selectedProductSpecs) ?></span>
                            </p>
                        </div>
                        
                        <button class="btn btn-sm btn-outline-primary btn-action shadow-sm px-3" onclick="exportTrendData()" style="height: fit-content; align-self: flex-start;">
                            <i class="bi bi-download"></i> Export Data
                        </button>
                    </div>

                    <?php if (!empty($marketExtremes['lowest']) && !empty($marketExtremes['highest'])): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="p-3 rounded border shadow-sm h-100" style="background-color: #f0fdf4; border-color: #d1e7dd !important;">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 45px; height: 45px;">
                                        <i class="bi bi-arrow-down-circle fs-5"></i>
                                    </div>
                                    <div class="w-100 overflow-hidden">
                                        <p class="text-success mb-0 fw-bold" style="font-size: 0.85rem;">LOWEST PRICE FOUND</p>
                                        <h4 class="fw-bold text-dark m-0">₱ <?= number_format($marketExtremes['lowest']['actual_price'], 2) ?></h4>
                                        <div class="mt-2 pe-1 store-list-scroll" style="max-height: 80px; overflow-y: auto;">
                                            <ul class="mb-0 ps-3 small text-secondary">
                                                <?php 
                                                    $lowStores = [];
                                                    if (isset($marketExtremes['lowest']['stores']) && is_array($marketExtremes['lowest']['stores'])) {
                                                        $lowStores = $marketExtremes['lowest']['stores'];
                                                    } elseif (isset($marketExtremes['lowest']['store_name'])) {
                                                        $lowStores = explode(', ', $marketExtremes['lowest']['store_name']);
                                                    }
                                                    foreach($lowStores as $store): 
                                                ?>
                                                    <li title="<?= htmlspecialchars(trim($store)) ?>"><?= htmlspecialchars(trim($store)) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 rounded border shadow-sm h-100" style="background-color: #fff3cd; border-color: #ffe69c !important;">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="text-white rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 45px; height: 45px; background-color: #fd7e14;">
                                        <i class="bi bi-arrow-up-circle fs-5"></i>
                                    </div>
                                    <div class="w-100 overflow-hidden">
                                        <p class="mb-0 fw-bold" style="color: #d35400; font-size: 0.85rem;">HIGHEST PRICE FOUND</p>
                                        <h4 class="fw-bold text-dark m-0">₱ <?= number_format($marketExtremes['highest']['actual_price'], 2) ?></h4>
                                        <div class="mt-2 pe-1 store-list-scroll" style="max-height: 80px; overflow-y: auto;">
                                            <ul class="mb-0 ps-3 small text-secondary">
                                                <?php 
                                                    $highStores = [];
                                                    if (isset($marketExtremes['highest']['stores']) && is_array($marketExtremes['highest']['stores'])) {
                                                        $highStores = $marketExtremes['highest']['stores'];
                                                    } elseif (isset($marketExtremes['highest']['store_name'])) {
                                                        $highStores = explode(', ', $marketExtremes['highest']['store_name']);
                                                    }
                                                    foreach($highStores as $store): 
                                                ?>
                                                    <li title="<?= htmlspecialchars(trim($store)) ?>"><?= htmlspecialchars(trim($store)) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="chart-card w-100">
                        <div class="chart-header flex-column flex-sm-row gap-2">
                            <h6 class="fw-bold m-0 text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i> Price Fluctuation History</h6>
                        </div>
                        <div class="p-2 p-md-4 w-100" style="height: 450px; position: relative; background-color: #fcfcfc;">
                            <?php if (empty($trendData)): ?>
                                <div class="d-flex flex-column justify-content-center align-items-center h-100 text-secondary">
                                    <i class="bi bi-bar-chart text-muted mb-2" style="font-size: 3rem; opacity: 0.5;"></i>
                                    <h5 class="fw-bold">No trend data found</h5>
                                    <p class="text-center small">Try selecting a different product, year, or province.</p>
                                </div>
                            <?php else: ?>
                                <canvas id="trendChart"></canvas>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="adminProfileModal" tabindex="-1" aria-hidden="true" style="z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; background-color: #f4f6f9;">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <div>
                        <h4 class="modal-title fw-bold" style="color: #0A0A3A;">Account Settings</h4>
                        <p class="text-secondary small mb-0">Manage your profile details and security credentials</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="bg-white p-4 rounded shadow-sm border h-100">
                                <h6 class="fw-bold mb-4 text-secondary"><i class="bi bi-person-lines-fill me-2"></i> Profile Information</h6>
                                <form id="profileForm" onsubmit="updateAdminProfile(event)">
                                    <div class="row mb-3 g-2">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">First Name</label>
                                            <input type="text" id="adminFirstName" class="form-control bg-light text-secondary" value="<?= htmlspecialchars($admin_first) ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Last Name</label>
                                            <input type="text" id="adminLastName" class="form-control bg-light text-secondary" value="<?= htmlspecialchars($admin_last) ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-at text-secondary"></i></span>
                                            <input type="text" id="adminUsername" class="form-control border-start-0 bg-light text-secondary" value="<?= htmlspecialchars($_SESSION['username'] ?? 'admin') ?>" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-dark fw-bold w-100 shadow-sm mb-5" style="border-radius: 6px;">Save Profile Changes</button>
                                </form>
                                <hr class="text-secondary mb-4">
                                <h6 class="fw-bold mb-3 text-secondary mt-4"><i class="bi bi-hdd-network me-2"></i> System Administration</h6>
                                <div class="p-3 bg-light rounded border">
                                    <p class="small text-secondary mb-3">Download a complete backup of the database system including all price records and product masterlists.</p>
                                    <div class="text-end">
                                        <button type="button" class="btn btn-success fw-bold px-3 shadow-sm w-100" style="border-radius: 6px;" onclick="openBackupModal()"><i class="bi bi-download me-1"></i> Download Backup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="bg-white p-4 rounded shadow-sm border h-100">
                                <h6 class="fw-bold mb-4 text-secondary"><i class="bi bi-shield-lock-fill me-2" style="color: #fd7e14;"></i> Security & Password</h6>
                                <form id="passwordForm" onsubmit="updateAdminPassword(event)">
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">Current Password</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-key text-secondary"></i></span>
                                            <input type="password" id="currentPassword" class="form-control border-start-0 bg-light" placeholder="Enter your current password to verify identity" required>
                                        </div>
                                    </div>
                                    <div class="row mb-4">
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">New Password</label>
                                            <input type="password" id="newPassword" class="form-control bg-light" placeholder="Type new password" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Confirm New Password</label>
                                            <input type="password" id="confirmPassword" class="form-control bg-light" placeholder="Type new password again" required>
                                        </div>
                                    </div>
                                    <div class="alert py-2 mt-2 d-flex align-items-center" style="background-color: #fff3cd; border: 1px solid #ffe69c; color: #856404;" role="alert">
                                        <i class="bi bi-info-circle-fill me-2 fs-5" style="color: #fd7e14;"></i>
                                        <div class="small">For your security, it is highly recommended to use a password containing at least one number and one special character.</div>
                                    </div>
                                    <div class="mt-4 pt-2">
                                        <button type="submit" class="btn btn-primary fw-bold px-4 shadow-sm w-100 py-2" style="background-color: #107ed9; border: none; border-radius: 6px;">Update Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="universalConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0A0A3A;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="confirmModalTitle">Confirm Action</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-3 text-secondary" id="confirmModalMessage">
                    Are you sure you want to proceed?
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-bold px-4 shadow-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary fw-bold px-4 shadow-sm" id="confirmModalBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="backupAuthModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #198754;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-success"><i class="bi bi-shield-lock me-2"></i>Authenticate Backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-3">
                    <p class="text-secondary mb-3">Please enter your current admin password to securely download the database backup.</p>
                    <input type="password" id="backupAuthPassword" class="form-control bg-light" placeholder="Enter Admin Password" required>
                    <div id="backupAuthError" class="text-danger small mt-2 d-none fw-bold"><i class="bi bi-exclamation-circle"></i> Incorrect password.</div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-bold px-4 shadow-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success fw-bold px-4 shadow-sm" id="confirmBackupBtn">Verify & Download</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        const selectEl = document.getElementById('productSelect');
        const allOptions = Array.from(selectEl.options);
        
        function handleTypeChange() {
            document.getElementById('productSelect').value = ""; 
            document.getElementById('filterForm').submit();     
        }
        
        function filterProductDropdown() {
            let term = document.getElementById('searchProduct').value.toLowerCase();
            let type = document.getElementById('typeFilter').value;
            
            let currentVal = selectEl.value;
            selectEl.innerHTML = '';
            
            allOptions.forEach(opt => {
                if (opt.value === "") {
                    selectEl.appendChild(opt); 
                    return;
                }
                
                let textMatch = opt.text.toLowerCase().includes(term);
                let typeMatch = (type === 'All' || opt.getAttribute('data-type') === type);
                
                if (textMatch && typeMatch) {
                    selectEl.appendChild(opt);
                }
            });
            
            if (Array.from(selectEl.options).some(opt => opt.value === currentVal)) {
                selectEl.value = currentVal;
            } else {
                selectEl.value = "";
            }
        }

        document.addEventListener("DOMContentLoaded", filterProductDropdown);

        function resetMonthAndSubmit() {
            let form = document.getElementById('filterForm');
            form.month.value = '';
            form.submit();
        }

        const trendDataRaw = <?= json_encode($trendData) ?>;
        const locName = "<?= addslashes($selectedProvinceName) ?>";
        
        if (trendDataRaw && trendDataRaw.length > 0) {
            const ctx = document.getElementById('trendChart').getContext('2d');
            
            let chartLabels = [];
            let maxPriceLine = [];
            let minPriceLine = [];

            trendDataRaw.forEach(row => {
                if (row.date_range_label) {
                    chartLabels.push([row.period_label, "[" + row.date_range_label + "]"]);
                } else {
                    chartLabels.push(row.period_label);
                }
                maxPriceLine.push(row.max_price);
                minPriceLine.push(row.min_price);
            });

            Chart.defaults.font.family = "'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: 'Highest Recorded Price',
                            data: maxPriceLine,
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#28a745',
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            tension: 0.4,
                            spanGaps: true // MAGIC FIX FOR CHART GAPS
                        },
                        {
                            label: 'Lowest Recorded Price',
                            data: minPriceLine,
                            borderColor: '#fd7e14',
                            backgroundColor: 'rgba(253, 126, 20, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            pointBackgroundColor: '#fff',
                            pointBorderColor: '#fd7e14',
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            tension: 0.4,
                            spanGaps: true // MAGIC FIX FOR CHART GAPS
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    scales: {
                        y: {
                            beginAtZero: false,
                            title: { display: true, text: 'Price in PHP (₱)', font: { weight: 'bold', size: 13 }, color: '#6c757d' },
                            grid: { color: 'rgba(0,0,0,0.05)', borderDash: [5, 5] }, 
                            ticks: { font: { weight: '600' } }
                        },
                        x: {
                            grid: { display: false }, 
                            ticks: { color: '#0A0A3A', font: { weight: 'bold', size: 12 }, maxRotation: 45, minRotation: 0 }
                        }
                    },
                    plugins: {
                        legend: { 
                            position: 'top', 
                            labels: { font: { weight: 'bold', size: 13 }, padding: 15, usePointStyle: true } 
                        },
                        tooltip: {
                            backgroundColor: 'rgba(10, 10, 58, 0.9)',
                            titleFont: { size: 14 },
                            bodyFont: { size: 13, weight: 'bold' },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.dataset.label + ': ₱ ' + Number(context.raw).toFixed(2);
                                }
                            }
                        }
                    }
                }          
            });
        }

        // Global Modals Logic
        function showConfirmModal(title, message, colorClass, btnText, callback) {
            document.getElementById('confirmModalTitle').innerText = title;
            document.getElementById('confirmModalTitle').className = 'modal-title fw-bold text-' + colorClass;
            document.querySelector('#universalConfirmModal .modal-content').style.borderTop = '5px solid var(--bs-' + colorClass + ')';
            document.getElementById('confirmModalMessage').innerHTML = message;
            
            let btn = document.getElementById('confirmModalBtn');
            btn.className = 'btn btn-' + colorClass + ' fw-bold px-4 shadow-sm';
            btn.innerHTML = btnText;
            
            let newBtn = btn.cloneNode(true);
            btn.parentNode.replaceChild(newBtn, btn);
            
            let modal = new bootstrap.Modal(document.getElementById('universalConfirmModal'));
            
            newBtn.addEventListener('click', function() {
                modal.hide();
                callback();
            });
            modal.show();
        }

        function confirmLinkAction(e, url, title, message, colorClass, btnText) {
            e.preventDefault();
            showConfirmModal(title, message, colorClass, btnText, function() {
                window.location.href = url;
            });
        }

        function confirmExportLink(e, url) {
            e.preventDefault();
            showConfirmModal('Export Report', 'Are you sure you want to download this generated report?', 'primary', '<i class="bi bi-download"></i> Download', function() {
                let a = document.createElement('a');
                a.href = url;
                a.download = '';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            });
        }

        function confirmLogout(e) {
            e.preventDefault();
            showConfirmModal('Secure Logout', 'Are you sure you want to log out of the system?', 'danger', '<i class="bi bi-box-arrow-right"></i> Logout', function() {
                window.location.href = '../admin/logout.php';
            });
        }

        function openBackupModal() {
            document.getElementById('backupAuthPassword').value = '';
            document.getElementById('backupAuthError').classList.add('d-none');
            new bootstrap.Modal(document.getElementById('backupAuthModal')).show();
        }

        document.getElementById('confirmBackupBtn')?.addEventListener('click', async function() {
            let pass = document.getElementById('backupAuthPassword').value;
            if(!pass) return;
            
            let btn = this;
            let origText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Verifying...';
            btn.disabled = true;

            let fd = new FormData();
            fd.append('action', 'verify_password_only');
            fd.append('password', pass);

            try {
                let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                let data = await res.json();
                if(data.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('backupAuthModal')).hide();
                    showConfirmModal('Download Backup', 'Authentication successful. Are you sure you want to generate and download the database backup now?', 'success', '<i class="bi bi-download"></i> Download', function() {
                        let a = document.createElement('a');
                        a.href = 'ajax_handler.php?action=download_backup';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    });
                } else {
                    document.getElementById('backupAuthError').classList.remove('d-none');
                }
            } catch(err) {
                alert("Connection error.");
            }
            btn.innerHTML = origText;
            btn.disabled = false;
        });

        function updateAdminProfile(e) {
            e.preventDefault();
            showConfirmModal('Update Profile', 'Are you sure you want to save these profile changes?', 'dark', '<i class="bi bi-check-circle"></i> Save Changes', async function() {
                const btn = document.querySelector('#profileForm button[type="submit"]');
                const origText = btn.innerText;
                btn.innerText = "Saving..."; btn.disabled = true;

                let fd = new FormData();
                fd.append('action', 'update_admin_profile');
                fd.append('firstname', document.getElementById('adminFirstName').value);
                fd.append('lastname', document.getElementById('adminLastName').value);
                fd.append('username', document.getElementById('adminUsername').value);

                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') {
                        alert("Profile updated successfully!");
                        location.reload();
                    } else {
                        alert("Error: " + data.message);
                    }
                } catch(err) { alert("Connection error."); }
                btn.innerText = origText; btn.disabled = false;
            });
        }

        function updateAdminPassword(e) {
            e.preventDefault();
            let newPass = document.getElementById('newPassword').value;
            let confPass = document.getElementById('confirmPassword').value;
            
            if (newPass !== confPass) {
                alert("New passwords do not match!");
                return;
            }

            showConfirmModal('Update Password', 'Are you sure you want to change your password? You will be securely logged out after.', 'primary', '<i class="bi bi-shield-lock"></i> Update Password', async function() {
                const btn = document.querySelector('#passwordForm button[type="submit"]');
                const origText = btn.innerText;
                btn.innerText = "Updating..."; btn.disabled = true;

                let fd = new FormData();
                fd.append('action', 'update_admin_password');
                fd.append('current_password', document.getElementById('currentPassword').value);
                fd.append('new_password', newPass);

                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') {
                        alert("Password updated successfully! Please log in again with your new credentials.");
                        window.location.href = '../admin/logout.php';
                    } else {
                        alert("Error: " + data.message);
                    }
                } catch(err) { alert("Connection error."); }
                btn.innerText = origText; btn.disabled = false;
            });
        }

        function exportTrendData() {
            if(!trendDataRaw || trendDataRaw.length === 0) {
                alert("No trend data available to export.");
                return;
            }
            
            showConfirmModal('Export Trend Data', 'Are you sure you want to generate and download this trend graph data?', 'primary', '<i class="bi bi-file-earmark-excel"></i> Export', function() {
                let wb = XLSX.utils.book_new();
                let wsData = [["Period", "Date Range", "Lowest Price (₱)", "Highest Price (₱)"]];
                
                trendDataRaw.forEach(row => {
                    wsData.push([
                        row.period_label,
                        row.date_range_label || "Full Month",
                        row.min_price,
                        row.max_price
                    ]);
                });
                
                let ws = XLSX.utils.aoa_to_sheet(wsData);
                XLSX.utils.book_append_sheet(wb, ws, "Price Trend");
                
                let cleanName = "<?= addslashes(preg_replace('/[^a-zA-Z0-9]/', '_', $selectedProductName)) ?>";
                let cleanLoc = "<?= addslashes(preg_replace('/[^a-zA-Z0-9]/', '_', $selectedProvinceName)) ?>";
                XLSX.writeFile(wb, `Trend_${cleanLoc}_${cleanName}_<?= $filter_year ?>.xlsx`);
            });
        }
    </script>
</body>
</html>
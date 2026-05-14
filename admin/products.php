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

    $products = $admin->getAllProducts();

    $categories = [];
    foreach ($products as $p) {
        if (!in_array($p['category_name'], $categories)) {
            $categories[] = $p['category_name'];
        }
    }
    sort($categories);

    // Fetch the most recent extracted SRP date to use as the default Masterlist "As Of" date
    $defaultExportDate = date('Y-m-d');
    try {
        $stmtLatestDate = $db->query("SELECT srp_date_label FROM uploaded_files WHERE srp_date_label IS NOT NULL ORDER BY id DESC LIMIT 1");
        if ($stmtLatestDate) {
            $latestDate = $stmtLatestDate->fetchColumn();
            if ($latestDate) {
                $defaultExportDate = $latestDate;
            } else {
                $stmtFallback = $db->query("SELECT DATE(uploaded_at) FROM uploaded_files ORDER BY uploaded_at DESC LIMIT 1");
                $fallbackDate = $stmtFallback ? $stmtFallback->fetchColumn() : false;
                if ($fallbackDate) $defaultExportDate = $fallbackDate;
            }
        }
    } catch (Exception $e) {
        // Safe fallback for older database versions without the new column
        $stmtFallback = $db->query("SELECT DATE(uploaded_at) FROM uploaded_files ORDER BY uploaded_at DESC LIMIT 1");
        $fallbackDate = $stmtFallback ? $stmtFallback->fetchColumn() : false;
        if ($fallbackDate) $defaultExportDate = $fallbackDate;
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product & SRP Management - DTI Region IX</title>
    
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
    
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <style>
        .filter-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .btn-action { transition: all 0.2s ease-in-out; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important; }
        .btn-action i { font-size: 1.05rem; }
        .dropdown-toggle::after { vertical-align: middle; }
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
                <li class="nav-item">
                    <a class="nav-link active py-3 fw-bold" href="products.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                        <i class="bi bi-tags me-2"></i> Product & SRP
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
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
                        <li class="nav-item">
                            <a class="nav-link active py-3 fw-bold" href="products.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                                <i class="bi bi-tags me-2"></i> Product & SRP
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 p-3 p-md-5 bg-light" style="min-height: 100vh;">
                <div class="inner-card shadow-sm bg-white p-3 p-md-4 rounded border">
                    
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
                        <h2 class="fw-bold m-0" style="color: #0A0A3A; font-size: 24px;">Product & SRP Masterlist</h2>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="movement_log.php" class="btn btn-sm btn-secondary shadow-sm px-3">
                                <i class="bi bi-clock-history"></i> Movement Log
                            </a>
                            <button class="btn btn-sm btn-primary shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#exportModal">
                                <i class="bi bi-download"></i> Export
                            </button>
                            <button class="btn btn-sm btn-success shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                <i class="bi bi-plus-circle"></i> Add Product
                            </button>
                        </div>
                    </div>
                    <div style="height: 2px; background-color: #8B0000; width: 100%; margin-bottom: 25px;"></div>

                    <div class="filter-box d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3">
                        <div class="input-group input-group-sm shadow-sm w-100" style="max-width: 350px;">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                            <input type="text" id="searchFilter" onkeyup="filterProducts()" class="form-control border-start-0" placeholder="Search product or brand...">
                        </div>
                        
                        <div class="d-flex flex-wrap gap-2 w-100 justify-content-xl-end m-0">
                             <select id="rowsPerPage" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="changeRowsPerPage()" style="min-width: 90px; max-width: 120px;">
                                <option value="25">25 rows</option>
                                <option value="50" selected>50 rows</option>
                                <option value="100">100 rows</option>
                                <option value="250">250 rows</option>
                                <option value="500">500 rows</option>
                            </select>
                            
                            <select id="typeFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 120px; max-width: 160px;" onchange="filterProducts()">
                                <option value="All">All Types</option>
                                <option value="BN">Basic Necessities</option>
                                <option value="PC">Prime Commodities</option>
                            </select>

                            <select id="catFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 160px; max-width: 220px;" onchange="filterProducts()">
                                <option value="All">All Categories</option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive rounded border shadow-sm" style="background-color: #f8f9fa; max-height: 600px;">
                        <table class="table table-hover align-middle mb-0 text-nowrap" id="productTable">
                            <thead style="border-bottom: 1px solid #aaa;" class="sticky-top bg-light">
                                <tr>
                                    <th class="fw-bold text-dark pb-2 text-center" style="width: 50px;">#</th>
                                    <th class="fw-bold text-dark pb-2">Type</th>
                                    <th class="fw-bold text-dark pb-2">Category</th>
                                    <th class="fw-bold text-dark pb-2">Brand</th>
                                    <th class="fw-bold text-dark pb-2">Product Name</th>
                                    <th class="fw-bold text-dark pb-2">Specifications</th>
                                    <th class="fw-bold text-dark pb-2">Current SRP</th>
                                    <th class="fw-bold text-dark pb-2 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="productTableBody">
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mt-3 px-2 gap-2">
                        <span class="text-secondary fw-bold" id="pageInfo">Loading data...</span>
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-outline-secondary fw-bold" onclick="prevPage()" id="prevBtn" disabled>Previous</button>
                            <button class="btn btn-outline-secondary fw-bold" onclick="nextPage()" id="nextBtn" disabled>Next</button>
                        </div>
                    </div>

                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 0; border-top: 5px solid #107ed9;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="color: #0A0A3A;"><i class="bi bi-download me-2"></i> Export Masterlist</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="exportForm" onsubmit="exportMasterlist(event)">
                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary">Masterlist As Of Date</label>
                            <input type="date" id="exportSrpDate" class="form-control bg-light border-0 shadow-sm" value="<?= htmlspecialchars($defaultExportDate) ?>" required>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary shadow-sm px-4" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                            <button type="submit" class="btn btn-primary shadow-sm px-4"><i class="bi bi-download"></i> Export</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius: 0; border-top: 5px solid #0A0A3A;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="color: #0A0A3A;">Update Product Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3 p-3 bg-light rounded shadow-sm border-start border-4 border-primary">
                        <small class="text-secondary d-block mb-1">Editing Product:</small>
                        <h6 class="fw-bold m-0" style="color: #0A0A3A;" id="editBrandDisplay">Brand Name</h6>
                    </div>
                    <form id="editForm" onsubmit="saveProductEdit(event)">
                        <input type="hidden" name="variant_id" id="editVariantId">
                        <input type="hidden" name="product_id" id="editProductId">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary">Product Name</label>
                            <input type="text" name="product_name" id="editProductName" class="form-control bg-light border-0 shadow-sm" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-secondary">Specifications</label>
                            <input type="text" name="specifications" id="editSpecs" class="form-control bg-light border-0 shadow-sm" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-secondary">SRP (Leave blank if none)</label>
                            <div class="input-group shadow-sm">
                                <span class="input-group-text bg-light border-0 fw-bold text-success">₱</span>
                                <input type="number" step="0.01" name="srp" id="editSrp" class="form-control bg-light border-0 fw-bold text-success">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary shadow-sm px-4" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                            <button type="submit" id="saveEditBtn" class="btn btn-primary shadow-sm px-4"><i class="bi bi-arrow-repeat"></i> Update & Log History</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius: 0; border-top: 5px solid #1a7a2e;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" style="color: #0A0A3A;">Add New Commodity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="addForm" onsubmit="saveNewProduct(event)">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Commodity Type</label>
                                <select name="type_code" class="form-select bg-light border-0 shadow-sm" required>
                                    <option value="" disabled selected>Select Type</option>
                                    <option value="BN">Basic Necessities (BN)</option>
                                    <option value="PC">Prime Commodities (PC)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Category</label>
                                <input type="text" name="category_name" list="catList" class="form-control bg-light border-0 shadow-sm" required>
                                <datalist id="catList">
                                    <?php foreach($categories as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"></option><?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Brand Name</label>
                                <input type="text" name="brand_name" class="form-control bg-light border-0 shadow-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Product Name</label>
                                <input type="text" name="product_name" class="form-control bg-light border-0 shadow-sm" required>
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Specifications</label>
                                <input type="text" name="specifications" class="form-control bg-light border-0 shadow-sm" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold text-secondary">Suggested Retail Price</label>
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text bg-light border-0 fw-bold text-success">₱</span>
                                    <input type="number" step="0.01" name="srp" class="form-control bg-light border-0 fw-bold text-success">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="button" class="btn btn-outline-secondary shadow-sm px-4" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancel</button>
                            <button type="submit" id="saveNewBtn" class="btn btn-success shadow-sm px-4"><i class="bi bi-check-circle"></i> Save Product</button>
                        </div>
                    </form>
                </div>
            </div>
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
        const allProducts = <?php echo json_encode($products); ?>;
        let filteredProducts = [...allProducts];
        
        let currentPage = 1;
        let rowsPerPage = 50;

        function changeRowsPerPage() {
            rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
            currentPage = 1;
            renderTable();
        }

        function filterProducts() {
            let type = document.getElementById('typeFilter').value || 'All';
            let cat = document.getElementById('catFilter').value || 'All';
            let search = (document.getElementById('searchFilter').value || '').toLowerCase();
            
            filteredProducts = allProducts.filter(p => {
                let pType = (p.type_code || '').trim().toUpperCase();
                let fType = type.trim().toUpperCase();
                let matchType = (fType === 'ALL' || pType === fType);
                
                let pCat = (p.category_name || '').trim();
                let fCat = cat.trim();
                let matchCat = (fCat === 'All' || pCat === fCat);
                
                let bName = (p.brand_name || '').toLowerCase();
                let pName = (p.product_name || '').toLowerCase();
                let sName = (p.specifications || '').toLowerCase();
                let textToSearch = bName + " " + pName + " " + sName;
                let matchSearch = textToSearch.includes(search);
                
                return matchType && matchCat && matchSearch;
            });
            
            currentPage = 1;
            renderTable();
        }

        function renderTable() {
            let start = (currentPage - 1) * rowsPerPage;
            let end = start + rowsPerPage;
            let paginatedItems = filteredProducts.slice(start, end);
            
            let html = '';
            let count = start + 1;
            
            if(paginatedItems.length === 0) {
                html = '<tr><td colspan="8" class="text-center py-5 text-secondary fw-bold">No products found.</td></tr>';
            } else {
                paginatedItems.forEach(p => {
                    let badgeClass = p.type_code === 'PC' ? 'bg-secondary' : 'bg-primary';
                    let srpDisplay = p.srp !== null ? "₱ " + parseFloat(p.srp).toFixed(2) : "<span class='text-secondary fw-normal'>None</span>";
                    
                    let safeBrand = p.brand_name ? p.brand_name.replace(/'/g, "\\'").replace(/"/g, "&quot;") : '';
                    let safeName = p.product_name ? p.product_name.replace(/'/g, "\\'").replace(/"/g, "&quot;") : '';
                    let safeSpecs = p.specifications ? p.specifications.replace(/'/g, "\\'").replace(/"/g, "&quot;") : '';
                    let safeSrp = p.srp !== null ? p.srp : '';

                    html += `
                        <tr class="product-row">
                            <td class="text-center fw-bold text-secondary bg-light">${count++}</td>
                            <td><span class="badge ${badgeClass}">${p.type_code}</span></td>
                            <td class="text-secondary text-wrap" style="max-width: 150px;">${p.category_name}</td>
                            <td class="fw-bold text-wrap" style="color: #0A0A3A; max-width: 180px;">${p.brand_name}</td>
                            <td class="text-dark text-wrap" style="max-width: 200px;">${p.product_name}</td>
                            <td class="text-secondary text-wrap" style="max-width: 200px;">${p.specifications}</td>
                            <td class="fw-bold text-success">${srpDisplay}</td>
                            <td class="text-center">
                               <div class="d-flex flex-wrap gap-2 justify-content-center">
                                   <button class="btn btn-sm btn-outline-primary shadow-sm px-2 px-md-3" 
                                       onclick="openEditModal(${p.variant_id}, ${p.product_id}, '${safeBrand}', '${safeName}', '${safeSpecs}', '${safeSrp}')">
                                       <i class="bi bi-pencil-square"></i> Edit
                                   </button>
                                   <button class="btn btn-sm btn-outline-danger shadow-sm px-2 px-md-3" onclick="deleteProduct(${p.variant_id})">
                                       <i class="bi bi-trash"></i> Delete
                                   </button>
                               </div>
                            </td>
                        </tr>
                    `;
                });
            }
            
            document.getElementById('productTableBody').innerHTML = html;
            updatePaginationInfo();
        }

        function updatePaginationInfo() {
            let total = filteredProducts.length;
            let start = total === 0 ? 0 : ((currentPage - 1) * rowsPerPage) + 1;
            let end = Math.min(currentPage * rowsPerPage, total);
            
            document.getElementById('pageInfo').innerText = `Showing ${start} to ${end} of ${total} entries`;
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = end >= total;
        }

        function prevPage() { if (currentPage > 1) { currentPage--; renderTable(); } }
        function nextPage() { if (currentPage * rowsPerPage < filteredProducts.length) { currentPage++; renderTable(); } }

        document.addEventListener("DOMContentLoaded", filterProducts);

        function openEditModal(vId, pId, brand, name, specs, srp) {
            document.getElementById('editVariantId').value = vId;
            document.getElementById('editProductId').value = pId;
            document.getElementById('editBrandDisplay').innerText = brand;
            document.getElementById('editProductName').value = name;
            document.getElementById('editSpecs').value = specs;
            document.getElementById('editSrp').value = srp;
            new bootstrap.Modal(document.getElementById('editProductModal')).show();
        }

        function exportMasterlist(e) {
            e.preventDefault();
            let srpDate = document.getElementById('exportSrpDate').value;
            if(!allProducts || allProducts.length === 0) return alert("No data to export!");
            
            showConfirmModal('Export Masterlist', 'Are you sure you want to download the product masterlist?', 'primary', '<i class="bi bi-download"></i> Export', function() {
                let wb = XLSX.utils.book_new();
                let srpHeader = "Current SRP - " + srpDate;
                let bnRows = [["Type", "Category", "Brand", "Product Name", "Specifications", srpHeader]];
                let pcRows = [["Type", "Category", "Brand", "Product Name", "Specifications", srpHeader]];
                
                allProducts.forEach(p => {
                    let row = [ p.type_code, p.category_name, p.brand_name, p.product_name, p.specifications, p.srp !== null ? p.srp : "N/A" ];
                    if (p.type_code === 'BN') bnRows.push(row); else pcRows.push(row);
                });
                
                if (bnRows.length > 1) XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(bnRows), "Basic Necessities");
                if (pcRows.length > 1) XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(pcRows), "Prime Commodities");
                
                if (bnRows.length === 1 && pcRows.length === 1) return alert("No valid data to export!");
                XLSX.writeFile(wb, "DTI_Product_Masterlist_" + new Date().getFullYear() + ".xlsx");
                bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide();
            });
        }
        
        async function saveProductEdit(e) {
            e.preventDefault();
            showConfirmModal('Update Product', 'Are you sure you want to update this product and log the history?', 'primary', '<i class="bi bi-arrow-repeat"></i> Update', async function() {
                const btn = document.getElementById('saveEditBtn');
                const origHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Updating...';
                btn.disabled = true;
                
                let fd = new FormData(document.getElementById('editForm'));
                fd.append('action', 'update_product');
                
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') location.reload(); 
                    else { alert("Error: " + data.message); btn.innerHTML = origHTML; btn.disabled = false; }
                } catch(err) { alert("Connection failed."); btn.innerHTML = origHTML; btn.disabled = false; }
            });
        }

        async function saveNewProduct(e) {
            e.preventDefault();
            showConfirmModal('Save Product', 'Are you sure you want to add this new product to the masterlist?', 'success', '<i class="bi bi-check-circle"></i> Save Product', async function() {
                const btn = document.getElementById('saveNewBtn');
                const origHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Saving...';
                btn.disabled = true;
                
                let fd = new FormData(document.getElementById('addForm'));
                fd.append('action', 'add_product');
                
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') location.reload(); 
                    else { alert("Error: " + data.message); btn.innerHTML = origHTML; btn.disabled = false; }
                } catch(err) { alert("Connection failed."); btn.innerHTML = origHTML; btn.disabled = false; }
            });
        }

        function deleteProduct(variant_id) {
            showConfirmModal('Delete Product', 'Are you sure you want to delete this product? All its price history will be deleted as well!', 'danger', '<i class="bi bi-trash"></i> Delete', async function() {
                let fd = new FormData();
                fd.append('action', 'delete_product');
                fd.append('variant_id', variant_id);
                
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') location.reload(); 
                    else alert("Error: " + data.message); 
                } catch(err) { alert("Connection failed."); }
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
    </script>
</body>
</html>
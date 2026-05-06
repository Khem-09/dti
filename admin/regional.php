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

    $availableYears = $admin->getAvailableYears();
    $latest_db_year = (count($availableYears) > 0) ? $availableYears[0]['year'] : date('Y');
    
    $filter_year = isset($_GET['year']) ? $_GET['year'] : $latest_db_year;
    $filter_month = isset($_GET['month']) ? $_GET['month'] : '';
    $filter_week = isset($_GET['week']) ? $_GET['week'] : '';
    $filter_type = isset($_GET['type']) ? $_GET['type'] : 'BN'; 

    $availableMonths = $admin->getAvailableMonths($filter_year);
    $availableWeeks = (!empty($filter_month)) ? $admin->getAvailableWeeks($filter_year, $filter_month) : [];
    
    $selected_week_label = '';
    $selected_week_num = '';
    if (!empty($filter_week)) {
        $stmt = $db->prepare("SELECT week_number, date_range_label FROM monitoring_periods WHERE id = ?");
        $stmt->execute([$filter_week]);
        if ($wRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $selected_week_num = "Week " . $wRow['week_number'];
            $selected_week_label = $wRow['date_range_label'];
        }
    }

    $reportData = $admin->getRegionalReport($filter_year, $filter_month, $filter_week, $filter_type);
    $exportData = $admin->getRegionalReport($filter_year, $filter_month, $filter_week, 'All');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Regional Summary - DTI Region IX</title>
    
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/regional.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <style>
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
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
                <li class="nav-item">
                    <a class="nav-link active py-3 fw-bold" href="regional.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                        <i class="bi bi-folder-fill me-2"></i> Regional Summary
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
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
                        <li class="nav-item">
                            <a class="nav-link active py-3 fw-bold" href="regional.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                                <i class="bi bi-folder-fill me-2"></i> Regional Summary
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 p-3 p-md-4" style="background-color: #EAEAEA;">
                <div class="inner-card shadow-sm bg-white p-3 p-md-4 rounded border">
                    
                    <h2 class="fw-bold mb-2 text-center" style="color: #0A0A3A; font-size: 26px;">Region IX</h2>
                    <div style="height: 2px; background-color: #8B0000; width: 100%; margin-bottom: 30px;"></div>

                    <div class="filter-box d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3">
                        <div class="d-flex align-items-center gap-3 w-100" style="max-width: 300px;">
                            <span class="fw-bold text-dark">Category:</span>
                            <div class="btn-group shadow-sm flex-grow-1" role="group">
                                <a href="regional.php?year=<?= $filter_year ?>&month=<?= $filter_month ?>&week=<?= $filter_week ?>&type=BN" 
                                   class="btn btn-sm <?= ($filter_type == 'BN') ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>">BN</a>
                                <a href="regional.php?year=<?= $filter_year ?>&month=<?= $filter_month ?>&week=<?= $filter_week ?>&type=PC" 
                                   class="btn btn-sm <?= ($filter_type == 'PC') ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>">PC</a>
                            </div>
                        </div>

                        <form method="GET" action="regional.php" class="d-flex flex-wrap gap-2 align-items-center w-100 justify-content-xl-end m-0">
                             <select id="rowsPerPage" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="changeRowsPerPage()" style="min-width: 100px; max-width: 120px; height: 31px;">
                                <option value="25">25 rows</option>
                                <option value="50" selected>50 rows</option>
                                <option value="100">100 rows</option>
                                <option value="250">250 rows</option>
                                <option value="500">500 rows</option>
                            </select>
                        
                            <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
                            
                            <select name="year" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="updateFilter(this)" style="min-width: 90px; max-width: 110px;">
                                <?php if(empty($availableYears)): ?>
                                    <option value="">No Data</option>
                                <?php else: ?>
                                    <?php foreach($availableYears as $y): ?>
                                        <option value="<?= $y['year'] ?>" <?= ($filter_year == $y['year']) ? 'selected' : '' ?>><?= $y['year'] ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>

                            <select name="month" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="updateFilter(this)" style="min-width: 120px; max-width: 150px;">
                                <option value="">Yearly Summary</option>
                                <?php foreach($availableMonths as $m): ?>
                                    <option value="<?= $m['month'] ?>" <?= ($filter_month == $m['month']) ? 'selected' : '' ?>><?= $m['month'] ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select name="week" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="updateFilter(this)" <?= empty($filter_month) ? 'disabled' : '' ?> style="min-width: 150px; max-width: 200px;">
                                <option value="">Monthly Summary</option>
                                <?php foreach($availableWeeks as $w): ?>
                                    <option value="<?= $w['id'] ?>" <?= ($filter_week == $w['id']) ? 'selected' : '' ?>><?= htmlspecialchars($w['date_range_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>

                    <div class="mb-3 px-2 d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3" style="font-size: 1rem;">
                        <div class="text-danger fw-bold" style="line-height: 1.6;">
                            <?php if (empty($availableYears)): ?>
                                [ No Data Available ]
                            <?php elseif (empty($filter_month)): ?>
                                Year: <?= htmlspecialchars($filter_year) ?> 
                            <?php elseif (empty($filter_week)): ?>
                                Year: <?= htmlspecialchars($filter_year) ?> | Month: <?= htmlspecialchars($filter_month) ?>
                            <?php else: ?>
                                Year: <?= htmlspecialchars($filter_year) ?> | Month: <?= htmlspecialchars($filter_month) ?><br>
                                Date Range: <?= htmlspecialchars($selected_week_label) ?><br>
                                <?= htmlspecialchars($selected_week_num) ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <button id="exportReportBtn" class="btn btn-outline-secondary btn-sm fw-bold shadow-sm px-3" onclick="exportRegionalReportToExcel()" style="height: 31px;">
                                <i class="bi bi-download me-1"></i> Export Local
                            </button>
                            <button id="saveDbBtn" class="btn btn-success btn-sm fw-bold shadow-sm px-3" onclick="saveRegionalReportToDB()" style="height: 31px;">
                                <i class="bi bi-journal-check me-1"></i> Generate & Save to DB
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive bg-white shadow-sm rounded border p-1">
                        <table class="table table-hover align-middle mb-0 text-nowrap" id="reportTable" style="font-size: 0.85rem;">
                            <thead class="table-light">
                                <tr style="border-bottom: 2px solid #8B0000;">
                                    <th class="fw-bold text-secondary text-center" style="width: 40px;">#</th>
                                    <th class="fw-bold text-secondary">Type</th>
                                    <th class="fw-bold text-secondary">Category</th>
                                    <th class="fw-bold text-dark">Brand</th>
                                    <th class="fw-bold text-dark">Product Name</th>
                                    <th class="fw-bold text-secondary">Specs</th>
                                    <th class="fw-bold text-center">Zamboanga City</th>
                                    <th class="fw-bold text-center">Zamboanga Sibugay</th>
                                    <th class="fw-bold text-center">Isabela City</th>
                                    <th class="fw-bold text-center">Zamboanga Del Sur</th>
                                    <th class="fw-bold text-center">Zamboanga Del Norte</th>
                                </tr>
                            </thead>
                            <tbody id="reportTableBody">
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mt-3 px-2 gap-2">
                        <span class="text-secondary fw-bold" id="pageInfo">Loading data...</span>
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-outline-secondary btn-action fw-bold" onclick="prevPage()" id="prevBtn" disabled>Previous</button>
                            <button class="btn btn-outline-secondary btn-action fw-bold" onclick="nextPage()" id="nextBtn" disabled>Next</button>
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
        const fullExportData = <?php echo json_encode($exportData); ?>;
        
        const regionalData = <?php echo json_encode($reportData); ?>;
        let currentPage = 1;
        let rowsPerPage = 50;

        function changeRowsPerPage() {
            rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
            currentPage = 1;
            renderTable();
        }

        function formatPriceHTML(min, max) {
            if (min === null || min === undefined) {
                return "<span class='text-danger fw-bold' style='font-size: 0.8rem;'>NO DATA</span>";
            }
            let minStr = parseFloat(min).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            let maxStr = parseFloat(max).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            if (min == max) return "₱ " + minStr;
            return "₱ " + minStr + " - " + maxStr;
        }

        function renderTable() {
            let start = (currentPage - 1) * rowsPerPage;
            let end = start + rowsPerPage;
            let paginatedItems = regionalData.slice(start, end);
            
            let html = '';
            let count = start + 1;
            
            if (paginatedItems.length === 0) {
                html = '<tr><td colspan="11" class="text-center py-5 text-secondary">No data found for this period.</td></tr>';
            } else {
                paginatedItems.forEach(row => {
                    let badgeClass = row.type_code === 'PC' ? 'bg-secondary' : 'bg-primary';
                    
                    let p1 = formatPriceHTML(row.p1_min, row.p1_max);
                    let p4 = formatPriceHTML(row.p4_min, row.p4_max);
                    let p5 = formatPriceHTML(row.p5_min, row.p5_max);
                    let p2 = formatPriceHTML(row.p2_min, row.p2_max);
                    let p3 = formatPriceHTML(row.p3_min, row.p3_max);

                    let safeCat = row.category_name ? row.category_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeBrand = row.brand_name ? row.brand_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeName = row.product_name ? row.product_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeSpecs = row.specifications ? row.specifications.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';

                    html += `<tr>
                        <td class="text-center fw-bold text-secondary bg-light">${count++}</td>
                        <td><span class="badge ${badgeClass}">${row.type_code}</span></td>
                        <td class="text-secondary text-wrap" style="max-width: 150px;">${safeCat}</td>
                        <td class="fw-bold text-wrap" style="max-width: 180px;">${safeBrand}</td>
                        <td class="text-wrap" style="max-width: 250px;">${safeName}</td>
                        <td class="text-secondary text-wrap" style="max-width: 200px;">${safeSpecs}</td>
                        <td class="text-center fw-bold" style="color: #1a7a2e;">${p1}</td>
                        <td class="text-center fw-bold" style="color: #1a7a2e;">${p4}</td>
                        <td class="text-center fw-bold" style="color: #1a7a2e;">${p5}</td>
                        <td class="text-center fw-bold" style="color: #1a7a2e;">${p2}</td>
                        <td class="text-center fw-bold" style="color: #1a7a2e;">${p3}</td>
                    </tr>`;
                });
            }
            
            document.getElementById('reportTableBody').innerHTML = html;
            updatePaginationInfo();
        }

        function updatePaginationInfo() {
            let total = regionalData.length;
            let start = total === 0 ? 0 : ((currentPage - 1) * rowsPerPage) + 1;
            let end = Math.min(currentPage * rowsPerPage, total);
            
            document.getElementById('pageInfo').innerText = `Showing ${start} to ${end} of ${total} entries`;
            
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = end >= total;
        }

        function prevPage() { if (currentPage > 1) { currentPage--; renderTable(); } }
        function nextPage() { if (currentPage * rowsPerPage < regionalData.length) { currentPage++; renderTable(); } }

        document.addEventListener("DOMContentLoaded", renderTable);

        function updateFilter(element) {
            let form = element.form;
            if (element.name === 'year') {
                form.month.value = '';
                form.week.value = '';
            } else if (element.name === 'month') {
                form.week.value = '';
            }
            form.submit();
        }

        // ==================================================
        // GLOBAL MODALS & ACTIONS LOGIC
        // ==================================================
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

        function saveRegionalReportToDB() {
            showConfirmModal('Generate Regional Report', 'Are you sure you want to generate and save this regional summary to the database?', 'success', '<i class="bi bi-gear"></i> Generate & Save', async function() {
                const btn = document.getElementById('saveDbBtn');
                const origHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Saving to DB...';
                btn.disabled = true;
                
                let fd = new FormData();
                fd.append('action', 'build_and_save_report');
                fd.append('province_id', ''); 
                fd.append('year', '<?= $filter_year ?>');
                
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') {
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Saved!';
                        setTimeout(() => { btn.innerHTML = origHTML; btn.disabled = false; }, 2000);
                    } else {
                        alert("Error saving report: " + data.message);
                        btn.innerHTML = origHTML;
                        btn.disabled = false;
                    }
                } catch(e) {
                    alert("Connection failed.");
                    console.error(e);
                    btn.innerHTML = origHTML;
                    btn.disabled = false;
                }
            });
        }

        function exportRegionalReportToExcel() {
            if(!fullExportData || fullExportData.length === 0) {
                alert("There is no data to export!");
                return;
            }
            showConfirmModal('Export to Excel', 'Are you sure you want to generate and download this regional summary?', 'primary', '<i class="bi bi-file-earmark-excel"></i> Export', function() {
                const btn = document.getElementById('exportReportBtn');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Exporting...';
                btn.disabled = true;

                setTimeout(() => {
                    try {
                        let wb = XLSX.utils.book_new();

                        let bnRows = [];
                        let pcRows = [];
                        let headers = ["#", "Type", "Category", "Brand", "Product Name", "Specs", "Zamboanga City", "Zamboanga Sibugay", "Isabela City", "Zamboanga Del Sur", "Zamboanga Del Norte"];
                        
                        bnRows.push(headers);
                        pcRows.push(headers);

                        let bnCounter = 1;
                        let pcCounter = 1;

                        function rawFmt(min, max) {
                            if (min === null) return "NO DATA";
                            if (min == max) return "₱ " + parseFloat(min).toFixed(2);
                            return "₱ " + parseFloat(min).toFixed(2) + " - " + parseFloat(max).toFixed(2);
                        }

                        fullExportData.forEach(row => {
                            let p1 = rawFmt(row.p1_min, row.p1_max);
                            let p4 = rawFmt(row.p4_min, row.p4_max);
                            let p5 = rawFmt(row.p5_min, row.p5_max);
                            let p2 = rawFmt(row.p2_min, row.p2_max);
                            let p3 = rawFmt(row.p3_min, row.p3_max);

                            let r = ["", row.type_code, row.category_name, row.brand_name, row.product_name, row.specifications, p1, p4, p5, p2, p3];

                            if(row.type_code === 'BN') {
                                r[0] = bnCounter++;
                                bnRows.push(r);
                            } else {
                                r[0] = pcCounter++;
                                pcRows.push(r);
                            }
                        });

                        if(bnRows.length > 1) XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(bnRows), "Basic Necessities");
                        if(pcRows.length > 1) XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(pcRows), "Prime Commodities");
                        
                        if (bnRows.length === 1 && pcRows.length === 1) {
                            alert("No data available to export.");
                            return;
                        }

                        XLSX.writeFile(wb, `Regional_Summary_Report_<?= $filter_year ?>.xlsx`);

                    } catch(e) {
                        alert("Export failed: " + e.message);
                    } finally {
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                }, 300);
            });
        }
        
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
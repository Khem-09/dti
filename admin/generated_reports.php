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

    if (isset($_GET['delete_report_id'])) {
        $del_id = $_GET['delete_report_id'];
        
        $stmt = $db->prepare("SELECT file_path FROM generated_reports WHERE id = ?");
        $stmt->execute([$del_id]);
        $file_path = $stmt->fetchColumn();
        if ($file_path && file_exists("../uploads/reports/" . $file_path)) {
            @unlink("../uploads/reports/" . $file_path);
        }
        
        $stmt = $db->prepare("DELETE FROM generated_reports WHERE id = ?");
        if ($stmt->execute([$del_id])) {
            echo "<script>alert('Report deleted successfully.'); window.location.href='generated_reports.php';</script>";
        } else {
            echo "<script>alert('Failed to delete report.'); window.location.href='generated_reports.php';</script>";
        }
        exit();
    }

    $part = isset($_GET['part']) ? $_GET['part'] : 1;
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'archive'; 
    $target_sheet = isset($_GET['sheet']) ? $_GET['sheet'] : null;

    $previewData = [];
    $preview_file_path = ''; 
    if ($part == 2 && isset($_GET['report_id'])) {
        $previewData = $admin->getReportPreview($_GET['report_id'], $target_sheet);
        
        $stmtFilePath = $db->prepare("SELECT file_path FROM generated_reports WHERE id = ?");
        $stmtFilePath->execute([$_GET['report_id']]);
        $preview_file_path = $stmtFilePath->fetchColumn();
    }

    $filter_type = isset($_GET['type']) ? $_GET['type'] : 'All';
    $filter_year = isset($_GET['year']) ? $_GET['year'] : 'All';

    $reports = $admin->getGeneratedReports($filter_type, $filter_year);
    $provinces = $admin->getProvinces(); 

    // Compliance Filter Logic
    $c_province = $_GET['c_province'] ?? '';
    $availableYears = $admin->getAvailableYears();
    $latest_db_year = (count($availableYears) > 0) ? $availableYears[0]['year'] : date('Y');
    $c_year = $_GET['c_year'] ?? $latest_db_year;
    $c_month = $_GET['c_month'] ?? '';
    $c_week = $_GET['c_week'] ?? '';

    $availableMonths = $admin->getAvailableMonths($c_year);
    $availableWeeks = (!empty($c_month)) ? $admin->getAvailableWeeks($c_year, $c_month) : [];

    $complianceData = [];
    $allCategories = [];
    if ($mode === 'compliance' && !empty($c_province)) {
        $complianceData = $admin->getSRPComplianceReport($c_province, $c_year, $c_month, $c_week);
        $allCategories = array_unique(array_column($complianceData, 'category_name'));
        sort($allCategories);
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generated Reports - DTI Region IX</title>
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/upload.css">
    <style>
        .report-row:hover { background-color: #f8f9fa; }
        .filter-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .btn-action { transition: all 0.2s ease-in-out; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important; }
        .btn-action i { font-size: 1.05rem; }
        .dropdown-toggle::after { vertical-align: middle; }
        #previewTable th { border: 1px solid #4a5056 !important; }
        
        /* Navigation Tabs */
        .nav-custom { border-bottom: 2px solid #dee2e6; margin-bottom: 25px; }
        .nav-custom .nav-link { border: none; color: #6c757d; font-weight: 600; padding: 10px 20px; position: relative; transition: all 0.3s; }
        .nav-custom .nav-link.active { color: #0A0A3A; }
        .nav-custom .nav-link.active::after { content: ''; position: absolute; bottom: -2px; left: 0; width: 100%; height: 3px; background-color: #8B0000; }
        .nav-custom .nav-link:hover { color: #8B0000; }

        /* Internal Table Scrolling UI */
        .table-responsive-scroll { max-height: 580px; overflow-y: auto; border: 1px solid #dee2e6; background-color: #fff; border-radius: 4px; }
        .table-responsive-scroll thead th { position: sticky; top: 0; z-index: 10; background-color: #212529 !important; color: #fff; }

        .compliance-list-container { max-height: 140px; overflow-y: auto; border: 1px solid #e9ecef; border-radius: 6px; background-color: #ffffff; }
        .compliance-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border-bottom: 1px solid #f1f3f5; font-size: 0.8rem; }
        .compliance-item:last-child { border-bottom: none; }
        .compliance-item .store-name { font-weight: 500; color: #495057; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px; }
        .price-val { font-family: monospace; font-weight: bold; }
        .price-above { color: #d63031; }
        .price-below { color: #27ae60; }
        .count-badge { font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; vertical-align: middle; margin-left: 5px; }
        .compliance-list-container::-webkit-scrollbar { width: 5px; }
        .compliance-list-container::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
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
                <li class="nav-item">
                    <a class="nav-link active py-3 fw-bold" href="generated_reports.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                        <i class="bi bi-journal-check me-2"></i> Generated Reports
                    </a>
                </li>
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
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                        <li class="nav-item">
                            <a class="nav-link active py-3 fw-bold" href="generated_reports.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                                <i class="bi bi-journal-check me-2"></i> Generated Reports
                            </a>
                        </li>
                         <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 p-3 p-md-4" style="background-color: #EAEAEA;">
                <div class="shadow-sm bg-white p-3 p-md-5" style="min-height: 80vh; border-radius: 0;">
                    
                    <div id="part1" class="<?php echo ($part == 1) ? '' : 'd-none'; ?>">
                        <h2 class="fw-bold mb-4 text-center" style="color: #0A0A3A; font-size: 26px;">Reports Center</h2>
                        
                        <ul class="nav nav-custom d-flex justify-content-center">
                            <li class="nav-item">
                                <a class="nav-link <?= ($mode == 'archive') ? 'active' : '' ?>" href="generated_reports.php?mode=archive">
                                    <i class="bi bi-archive me-2"></i> Report Archive
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= ($mode == 'compliance') ? 'active' : '' ?>" href="generated_reports.php?mode=compliance">
                                    <i class="bi bi-shield-check me-2"></i> SRP Compliance Tracker
                                </a>
                            </li>
                        </ul>

                        <?php if($mode == 'archive'): ?>
                            <div class="filter-box d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
                                <div class="input-group input-group-sm shadow-sm w-100" style="max-width: 400px;">
                                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                                    <input type="text" id="searchFile" class="form-control border-start-0" placeholder="Search report name..." onkeyup="filterReports()">
                                </div>
                                <div class="d-flex flex-wrap gap-2 w-100 justify-content-lg-end">
                                    <select id="filterProv" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterReports()" style="min-width: 140px; max-width: 200px;">
                                        <option value="All">All Regions/Provinces</option>
                                        <option value="Regional">Regional Summary</option>
                                        <?php foreach($provinces as $p): ?><option value="<?= htmlspecialchars($p['province_name']) ?>"><?= htmlspecialchars($p['province_name']) ?></option><?php endforeach; ?>
                                    </select>
                                    <input type="date" id="filterDate" class="form-control form-control-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterReports()" style="min-width: 140px; max-width: 180px;">
                                    <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="clearFilters()"><i class="bi bi-x-circle"></i></button>
                                </div>
                            </div>
                            <div class="table-responsive p-3 p-md-4 rounded" style="background-color: #D3D3D3; min-height: 400px;">
                                <table class="table table-borderless table-hover bg-transparent align-middle mb-0 text-nowrap" id="archiveTable">
                                    <thead style="border-bottom: 1px solid #aaa;">
                                        <tr><th>Report Name</th><th>Type</th><th>Date Generated</th><th class="text-center">Action</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php if(count($reports) > 0): ?>
                                            <?php foreach($reports as $report): 
                                                $typeName = $report['report_type'] ?: 'Provincial';
                                                $badgeColor = ($typeName == 'Provincial') ? 'bg-secondary' : 'bg-primary';
                                                $provName = $report['province_name'] ? $report['province_name'] : 'Regional';
                                            ?>
                                                <tr class="report-row" data-province="<?= htmlspecialchars($provName) ?>" data-date="<?= date('Y-m-d', strtotime($report['created_at'])) ?>">
                                                    <td class="fw-bold report-name text-wrap" style="color: #0A0A3A; max-width: 250px;"><?= htmlspecialchars($report['report_name']) ?></td>
                                                    <td><span class="badge <?= $badgeColor ?>"><?= $typeName ?></span></td>
                                                    <td class="text-secondary"><?= date('M d, Y h:i A', strtotime($report['created_at'])) ?></td>
                                                    <td class="text-center">
                                                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                            <a href="generated_reports.php?part=2&report_id=<?= $report['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm"><i class="bi bi-eye"></i> View</a>
                                                            <a href="#" onclick="confirmExportLink(event, '../uploads/reports/<?= htmlspecialchars($report['file_path']) ?>')" class="btn btn-sm btn-primary shadow-sm"><i class="bi bi-download"></i> Export</a>
                                                            <a href="#" class="btn btn-sm btn-outline-danger shadow-sm" onclick="confirmLinkAction(event, 'generated_reports.php?delete_report_id=<?= $report['id'] ?>', 'Delete Report', 'Are you sure?', 'danger', 'Delete')"><i class="bi bi-trash"></i> Delete</a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?><tr><td colspan="4" class="text-center py-5">No reports found.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="filter-box">
                                <form method="GET" action="generated_reports.php" class="row g-2 align-items-end">
                                    <input type="hidden" name="mode" value="compliance">
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-secondary">Province</label>
                                        <select name="c_province" class="form-select form-select-sm" required>
                                            <option value="">Select Province</option>
                                            <?php foreach($provinces as $p): ?><option value="<?= $p['id'] ?>" <?= ($c_province == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['province_name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="small fw-bold text-secondary">Year</label>
                                        <select name="c_year" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach($availableYears as $y): ?><option value="<?= $y['year'] ?>" <?= ($c_year == $y['year']) ? 'selected' : '' ?>><?= $y['year'] ?></option><?php endforeach; ?></select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="small fw-bold text-secondary">Month</label>
                                        <select name="c_month" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Full Year</option><?php foreach($availableMonths as $m): ?><option value="<?= $m['month'] ?>" <?= ($c_month == $m['month']) ? 'selected' : '' ?>><?= $m['month'] ?></option><?php endforeach; ?></select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="small fw-bold text-secondary">Week Range</label>
                                        <select name="c_week" class="form-select form-select-sm" <?= empty($c_month) ? 'disabled' : '' ?>><option value="">All Weeks</option><?php foreach($availableWeeks as $w): ?><option value="<?= $w['id'] ?>" <?= ($c_week == $w['id']) ? 'selected' : '' ?>><?= htmlspecialchars($w['date_range_label']) ?></option><?php endforeach; ?></select>
                                    </div>
                                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-dark w-100 shadow-sm fw-bold">Analyze Data</button></div>
                                </form>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span><input type="text" id="compSearch" class="form-control" placeholder="Search product..." onkeyup="runComplianceFilter()"></div></div>
                                <div class="col-md-2"><select id="compRows" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="25">25 rows</option><option value="50" selected>50 rows</option><option value="100">100 rows</option></select></div>
                                <div class="col-md-2"><select id="compType" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="All">All Types</option><option value="BN">BN</option><option value="PC">PC</option></select></div>
                                <div class="col-md-3"><select id="compCat" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="All">All Categories</option><?php foreach($allCategories as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-2">
                                    <button class="btn btn-success btn-sm w-100 fw-bold shadow-sm" onclick="exportCompliance()">
                                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                                    </button>
                                </div>
                            </div>

                            <div class="table-responsive-scroll shadow-sm rounded">
                                <table class="table table-hover align-top mb-0" id="complianceTable">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="width: 250px;">Product Information</th>
                                            <th class="text-center" style="width: 100px;">SRP</th>
                                            <th>Stores ABOVE SRP</th>
                                            <th>Stores WITHIN/BELOW SRP</th>
                                            <th>NO SRP DATA</th>
                                        </tr>
                                    </thead>
                                    <tbody id="complianceBody"><tr><td colspan="5" class="text-center py-5">Please Analyze Data to start.</td></tr></tbody>
                                </table>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-3"><span id="compInfo" class="small fw-bold text-secondary"></span><div class="btn-group shadow-sm"><button class="btn btn-sm btn-outline-secondary" onclick="compPrev()">Prev</button><button class="btn btn-sm btn-outline-secondary" onclick="compNext()">Next</button></div></div>
                        <?php endif; ?>
                    </div>

                    <div id="part2" class="<?php echo ($part == 2) ? '' : 'd-none'; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div class="d-flex align-items-center gap-2"><a href="generated_reports.php?part=1" class="text-dark"><i class="bi bi-arrow-left fs-4"></i></a><h3 class="m-0 fw-bold" style="color: #8B0000;">Report Preview</h3></div>
                            <div class="d-flex align-items-center gap-2"><select id="previewRowsPerPage" class="form-select form-select-sm" onchange="changePreviewRowsPerPage()"><option value="50">50 rows</option><option value="100">100 rows</option></select>
                            <?php if (!empty($preview_file_path)): ?><a href="#" onclick="confirmExportLink(event, '../uploads/reports/<?= htmlspecialchars($preview_file_path) ?>')" class="btn btn-primary btn-sm fw-bold px-3"><i class="bi bi-download me-1"></i> Export</a><?php endif; ?></div>
                        </div>
                        <div class="bg-white border rounded shadow-sm p-4 overflow-hidden">
                            <div class="table-responsive border rounded" style="max-height: 550px;"><table class="table table-bordered table-sm text-nowrap" id="previewTable"><thead class="table-dark sticky-top" id="previewTableHead"></thead><tbody id="previewTableBody"></tbody></table></div>
                            <div class="d-flex justify-content-between align-items-center mt-3"><span id="previewPageInfo"></span><div class="btn-group"><button class="btn btn-outline-secondary" onclick="previewPrevPage()" id="previewPrevBtn">Prev</button><button class="btn btn-outline-secondary" onclick="previewNextPage()" id="previewNextBtn">Next</button></div></div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="adminProfileModal" tabindex="-1" aria-hidden="true" style="z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; background-color: #f4f6f9;">
                <div class="modal-header border-0 pb-0 px-4 pt-4"><div><h4 class="modal-title fw-bold" style="color: #0A0A3A;">Account Settings</h4><p class="text-secondary small mb-0">Manage your credentials</p></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body p-4"><div class="row g-4"><div class="col-md-5"><div class="bg-white p-4 rounded shadow-sm border h-100"><h6 class="fw-bold mb-4">Profile Info</h6><form id="profileForm" onsubmit="updateAdminProfile(event)"><div class="row mb-3 g-2"><div class="col-md-6"><label class="small fw-bold">First Name</label><input type="text" id="adminFirstName" class="form-control" value="<?= htmlspecialchars($admin_first) ?>" required></div><div class="col-md-6"><label class="small fw-bold">Last Name</label><input type="text" id="adminLastName" class="form-control" value="<?= htmlspecialchars($admin_last) ?>" required></div></div><div class="mb-4"><label class="small fw-bold">Username</label><input type="text" id="adminUsername" class="form-control" value="<?= htmlspecialchars($_SESSION['username'] ?? 'admin') ?>" required></div><button type="submit" class="btn btn-dark fw-bold w-100">Save Changes</button></form><hr><button class="btn btn-success w-100 mt-3" onclick="openBackupModal()">Download Database Backup</button></div></div>
                <div class="col-md-7"><div class="bg-white p-4 rounded shadow-sm border h-100"><h6>Update Password</h6><form id="passwordForm" onsubmit="updateAdminPassword(event)"><div class="mb-3"><label class="small fw-bold">Current Password</label><input type="password" id="currentPassword" class="form-control" required></div><div class="row mb-3"><div class="col-md-6"><label class="small fw-bold">New Password</label><input type="password" id="newPassword" class="form-control" required></div><div class="col-md-6"><label class="small fw-bold">Confirm</label><input type="password" id="confirmPassword" class="form-control" required></div></div><button type="submit" class="btn btn-primary w-100">Update Password</button></form></div></div></div></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="universalConfirmModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header border-0"><h5 class="modal-title fw-bold" id="confirmModalTitle">Confirm Action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4 pt-3 text-secondary" id="confirmModalMessage">Proceed?</div><div class="modal-footer border-0 pb-4 px-4"><button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary px-4" id="confirmModalBtn">Confirm</button></div></div></div>
    </div>

    <div class="modal fade" id="backupAuthModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header border-0"><h5 class="modal-title fw-bold text-success">Authenticate Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><input type="password" id="backupAuthPassword" class="form-control" placeholder="Enter Admin Password"><div id="backupAuthError" class="text-danger small mt-2 d-none">Incorrect password.</div></div><div class="modal-footer border-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-success" id="confirmBackupBtn">Download</button></div></div></div>
    </div>
    <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>

    <script>
        // Compliance Tracker Logic
        const compMasterData = <?= json_encode($complianceData) ?>;
        let compCurrentPage = 1;
        let compFilteredData = [...compMasterData];

        function runComplianceFilter() {
            const search = document.getElementById('compSearch').value.toLowerCase();
            const type = document.getElementById('compType').value;
            const cat = document.getElementById('compCat').value;
            compFilteredData = compMasterData.filter(item => {
                const matchSearch = (item.brand_name + ' ' + item.product_name).toLowerCase().includes(search);
                const matchType = (type === 'All' || item.type_code === type);
                const matchCat = (cat === 'All' || item.category_name === cat);
                return matchSearch && matchType && matchCat;
            });
            compCurrentPage = 1;
            renderComplianceTable();
        }

        function renderComplianceTable() {
            const tbody = document.getElementById('complianceBody');
            const rowsPerPage = parseInt(document.getElementById('compRows').value);
            const start = (compCurrentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const pageItems = compFilteredData.slice(start, end);
            let html = '';
            if (pageItems.length === 0) { html = '<tr><td colspan="5" class="text-center py-5">No data.</td></tr>'; } 
            else {
                pageItems.forEach(row => {
                    const aboveList = row.above_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val price-above">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                    const belowList = row.below_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val price-below">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                    const noSrpList = row.no_srp_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val text-dark">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                    
                    let srpDisplay = (row.srp && row.srp > 0) ? `₱${parseFloat(row.srp).toFixed(2)}` : 'N/A';

                    html += `<tr><td><div class="fw-bold">${row.brand_name} ${row.product_name}</div><small class="text-secondary">${row.specifications}</small></td>
                    <td class="text-center"><span class="badge bg-light text-primary border px-2 py-2">${srpDisplay}</span></td>
                    <td><div class="small text-danger">Found: ${row.above_stores.length}</div><div class="compliance-list-container">${aboveList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                    <td><div class="small text-success">Found: ${row.below_stores.length}</div><div class="compliance-list-container">${belowList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                    <td><div class="small text-secondary">Found: ${row.no_srp_stores.length}</div><div class="compliance-list-container">${noSrpList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                    </tr>`;
                });
            }
            tbody.innerHTML = html;
            document.getElementById('compInfo').innerText = `Showing ${Math.min(start + 1, compFilteredData.length)} to ${Math.min(end, compFilteredData.length)} of ${compFilteredData.length}`;
        }
        function compPrev() { if (compCurrentPage > 1) { compCurrentPage--; renderComplianceTable(); } }
        function compNext() { if (compCurrentPage * parseInt(document.getElementById('compRows').value) < compFilteredData.length) { compCurrentPage++; renderComplianceTable(); } }

        function exportCompliance() {
            const urlParams = new URLSearchParams(window.location.search);
            const prov = urlParams.get('c_province');
            const yr = urlParams.get('c_year');
            const mo = urlParams.get('c_month') || '';
            const wk = urlParams.get('c_week') || '';
            if(!prov) { alert("Please select a province and analyze data first."); return; }
            
            window.location.href = `ajax_handler.php?action=export_compliance_excel&c_province=${prov}&c_year=${yr}&c_month=${mo}&c_week=${wk}`;
        }

        // Archive Filters
        function filterReports() {
            let search = document.getElementById("searchFile").value.toLowerCase();
            let prov = document.getElementById("filterProv").value;
            let dateVal = document.getElementById("filterDate").value;
            document.querySelectorAll(".report-row").forEach(row => {
                let matchSearch = row.querySelector(".report-name").innerText.toLowerCase().includes(search);
                let matchProv = (prov === "All" || row.getAttribute("data-province") === prov);
                let matchDate = (dateVal === "" || row.getAttribute("data-date") === dateVal);
                row.style.display = (matchSearch && matchProv && matchDate) ? "" : "none";
            });
        }
        function clearFilters() { document.getElementById('searchFile').value = ''; document.getElementById('filterProv').value = 'All'; document.getElementById('filterDate').value = ''; filterReports(); }

        // EXCEL PREVIEW LOGIC
        const rawPreviewData = <?= json_encode($previewData['data'] ?? []) ?>;
        let previewCurrentPage = 1, previewRowsPerPage = 50;
        const headerRow = rawPreviewData.length > 0 ? rawPreviewData[0] : [];
        const dataRows = rawPreviewData.length > 1 ? rawPreviewData.slice(1) : [];

        function renderPreviewTable() {
            let thead = document.getElementById('previewTableHead'), tbody = document.getElementById('previewTableBody');
            if (!thead || !tbody) return;
            let headHtml = '<tr><th>#</th>'; headerRow.forEach(c => headHtml += `<th>${String(c).replace(/</g, "&lt;")}</th>`); headHtml += '</tr>';
            thead.innerHTML = headHtml;
            let start = (previewCurrentPage - 1) * previewRowsPerPage, end = start + previewRowsPerPage, pItems = dataRows.slice(start, end), html = '';
            pItems.forEach((r, i) => {
                html += `<tr><td>${start + i + 1}</td>`;
                for(let j=0; j<headerRow.length; j++) html += `<td>${(r[j] || '').toString().replace(/PHP\s*/ig, '')}</td>`;
                html += '</tr>';
            });
            tbody.innerHTML = html;
            document.getElementById('previewPageInfo').innerText = `Page ${previewCurrentPage}`;
        }
        function previewPrevPage() { if (previewCurrentPage > 1) { previewCurrentPage--; renderPreviewTable(); } }
        function previewNextPage() { if (previewCurrentPage * previewRowsPerPage < dataRows.length) { previewCurrentPage++; renderPreviewTable(); } }
        function changePreviewRowsPerPage() { previewRowsPerPage = parseInt(document.getElementById('previewRowsPerPage').value); previewCurrentPage = 1; renderPreviewTable(); }

        // GLOBAL HELPERS
        function showConfirmModal(t, m, c, bt, cb) {
            document.getElementById('confirmModalTitle').innerText = t;
            document.getElementById('confirmModalMessage').innerHTML = m;
            let btn = document.getElementById('confirmModalBtn'); btn.className = 'btn btn-' + c; btn.innerText = bt;
            let nBtn = btn.cloneNode(true); btn.parentNode.replaceChild(nBtn, btn);
            let mod = new bootstrap.Modal(document.getElementById('universalConfirmModal'));
            nBtn.onclick = () => { mod.hide(); cb(); }; mod.show();
        }
        function confirmLinkAction(e, u, t, m, c, bt) { e.preventDefault(); showConfirmModal(t, m, c, bt, () => window.location.href = u); }
        function confirmExportLink(e, u) { e.preventDefault(); showConfirmModal('Export', 'Download this file?', 'primary', 'Download', () => { let a = document.createElement('a'); a.href = u; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }); }
        function confirmLogout(e) { e.preventDefault(); showConfirmModal('Logout', 'Are you sure?', 'danger', 'Logout', () => window.location.href = '../admin/logout.php'); }

        // PROFILE & BACKUP
        function openBackupModal() { document.getElementById('backupAuthPassword').value = ''; new bootstrap.Modal(document.getElementById('backupAuthModal')).show(); }
        document.getElementById('confirmBackupBtn').onclick = async function() {
            let pass = document.getElementById('backupAuthPassword').value;
            let fd = new FormData(); fd.append('action', 'verify_password_only'); fd.append('password', pass);
            let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
            let data = await res.json();
            if(data.status === 'success') { window.location.href = 'ajax_handler.php?action=download_backup'; } 
            else { document.getElementById('backupAuthError').classList.remove('d-none'); }
        };

        async function updateAdminProfile(e) {
            e.preventDefault();
            let fd = new FormData(); fd.append('action', 'update_admin_profile');
            fd.append('firstname', document.getElementById('adminFirstName').value);
            fd.append('lastname', document.getElementById('adminLastName').value);
            fd.append('username', document.getElementById('adminUsername').value);
            let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
            let d = await res.json(); if(d.status === 'success') location.reload(); else alert(d.message);
        }

        async function updateAdminPassword(e) {
            e.preventDefault();
            if(document.getElementById('newPassword').value !== document.getElementById('confirmPassword').value) return alert("Mismatch");
            let fd = new FormData(); fd.append('action', 'update_admin_password');
            fd.append('current_password', document.getElementById('currentPassword').value);
            fd.append('new_password', document.getElementById('newPassword').value);
            let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
            let d = await res.json(); if(d.status === 'success') window.location.href = '../admin/logout.php'; else alert(d.message);
        }

        document.addEventListener("DOMContentLoaded", () => {
            if (document.getElementById('complianceBody') && compMasterData.length > 0) renderComplianceTable();
            if (document.getElementById('previewTableBody')) renderPreviewTable();
        });
    </script>
</body>
</html>
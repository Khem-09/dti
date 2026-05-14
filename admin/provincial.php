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

    if (isset($_GET['delete_file_id'])) {
        $del_id = $_GET['delete_file_id'];
        $stmt = $db->prepare("DELETE FROM uploaded_files WHERE id = ?");
        if ($stmt->execute([$del_id])) {
            echo "<script>alert('File and all its price data deleted successfully.'); window.location.href='provincial.php';</script>";
        } else {
            echo "<script>alert('Failed to delete file.'); window.location.href='provincial.php';</script>";
        }
        exit();
    }

    $part = isset($_GET['part']) ? $_GET['part'] : 1;
    $filter_province = isset($_GET['province_id']) ? $_GET['province_id'] : '';
    
    $availableYears = $admin->getAvailableYears();
    $latest_db_year = (count($availableYears) > 0) ? $availableYears[0]['year'] : date('Y');
    $filter_year = isset($_GET['year']) ? $_GET['year'] : $latest_db_year;
    
    $filter_month = isset($_GET['month']) ? $_GET['month'] : '';
    $filter_week = isset($_GET['week']) ? $_GET['week'] : '';
    
    $filter_type = isset($_GET['type']) ? $_GET['type'] : 'BN'; 
    $target_sheet = isset($_GET['sheet']) ? $_GET['sheet'] : null;

    $provinces = $admin->getProvinces();
    $uploadedFiles = $admin->getUploadedFiles(); 
    
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

    $current_province_name = "Region_IX";
    if (!empty($filter_province)) {
        foreach ($provinces as $p) {
            if ($p['id'] == $filter_province) {
                $current_province_name = $p['province_name'];
                break;
            }
        }
    }
    $safe_prov_name = preg_replace('/[^a-zA-Z0-9]/', '_', $current_province_name);

    $reportData = [];
    $exportData = []; 
    if ($part == 3 && !empty($filter_province)) {
        $reportData = $admin->getProvincialReport($filter_province, $filter_year, $filter_month, $filter_week, $filter_type);
        $exportData = $admin->getProvincialReport($filter_province, $filter_year, $filter_month, $filter_week, 'All');
    }

    $current_preview_prov = 1;
    $current_preview_year = date('Y');
    $previewData = [];
    if ($part == 2 && isset($_GET['file_id'])) {
        $stmt = $db->prepare("SELECT province_id, target_year FROM uploaded_files WHERE id = ?");
        $stmt->execute([$_GET['file_id']]);
        if ($fileRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $current_preview_prov = $fileRow['province_id'];
            $current_preview_year = $fileRow['target_year'];
        }
        $previewData = $admin->getExcelPreview($_GET['file_id'], $target_sheet);
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provincial Reports - DTI Region IX</title>
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/provincial.css">
    <script src="https://cdn.jsdelivr.net/npm/xlsx/dist/xlsx.full.min.js"></script>
    <style>
        .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .filter-box { background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 25px; }
        .btn-action { transition: all 0.2s ease-in-out; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 0.4rem; }
        .btn-action:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important; }
        .btn-action i { font-size: 1.05rem; }
        .dropdown-toggle::after { vertical-align: middle; }
        #previewTable th { border: 1px solid #4a5056 !important; }
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
                <li class="nav-item">
                    <a class="nav-link active py-3 fw-bold" href="provincial.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                        <i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports
                    </a>
                </li>
                <li class="nav-item"><a class="nav-link py-3 text-white" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
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
                        <li class="nav-item">
                            <a class="nav-link active py-3 fw-bold" href="provincial.php" style="background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;">
                                <i class="bi bi-file-earmark-text-fill me-2"></i> Provincial Reports
                            </a>
                        </li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="regional.php"><i class="bi bi-folder-fill me-2"></i> Regional Summary</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="generated_reports.php"><i class="bi bi-journal-check me-2"></i> Generated Reports</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="products.php"><i class="bi bi-tags me-2"></i> Product & SRP</a></li>
                        <li class="nav-item"><a class="nav-link py-3 text-white" href="trends.php"><i class="bi bi-graph-up me-2"></i> Price Trends</a></li>
                    </ul>
                </div>
            </nav>

            <main class="col-12 col-md-10 content-wrapper p-3 p-md-4">
                <div class="inner-card shadow-sm bg-white p-3 p-md-4 rounded border">
                    
                    <div id="part1" class="<?php echo ($part == 1) ? '' : 'd-none'; ?>">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="section-title m-0">Upload File</h4>
                        </div>
                        
                        <div id="uploadStatus" class="alert alert-info d-none fw-bold shadow-sm">
                            <i class="bi bi-arrow-repeat spin" id="spinnerIcon"></i> 
                            <span id="statusText">Initializing upload...</span>
                        </div>

                        <form id="uploadForm" enctype="multipart/form-data">
                            <input type="hidden" name="province_id" value="<?= !empty($filter_province) ? $filter_province : 1 ?>"> 
                            <input type="hidden" name="target_year" value="<?= date('Y') ?>">
                            <input type="file" name="excel_file" id="fileInput" class="d-none" accept=".xlsx, .xls, .csv">
                            
                            <div class="dropzone mb-4" id="dropzoneBox" style="cursor: pointer;" onclick="document.getElementById('fileInput').click()">
                                <i class="bi bi-file-earmark-arrow-down dropzone-icon"></i>
                                <h6 class="text-secondary fw-normal mt-2">Drag and Drop File here or <span class="text-dark fw-bold text-decoration-underline upload-file">Choose File</span></h6>
                            </div>
                        </form>

                        <h4 class="section-title mb-3">Uploaded Files</h4>
                        <div class="filter-box d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
                            <div class="input-group input-group-sm shadow-sm w-100" style="max-width: 400px;">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                                <input type="text" id="searchFile" class="form-control border-start-0" placeholder="Search file name..." onkeyup="filterUploadedFiles()">
                            </div>
                            
                            <div class="d-flex flex-wrap gap-2 w-100 justify-content-lg-end">
                                <select id="filterProv" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterUploadedFiles()" style="min-width: 140px; max-width: 200px;">
                                    <option value="All">All Provinces</option>
                                    <?php foreach($provinces as $p): ?>
                                        <option value="<?= htmlspecialchars($p['province_name']) ?>"><?= htmlspecialchars($p['province_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <input type="date" id="filterDate" class="form-control form-control-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterUploadedFiles()" style="min-width: 140px; max-width: 180px;">
                                
                                <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="clearArchiveFilters()" title="Clear Filters"><i class="bi bi-x-circle"></i></button>
                            </div>
                        </div>
                        
                        <div class="table-responsive border rounded shadow-sm">
                            <table class="table table-hover align-middle mb-0 text-nowrap">
                                <thead class="table-light text-secondary">
                                    <tr>
                                        <th>File Name</th>
                                        <th>Province</th>
                                        <th>Date Uploaded</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(count($uploadedFiles) > 0): ?>
                                        <?php foreach($uploadedFiles as $file): ?>
                                            <?php $displayName = preg_replace('/^[0-9]+_/', '', $file['original_filename']); ?>
                                            <tr class="upload-row" data-province="<?= htmlspecialchars($file['province_name']) ?>" data-date="<?= date('Y-m-d', strtotime($file['uploaded_at'])) ?>">
                                                <td class="text-dark fw-bold file-name-cell text-wrap" style="max-width: 200px;"><?= htmlspecialchars($displayName) ?></td>
                                                <td class="text-secondary"><?= htmlspecialchars($file['province_name']) ?></td>
                                                <td class="text-secondary"><?= date('M d, Y h:i A', strtotime($file['uploaded_at'])) ?></td>
                                                <td class="text-center">
                                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                        <a href="provincial.php?part=2&file_id=<?= $file['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm px-2 px-md-3">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-success shadow-sm px-2 px-md-3" onclick="buildAndNavigateReport(<?= $file['province_id'] ?>, <?= $file['target_year'] ?>, this)">
                                                            <i class="bi bi-journal-check"></i> Generate
                                                        </button>
                                                        <a href="#" class="btn btn-sm btn-outline-danger shadow-sm px-2 px-md-3" onclick="confirmLinkAction(event, 'provincial.php?delete_file_id=<?= $file['id'] ?>', 'Delete File', 'Are you sure you want to delete this uploaded file and all its price records?', 'danger', '<i class=\'bi bi-trash\'></i> Delete')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-secondary py-4">No files uploaded yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="part2" class="<?php echo ($part == 2) ? '' : 'd-none'; ?>">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-2 gap-2">
                            <div class="province-header d-flex align-items-center gap-2">
                                <a href="provincial.php?part=1" class="text-dark text-decoration-none"><i class="bi bi-arrow-left fs-4"></i></a> 
                                <h3 class="m-0 fw-bold" style="color: #8B0000;">Data Preview</h3>
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-secondary small">Rows per page:</span>
                                <select id="previewRowsPerPage" class="form-select form-select-sm border shadow-sm fw-bold text-secondary" onchange="changePreviewRowsPerPage()" style="width: 100px;">
                                    <option value="25">25 rows</option>
                                    <option value="50" selected>50 rows</option>
                                    <option value="100">100 rows</option>
                                    <option value="250">250 rows</option>
                                    <option value="500">500 rows</option>
                                </select>
                            </div>
                        </div>
                        <div class="red-line mb-4" style="height: 3px; background-color: #8B0000; width: 100%;"></div>

                        <div class="bg-white border rounded shadow-sm p-3 p-md-4 overflow-hidden">
                            <?php if (isset($previewData['error'])): ?>
                                <div class="alert alert-danger mb-0">
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $previewData['error'] ?>
                                </div>
                            <?php else: ?>
                                
                                <?php if(!empty($previewData['sheets']) && count($previewData['sheets']) > 1): ?>
                                <div class="mb-3 pb-3 border-bottom d-flex align-items-center flex-wrap gap-2">
                                    <span class="fw-bold text-secondary me-3"><i class="bi bi-layers"></i> Select Sheet:</span>
                                    <?php foreach($previewData['sheets'] as $sheet): ?>
                                        <a href="provincial.php?part=2&file_id=<?= $_GET['file_id'] ?>&sheet=<?= urlencode($sheet) ?>" 
                                        class="btn btn-sm <?= ($previewData['current_sheet'] == $sheet) ? 'btn-primary shadow-sm' : 'btn-outline-secondary' ?> fw-bold me-2 px-3">
                                        <?= htmlspecialchars($sheet) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <div id="previewTitleContainer" class="mb-3 text-center text-secondary fw-bold" style="font-size: 0.95rem;"></div>

                                <div class="table-responsive border rounded" style="max-height: 550px;">
                                    <?php if (!empty($previewData['data'])): ?>
                                        <table class="table table-bordered table-hover table-sm text-nowrap align-middle mb-0" id="previewTable" style="font-size: 0.85rem;">
                                            <thead class="table-dark sticky-top" id="previewTableHead" style="z-index: 2;">
                                            </thead>
                                            <tbody id="previewTableBody">
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <div class="text-center py-5 text-secondary">No readable data found in this sheet.</div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($previewData['data'])): ?>
                                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mt-3 px-2 gap-2">
                                    <span class="text-secondary fw-bold" id="previewPageInfo">Loading data...</span>
                                    <div class="btn-group shadow-sm">
                                        <button class="btn btn-outline-secondary fw-bold" onclick="previewPrevPage()" id="previewPrevBtn" disabled>Previous</button>
                                        <button class="btn btn-outline-secondary fw-bold" onclick="previewNextPage()" id="previewNextBtn" disabled>Next</button>
                                    </div>
                                </div>
                                <?php endif; ?>

                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="part3" class="<?php echo ($part == 3) ? '' : 'd-none'; ?>">
                        <div class="province-header d-flex align-items-center gap-2 mb-2">
                            <a href="provincial.php?part=1" class="text-dark text-decoration-none"><i class="bi bi-arrow-left fs-4"></i></a> 
                            <h3 class="m-0 fw-bold" style="color: #8B0000;">Data Summary</h3>
                        </div>
                        <div class="red-line mb-4" style="height: 3px; background-color: #8B0000; width: 100%;"></div>

                        <div class="filter-box d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3">
                            <div class="d-flex align-items-center gap-3 w-100" style="max-width: 300px;">
                                <span class="fw-bold text-dark">Category:</span>
                                <div class="btn-group shadow-sm flex-grow-1" role="group">
                                    <a href="provincial.php?part=3&province_id=<?= $filter_province ?>&year=<?= $filter_year ?>&month=<?= $filter_month ?>&week=<?= $filter_week ?>&type=BN" 
                                       class="btn btn-sm <?= ($filter_type == 'BN') ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>"> BN</a>
                                    <a href="provincial.php?part=3&province_id=<?= $filter_province ?>&year=<?= $filter_year ?>&month=<?= $filter_month ?>&week=<?= $filter_week ?>&type=PC" 
                                       class="btn btn-sm <?= ($filter_type == 'PC') ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>">PC</a>
                                </div>
                            </div>
                            
                            <form method="GET" action="provincial.php" class="d-flex flex-wrap gap-2 align-items-center w-100 justify-content-xl-end m-0">
                                <input type="hidden" name="part" value="3">
                                <input type="hidden" name="province_id" value="<?= $filter_province ?>">
                                <input type="hidden" name="type" value="<?= htmlspecialchars($filter_type) ?>">
                                
                                <select id="rowsPerPage" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="changeRowsPerPage()" style="min-width: 100px; max-width: 120px; height: 31px;">
                                    <option value="25">25 rows</option>
                                    <option value="50" selected>50 rows</option>
                                    <option value="100">100 rows</option>
                                    <option value="250">250 rows</option>
                                    <option value="500">500 rows</option>
                                </select>
                                
                                <select name="year" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="updateFilter(this)" style="min-width: 90px; max-width: 110px;">
                                    <?php foreach($availableYears as $y): ?>
                                        <option value="<?= $y['year'] ?>" <?= ($filter_year == $y['year']) ? 'selected' : '' ?>><?= $y['year'] ?></option>
                                    <?php endforeach; ?>
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
                                <?php if (empty($filter_month)): ?>
                                    Year: <?= htmlspecialchars($filter_year) ?> 
                                <?php elseif (empty($filter_week)): ?>
                                    Year: <?= htmlspecialchars($filter_year) ?> | Month: <?= htmlspecialchars($filter_month) ?>
                                <?php else: ?>
                                    Year: <?= htmlspecialchars($filter_year) ?> | Month: <?= htmlspecialchars($filter_month) ?> <br>
                                    Date Range: <?= htmlspecialchars($selected_week_label) ?> <br>
                                    <?= htmlspecialchars($selected_week_num) ?>
                                <?php endif; ?>
                            </div>
                            
                            <button id="exportReportBtn" class="btn btn-primary shadow-sm px-4" onclick="exportFullReportToExcel()">
                                <i class="bi bi-download"></i> Export
                            </button>
                        </div>

                        <div class="table-responsive bg-white shadow-sm rounded border" style="max-height: 550px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0 text-nowrap" id="reportTable">
                                <thead class="table-light sticky-top" style="z-index: 2;">
                                    <tr style="border-bottom: 2px solid #8B0000;">
                                        <th class="fw-bold text-secondary text-center" style="width: 50px;">#</th>
                                        <th class="fw-bold text-secondary">Type</th>
                                        <th class="fw-bold text-secondary">Category</th>
                                        <th class="fw-bold text-dark">Brand</th>
                                        <th class="fw-bold text-dark">Product Name</th>
                                        <th class="fw-bold text-secondary">Specs</th>
                                        <th class="fw-bold text-center" style="color: #8B0000;">Price Range</th>
                                    </tr>
                                </thead>
                                <tbody id="reportTableBody">
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
        const provincialData = <?php echo json_encode($reportData); ?>;
        
        let currentPage = 1;
        let rowsPerPage = 50;

        function formatIfExcelDate(val) {
            if (val === null || val === undefined || val === '') return val;
            let strVal = String(val).trim();
            if (/^\d{5}$/.test(strVal)) {
                let num = parseInt(strVal, 10);
                if (num >= 30000 && num <= 65000) {
                    let jsDate = new Date(Math.round((num - 25569) * 86400 * 1000));
                    let mNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
                    return `${mNames[jsDate.getMonth()]} ${jsDate.getDate()}, ${jsDate.getFullYear()}`;
                }
            }
            return val;
        }

        function changeRowsPerPage() {
            rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
            currentPage = 1;
            renderTable();
        }

        function formatPriceHTML(min, max) {
            if (min === null || min === undefined) {
                return "<span class='text-danger fw-bold' style='font-size: 0.85rem;'>NO DATA</span>";
            }
            let minStr = parseFloat(min).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            let maxStr = parseFloat(max).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            
            if (min == max) return "₱ " + minStr;
            return "₱ " + minStr + " - " + maxStr;
        }

        function renderTable() {
            let tbody = document.getElementById('reportTableBody');
            if (!tbody) return; 
            
            let start = (currentPage - 1) * rowsPerPage;
            let end = start + rowsPerPage;
            let paginatedItems = provincialData.slice(start, end);
            
            let html = '';
            let count = start + 1;
            
            if (paginatedItems.length === 0) {
                html = '<tr><td colspan="7" class="text-center py-5 text-secondary">No data found.</td></tr>';
            } else {
                paginatedItems.forEach(row => {
                    let badgeClass = row.type_code === 'PC' ? 'bg-secondary' : 'bg-primary';
                    let safeCat = row.category_name ? row.category_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeBrand = row.brand_name ? row.brand_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeName = row.product_name ? row.product_name.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let safeSpecs = row.specifications ? row.specifications.replace(/</g, "&lt;").replace(/>/g, "&gt;") : '';
                    let priceHtml = formatPriceHTML(row.lowest_price, row.highest_price);

                    html += `<tr>
                        <td class="text-center fw-bold text-secondary bg-light">${count++}</td>
                        <td><span class="badge ${badgeClass}">${row.type_code}</span></td>
                        <td class="text-secondary text-wrap" style="max-width: 150px;">${safeCat}</td>
                        <td class="fw-bold text-wrap" style="max-width: 180px;">${safeBrand}</td>
                        <td class="text-wrap" style="max-width: 250px;">${safeName}</td>
                        <td class="text-secondary text-wrap" style="max-width: 250px;">${safeSpecs}</td>
                        <td class="text-center fw-bold fs-6" style="color: #1a7a2e;">${priceHtml}</td>
                    </tr>`;
                });
            }
            
            tbody.innerHTML = html;
            updatePaginationInfo();
        }

        function updatePaginationInfo() {
            let total = provincialData.length;
            let start = total === 0 ? 0 : ((currentPage - 1) * rowsPerPage) + 1;
            let end = Math.min(currentPage * rowsPerPage, total);
            
            let pageInfo = document.getElementById('pageInfo');
            if (pageInfo) pageInfo.innerText = `Showing ${start} to ${end} of ${total} entries`;
            
            let prevBtn = document.getElementById('prevBtn');
            if (prevBtn) prevBtn.disabled = currentPage === 1;
            
            let nextBtn = document.getElementById('nextBtn');
            if (nextBtn) nextBtn.disabled = end >= total;
        }

        function prevPage() { if (currentPage > 1) { currentPage--; renderTable(); } }
        function nextPage() { if (currentPage * rowsPerPage < provincialData.length) { currentPage++; renderTable(); } }

        document.addEventListener("DOMContentLoaded", () => {
            if (document.getElementById('reportTableBody')) {
                renderTable();
            }
        });

        const rawPreviewData = <?php echo json_encode($previewData['data'] ?? []); ?>;
        let previewCurrentPage = 1;
        let previewRowsPerPage = 50;
        
        let hRow = -1;
        let srpCol = -1;
        let storeStartCol = -1;

        if (rawPreviewData.length > 0) {
            for(let i=0; i < Math.min(30, rawPreviewData.length); i++) {
                if (!rawPreviewData[i]) continue;
                let str = rawPreviewData[i].join(" ").toUpperCase();
                if(str.includes("COMMODITY") || str.includes("BRAND") || str.includes("SPECIFICATION")) {
                    hRow = i; 
                    break; 
                }
            }
            if (hRow === -1) hRow = 0; 

            let headerData = rawPreviewData[hRow];
            let maxCol = -1;
            for(let c=0; c < headerData.length; c++) {
                let header = (headerData[c] || "").toString().toUpperCase();
                if(header.includes("SRP") || header.includes("SUGGESTED")) srpCol = c;
                if(header.includes("TYPE") || header.includes("CATEGO") || header.includes("COMMODITY") || header.includes("PRODUCT") || header.includes("BRAND") || header.includes("SPEC")) {
                    if (c > maxCol) maxCol = c;
                }
                if(header.includes("SRP") && c > maxCol) maxCol = c;
            }
            storeStartCol = maxCol !== -1 ? maxCol + 1 : 6;
        }

        const titleRows = hRow > 0 ? rawPreviewData.slice(0, hRow) : [];
        const headerRow = rawPreviewData.length > 0 ? rawPreviewData[hRow] : [];
        const dataRows = rawPreviewData.length > 0 ? rawPreviewData.slice(hRow + 1) : [];

        function renderPreviewTable() {
            let thead = document.getElementById('previewTableHead');
            let tbody = document.getElementById('previewTableBody');
            if (!thead || !tbody) return;

            let headHtml = '';
            
            if (titleRows.length > 0) {
                titleRows.forEach((r, idx) => {
                    headHtml += '<tr style="background-color: #343a40;">';
                    headHtml += `<th class="text-center text-secondary border-secondary" style="width: 40px; background-color: #212529;">${idx + 1}</th>`;
                    for (let c = 0; c < headerRow.length; c++) {
                        let cellVal = r[c] !== undefined && r[c] !== null ? r[c] : '';
                        cellVal = formatIfExcelDate(cellVal); 
                        headHtml += `<th class="fw-normal text-light border-secondary" style="white-space: nowrap;">${cellVal}</th>`;
                    }
                    headHtml += '</tr>';
                });
            }

            headHtml += '<tr style="background-color: #212529;">';
            headHtml += `<th class="text-center text-secondary border-secondary" style="width: 40px;">${hRow + 1}</th>`;
            if (headerRow) {
                for (let c = 0; c < headerRow.length; c++) {
                    let cell = headerRow[c];
                    cell = formatIfExcelDate(cell); 
                    headHtml += `<th class="border-secondary text-white" style="white-space: nowrap;">${cell !== null && cell !== undefined ? cell : ''}</th>`;
                }
            }
            headHtml += '</tr>';
            
            thead.innerHTML = headHtml;

            let start = (previewCurrentPage - 1) * previewRowsPerPage;
            let end = start + previewRowsPerPage;
            let paginatedItems = dataRows.slice(start, end);

            let html = '';
            let baseRowOffset = hRow + 2; 
            let count = start + baseRowOffset;

            if (paginatedItems.length === 0) {
                html = `<tr><td colspan="${(headerRow.length || 5) + 1}" class="text-center py-5 text-secondary">No readable data found in this sheet.</td></tr>`;
            } else {
                paginatedItems.forEach(row => {
                    html += `<tr><td class="text-center fw-bold bg-light text-secondary">${count++}</td>`;
                    
                    let srpRaw = srpCol !== -1 && row[srpCol] !== null && row[srpCol] !== undefined ? parseFloat(String(row[srpCol]).replace(/[^0-9.]/g, '')) : null;

                    for (let c = 0; c < headerRow.length; c++) { 
                        let cellVal = row[c] !== undefined && row[c] !== null ? row[c] : '';
                        let cellStr = String(cellVal).trim();
                        
                        let textColorClass = "";
                        
                        let colHeadStr = "";
                        if (hRow >= 0 && rawPreviewData[hRow] && rawPreviewData[hRow][c]) colHeadStr = String(rawPreviewData[hRow][c]).trim().toUpperCase();
                        if (!colHeadStr && hRow - 1 >= 0 && rawPreviewData[hRow - 1] && rawPreviewData[hRow - 1][c]) colHeadStr = String(rawPreviewData[hRow - 1][c]).trim().toUpperCase();
                        if (!colHeadStr && hRow - 2 >= 0 && rawPreviewData[hRow - 2] && rawPreviewData[hRow - 2][c]) colHeadStr = String(rawPreviewData[hRow - 2][c]).trim().toUpperCase();
                        
                        let isExcludedCol = false;
                        if (colHeadStr) {
                            isExcludedCol = ['MIN', 'MAX', 'MODE', 'AVERAGE', 'NAN'].some(kw => colHeadStr.includes(kw));
                        }
                        
                        let isStoreCol = (c >= storeStartCol) && !isExcludedCol;
                        
                        if (isStoreCol && srpRaw !== null && !isNaN(srpRaw) && cellStr !== "") {
                            let priceRaw = parseFloat(cellStr.replace(/[^0-9.]/g, ''));
                            if (!isNaN(priceRaw) && priceRaw > 0) {
                                if (priceRaw > srpRaw) {
                                    textColorClass = "text-danger fw-bold"; 
                                } else {
                                    textColorClass = "text-success fw-bold"; 
                                }
                            }
                        }

                        if (textColorClass) {
                            html += `<td class="${textColorClass}">${cellVal}</td>`;
                        } else {
                            html += `<td>${cellVal}</td>`;
                        }
                    }
                    html += '</tr>';
                });
            }

            tbody.innerHTML = html;
            updatePreviewPaginationInfo();
        }

        function updatePreviewPaginationInfo() {
            let total = dataRows.length;
            let start = total === 0 ? 0 : ((previewCurrentPage - 1) * previewRowsPerPage) + 1;
            let end = Math.min(previewCurrentPage * previewRowsPerPage, total);
            
            let pageInfo = document.getElementById('previewPageInfo');
            if (pageInfo) pageInfo.innerText = `Showing ${start} to ${end} of ${total} entries`;
            
            let prevBtn = document.getElementById('previewPrevBtn');
            if (prevBtn) prevBtn.disabled = previewCurrentPage === 1;
            
            let nextBtn = document.getElementById('previewNextBtn');
            if (nextBtn) nextBtn.disabled = end >= total;
        }

        function previewPrevPage() { if (previewCurrentPage > 1) { previewCurrentPage--; renderPreviewTable(); } }
        function previewNextPage() { if (previewCurrentPage * previewRowsPerPage < dataRows.length) { previewCurrentPage++; renderPreviewTable(); } }
        function changePreviewRowsPerPage() {
            previewRowsPerPage = parseInt(document.getElementById('previewRowsPerPage').value);
            previewCurrentPage = 1;
            renderPreviewTable();
        }

        document.addEventListener("DOMContentLoaded", () => {
            if (document.getElementById('previewTableBody')) {
                renderPreviewTable();
            }
        });


        function filterUploadedFiles() {
            let search = document.getElementById("searchFile").value.toLowerCase();
            let prov = document.getElementById("filterProv").value;
            let dateVal = document.getElementById("filterDate").value;

            let rows = document.querySelectorAll(".upload-row");
            rows.forEach(row => {
                let fileName = row.querySelector(".file-name-cell").innerText.toLowerCase();
                let rowProv = row.getAttribute("data-province");
                let rowDate = row.getAttribute("data-date");

                let matchSearch = fileName.includes(search);
                let matchProv = (prov === "All" || rowProv === prov);
                let matchDate = (dateVal === "" || rowDate === dateVal);

                if (matchSearch && matchProv && matchDate) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }

        function clearArchiveFilters() {
            document.getElementById('searchFile').value = '';
            document.getElementById('filterProv').value = 'All';
            document.getElementById('filterDate').value = '';
            filterUploadedFiles();
        }

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

        function confirmLinkAction(e, url, title, message, colorClass, btnText) {
            e.preventDefault();
            showConfirmModal(title, message, colorClass, btnText, function() {
                window.location.href = url;
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

        async function buildAndNavigateReport(prov_id, year, btn) {
            showConfirmModal('Generate Report', 'Are you sure you want to generate a new report for this data?', 'success', '<i class="bi  bi-journal-check"></i> Generate', async function() {
                let origHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Generating...';
                btn.disabled = true;
                
                let fd = new FormData();
                fd.append('action', 'build_and_save_report');
                fd.append('province_id', prov_id);
                fd.append('year', year);
                
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                    let data = await res.json();
                    if(data.status === 'success') {
                        window.location.href = `provincial.php?part=3&province_id=${prov_id}&year=${year}`;
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

        function exportFullReportToExcel() {
            if(!fullExportData || fullExportData.length === 0) {
                alert("There is no data to export for this filter selection!");
                return;
            }
            showConfirmModal('Export to Excel', 'Are you sure you want to generate and download this report?', 'primary', '<i class="bi bi-download"></i> Export', function() {
                const btn = document.getElementById('exportReportBtn');
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Exporting...';
                btn.disabled = true;

                setTimeout(() => {
                    try {
                        let wb = XLSX.utils.book_new();

                        let bnRows = [];
                        let pcRows = [];
                        let headers = ["#", "Type", "Category", "Brand", "Product Name", "Specs", "Price Range"];
                        
                        bnRows.push(headers);
                        pcRows.push(headers);

                        let bnCounter = 1;
                        let pcCounter = 1;

                        fullExportData.forEach(row => {
                            let priceStr = "NO DATA";
                            if (row.lowest_price !== null) {
                                if (row.lowest_price == row.highest_price) {
                                    priceStr = "₱ " + parseFloat(row.lowest_price).toFixed(2);
                                } else {
                                    priceStr = "₱ " + parseFloat(row.lowest_price).toFixed(2) + " - " + parseFloat(row.highest_price).toFixed(2);
                                }
                            }

                            let r = ["", row.type_code, row.category_name, row.brand_name, row.product_name, row.specifications, priceStr];

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

                        let filename = `<?= $safe_prov_name ?>_Full_Report_<?= $filter_year ?>.xlsx`;
                        XLSX.writeFile(wb, filename);

                    } catch(e) {
                        alert("Export failed: " + e.message);
                    } finally {
                        btn.innerHTML = originalHTML;
                        btn.disabled = false;
                    }
                }, 300);
            });
        }

        // =====================================================================
        // NEW FILE UPLOAD HANDLER: JS EXTRACTS SRP DATE & SENDS CHUNKS (FAST)
        // =====================================================================
        document.getElementById('fileInput')?.addEventListener('change', function(e) {
            let file = e.target.files[0];
            if(!file) return;

            document.getElementById('uploadStatus').classList.remove('d-none');
            const statusText = document.getElementById('statusText');

            statusText.innerText = "Step 1: Uploading file...";
            let formData = new FormData(document.getElementById('uploadForm'));
            formData.append('action', 'upload_file_only');
            
            let yearMatch = file.name.match(/(20\d{2})/);
            if(yearMatch) formData.set('target_year', yearMatch[1]);
            
            fetch('ajax_handler.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(uploadData => {
                if(uploadData.status !== 'success') {
                    alert("Upload Error: " + (uploadData.message || "Unknown server error."));
                    location.reload(); return;
                }

                statusText.innerText = "Step 2: Extracting 100% of Master List... (Please wait a few seconds)";
                
                setTimeout(() => {
                    const reader = new FileReader();
                    
                    reader.onload = async function(e) {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, {type: 'array'});
                        let allFlatData = [];
                        let extractedSrpDate = null; 

                        workbook.SheetNames.forEach(sheetName => {
                            let sn = sheetName.toLowerCase();
                            if(sn.includes('instruction') || sn.includes('summary')) return;
                            
                            const sheet = workbook.Sheets[sheetName];
                            const jData = XLSX.utils.sheet_to_json(sheet, {
                                header: 1, 
                                defval: "", 
                                blankrows: false, 
                                raw: false, 
                                dateNF: 'mmmm d, yyyy'
                            }); 

                            let hRow = -1; 
                            for(let i=0; i < Math.min(30, jData.length); i++) {
                                if (!jData[i]) continue;
                                let str = jData[i].join(" ").toUpperCase();
                                if(str.includes("COMMODITY") || str.includes("BRAND") || str.includes("SPECIFICATION")) {
                                    hRow = i; 
                                    
                                    // --- NEW, BULLETPROOF REGEX DATE EXTRACTION ---
                                    if (!extractedSrpDate) { 
                                        for(let c=0; c < jData[i].length; c++) {
                                            let val = String(jData[i][c] || "").trim();
                                            if(val.toUpperCase().includes("SRP")) {
                                                // Hunts for patterns like "01 FEB 2025" or "FEB 01, 2025" anywhere in the cell
                                                let dateMatch = val.match(/(\d{1,2}\s+[a-zA-Z]{3,}\s+\d{4}|[a-zA-Z]{3,}\s+\d{1,2},?\s+\d{4})/);
                                                if (dateMatch) {
                                                    let d = new Date(dateMatch[0]);
                                                    if (!isNaN(d)) {
                                                        extractedSrpDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
                                                        break; 
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    // ----------------------------------------------
                                    break; 
                                }
                            }
                            if(hRow === -1) return; 

                            function normalizeMonth(m) {
                                if(!m) return "Unknown";
                                m = m.toLowerCase();
                                if(m.startsWith('jan')) return "January";
                                if(m.startsWith('feb')) return "February";
                                if(m.startsWith('mar')) return "March";
                                if(m.startsWith('apr')) return "April";
                                if(m.startsWith('may')) return "May";
                                if(m.startsWith('jun')) return "June";
                                if(m.startsWith('jul')) return "July";
                                if(m.startsWith('aug')) return "August";
                                if(m.startsWith('sep')) return "September";
                                if(m.startsWith('oct')) return "October";
                                if(m.startsWith('nov')) return "November";
                                if(m.startsWith('dec')) return "December";
                                return "Unknown";
                            }

                            // === PRE-SCAN GLOBALS START ===
                            let globalYear = parseInt(uploadData.target_year) || new Date().getFullYear();
                            let globalMonth = "Unknown";
                            let globalWeek = 1;

                            let fileMonthMatch = file.name.match(/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\b/i);
                            if (fileMonthMatch) globalMonth = normalizeMonth(fileMonthMatch[1]);

                            let sheetMonthMatch = sheetName.match(/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\b/i);
                            if (sheetMonthMatch) globalMonth = normalizeMonth(sheetMonthMatch[1]);

                            for(let sR = 0; sR <= hRow; sR++) {
                                for(let scanC = 0; scanC < jData[sR].length; scanC++) {
                                    let cellTxt = (jData[sR][scanC] || "").toString().trim();
                                    if(!cellTxt) continue;
                                    
                                    if(cellTxt.toUpperCase().includes("PRICE FREEZE")) continue;
                                    
                                    let yMatch = cellTxt.match(/\b(20[2-3]\d)\b/);
                                    if(yMatch) globalYear = parseInt(yMatch[1]);
                                    
                                    let mMatch = cellTxt.match(/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\b/i);
                                    if(mMatch) globalMonth = normalizeMonth(mMatch[1]);
                                    
                                    let wM = cellTxt.match(/Week\s*(\d+)/i);
                                    if(wM) globalWeek = parseInt(wM[1]);

                                    let mmddyyyy = cellTxt.match(/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/);
                                    if (mmddyyyy) {
                                        let mNum = parseInt(mmddyyyy[1]);
                                        let dNum = parseInt(mmddyyyy[2]);
                                        let yNum = parseInt(mmddyyyy[3]);
                                        
                                        if (mNum > 12 && dNum <= 12) { let t = mNum; mNum = dNum; dNum = t; }
                                        else if (mNum > 1000) { yNum = mNum; mNum = parseInt(mmddyyyy[2]); dNum = parseInt(mmddyyyy[3]); }
                                        
                                        if (yNum < 100) yNum += 2000;
                                        if (mNum >= 1 && mNum <= 12) {
                                            const mNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
                                            globalMonth = mNames[mNum - 1];
                                            globalYear = yNum;
                                        }
                                    }
                                }
                            }
                            // === PRE-SCAN GLOBALS END ===

                            let colMap = { type: -1, cat: -1, prod: -1, brand: -1, specs: -1, srp: -1 };
                            let maxCol = -1;
                            
                            let headerLimit = Math.min(jData[hRow].length, 50); 
                            
                            for(let c=0; c < headerLimit; c++) {
                                let header = (jData[hRow][c] || "").toString().toUpperCase();
                                if(!header) continue;
                                if(header.includes("TYPE") && !header.includes("COMMODITY")) colMap.type = c;
                                else if(header.includes("CATEGO")) colMap.cat = c;
                                else if(header.includes("COMMODITY") || header.includes("PRODUCT")) colMap.prod = c;
                                else if(header.includes("BRAND")) colMap.brand = c;
                                else if(header.includes("SPEC")) colMap.specs = c;
                                else if(header.includes("SRP") || header.includes("SUGGESTED")) colMap.srp = c;
                            }
                            
                            if (colMap.prod === -1) colMap.prod = 2; 
                            
                            for(let key in colMap) {
                                if(colMap[key] > maxCol) maxCol = colMap[key];
                            }
                            let storeStartCol = maxCol !== -1 ? maxCol + 1 : 6;

                            let storesMap = {};
                            let emptyCols = 0;
                            let storeCount = 0;
                            
                            let lastYear = globalYear;
                            let lastMonth = globalMonth;
                            let lastWeek = globalWeek;
                            let lastDateLabel = "";

                            for(let c = storeStartCol; c < jData[hRow].length; c++) {
                                
                                let st = "";
                                if (hRow >= 0 && jData[hRow][c]) st = jData[hRow][c].toString().trim();
                                if (!st && hRow - 1 >= 0 && jData[hRow - 1][c]) st = jData[hRow - 1][c].toString().trim();
                                if (!st && hRow - 2 >= 0 && jData[hRow - 2][c]) st = jData[hRow - 2][c].toString().trim();

                                if(!st || ['MIN','MAX','MODE','AVERAGE','NAN'].includes(st.toUpperCase()) || st.toUpperCase().includes('WEEK')) {
                                    emptyCols++;
                                    if (emptyCols > 10) break; 
                                    continue;
                                }

                                emptyCols = 0;
                                storeCount++;
                                
                                let tempYear = lastYear;
                                let tempMonth = lastMonth;
                                let tempWeek = lastWeek;
                                let tempDateLabel = lastDateLabel;
                                let foundDateInfo = false;

                                for(let sR = 0; sR <= hRow; sR++) {
                                    let cTxt = (jData[sR][c] || "").toString().trim();
                                    if (!cTxt) continue;

                                    if(cTxt.toUpperCase().includes("PRICE FREEZE")) continue;

                                    let yMatch = cTxt.match(/\b(20[2-3]\d)\b/);
                                    if(yMatch) { tempYear = parseInt(yMatch[1]); foundDateInfo = true; }

                                    let mMatch = cTxt.match(/\b(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)\b/i);
                                    if(mMatch) { tempMonth = normalizeMonth(mMatch[1]); foundDateInfo = true; }

                                    let mmddyyyy = cTxt.match(/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/);
                                    if (mmddyyyy) {
                                        let mNum = parseInt(mmddyyyy[1]);
                                        let dNum = parseInt(mmddyyyy[2]);
                                        let yNum = parseInt(mmddyyyy[3]);
                                        
                                        if (mNum > 12 && dNum <= 12) { let t = mNum; mNum = dNum; dNum = t; }
                                        else if (mNum > 1000) { yNum = mNum; mNum = parseInt(mmddyyyy[2]); dNum = parseInt(mmddyyyy[3]); }
                                        
                                        if (yNum < 100) yNum += 2000;
                                        if (mNum >= 1 && mNum <= 12) {
                                            const mNames = ["January","February","March","April","May","June","July","August","September","October","November","December"];
                                            tempMonth = mNames[mNum - 1];
                                            tempYear = yNum;
                                            tempDateLabel = `${tempMonth} ${dNum}, ${tempYear}`;
                                            tempWeek = Math.ceil(dNum / 7);
                                            if(tempWeek < 1) tempWeek = 1;
                                            if(tempWeek > 5) tempWeek = 5;
                                            foundDateInfo = true;
                                        }
                                    }

                                    let rangeMatch = cTxt.match(/\b(\d{1,2})\s*(?:-|to|and|&)\s*(\d{1,2})\b/i);
                                    if (!mmddyyyy && rangeMatch && !cTxt.toUpperCase().includes('WEEK')) {
                                        let startDay = parseInt(rangeMatch[1]);
                                        let endDay = parseInt(rangeMatch[2]);
                                        if (startDay <= 31 && endDay <= 31) {
                                            tempDateLabel = `${tempMonth !== 'Unknown' ? tempMonth + ' ' : ''}${startDay}-${endDay}, ${tempYear}`;
                                            tempWeek = Math.ceil(startDay / 7);
                                            if(tempWeek < 1) tempWeek = 1;
                                            if(tempWeek > 5) tempWeek = 5;
                                            foundDateInfo = true;
                                        }
                                    } 
                                    else if (!mmddyyyy && /Week\s*(\d+)/i.test(cTxt)) {
                                        let wM = cTxt.match(/Week\s*(\d+)/i);
                                        if(wM) { 
                                            tempWeek = parseInt(wM[1]); 
                                            tempDateLabel = `Week ${tempWeek} of ${tempMonth} ${tempYear}`;
                                            foundDateInfo = true; 
                                        }
                                    } 
                                    else if (!mmddyyyy && !rangeMatch) {
                                        let exactDateMatch = cTxt.match(/\b([A-Za-z]+)\s+(\d{1,2})\b/);
                                        if (exactDateMatch && !cTxt.includes("-") && !cTxt.includes("to")) {
                                            let parsedM = normalizeMonth(exactDateMatch[1]);
                                            if (parsedM !== "Unknown") {
                                                let d = parseInt(exactDateMatch[2]);
                                                if (d <= 31) {
                                                    tempMonth = parsedM;
                                                    tempDateLabel = `${tempMonth} ${d}, ${tempYear}`;
                                                    tempWeek = Math.ceil(d / 7);
                                                    if(tempWeek < 1) tempWeek = 1;
                                                    if(tempWeek > 5) tempWeek = 5;
                                                    foundDateInfo = true;
                                                }
                                            }
                                        }
                                    }
                                }

                                if (foundDateInfo) {
                                    lastYear = tempYear;
                                    lastMonth = tempMonth;
                                    lastWeek = tempWeek;
                                    lastDateLabel = tempDateLabel;
                                }

                                if (!lastDateLabel || lastDateLabel.trim() === "") {
                                    lastDateLabel = `Week ${lastWeek} of ${lastMonth} ${lastYear}`;
                                }

                                storesMap[c] = { store: st.substring(0,145), year: lastYear, month: lastMonth, week: lastWeek, date_label: lastDateLabel };
                            }

                            let sheetType = 'BN';
                            if (sn.includes("prime") || sn.includes("pc") || sn.includes("commodity")) {
                                sheetType = 'PC';
                            }

                            for(let r = hRow + 1; r < jData.length; r++) {
                                if (!jData[r] || jData[r].length === 0) continue;
                                
                                let prod = colMap.prod !== -1 && jData[r][colMap.prod] ? jData[r][colMap.prod].toString().trim() : null;
                                if(!prod || prod.toUpperCase() === 'COMMODITY' || prod.toUpperCase() === 'PRODUCT CATEGORY') continue;

                                let currentType = colMap.type !== -1 && jData[r][colMap.type] ? jData[r][colMap.type].toString().trim().toUpperCase() : null;
                                let currentCat = colMap.cat !== -1 && jData[r][colMap.cat] ? jData[r][colMap.cat].toString().trim() : null;
                                let currentBrand = colMap.brand !== -1 && jData[r][colMap.brand] ? jData[r][colMap.brand].toString().trim() : null;
                                let sRaw = colMap.specs !== -1 && jData[r][colMap.specs] ? jData[r][colMap.specs].toString().trim() : "N/A";
                                
                                let srpStr = colMap.srp !== -1 && jData[r][colMap.srp] ? jData[r][colMap.srp].toString().replace(/[^0-9.]/g, '') : null;
                                let srpRaw = (srpStr && !isNaN(srpStr)) ? parseFloat(srpStr) : null;

                                let tCode = sheetType;
                                if (currentType) {
                                    if (currentType.includes('PRIME') || currentType.includes('PC')) tCode = 'PC';
                                    else if (currentType.includes('BASIC') || currentType.includes('BN')) tCode = 'BN';
                                }
                                let tName = (tCode === 'PC') ? 'Prime Commodity' : 'Basic Necessity';

                                for(let col in storesMap) {
                                    let prStr = jData[r][col]?.toString().replace(/[^0-9.]/g, '');
                                    let prVal = (prStr && !isNaN(prStr) && parseFloat(prStr) > 0) ? parseFloat(prStr) : null;
                                    
                                    allFlatData.push({
                                        type_code: tCode, type_name: tName,
                                        cat: currentCat || "Uncategorized", 
                                        brand: currentBrand || "No Brand",
                                        prod: prod, specs: sRaw, srp: srpRaw,
                                        price: prVal, 
                                        store: storesMap[col].store, year: storesMap[col].year, 
                                        month: storesMap[col].month, week: storesMap[col].week, 
                                        date_label: storesMap[col].date_label
                                    });
                                }
                            }
                        });

                        if(allFlatData.length === 0) {
                            alert("No valid products found. Ensure the file follows the format template.");
                            location.reload(); return;
                        }

                        let chunkSize = 250; 
                        let totalChunks = Math.ceil(allFlatData.length / chunkSize);
                        let hasError = false;
                        
                        async function saveChunksSequentially() {
                            for(let i=0; i < allFlatData.length; i += chunkSize) {
                                let currentChunk = Math.floor(i/chunkSize) + 1;
                                statusText.innerText = `Step 3: Saving batch ${currentChunk} of ${totalChunks} to database...`;
                                
                                let chunk = allFlatData.slice(i, i+chunkSize);
                                let saveRes = await fetch('ajax_handler.php', {
                                    method: 'POST',
                                    headers: {'Content-Type': 'application/json'},
                                    body: JSON.stringify({
                                        action: 'save_chunk',
                                        file_id: uploadData.file_id,
                                        province_id: uploadData.province_id,
                                        srp_date_label: extractedSrpDate,
                                        data: chunk
                                    })
                                });
                                
                                let saveData = await saveRes.json();
                                
                                if(saveData.status !== 'success') {
                                    alert("Database Error Details:\n" + saveData.message);
                                    hasError = true;
                                    break;
                                }
                            }

                            if (!hasError) {
                                document.getElementById('spinnerIcon').classList.remove('spin');
                                document.getElementById('spinnerIcon').classList.replace('bi-arrow-repeat', 'bi-check-circle');
                                statusText.innerText = `Success! Fully extracted and saved ${allFlatData.length} records. Reloading...`;
                                setTimeout(() => { window.location.reload(); }, 1500);
                            } else {
                                statusText.innerText = `Extraction failed. Check the alert box.`;
                                document.getElementById('spinnerIcon').classList.remove('spin');
                                document.getElementById('spinnerIcon').classList.replace('bi-arrow-repeat', 'bi-exclamation-triangle-fill');
                            }
                        }
                        
                        saveChunksSequentially();

                    };
                    reader.readAsArrayBuffer(file);
                }, 100);
            })
            .catch(error => {
                alert("Upload process failed. Check console.");
                console.error(error);
                location.reload();
            });
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
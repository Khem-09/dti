<?php
    // CRITICAL SPEED & STABILITY FIX: Start an Output Buffer immediately. 
    // This catches ANY hidden warnings or stray whitespaces from database.php/admin.php 
    // before they can corrupt the JSON response.
    ob_start();

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

    // FIX: Deep scrub of all associated records when a file is deleted
    if (isset($_GET['delete_file_id'])) {
        $del_id = $_GET['delete_file_id'];
        
        try {
            $db->beginTransaction();
            // Destroy all ghost data tied to this file first
            $stmtScrub = $db->prepare("DELETE FROM price_records WHERE file_id = ?");
            $stmtScrub->execute([$del_id]);
            
            // Delete the file record
            $stmt = $db->prepare("DELETE FROM uploaded_files WHERE id = ?");
            $stmt->execute([$del_id]);
            $db->commit();
            
            echo "<script>alert('File and all its associated price data have been completely scrubbed from the system.'); window.location.href='provincial.php';</script>";
        } catch (Exception $e) {
            $db->rollBack();
            echo "<script>alert('Failed to cleanly delete file data.'); window.location.href='provincial.php';</script>";
        }
        exit();
    }

    // ------------------------------------------------------------------------------------------
    // THE SPEED FIX: ONLY run the heavy database/excel queries if requested via background AJAX
    // ------------------------------------------------------------------------------------------
    if (isset($_GET['fetch_ajax_data'])) {
        $responseData = [];
        $partAjax = $_GET['part'] ?? 1;

        try {
            if ($partAjax == 3 && !empty($_GET['province_id'])) {
                $p_prov = $_GET['province_id'];
                $p_year = $_GET['year'] ?? '';
                $p_month = $_GET['month'] ?? '';
                $p_week = $_GET['week'] ?? '';
                $p_type = $_GET['type'] ?? 'All';

                $reportData = $admin->getProvincialReport($p_prov, $p_year, $p_month, $p_week, $p_type);
                $exportData = $admin->getProvincialReport($p_prov, $p_year, $p_month, $p_week, 'All');
                $responseData = ['reportData' => $reportData, 'exportData' => $exportData];
                
            } elseif ($partAjax == 2 && isset($_GET['file_id'])) {
                $f_id = $_GET['file_id'];
                $t_sheet = !empty($_GET['sheet']) ? $_GET['sheet'] : null;
                $previewData = $admin->getExcelPreview($f_id, $t_sheet);
                
                if ($previewData === false || $previewData === null) {
                    $responseData = ['error' => 'Failed to read data. The Excel file might be heavily corrupted or empty.'];
                } else {
                    $responseData = $previewData;
                }
            }
        } catch (Exception $e) {
            $responseData = ['error' => 'System Error: ' . $e->getMessage()];
        }

        // Deep clean the buffer to destroy any stray characters or warnings
        // ensuring absolutely nothing but pure JSON is outputted to JavaScript.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        
        $jsonOutput = json_encode($responseData);
        // Fallback if the Excel file contains weirdly encoded symbols breaking the JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['error' => 'Data encoding error: ' . json_last_error_msg()]);
        } else {
            echo $jsonOutput;
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

    $current_preview_prov = 1;
    $current_preview_year = date('Y');
    if ($part == 2 && isset($_GET['file_id'])) {
        $stmt = $db->prepare("SELECT province_id, target_year FROM uploaded_files WHERE id = ?");
        $stmt->execute([$_GET['file_id']]);
        if ($fileRow = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $current_preview_prov = $fileRow['province_id'];
            $current_preview_year = $fileRow['target_year'];
        }
    }

    // -------------------------------------------------------------
    // PULL IN THE MASTER HTML LAYOUT (Head, Top Nav, Sidebars)
    // -------------------------------------------------------------
    include '../includes/navigations.php';
?>

<style>
    .spin { animation: spin 1.5s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<div class="inner-card shadow-sm bg-white p-3 p-md-4 rounded border">
    
    <div id="part1" class="<?php echo ($part == 1) ? '' : 'd-none'; ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="section-title m-0">Upload File</h4>
            <button class="btn btn-sm btn-primary shadow-sm fw-bold" data-bs-toggle="modal" data-bs-target="#manageProvincesModal">
                <i class="bi bi-map"></i> Manage Provinces
            </button>
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
                        <th>Upload Timeline</th>
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
                                <td class="text-secondary" style="font-size: 0.85rem;">
                                    <div class="d-flex flex-column">
                                        <span><i class="bi bi-clock-history me-1"></i> <b class="text-dark">Started:</b> <?= date('M d, Y h:i A', strtotime($file['uploaded_at'])) ?></span>
                                        <?php if(isset($file['finished_at']) && !empty($file['finished_at'])): ?>
                                            <span class="text-success"><i class="bi bi-check-all me-1"></i> <b>Finished:</b> <?= date('M d, Y h:i A', strtotime($file['finished_at'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <a href="provincial.php?part=2&file_id=<?= $file['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm px-2 px-md-3">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <button type="button" class="btn btn-sm btn-success shadow-sm px-2 px-md-3" onclick="buildAndNavigateReport(<?= $file['province_id'] ?>, <?= $file['target_year'] ?>, this)">
                                            <i class="bi bi-journal-check"></i> Generate
                                        </button>
                                        <a href="#" class="btn btn-sm btn-outline-danger shadow-sm px-2 px-md-3" onclick="confirmLinkAction(event, 'provincial.php?delete_file_id=<?= $file['id'] ?>', 'Delete File', 'Are you sure you want to completely scrub this uploaded file and all its price records from the database?', 'danger', '<i class=\'bi bi-trash\'></i> Delete')">
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
            
            <div class="d-flex align-items-center gap-3">
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
        </div>
        <div class="red-line mb-4" style="height: 3px; background-color: #8B0000; width: 100%;"></div>

        <div class="bg-white border rounded shadow-sm p-3 p-md-4 overflow-hidden">
            <div id="previewAlertContainer"></div>
            
            <div id="sheetButtonsContainer" class="mb-3 pb-3 border-bottom d-flex align-items-center flex-wrap gap-2 d-none"></div>

            <div id="previewTitleContainer" class="mb-3 text-center text-secondary fw-bold" style="font-size: 0.95rem;"></div>

            <div class="table-responsive border rounded" style="max-height: 550px; overflow: auto;">
                <table class="table table-bordered table-hover table-sm text-nowrap align-middle mb-0" id="previewTable" style="font-size: 0.85rem;">
                    <thead class="table-dark sticky-top" id="previewTableHead" style="z-index: 2; top: 0;">
                    </thead>
                    <tbody id="previewTableBody">
                        <tr><td colspan="100%" class="text-center py-5 text-secondary"><i class="bi bi-hourglass-split spin me-2 fs-5"></i> <span class="fw-bold">Loading preview data...</span></td></tr>
                    </tbody>
                </table>
            </div>

            <div id="previewPaginationContainer" class="d-flex flex-column flex-sm-row justify-content-between align-items-center mt-3 px-2 gap-2 d-none">
                <span class="text-secondary fw-bold" id="previewPageInfo">Loading data...</span>
                <div class="d-flex gap-2">
                    <div class="btn-group shadow-sm">
                        <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="previewPrevPage()" id="previewPrevBtn" disabled>Previous</button>
                        <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="previewNextPage()" id="previewNextBtn" disabled>Next</button>
                    </div>
                </div>
            </div>
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
                       class="btn btn-sm <?= ($filter_type == 'BN') ? 'btn-primary fw-bold' : 'btn-outline-primary' ?>">BN</a>
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
            
            <div class="d-inline-flex align-items-center bg-white border rounded-pill px-3 py-2 shadow-sm" style="border-color: #dee2e6 !important;">
                <i class="bi bi-calendar-event text-primary fs-5 me-2"></i>
                <span class="text-secondary fw-bold me-2">Period:</span> 
                <span class="fw-bold text-dark" style="font-size: 1.05rem;">
                    <?php if (empty($availableYears)): ?>
                        [ No Data Available ]
                    <?php elseif (empty($filter_month)): ?>
                        <?= htmlspecialchars($filter_year) ?> (12-Month Summary)
                    <?php elseif (empty($filter_week)): ?>
                        <?= htmlspecialchars($filter_month) ?> <?= htmlspecialchars($filter_year) ?> (Monthly Summary)
                    <?php else: ?>
                        <?= htmlspecialchars($filter_month) ?> <?= htmlspecialchars($filter_year) ?> - <?= htmlspecialchars($selected_week_label) ?> (<?= htmlspecialchars($selected_week_num) ?>)
                    <?php endif; ?>
                </span>
            </div>
            
            <button id="exportReportBtn" class="btn btn-primary shadow-sm px-4" onclick="exportFullReportToExcel()">
                <i class="bi bi-download"></i> Export
            </button>
        </div>

        <div class="table-responsive bg-white shadow-sm rounded border" style="max-height: 550px; overflow-y: auto;">
            <table class="table table-hover align-middle mb-0 text-nowrap" id="reportTable">
                <thead class="table-light sticky-top" style="z-index: 2; top: 0;">
                    <tr style="border-bottom: 2px solid #8B0000;">
                        <th class="fw-bold text-secondary text-center" style="width: 50px; background-color: #f8f9fa;">#</th>
                        <th class="fw-bold text-secondary" style="background-color: #f8f9fa;">Type</th>
                        <th class="fw-bold text-secondary" style="background-color: #f8f9fa;">Category</th>
                        <th class="fw-bold text-dark" style="background-color: #f8f9fa;">Brand</th>
                        <th class="fw-bold text-dark" style="background-color: #f8f9fa;">Product Name</th>
                        <th class="fw-bold text-secondary" style="background-color: #f8f9fa;">Specs</th>
                        <th class="fw-bold text-center" style="color: #8B0000; background-color: #f8f9fa;">Price Range</th>
                    </tr>
                </thead>
                <tbody id="reportTableBody">
                    <tr><td colspan="7" class="text-center py-5 text-secondary"><i class="bi bi-hourglass-split spin me-2 fs-5"></i> <span class="fw-bold">Loading data...</span></td></tr>
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

<div class="modal fade" id="uploadProgressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0A0A3A; border-bottom: 5px solid #0A0A3A;">
            <div class="modal-body p-5 text-center">
                <i class="bi bi-hourglass-split spin text-primary mb-3 d-inline-block" id="modalSpinnerIcon" style="font-size: 3.5rem;"></i>
                <h5 class="fw-bold mb-4" style="color: #0A0A3A;" id="modalStatusText">Initializing upload...</h5>
                <div class="alert alert-warning small p-3 mb-0 fw-bold border-warning text-start" style="background-color: #fff3cd; color: #856404; line-height: 1.5;">
                    <i class="bi bi-exclamation-triangle-fill fs-5 me-2" style="float: left; margin-top: -2px;"></i> 
                    Please do not close this window, click anything else, or refresh your browser until the process successfully completes.
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="manageProvincesModal" tabindex="-1" aria-hidden="true" style="z-index: 1055;">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0A0A3A;">
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <h4 class="modal-title fw-bold" style="color: #0A0A3A;"><i class="bi bi-map me-2"></i>Manage Provinces</h4>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-secondary small m-0">View, edit aliases, or add new provinces to the Region IX directory.</p>
                    <button class="btn btn-sm btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#addProvinceModal">
                        <i class="bi bi-plus-circle"></i> Add New
                    </button>
                </div>
                
                <div class="table-responsive border rounded shadow-sm">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-light text-secondary">
                            <tr>
                                <th style="width: 50px;">ID</th>
                                <th>Province Name</th>
                                <th>Filename Aliases</th>
                                <th class="text-center" style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="provincesTableBody">
                            </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3 px-1 gap-2">
                    <span class="text-secondary fw-bold small" id="provPageInfo">Loading...</span>
                    <div class="btn-group shadow-sm">
                        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="prevProvPage()" id="provPrevBtn" disabled>Prev</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="nextProvPage()" id="provNextBtn" disabled>Next</button>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addProvinceModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #198754;">
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <h5 class="modal-title fw-bold text-success"><i class="bi bi-plus-circle me-2"></i>Add Province</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="addProvinceForm" onsubmit="saveNewProvince(event)">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Province Name</label>
                        <input type="text" id="newProvinceName" class="form-control bg-light" placeholder="e.g. Zamboanga Sibugay" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary">Aliases (Comma-separated)</label>
                        <input type="text" id="newProvinceAliases" class="form-control bg-light" placeholder="e.g. zamsur, zds, sur">
                        <div class="form-text small mt-1">These words help the system detect the province from uploaded filenames.</div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 pt-2">
                        <button type="button" class="btn btn-outline-secondary fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#manageProvincesModal">Back</button>
                        <button type="submit" id="saveProvinceBtn" class="btn btn-success fw-bold px-4 shadow-sm">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editProvinceModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0d6efd;">
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <h5 class="modal-title fw-bold text-primary"><i class="bi bi-pencil-square me-2"></i>Edit Province</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="editProvinceForm" onsubmit="updateProvince(event)">
                    <input type="hidden" id="editProvinceId">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-secondary">Province Name</label>
                        <input type="text" id="editProvinceName" class="form-control bg-light" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-secondary">Aliases (Comma-separated)</label>
                        <input type="text" id="editProvinceAliases" class="form-control bg-light">
                    </div>
                    <div class="d-flex justify-content-end gap-2 pt-2">
                        <button type="button" class="btn btn-outline-secondary fw-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#manageProvincesModal">Cancel</button>
                        <button type="submit" id="updateProvinceBtn" class="btn btn-primary fw-bold px-4 shadow-sm">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // =======================================================================
    // NEW: MANAGE PROVINCES PAGINATION & LOGIC
    // =======================================================================
    let manageProvincesData = <?= json_encode($provinces) ?>;
    let currentProvPage = 1;
    const provRowsPerPage = 5;

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function renderProvincesTable() {
        let tbody = document.getElementById('provincesTableBody');
        if (!tbody) return;

        let start = (currentProvPage - 1) * provRowsPerPage;
        let end = start + provRowsPerPage;
        let paginatedItems = manageProvincesData.slice(start, end);

        let html = '';

        if (paginatedItems.length === 0) {
            html = '<tr><td colspan="4" class="text-center py-4 text-secondary">No provinces found.</td></tr>';
        } else {
            paginatedItems.forEach(p => {
                let aliasesDisplay = p.aliases ? escapeHtml(p.aliases) : '<span class="text-muted">None</span>';
                
                // Securely pass variables to the onclick handler
                let safeName = escapeHtml(p.province_name).replace(/'/g, "\\'");
                let safeAliases = p.aliases ? escapeHtml(p.aliases).replace(/'/g, "\\'") : '';
                
                html += `
                    <tr>
                        <td class="fw-bold text-secondary">${p.id}</td>
                        <td class="fw-bold text-dark">${escapeHtml(p.province_name)}</td>
                        <td class="text-secondary fst-italic">${aliasesDisplay}</td>
                        <td class="text-center">
                            <div class="btn-group shadow-sm">
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="openEditProvinceModal(${p.id}, '${safeName}', '${safeAliases}')">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger fw-bold" onclick="deleteProvince(${p.id})">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
        }

        tbody.innerHTML = html;
        updateProvPaginationInfo();
    }

    function updateProvPaginationInfo() {
        let total = manageProvincesData.length;
        let start = total === 0 ? 0 : ((currentProvPage - 1) * provRowsPerPage) + 1;
        let end = Math.min(currentProvPage * provRowsPerPage, total);

        document.getElementById('provPageInfo').innerText = `Showing ${start} to ${end} of ${total}`;
        document.getElementById('provPrevBtn').disabled = currentProvPage === 1;
        document.getElementById('provNextBtn').disabled = end >= total;
    }

    function prevProvPage() { if (currentProvPage > 1) { currentProvPage--; renderProvincesTable(); } }
    function nextProvPage() { if (currentProvPage * provRowsPerPage < manageProvincesData.length) { currentProvPage++; renderProvincesTable(); } }

    async function saveNewProvince(e) {
        e.preventDefault();
        const btn = document.getElementById('saveProvinceBtn');
        const origText = btn.innerText;
        btn.innerText = "Saving..."; btn.disabled = true;

        let fd = new FormData();
        fd.append('action', 'add_province');
        fd.append('province_name', document.getElementById('newProvinceName').value);
        fd.append('aliases', document.getElementById('newProvinceAliases').value);

        try {
            let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
            let data = await res.json();
            if(data.status === 'success') {
                location.reload(); 
            } else {
                alert("Error: " + data.message);
                btn.innerText = origText; btn.disabled = false;
            }
        } catch(err) { alert("Connection error."); btn.innerText = origText; btn.disabled = false; }
    }

    function openEditProvinceModal(id, name, aliases) {
        document.getElementById('editProvinceId').value = id;
        document.getElementById('editProvinceName').value = name;
        document.getElementById('editProvinceAliases').value = aliases;
        new bootstrap.Modal(document.getElementById('editProvinceModal')).show();
    }

    async function updateProvince(e) {
        e.preventDefault();
        const btn = document.getElementById('updateProvinceBtn');
        const origText = btn.innerText;
        btn.innerText = "Updating..."; btn.disabled = true;

        let fd = new FormData();
        fd.append('action', 'edit_province');
        fd.append('province_id', document.getElementById('editProvinceId').value);
        fd.append('province_name', document.getElementById('editProvinceName').value);
        fd.append('aliases', document.getElementById('editProvinceAliases').value);

        try {
            let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
            let data = await res.json();
            if(data.status === 'success') {
                location.reload(); 
            } else {
                alert("Error: " + data.message);
                btn.innerText = origText; btn.disabled = false;
            }
        } catch(err) { alert("Connection error."); btn.innerText = origText; btn.disabled = false; }
    }

    function deleteProvince(id) {
        showConfirmModal('Delete Province', 'Are you sure you want to delete this province? This action cannot be undone and will fail if price records are currently linked to it.', 'danger', '<i class="bi bi-trash"></i> Delete', async function() {
            let fd = new FormData();
            fd.append('action', 'delete_province');
            fd.append('province_id', id);
            
            try {
                let res = await fetch('ajax_handler.php', { method: 'POST', body: fd });
                let data = await res.json();
                if(data.status === 'success') {
                    location.reload(); 
                } else alert("Error: " + data.message); 
            } catch(err) { alert("Connection failed."); }
        });
    }

    // Initialize Modal Table on load
    document.addEventListener("DOMContentLoaded", () => {
        renderProvincesTable();
    });

    // =======================================================================
    // CACHING LOGIC FOR PROVINCIAL SUMMARY (PART 3)
    // =======================================================================
    let fullExportData = [];
    let provincialData = [];
    let currentPage = 1;
    let rowsPerPage = 50;
    
    const fProv = "<?= htmlspecialchars($filter_province) ?>";
    const fYear = "<?= htmlspecialchars($filter_year) ?>";
    const fMonth = "<?= htmlspecialchars($filter_month) ?>";
    const fWeek = "<?= htmlspecialchars($filter_week) ?>";
    const fType = "<?= htmlspecialchars($filter_type) ?>";
    const currentPart = "<?= htmlspecialchars($part) ?>";

    function loadProvincialData() {
        if (currentPart != 3 || !fProv) return;
        
        const cacheKey = `prov_part3_${fProv}_${fYear}_${fMonth}_${fWeek}_${fType}`;
        const cachedData = sessionStorage.getItem(cacheKey);

        if (cachedData) {
            const parsedData = JSON.parse(cachedData);
            provincialData = parsedData.reportData;
            fullExportData = parsedData.exportData;
            renderTable();
        } else {
            fetch(`provincial.php?fetch_ajax_data=1&part=3&province_id=${fProv}&year=${fYear}&month=${fMonth}&week=${fWeek}&type=${fType}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    provincialData = data.reportData;
                    fullExportData = data.exportData;
                    
                    try { sessionStorage.setItem(cacheKey, JSON.stringify(data)); } 
                    catch (e) { console.warn("Data too large for browser cache."); }
                    
                    renderTable();
                })
                .catch(error => {
                    console.error("Data Fetch Error:", error);
                    document.getElementById('reportTableBody').innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger fw-bold">Failed to load data. ${error.message}</td></tr>`;
                });
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (currentPart == 3) loadProvincialData();
    });

    // =======================================================================
    // CACHING LOGIC FOR EXCEL PREVIEW (PART 2)
    // =======================================================================
    const pFileId = "<?= isset($_GET['file_id']) ? htmlspecialchars($_GET['file_id']) : '' ?>";
    const pSheet = "<?= isset($_GET['sheet']) ? htmlspecialchars($_GET['sheet']) : '' ?>";

    let rawPreviewData = [];
    let previewCurrentPage = 1;
    let previewRowsPerPage = 50;
    
    let hRow = -1;
    let srpCol = -1;
    let storeStartCol = -1;
    let titleRows = [];
    let headerRow = [];
    let dataRows = [];

    function loadPreviewData(targetSheet = pSheet) {
        if (currentPart != 2 || !pFileId) return;

        const cacheKey = `prov_part2_${pFileId}_${targetSheet}`;
        const cachedData = sessionStorage.getItem(cacheKey);

        if (cachedData) {
            processPreviewData(JSON.parse(cachedData));
        } else {
            document.getElementById('previewTableBody').innerHTML = '<tr><td colspan="100%" class="text-center py-5 text-secondary"><i class="bi bi-hourglass-split spin me-2 fs-5"></i> <span class="fw-bold">Loading preview data...</span></td></tr>';
            
            fetch(`provincial.php?fetch_ajax_data=1&part=2&file_id=${pFileId}&sheet=${encodeURIComponent(targetSheet)}`)
                .then(async res => {
                    if (!res.ok) {
                        const text = await res.text();
                        throw new Error(`HTTP ${res.status}: ${text.substring(0, 100)}`);
                    }
                    return res.json();
                })
                .then(data => {
                    try { 
                        sessionStorage.setItem(cacheKey, JSON.stringify(data)); 
                    } catch (e) { 
                        console.warn("Preview data too large for browser cache. Bypassing cache."); 
                    }
                    processPreviewData(data);
                })
                .catch(error => {
                    console.error("Preview Fetch Error:", error);
                    document.getElementById('previewAlertContainer').innerHTML = `<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> Failed to load Excel preview. Error: ${error.message}</div>`;
                    document.getElementById('previewTableBody').innerHTML = ''; 
                });
        }
    }

    function processPreviewData(data) {
        if (data.error) {
            document.getElementById('previewAlertContainer').innerHTML = `<div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> ${data.error}</div>`;
            document.getElementById('previewTable').classList.add('d-none');
            document.getElementById('previewTableBody').innerHTML = '';
            return;
        }

        if (data.sheets && data.sheets.length > 1) {
            let sheetHtml = `<span class="fw-bold text-secondary me-3"><i class="bi bi-layers"></i> Select Sheet:</span>`;
            data.sheets.forEach(sheet => {
                let isCurrent = (data.current_sheet === sheet);
                let btnClass = isCurrent ? 'btn-primary shadow-sm' : 'btn-outline-secondary';
                sheetHtml += `<button onclick="window.location.href='provincial.php?part=2&file_id=${pFileId}&sheet=${encodeURIComponent(sheet)}'" class="btn btn-sm ${btnClass} fw-bold me-2 px-3">${sheet}</button>`;
            });
            let sContainer = document.getElementById('sheetButtonsContainer');
            sContainer.innerHTML = sheetHtml;
            sContainer.classList.remove('d-none');
            sContainer.classList.add('d-flex');
        }

        rawPreviewData = data.data || [];
        hRow = -1; srpCol = -1; storeStartCol = -1;

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
                let header = (headerData[c] || "").toString().toUpperCase().trim();
                if(!header) continue;
                
                if(header.includes("SRP") || header.includes("SUGGESTED")) srpCol = c;
                
                if(header === "BRAND" || (header.includes("BRAND") && !header.includes("SPEC"))) {
                    if (c > maxCol) maxCol = c;
                } else if(header === "SPECIFICATIONS" || header.includes("SPEC") || header.includes("WEIGHT") || header.includes("SIZE") || header.includes("UNIT")) {
                    if (c > maxCol) maxCol = c;
                } else if(header.includes("TYPE") || header.includes("CATEGO") || header.includes("COMMODITY") || header.includes("PRODUCT")) {
                    if (c > maxCol) maxCol = c;
                }
                
                if(header.includes("SRP") && c > maxCol) maxCol = c;
            }
            storeStartCol = maxCol !== -1 ? maxCol + 1 : 6;
        }

        titleRows = hRow > 0 ? rawPreviewData.slice(0, hRow) : [];
        headerRow = rawPreviewData.length > 0 ? rawPreviewData[hRow] : [];
        dataRows = rawPreviewData.length > 0 ? rawPreviewData.slice(hRow + 1) : [];

        previewCurrentPage = 1;
        document.getElementById('previewPaginationContainer').classList.remove('d-none');
        document.getElementById('previewPaginationContainer').classList.add('d-flex');
        renderPreviewTable();
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (currentPart == 2) loadPreviewData();
    });

    // --------------------------------------------------------------------------------------
    // Standard File Logic
    // --------------------------------------------------------------------------------------
    let uploadStartTime = null;

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
        if (min === null || min === undefined) return "<span class='text-danger fw-bold' style='font-size: 0.85rem;'>NO DATA</span>";
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
                        isExcludedCol = ['MIN', 'MAX', 'MODE', 'AVERAGE', 'NAN', 'FREEZE'].some(kw => colHeadStr.includes(kw));
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
                    sessionStorage.removeItem('products_masterlist_cache');
                    sessionStorage.removeItem('movement_log_cache');
                    Object.keys(sessionStorage).forEach(key => {
                        if (key.startsWith('prov_part') || key.startsWith('regional_cache_') || key.startsWith('comp_cache_')) {
                            sessionStorage.removeItem(key);
                        }
                    });

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
        showConfirmModal('Export to Excel', 'Are you sure you want to generate and download this report?', 'primary', '<i class="bi bi-file-earmark-excel"></i> Export', function() {
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

    document.getElementById('fileInput')?.addEventListener('change', function(e) {
        let file = e.target.files[0];
        if(!file) return;

        uploadStartTime = Date.now(); 

        const progressModal = new bootstrap.Modal(document.getElementById('uploadProgressModal'));
        progressModal.show();
        
        const statusText = document.getElementById('modalStatusText');
        const spinnerIcon = document.getElementById('modalSpinnerIcon');

        statusText.innerText = "Step 1: Uploading file...";
        let formData = new FormData(document.getElementById('uploadForm'));
        formData.append('action', 'upload_file_only');
        
        let yearMatch = file.name.match(/(20\d{2})/);
        if(yearMatch) formData.set('target_year', yearMatch[1]);
        
        fetch('ajax_handler.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(uploadData => {
            if(uploadData.status !== 'success') {
                spinnerIcon.classList.replace('bi-hourglass-split', 'bi-exclamation-triangle-fill');
                spinnerIcon.classList.replace('text-primary', 'text-danger');
                statusText.innerText = "Upload Error: " + (uploadData.message || "Unknown server error.");
                setTimeout(() => { location.reload(); }, 3000);
                return;
            }

            statusText.innerText = "Step 2: Extracting 100% of Master List... (Please wait)";
            
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
                                
                                if (!extractedSrpDate) { 
                                    for(let c=0; c < jData[i].length; c++) {
                                        let val = String(jData[i][c] || "").trim();
                                        if(val.toUpperCase().includes("SRP")) {
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
                                
                                cellTxt = formatIfExcelDate(cellTxt); 
                                
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

                        let colMap = { type: -1, cat: -1, prod: -1, brand: -1, specs: -1, srp: -1 };
                        let maxCol = -1;
                        let headerLimit = Math.min(jData[hRow].length, 50); 
                        
                        for(let c=0; c < headerLimit; c++) {
                            let rawHeader = (jData[hRow][c] || "").toString();
                            let header = rawHeader.toUpperCase().replace(/\r?\n|\r/g, " ").trim();
                            if(!header) continue;
                            
                            if(header === "TYPE") { colMap.type = c; continue; }
                            if(header === "CATEGORY" || header === "CATEGORIES") { colMap.cat = c; continue; }
                            if(header === "COMMODITY" || header === "PRODUCT" || header === "PRODUCT NAME" || header === "BASIC NECESSITIES" || header === "PRIME COMMODITIES" || header === "ITEMS") { colMap.prod = c; continue; }
                            if(header === "BRAND" || header === "BRAND NAME" || header === "BRANDS") { colMap.brand = c; continue; }
                            if(header === "SPECIFICATION" || header === "SPECIFICATIONS" || header === "WEIGHT" || header === "SIZE" || header === "WEIGHT/SPECIFICATION" || header === "WEIGHT / SPECIFICATION" || header === "UNIT/SPECIFICATION" || header === "UNIT / SPECIFICATION") { colMap.specs = c; continue; }
                            if(header === "SRP" || header === "SUGGESTED RETAIL PRICE" || header === "PREV SRP") { colMap.srp = c; continue; }
                            
                            if(colMap.type === -1 && header.includes("TYPE") && !header.includes("COMMODITY")) colMap.type = c;
                            else if(colMap.cat === -1 && header.includes("CATEGO")) colMap.cat = c;
                            else if(colMap.prod === -1 && (header.includes("COMMODITY") || (header.includes("PRODUCT") && !header.includes("SPEC") && !header.includes("BRAND")))) colMap.prod = c;
                            else if(colMap.specs === -1 && (header.includes("SPEC") || header.includes("WEIGHT") || header.includes("SIZE"))) colMap.specs = c;
                            else if(colMap.brand === -1 && header.includes("BRAND") && !header.includes("SPEC")) colMap.brand = c;
                            else if(colMap.srp === -1 && (header.includes("SRP") || header.includes("SUGGESTED"))) colMap.srp = c;
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

                            if(!st || ['MIN','MAX','MODE','AVERAGE','NAN'].includes(st.toUpperCase()) || st.toUpperCase().includes('WEEK') || st.toUpperCase().includes('FREEZE')) {
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

                                cTxt = formatIfExcelDate(cTxt); 

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
                        spinnerIcon.classList.replace('bi-hourglass-split', 'bi-exclamation-triangle-fill');
                        spinnerIcon.classList.replace('text-primary', 'text-danger');
                        statusText.innerText = "No valid products found. Ensure the file follows the format template.";
                        setTimeout(() => { location.reload(); }, 3000);
                        return;
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
                                spinnerIcon.classList.replace('bi-hourglass-split', 'bi-exclamation-triangle-fill');
                                spinnerIcon.classList.replace('text-primary', 'text-danger');
                                statusText.innerText = "Database Error Details: " + saveData.message;
                                hasError = true;
                                setTimeout(() => { location.reload(); }, 4000);
                                break;
                            }
                        }

                        if (!hasError) {
                            let fdFinish = new FormData();
                            fdFinish.append('action', 'mark_file_finished');
                            fdFinish.append('file_id', uploadData.file_id);
                            await fetch('ajax_handler.php', { method: 'POST', body: fdFinish });

                            sessionStorage.removeItem('products_masterlist_cache');
                            sessionStorage.removeItem('movement_log_cache');
                            Object.keys(sessionStorage).forEach(key => {
                                if (key.startsWith('prov_part') || key.startsWith('regional_cache_') || key.startsWith('comp_cache_')) {
                                    sessionStorage.removeItem(key);
                                }
                            });

                            let timeDiff = Math.floor((Date.now() - uploadStartTime) / 1000);
                            
                            spinnerIcon.classList.remove('spin');
                            spinnerIcon.classList.replace('bi-hourglass-split', 'bi-check-circle');
                            spinnerIcon.classList.replace('text-primary', 'text-success');
                            statusText.innerText = `Success! Extracted ${allFlatData.length} records in ${timeDiff} seconds. Redirecting...`;
                            
                            setTimeout(() => { 
                                window.location.href = 'provincial.php?part=2&file_id=' + uploadData.file_id; 
                            }, 2000);
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
</script>

<?php 
include '../includes/footer.php'; 
?>
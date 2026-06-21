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

    // ------------------------------------------------------------------------------------------
    // THE SPEED FIX: ONLY run the lightweight Compliance query via background AJAX.
    // Excel Preview (Part 2) will run server-side to prevent memory crashes!
    // ------------------------------------------------------------------------------------------
    
    // AJAX for Compliance Tracker
    if (isset($_GET['fetch_compliance_data'])) {
        $responseData = [];
        try {
            $responseData = $admin->getSRPComplianceReport($c_province, $c_year, $c_month, $c_week);
        } catch (Exception $e) {
            $responseData = ['error' => 'System Error: ' . $e->getMessage()];
        }
        
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($responseData);
        exit();
    }

    // ------------------------------------------------------------------------------------------
    // STANDARD PHP LOGIC (Safe, heavy lifting for Excel Preview)
    // ------------------------------------------------------------------------------------------
    $previewData = [];
    $preview_file_path = ''; 
    if ($part == 2 && isset($_GET['report_id'])) {
        $stmtFilePath = $db->prepare("SELECT file_path FROM generated_reports WHERE id = ?");
        $stmtFilePath->execute([$_GET['report_id']]);
        $preview_file_path = $stmtFilePath->fetchColumn();
        
        // This is now processed natively by PHP before the page loads to prevent connection drops
        $previewData = $admin->getReportPreview($_GET['report_id'], $target_sheet);
    }

    // Lightweight query to populate the category dropdown instantly
    $allCategories = [];
    if ($mode === 'compliance') {
        $stmtCats = $db->query("SELECT DISTINCT category_name FROM categories ORDER BY category_name ASC");
        $allCategories = $stmtCats->fetchAll(PDO::FETCH_COLUMN);
    }

    // -------------------------------------------------------------
    // PULL IN THE MASTER HTML LAYOUT (Head, Top Nav, Sidebars)
    // -------------------------------------------------------------
    include '../includes/navigations.php';
?>

<style>
    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
    .report-row:hover { background-color: #f8f9fa; }
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
                <form id="complianceForm" class="row g-2 align-items-end" onsubmit="submitComplianceForm(event)">
                    <input type="hidden" name="mode" value="compliance">
                    <div class="col-md-3">
                        <label class="small fw-bold text-secondary">Province</label>
                        <select name="c_province" id="c_province" class="form-select form-select-sm" required>
                            <option value="">Select Province</option>
                            <?php foreach($provinces as $p): ?><option value="<?= $p['id'] ?>" <?= ($c_province == $p['id']) ? 'selected' : '' ?>><?= htmlspecialchars($p['province_name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-secondary">Year</label>
                        <select name="c_year" id="c_year" class="form-select form-select-sm" onchange="submitComplianceForm(event)"><?php foreach($availableYears as $y): ?><option value="<?= $y['year'] ?>" <?= ($c_year == $y['year']) ? 'selected' : '' ?>><?= $y['year'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-secondary">Month</label>
                        <select name="c_month" id="c_month" class="form-select form-select-sm" onchange="submitComplianceForm(event)"><option value="">Full Year</option><?php foreach($availableMonths as $m): ?><option value="<?= $m['month'] ?>" <?= ($c_month == $m['month']) ? 'selected' : '' ?>><?= $m['month'] ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-secondary">Week Range</label>
                        <select name="c_week" id="c_week" class="form-select form-select-sm" <?= empty($c_month) ? 'disabled' : '' ?>><option value="">All Weeks</option><?php foreach($availableWeeks as $w): ?><option value="<?= $w['id'] ?>" <?= ($c_week == $w['id']) ? 'selected' : '' ?>><?= htmlspecialchars($w['date_range_label']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-2"><button type="button" onclick="executeComplianceFetch()" class="btn btn-sm btn-dark w-100 shadow-sm fw-bold">Analyze Data</button></div>
                </form>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text bg-white"><i class="bi bi-search"></i></span><input type="text" id="compSearch" class="form-control" placeholder="Search product..." onkeyup="runComplianceFilter()"></div></div>
                <div class="col-md-2"><select id="compRows" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="25">25 rows</option><option value="50" selected>50 rows</option><option value="100">100 rows</option></select></div>
                <div class="col-md-2"><select id="compType" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="All">All Types</option><option value="BN">BN</option><option value="PC">PC</option></select></div>
                <div class="col-md-3"><select id="compCat" class="form-select form-select-sm" onchange="runComplianceFilter()"><option value="All">All Categories</option><?php foreach($allCategories as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2">
                    <button class="btn btn-primary btn-sm w-100 fw-bold shadow-sm" onclick="exportCompliance()">
                        <i class="bi bi-download me-1"></i> Export
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
                    <tbody id="complianceBody">
                        <tr>
                            <td colspan="5" class="text-center py-5 text-secondary">
                                <span class="fw-bold">Please Select filters and click Analyze Data to start.</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3"><span id="compInfo" class="small fw-bold text-secondary"></span><div class="btn-group shadow-sm"><button class="btn btn-sm btn-outline-secondary" onclick="compPrev()">Prev</button><button class="btn btn-sm btn-outline-secondary" onclick="compNext()">Next</button></div></div>
        <?php endif; ?>
    </div>

    <div id="part2" class="<?php echo ($part == 2) ? '' : 'd-none'; ?>">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="d-flex align-items-center gap-2"><a href="generated_reports.php?part=1" class="text-dark"><i class="bi bi-arrow-left fs-4"></i></a><h3 class="m-0 fw-bold" style="color: #8B0000;">Report Preview</h3></div>
            <div class="d-flex align-items-center gap-2"><select id="previewRowsPerPage" class="form-select form-select-sm" onchange="changePreviewRowsPerPage()"><option value="50">50 rows</option><option value="100">100 rows</option></select>
            <?php if (!empty($preview_file_path)): ?><a href="#" onclick="confirmExportLink(event, '../uploads/reports/<?= htmlspecialchars($preview_file_path) ?>')" class="btn btn-primary btn-sm fw-bold px-4"><i class="bi bi-download me-1"></i> Export</a><?php endif; ?></div>
        </div>
        <div class="bg-white border rounded shadow-sm p-4 overflow-hidden">
            <div class="table-responsive border rounded" style="max-height: 550px; overflow: auto;"><table class="table table-bordered table-sm text-nowrap" id="previewTable"><thead class="table-dark sticky-top" id="previewTableHead" style="z-index: 2; top: 0;"></thead><tbody id="previewTableBody"></tbody></table></div>
            <div class="d-flex justify-content-between align-items-center mt-3"><span id="previewPageInfo"></span><div class="btn-group"><button class="btn btn-outline-secondary" onclick="previewPrevPage()" id="previewPrevBtn">Prev</button><button class="btn btn-outline-secondary" onclick="previewNextPage()" id="previewNextBtn">Next</button></div></div>
        </div>
    </div>
</div>

<script>
    // =======================================================================
    // THE SPEED FIX: CACHING & FILTER MEMORY FOR COMPLIANCE TRACKER
    // =======================================================================
    let compMasterData = [];
    let compFilteredData = [];
    let compCurrentPage = 1;
    
    // Constant Memory Key
    const COMPLIANCE_STATE_KEY = 'dti_compliance_tracker_state';

    function submitComplianceForm(e) {
        if (e) e.preventDefault();
        document.getElementById('complianceForm').submit();
    }

    function executeComplianceFetch() {
        const prov = document.getElementById('c_province').value;
        const year = document.getElementById('c_year').value;
        const month = document.getElementById('c_month').value;
        const week = document.getElementById('c_week') ? document.getElementById('c_week').value : '';

        if (!prov) {
            alert("Please select a Province first.");
            return;
        }

        document.getElementById('complianceBody').innerHTML = '<tr><td colspan="5" class="text-center py-5 text-secondary"><i class="bi bi-hourglass-split spin me-2 fs-5"></i> <span class="fw-bold">Analyzing data...</span></td></tr>';

        // Save State Intention
        const stateToSave = { prov, year, month, week, data: null };

        const url = `generated_reports.php?fetch_compliance_data=1&c_province=${prov}&c_year=${year}&c_month=${month}&c_week=${week}`;
        
        fetch(url)
            .then(async res => {
                if (!res.ok) {
                    const text = await res.text();
                    throw new Error(`HTTP Error ${res.status}: ${text.substring(0, 100)}`);
                }
                return res.json();
            })
            .then(data => {
                if (data.error) throw new Error(data.error);
                
                compMasterData = data;
                stateToSave.data = data; 
                
                try { sessionStorage.setItem(COMPLIANCE_STATE_KEY, JSON.stringify(stateToSave)); } 
                catch (e) { console.warn("Compliance data too large for cache."); }
                
                const newUrl = `generated_reports.php?mode=compliance&c_province=${prov}&c_year=${year}&c_month=${month}&c_week=${week}`;
                window.history.replaceState(null, '', newUrl);

                runComplianceFilter();
            })
            .catch(err => {
                console.error(err);
                document.getElementById('complianceBody').innerHTML = `<tr><td colspan="5" class="text-center py-5 text-danger fw-bold">Failed to load compliance data. Error: ${err.message}</td></tr>`;
            });
    }

    function initComplianceTracker() {
        const savedStateString = sessionStorage.getItem(COMPLIANCE_STATE_KEY);
        const phpProv = "<?= htmlspecialchars($c_province) ?>";
        const phpYear = "<?= htmlspecialchars($c_year) ?>";
        const phpMonth = "<?= htmlspecialchars($c_month) ?>";
        const phpWeek = "<?= htmlspecialchars($c_week) ?>";
        
        if (savedStateString) {
            const state = JSON.parse(savedStateString);
            if (phpProv !== '' && (phpProv !== state.prov || phpYear !== state.year || phpMonth !== state.month || phpWeek !== state.week)) {
                executeComplianceFetch();
                return;
            }

            document.getElementById('c_province').value = state.prov;
            document.getElementById('c_year').value = state.year;
            
            if (document.querySelector(`#c_month option[value='${state.month}']`)) {
                document.getElementById('c_month').value = state.month;
                if (state.month !== '') {
                    const weekEl = document.getElementById('c_week');
                    if (weekEl) weekEl.disabled = false;
                }
            }
            if (document.querySelector(`#c_week option[value='${state.week}']`)) {
                document.getElementById('c_week').value = state.week;
            }

            if (state.data) {
                compMasterData = state.data;
                const newUrl = `generated_reports.php?mode=compliance&c_province=${state.prov}&c_year=${state.year}&c_month=${state.month}&c_week=${state.week}`;
                window.history.replaceState(null, '', newUrl);
                runComplianceFilter();
            }
        } else {
            if (phpProv !== '') executeComplianceFetch();
        }
    }

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
        
        if (pageItems.length === 0) { html = '<tr><td colspan="5" class="text-center py-5 fw-bold text-secondary">No data matching filters.</td></tr>'; } 
        else {
            pageItems.forEach(row => {
                const aboveList = row.above_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val price-above">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                const belowList = row.below_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val price-below">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                const noSrpList = row.no_srp_stores.map(st => `<div class="compliance-item"><span class="store-name" title="${st.store} (${st.date})">${st.store} <small class="text-muted">(${st.date})</small></span><span class="price-val text-dark">₱${parseFloat(st.price).toFixed(2)}</span></div>`).join('');
                
                let srpDisplay = (row.srp && row.srp > 0) ? `₱${parseFloat(row.srp).toFixed(2)}` : 'N/A';

                html += `<tr><td><div class="fw-bold">${row.brand_name} ${row.product_name}</div><small class="text-secondary">${row.specifications}</small></td>
                <td class="text-center"><span class="badge bg-light text-primary border px-2 py-2">${srpDisplay}</span></td>
                <td><div class="small text-danger fw-bold mb-1">Found: ${row.above_stores.length}</div><div class="compliance-list-container">${aboveList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                <td><div class="small text-success fw-bold mb-1">Found: ${row.below_stores.length}</div><div class="compliance-list-container">${belowList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                <td><div class="small text-secondary fw-bold mb-1">Found: ${row.no_srp_stores.length}</div><div class="compliance-list-container">${noSrpList || '<div class="p-2 text-center text-muted">None</div>'}</div></td>
                </tr>`;
            });
        }
        tbody.innerHTML = html;
        document.getElementById('compInfo').innerText = `Showing ${Math.min(start + 1, compFilteredData.length)} to ${Math.min(end, compFilteredData.length)} of ${compFilteredData.length}`;
    }
    
    function compPrev() { if (compCurrentPage > 1) { compCurrentPage--; renderComplianceTable(); } }
    function compNext() { if (compCurrentPage * parseInt(document.getElementById('compRows').value) < compFilteredData.length) { compCurrentPage++; renderComplianceTable(); } }

    function exportCompliance() {
        const prov = document.getElementById('c_province').value;
        const yr = document.getElementById('c_year').value;
        const mo = document.getElementById('c_month').value || '';
        const wk = document.getElementById('c_week') ? document.getElementById('c_week').value : '';
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

    // =======================================================================
    // REPORT PREVIEW LOGIC (STANDARD PHP INJECTION TO PREVENT CRASHES)
    // =======================================================================
    const rawPreviewData = <?= json_encode($previewData['data'] ?? []) ?>;
    let previewCurrentPage = 1, previewRowsPerPage = 50;
    const headerRow = rawPreviewData.length > 0 ? rawPreviewData[0] : [];
    const dataRows = rawPreviewData.length > 1 ? rawPreviewData.slice(1) : [];

    function renderPreviewTable() {
        let thead = document.getElementById('previewTableHead'), tbody = document.getElementById('previewTableBody');
        if (!thead || !tbody) return;
        
        let headHtml = '<tr><th class="text-center" style="width: 50px;">#</th>'; 
        headerRow.forEach(c => headHtml += `<th>${String(c).replace(/</g, "&lt;")}</th>`); 
        headHtml += '</tr>';
        thead.innerHTML = headHtml;
        
        let start = (previewCurrentPage - 1) * previewRowsPerPage;
        let end = start + previewRowsPerPage;
        let pItems = dataRows.slice(start, end);
        let html = '';
        
        if (pItems.length === 0) {
             html = '<tr><td colspan="100%" class="text-center py-5 text-secondary">No readable data found in this sheet.</td></tr>';
        } else {
            pItems.forEach((r, i) => {
                html += `<tr><td class="text-center fw-bold bg-light">${start + i + 1}</td>`;
                for(let j=0; j<headerRow.length; j++) html += `<td>${(r[j] || '').toString().replace(/PHP\s*/ig, '')}</td>`;
                html += '</tr>';
            });
        }
        
        tbody.innerHTML = html;
        let pageInfo = document.getElementById('previewPageInfo');
        if (pageInfo) pageInfo.innerText = `Showing ${start + 1} to ${Math.min(end, dataRows.length + 1)} of ${dataRows.length} entries`;
    }

    function previewPrevPage() { if (previewCurrentPage > 1) { previewCurrentPage--; renderPreviewTable(); } }
    function previewNextPage() { if (previewCurrentPage * previewRowsPerPage < dataRows.length) { previewCurrentPage++; renderPreviewTable(); } }
    function changePreviewRowsPerPage() { previewRowsPerPage = parseInt(document.getElementById('previewRowsPerPage').value); previewCurrentPage = 1; renderPreviewTable(); }

    function confirmExportLink(e, u) { 
        e.preventDefault(); 
        showConfirmModal('Export', 'Download this file?', 'primary', 'Download', () => { 
            let a = document.createElement('a'); a.href = u; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); 
        }); 
    }

    // Setup SPA compatible initialization
    setTimeout(() => {
        if (document.getElementById('complianceBody')) initComplianceTracker();
        if (document.getElementById('previewTableBody') && rawPreviewData.length > 0) renderPreviewTable();
    }, 100);
</script>

<?php 
// -------------------------------------------------------------
// PULL IN THE MASTER FOOTER (Modals and Global Scripts)
// -------------------------------------------------------------
include '../includes/footer.php'; 
?>
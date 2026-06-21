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

    $stats = $admin->getDashboardStats();
    $recentUploads = array_slice($admin->getUploadedFiles(), 0, 5);
    $recentReports = array_slice($admin->getGeneratedReports(), 0, 5);

    // -------------------------------------------------------------
    // PULL IN THE MASTER HTML LAYOUT (Head, Top Nav, Sidebars)
    // -------------------------------------------------------------
    include '../includes/navigations.php';
?>

<style>
    .spin { animation: spin 1s linear infinite; }
    @keyframes spin { 100% { transform: rotate(360deg); } }
</style>

<div class="p-2 p-md-3">
    <h2 class="fw-bold mb-4" style="color: #0A0A3A;">Dashboard Overview</h2>

    <div class="row mb-4 mb-md-5 g-3">
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm border-0 pt-3" style="background-color: #fff4e6; border-bottom: 5px solid #fd7e14 !important; border-radius: 8px;">
                <div class="card-body text-center">
                    <h1 class="fw-bold text-dark display-6 mb-1"><?= number_format($stats['total_products']) ?></h1>
                    <h6 class="fw-bold text-secondary mb-0 mt-2">Total Products</h6>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm border-0 pt-3" style="background-color: #e6f4ea; border-bottom: 5px solid #28a745 !important; border-radius: 8px;">
                <div class="card-body text-center">
                    <h1 class="fw-bold text-dark display-6 mb-1"><?= number_format($stats['total_stores']) ?></h1>
                    <h6 class="fw-bold text-secondary mb-0 mt-2">Total Stores</h6>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm border-0 pt-3" style="background-color: #f3e8ff; border-bottom: 5px solid #6f42c1 !important; border-radius: 8px;">
                <div class="card-body text-center">
                    <h1 class="fw-bold text-dark display-6 mb-1"><?= number_format($stats['total_reports']) ?></h1>
                    <h6 class="fw-bold text-secondary mb-0 mt-2">Reports Generated</h6>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="card h-100 shadow-sm border-0 pt-2" style="background-color: #e0f2fe; border-bottom: 5px solid #0dcaf0 !important; border-radius: 8px;">
                <div class="card-body text-center">
                    <h1 class="fw-bold text-dark display-6 mb-0"><?= number_format($stats['total_prices']) ?></h1>
                    <h6 class="fw-bold text-secondary mb-2 mt-1">Price Data Points</h6>
                    <a href="trends.php" class="btn btn-sm btn-info px-3 mt-1">
                        <i class="bi bi-graph-up"></i> View Analytics
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-4 mb-md-5 bg-white p-3 p-md-4 rounded shadow-sm border">
        <h5 class="fw-bold mb-3" style="color: #0A0A3A;"><i class="bi bi-journal-check text-primary me-2"></i>Generated Reports</h5>
        <div class="table-responsive border rounded shadow-sm">
            <table class="table table-hover align-middle mb-0 text-nowrap">
                <thead class="table-light text-secondary">
                    <tr>
                        <th>File Name</th>
                        <th>Type</th>
                        <th>Date Generated</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($recentReports) > 0): ?>
                        <?php foreach($recentReports as $report): 
                            $typeName = $report['report_type'] ?: 'Provincial';
                            $badgeColor = ($typeName == 'Provincial') ? 'bg-secondary' : 'bg-primary';
                        ?>
                            <tr>
                                <td class="text-dark fw-bold"><?= htmlspecialchars($report['report_name']) ?></td>
                                <td><span class="badge <?= $badgeColor ?>"><?= $typeName ?></span></td>
                                <td class="text-secondary"><?= date('M d, Y h:i A', strtotime($report['created_at'])) ?></td>
                                <td class="text-center">
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <a href="generated_reports.php?part=2&report_id=<?= $report['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm px-md-3">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="#" onclick="confirmExportLink(event, '../uploads/reports/<?= htmlspecialchars($report['file_path']) ?>')" class="btn btn-sm btn-primary shadow-sm px-md-3">
                                            <i class="bi bi-download"></i> Export
                                        </a>
                                        <a href="#" class="btn btn-sm btn-outline-danger shadow-sm px-md-3" onclick="confirmLinkAction(event, 'generated_reports.php?delete_report_id=<?= $report['id'] ?>', 'Delete Report', 'Are you sure you want to delete this generated report permanently?', 'danger', '<i class=\'bi bi-trash\'></i> Delete')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-secondary">No reports generated yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-3">
            <a href="generated_reports.php" class="text-decoration-none fw-bold" style="color: #8B0000;">View All Reports <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>

    <div class="mb-4 mb-md-5 bg-white p-3 p-md-4 rounded shadow-sm border">
        <h5 class="fw-bold mb-3" style="color: #0A0A3A;"><i class="bi bi-cloud-arrow-up text-success me-2"></i>Uploaded Files</h5>
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
                    <?php if(count($recentUploads) > 0): ?>
                        <?php foreach($recentUploads as $file): 
                            $displayName = preg_replace('/^[0-9]+_/', '', $file['original_filename']);
                        ?>
                            <tr>
                                <td class="text-dark fw-bold"><?= htmlspecialchars($displayName) ?></td>
                                <td class="text-secondary"><?= htmlspecialchars($file['province_name']) ?></td>
                                <td class="text-secondary"><?= date('M d, Y h:i A', strtotime($file['uploaded_at'])) ?></td>
                                <td class="text-center">
                                    <div class="d-flex flex-wrap gap-2 justify-content-center">
                                        <a href="provincial.php?part=2&file_id=<?= $file['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm px-md-3">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <button type="button" class="btn btn-sm btn-success shadow-sm px-md-3" onclick="buildAndNavigateReport(<?= $file['province_id'] ?>, <?= $file['target_year'] ?>, this)">
                                            <i class="bi bi-journal-check"></i> Generate
                                        </button>
                                        <a href="#" class="btn btn-sm btn-outline-danger shadow-sm px-md-3" onclick="confirmLinkAction(event, 'provincial.php?delete_file_id=<?= $file['id'] ?>', 'Delete File', 'Are you sure you want to delete this file and all its price records?', 'danger', '<i class=\'bi bi-trash\'></i> Delete')">
                                            <i class="bi bi-trash"></i> Delete
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center py-4 text-secondary">No raw data files uploaded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-3">
            <a href="provincial.php" class="text-decoration-none fw-bold" style="color: #8B0000;">View All Uploads <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>
</div>

<script>
    // Link the Dashboard "Generate" button to the exact same logic used in the Provincial tab
    async function buildAndNavigateReport(prov_id, year, btn) {
        // Ensure showConfirmModal is globally available from footer.php
        if (typeof showConfirmModal === 'function') {
            showConfirmModal('Generate Report', 'Are you sure you want to generate a new report for this data?', 'success', '<i class="bi bi-journal-check"></i> Generate', async function() {
                executeGenerate(prov_id, year, btn);
            });
        } else {
            if (confirm("Are you sure you want to generate a new report for this data?")) {
                executeGenerate(prov_id, year, btn);
            }
        }
    }

    async function executeGenerate(prov_id, year, btn) {
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
                // Wipe cache memory so the new report shows instantly
                sessionStorage.removeItem('products_masterlist_cache');
                sessionStorage.removeItem('movement_log_cache');
                Object.keys(sessionStorage).forEach(key => {
                    if (key.startsWith('prov_part') || key.startsWith('regional_cache_') || key.startsWith('comp_cache_')) {
                        sessionStorage.removeItem(key);
                    }
                });

                // Redirect straight to the provincial summary table
                window.location.href = `provincial.php?part=3&province_id=${prov_id}&year=${year}`;
            } else {
                alert("Error saving report: " + data.message);
                btn.innerHTML = origHTML;
                btn.disabled = false;
            }
        } catch(e) {
            alert("Connection failed. Please try again.");
            console.error(e);
            btn.innerHTML = origHTML;
            btn.disabled = false;
        }
    }

    // Link the Dashboard "Export" button to a secure download prompt
    function confirmExportLink(e, url) {
        e.preventDefault();
        if (typeof showConfirmModal === 'function') {
            showConfirmModal('Export Report', 'Are you sure you want to download this generated report?', 'primary', '<i class="bi bi-download"></i> Download', function() {
                window.location.href = url;
            });
        } else {
            if (confirm("Are you sure you want to download this generated report?")) {
                window.location.href = url;
            }
        }
    }
</script>

<?php 
include '../includes/footer.php'; 
?>
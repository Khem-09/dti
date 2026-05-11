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
    $target_sheet = isset($_GET['sheet']) ? $_GET['sheet'] : null;

    $previewData = [];
    if ($part == 2 && isset($_GET['report_id'])) {
        $previewData = $admin->getReportPreview($_GET['report_id'], $target_sheet);
    }

    $filter_type = isset($_GET['type']) ? $_GET['type'] : 'All';
    $filter_year = isset($_GET['year']) ? $_GET['year'] : 'All';

    $reports = $admin->getGeneratedReports($filter_type, $filter_year);
    $provinces = $admin->getProvinces(); 
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
                        <h2 class="fw-bold mb-2 text-center" style="color: #0A0A3A; font-size: 26px;">Generated Reports Archive</h2>
                        <div style="height: 2px; background-color: #8B0000; width: 100%; margin-bottom: 30px;"></div>

                        <div class="filter-box d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
                            <div class="input-group input-group-sm shadow-sm w-100" style="max-width: 400px;">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                                <input type="text" id="searchFile" class="form-control border-start-0" placeholder="Search report name..." onkeyup="filterReports()">
                            </div>
                            
                            <div class="d-flex flex-wrap gap-2 w-100 justify-content-lg-end">
                                <select id="filterProv" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterReports()" style="min-width: 140px; max-width: 200px;">
                                    <option value="All">All Regions/Provinces</option>
                                    <option value="Regional">Regional Summary</option>
                                    <?php foreach($provinces as $p): ?>
                                        <option value="<?= htmlspecialchars($p['province_name']) ?>"><?= htmlspecialchars($p['province_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <input type="date" id="filterDate" class="form-control form-control-sm border shadow-sm fw-bold text-secondary flex-grow-1" onchange="filterReports()" style="min-width: 140px; max-width: 180px;">
                                
                                <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="clearFilters()" title="Clear Filters"><i class="bi bi-x-circle"></i></button>
                            </div>
                        </div>
                    
                        <div class="table-responsive p-3 p-md-4 rounded" style="background-color: #D3D3D3; min-height: 400px;">
                            <table class="table table-borderless table-hover bg-transparent align-middle mb-0 text-nowrap" id="archiveTable">
                                <thead style="border-bottom: 1px solid #aaa;">
                                    <tr>
                                        <th class="fw-bold text-dark pb-3">Report Name</th>
                                        <th class="fw-bold text-dark pb-3">Type</th>
                                        <th class="fw-bold text-dark pb-3">Date Generated</th>
                                        <th class="fw-bold text-dark pb-3 text-center">Action</th>
                                    </tr>
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
                                                        <a href="generated_reports.php?part=2&report_id=<?= $report['id'] ?>" class="btn btn-sm btn-outline-primary shadow-sm px-2 px-md-3">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
                                                        <a href="#" onclick="confirmExportLink(event, '../uploads/reports/<?= htmlspecialchars($report['file_path']) ?>')" class="btn btn-sm btn-primary shadow-sm px-2 px-md-3">
                                                            <i class="bi bi-download"></i> Export
                                                        </a>
                                                        <a href="#" class="btn btn-sm btn-outline-danger shadow-sm px-2 px-md-3" onclick="confirmLinkAction(event, 'generated_reports.php?delete_report_id=<?= $report['id'] ?>', 'Delete Report', 'Are you sure you want to delete this generated report permanently?', 'danger', '<i class=\'bi bi-trash\'></i> Delete')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center py-5 text-secondary">No generated reports found.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="part2" class="<?php echo ($part == 2) ? '' : 'd-none'; ?>">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-2 gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <a href="generated_reports.php?part=1" class="text-dark text-decoration-none"><i class="bi bi-arrow-left fs-4"></i></a> 
                                <h3 class="m-0 fw-bold" style="color: #8B0000;">Report Preview</h3>
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
                                        <a href="generated_reports.php?part=2&report_id=<?= $_GET['report_id'] ?>&sheet=<?= urlencode($sheet) ?>" 
                                        class="btn btn-sm <?= ($previewData['current_sheet'] == $sheet) ? 'btn-primary shadow-sm' : 'btn-outline-secondary' ?> fw-bold me-2 px-3">
                                        <?= htmlspecialchars($sheet) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

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
        function filterReports() {
            let search = document.getElementById("searchFile").value.toLowerCase();
            let prov = document.getElementById("filterProv").value;
            let dateVal = document.getElementById("filterDate").value;

            let rows = document.querySelectorAll(".report-row");
            rows.forEach(row => {
                let fileName = row.querySelector(".report-name").innerText.toLowerCase();
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

        function clearFilters() {
            document.getElementById('searchFile').value = '';
            document.getElementById('filterProv').value = 'All';
            document.getElementById('filterDate').value = '';
            filterReports();
        }

        // ==================================================
        // JAVASCRIPT PAGINATION FOR EXCEL PREVIEW
        // ==================================================
        const rawPreviewData = <?= json_encode($previewData['data'] ?? []) ?>;
        let previewCurrentPage = 1;
        let previewRowsPerPage = 50;
        
        const headerRow = rawPreviewData.length > 0 ? rawPreviewData[0] : [];
        const dataRows = rawPreviewData.length > 1 ? rawPreviewData.slice(1) : [];

        function renderPreviewTable() {
            let thead = document.getElementById('previewTableHead');
            let tbody = document.getElementById('previewTableBody');
            if (!thead || !tbody) return;

            let headHtml = '<tr><th class="text-center" style="width: 40px;">#</th>';
            headerRow.forEach(cell => {
                let cellVal = cell !== null && cell !== undefined ? String(cell) : '';
                // Escape HTML for safety just like PHP did
                cellVal = cellVal.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                headHtml += `<th>${cellVal}</th>`;
            });
            headHtml += '</tr>';
            thead.innerHTML = headHtml;

            let start = (previewCurrentPage - 1) * previewRowsPerPage;
            let end = start + previewRowsPerPage;
            let paginatedItems = dataRows.slice(start, end);

            let html = '';
            let count = start + 1;

            if (paginatedItems.length === 0) {
                html = `<tr><td colspan="${headerRow.length + 1}" class="text-center py-5 text-secondary">No readable data found in this sheet.</td></tr>`;
            } else {
                paginatedItems.forEach(row => {
                    html += `<tr><td class="text-center fw-bold bg-light text-secondary">${count++}</td>`;
                    for(let i=0; i<headerRow.length; i++) {
                        let cellVal = row[i] !== undefined && row[i] !== null ? String(row[i]) : '';
                        
                        // MAGIC FIX: Remove the text "PHP " (case-insensitive) to only show numbers in the range
                        cellVal = cellVal.replace(/PHP\s*/ig, '');
                        
                        // Escape HTML for safety
                        cellVal = cellVal.replace(/</g, "&lt;").replace(/>/g, "&gt;");
                        html += `<td>${cellVal}</td>`;
                    }
                    html += `</tr>`;
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
            let selectBox = document.getElementById('previewRowsPerPage');
            if (selectBox) {
                previewRowsPerPage = parseInt(selectBox.value);
                previewCurrentPage = 1;
                renderPreviewTable();
            }
        }

        document.addEventListener("DOMContentLoaded", () => {
            if (document.getElementById('previewTableBody')) {
                renderPreviewTable();
            }
        });

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
    </script>
</body>
</html>
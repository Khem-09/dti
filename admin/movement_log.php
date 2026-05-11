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

    $historyLogs = $admin->getProductHistory();

    $categories = [];
    foreach ($historyLogs as $h) {
        if (!in_array($h['category_name'], $categories)) {
            $categories[] = $h['category_name'];
        }
    }
    sort($categories);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movement Log - DTI Region IX</title>
    <link rel="stylesheet" href="../bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/products.css">
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
            <button class="btn btn-light d-md-none me-3 border-0 shadow-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar">
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

            <main class="col-12 col-md-10 p-3 p-md-4" style="background-color: #EAEAEA;">
                <div class="shadow-sm bg-white p-3 p-md-5" style="min-height: 80vh; border-radius: 0;">
                    
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-2 gap-2">
                        <h2 class="fw-bold m-0" style="color: #0A0A3A; font-size: 26px;">
                            <a href="products.php" class="text-decoration-none text-secondary me-2"><i class="bi bi-arrow-left"></i></a>
                            Masterlist Movement Log
                        </h2>
                    </div>
                    <div style="height: 2px; background-color: #8B0000; width: 100%; margin-bottom: 30px;"></div>

                    <div class="filter-box d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3">
                         <div class="d-flex flex-wrap align-items-center gap-2 w-100" style="max-width: 400px;">
                            <span class="fw-bold text-dark me-1">From:</span>
                            <input type="date" id="fromDate" onchange="filterLogs()" class="form-control form-control-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 120px;">
                            <span class="fw-bold text-dark ms-1 me-1">To:</span>
                            <input type="date" id="toDate" onchange="filterLogs()" class="form-control form-control-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 120px;">
                        </div>
                        
                        <div class="d-flex flex-wrap gap-2 w-100 justify-content-xl-end m-0">
                             <select id="rowsPerPage" class="form-select form-select-sm border shadow-sm fw-bold text-secondary ms-xl-3 flex-grow-1" onchange="changeRowsPerPage()" style="min-width: 100px; max-width: 120px;">
                                <option value="25">25 rows</option>
                                <option value="50" selected>50 rows</option>
                                <option value="100">100 rows</option>
                                <option value="250">250 rows</option>
                                <option value="500">500 rows</option>
                            </select>
                            
                            <select id="typeFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 130px; max-width: 160px;" onchange="filterLogs()">
                                <option value="All">All Types</option>
                                <option value="BN">Basic Necessities</option>
                                <option value="PC">Prime Commodities</option>
                            </select>

                            <select id="catFilter" class="form-select form-select-sm border shadow-sm fw-bold text-secondary flex-grow-1" style="min-width: 160px; max-width: 220px;" onchange="filterLogs()">
                                <option value="All">All Categories</option>
                                <?php foreach($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="table-responsive border rounded shadow-sm" style="background-color: #f8f9fa; max-height: 600px;">
                        <table class="table table-hover align-middle mb-0 text-nowrap" id="logTable">
                            <thead class="table-light text-secondary sticky-top" style="border-bottom: 1px solid #aaa;">
                                <tr>
                                    <th class="fw-bold text-dark pb-2 ps-3">Date Edited</th>
                                    <th class="fw-bold text-dark pb-2">Category & Brand</th>
                                    <th class="fw-bold text-dark pb-2">Changes Applied (Old &rarr; New)</th>
                                </tr>
                            </thead>
                            <tbody id="logTableBody">
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center mt-3 px-2 gap-2">
                        <span class="text-secondary fw-bold" id="pageInfo">Loading log data...</span>
                        <div class="btn-group shadow-sm">
                            <button class="btn btn-outline-secondary fw-bold" onclick="prevPage()" id="prevBtn" disabled>Previous</button>
                            <button class="btn btn-outline-secondary fw-bold" onclick="nextPage()" id="nextBtn" disabled>Next</button>
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
        const allLogs = <?php echo json_encode($historyLogs); ?>;
        let filteredLogs = [...allLogs];
        
        let currentPage = 1;
        let rowsPerPage = 50;

        function changeRowsPerPage() {
            rowsPerPage = parseInt(document.getElementById('rowsPerPage').value);
            currentPage = 1;
            renderTable();
        }

        function filterLogs() {
            let fromDate = document.getElementById('fromDate').value;
            let toDate = document.getElementById('toDate').value;
            let type = document.getElementById('typeFilter').value;
            let cat = document.getElementById('catFilter').value;
            
            filteredLogs = allLogs.filter(h => {
                let matchType = (type === 'All' || h.type_code === type);
                let matchCat = (cat === 'All' || h.category_name === cat);
                
                let matchFrom = true;
                let matchTo = true;
                
                let logDate = new Date(h.changed_at.split(' ')[0]);
                
                if (fromDate) matchFrom = logDate >= new Date(fromDate);
                if (toDate) matchTo = logDate <= new Date(toDate);
                
                return matchType && matchCat && matchFrom && matchTo;
            });
            
            currentPage = 1;
            renderTable();
        }

        function renderTable() {
            let start = (currentPage - 1) * rowsPerPage;
            let end = start + rowsPerPage;
            let paginatedItems = filteredLogs.slice(start, end);
            
            let html = '';
            
            if(paginatedItems.length === 0) {
                html = '<tr><td colspan="3" class="text-center py-5 text-secondary fw-bold">No history logs match your filter.</td></tr>';
            } else {
                paginatedItems.forEach(h => {
                    let d = new Date(h.changed_at);
                    let dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
                    
                    let changesHTML = '';
                    if (h.old_name !== h.new_name) changesHTML += `<div class="mb-1"><span class="badge bg-light text-dark border">Name</span> <del class="text-danger">${h.old_name}</del> &rarr; <span class="text-success fw-bold">${h.new_name}</span></div>`;
                    if (h.old_specs !== h.new_specs) changesHTML += `<div class="mb-1"><span class="badge bg-light text-dark border">Specs</span> <del class="text-danger">${h.old_specs}</del> &rarr; <span class="text-success fw-bold">${h.new_specs}</span></div>`;
                    if (h.old_srp !== h.new_srp) {
                        let os = h.old_srp ? parseFloat(h.old_srp).toFixed(2) : "None";
                        let ns = h.new_srp ? parseFloat(h.new_srp).toFixed(2) : "None";
                        changesHTML += `<div><span class="badge bg-light text-dark border">SRP</span> <del class="text-danger">₱${os}</del> &rarr; <span class="text-success fw-bold">₱${ns}</span></div>`;
                    }

                    html += `
                        <tr>
                            <td class="text-secondary fw-bold ps-3" style="white-space: nowrap;">${dateStr}</td>
                            <td>
                                <span class="badge bg-secondary mb-1">${h.category_name}</span><br>
                                <strong class="text-dark">${h.brand_name}</strong>
                            </td>
                            <td>${changesHTML}</td>
                        </tr>
                    `;
                });
            }
            
            document.getElementById('logTableBody').innerHTML = html;
            updatePaginationInfo();
        }

        function updatePaginationInfo() {
            let total = filteredLogs.length;
            let start = total === 0 ? 0 : ((currentPage - 1) * rowsPerPage) + 1;
            let end = Math.min(currentPage * rowsPerPage, total);
            
            document.getElementById('pageInfo').innerText = `Showing ${start} to ${end} of ${total} entries`;
            document.getElementById('prevBtn').disabled = currentPage === 1;
            document.getElementById('nextBtn').disabled = end >= total;
        }

        function prevPage() { if (currentPage > 1) { currentPage--; renderTable(); } }
        function nextPage() { if (currentPage * rowsPerPage < filteredLogs.length) { currentPage++; renderTable(); } }

        document.addEventListener("DOMContentLoaded", filterLogs);

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
</main> </div>
    </div>

    <?php 
    // Determine if the user is forced to reset their password
    $is_forced_reset = isset($_SESSION['require_password_change']) && $_SESSION['require_password_change'] === true; 
    ?>

    <div class="modal fade" id="adminProfileModal" tabindex="-1" aria-hidden="true" <?= $is_forced_reset ? 'data-bs-backdrop="static" data-bs-keyboard="false"' : '' ?> style="z-index: 1055;">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; background-color: #f4f6f9;">
                <div class="modal-header border-0 pb-0 px-4 pt-4">
                    <div>
                        <h4 class="modal-title fw-bold" style="color: #0A0A3A;">Account Settings</h4>
                        <p class="text-secondary small mb-0">Manage your profile details and security credentials</p>
                    </div>
                    <?php if (!$is_forced_reset): ?>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <?php endif; ?>
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
                                            <input type="text" id="adminFirstName" class="form-control bg-light text-secondary" value="<?= htmlspecialchars($admin_first ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label small fw-bold text-secondary">Last Name</label>
                                            <input type="text" id="adminLastName" class="form-control bg-light text-secondary" value="<?= htmlspecialchars($admin_last ?? '') ?>" required>
                                        </div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">Username</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-at text-secondary"></i></span>
                                            <input type="text" id="adminUsername" class="form-control border-start-0 bg-light text-secondary" value="<?= htmlspecialchars($_SESSION['username'] ?? 'admin') ?>" required>
                                        </div>
                                    </div>
                                    <button type="submit" class="btn btn-dark fw-bold w-100 shadow-sm mb-5" style="border-radius: 6px;" <?= $is_forced_reset ? 'disabled' : '' ?>>Save Profile Changes</button>
                                </form>
                                <hr class="text-secondary mb-4">
                                <h6 class="fw-bold mb-3 text-secondary mt-4"><i class="bi bi-hdd-network me-2"></i> System Administration</h6>
                                <div class="p-3 bg-light rounded border">
                                    <p class="small text-secondary mb-3">Download a complete backup of the database system including all price records and product masterlists.</p>
                                    <div class="text-end">
                                        <button type="button" class="btn btn-success fw-bold px-3 shadow-sm w-100" style="border-radius: 6px;" onclick="openBackupModal()" <?= $is_forced_reset ? 'disabled' : '' ?>><i class="bi bi-download me-1"></i> Download Backup</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-7">
                            <div class="bg-white p-4 rounded shadow-sm border h-100">
                                <h6 class="fw-bold mb-4 text-secondary"><i class="bi bi-shield-lock-fill me-2" style="color: #fd7e14;"></i> Security & Password</h6>
                                
                                <?php if ($is_forced_reset): ?>
                                    <div class="alert alert-danger fw-bold small p-3 mb-4 shadow-sm" style="border-left: 5px solid #dc3545;">
                                        <i class="bi bi-shield-exclamation fs-5 me-2" style="vertical-align: middle;"></i> 
                                        ACTION REQUIRED: You must change your temporary password before accessing the system.
                                    </div>
                                <?php endif; ?>

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
                                        <div class="small">For your security, it is highly recommended to use a password containing at least 8 characters, one number, and one special character.</div>
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

        <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0A0A3A;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="forgotModalTitle">Forgot Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-3 text-secondary" id="forgotMessage">
                    For security purposes, automated password recovery is disabled. Please contact your IT Personnel to reset your account password or issue a temporary password.
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-primary fw-bold px-4 shadow-sm" id="confirmModalBtn" data-bs-dismiss="modal">Ok</button>
                </div>
            </div>
        </div>
    </div>
    <script src="../bootstrap/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            // Force open the profile modal if the user must change their password
            <?php if ($is_forced_reset): ?>
                var profileModal = new bootstrap.Modal(document.getElementById('adminProfileModal'), {
                    backdrop: 'static',
                    keyboard: false
                });
                profileModal.show();
            <?php endif; ?>
        });

        // GLOBAL MODALS LOGIC
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
            newBtn.addEventListener('click', function() { modal.hide(); callback(); });
            modal.show();
        }

        function confirmLinkAction(e, url, title, message, colorClass, btnText) { e.preventDefault(); showConfirmModal(title, message, colorClass, btnText, function() { window.location.href = url; }); }
        function confirmLogout(e) { e.preventDefault(); showConfirmModal('Secure Logout', 'Are you sure you want to log out of the system?', 'danger', '<i class="bi bi-box-arrow-right"></i> Logout', function() { window.location.href = '../admin/logout.php'; }); }
        function openBackupModal() { document.getElementById('backupAuthPassword').value = ''; document.getElementById('backupAuthError').classList.add('d-none'); new bootstrap.Modal(document.getElementById('backupAuthModal')).show(); }

        document.getElementById('confirmBackupBtn')?.addEventListener('click', async function() {
            let pass = document.getElementById('backupAuthPassword').value;
            if(!pass) return;
            let btn = this; let origText = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Verifying...'; btn.disabled = true;
            let fd = new FormData(); fd.append('action', 'verify_password_only'); fd.append('password', pass);
            try {
                let res = await fetch('ajax_handler.php', { method: 'POST', body: fd }); let data = await res.json();
                if(data.status === 'success') {
                    bootstrap.Modal.getInstance(document.getElementById('backupAuthModal')).hide();
                    showConfirmModal('Download Backup', 'Authentication successful. Download backup now?', 'success', '<i class="bi bi-download"></i> Download', function() { window.location.href = 'ajax_handler.php?action=download_backup'; });
                } else { document.getElementById('backupAuthError').classList.remove('d-none'); }
            } catch(err) { alert("Connection error."); }
            btn.innerHTML = origText; btn.disabled = false;
        });

        async function updateAdminProfile(e) {
            e.preventDefault();
            showConfirmModal('Update Profile', 'Save these profile changes?', 'dark', '<i class="bi bi-check-circle"></i> Save Changes', async function() {
                const btn = document.querySelector('#profileForm button[type="submit"]'); const origText = btn.innerText;
                btn.innerText = "Saving..."; btn.disabled = true;
                let fd = new FormData(); fd.append('action', 'update_admin_profile');
                fd.append('firstname', document.getElementById('adminFirstName').value);
                fd.append('lastname', document.getElementById('adminLastName').value);
                fd.append('username', document.getElementById('adminUsername').value);
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd }); let data = await res.json();
                    if(data.status === 'success') location.reload(); else alert("Error: " + data.message);
                } catch(err) { alert("Connection error."); }
                btn.innerText = origText; btn.disabled = false;
            });
        }

        async function updateAdminPassword(e) {
            e.preventDefault();
            let newPass = document.getElementById('newPassword').value; 
            let confPass = document.getElementById('confirmPassword').value;
            
            if (newPass !== confPass) return alert("New passwords do not match!");
            
            // Client-side regex check for faster UI feedback
            const regex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[A-Za-z\d\W_]{8,}$/;
            if (!regex.test(newPass)) {
                return alert("Password must be at least 8 characters long and include an uppercase letter, a lowercase letter, a number, and a special character.");
            }

            showConfirmModal('Update Password', 'Change your password? You will be logged out upon success.', 'primary', '<i class="bi bi-shield-lock"></i> Update Password', async function() {
                const btn = document.querySelector('#passwordForm button[type="submit"]'); const origText = btn.innerText;
                btn.innerText = "Updating..."; btn.disabled = true;
                let fd = new FormData(); fd.append('action', 'update_admin_password');
                fd.append('current_password', document.getElementById('currentPassword').value);
                fd.append('new_password', newPass);
                try {
                    let res = await fetch('ajax_handler.php', { method: 'POST', body: fd }); let data = await res.json();
                    if(data.status === 'success') window.location.href = '../admin/logout.php'; else alert("Error: " + data.message);
                } catch(err) { alert("Connection error."); }
                btn.innerText = origText; btn.disabled = false;
            });
        }

        // =========================================================================
        // AJAX SINGLE PAGE APPLICATION (SPA) ROUTER LOGIC
        // =========================================================================
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.sidebar .nav-link, #mobileSidebar .nav-link').forEach(link => {
                link.addEventListener('click', async function(e) {
                    const targetUrl = this.getAttribute('href');
                    
                    // Don't intercept external links or logouts
                    if (!targetUrl || targetUrl === '#' || targetUrl.includes('logout.php')) return;

                    e.preventDefault();
                    
                    const mainContent = document.getElementById('spa-main-content');
                    mainContent.classList.add('spa-loading'); // Add a fade effect

                    try {
                        const response = await fetch(targetUrl);
                        if (!response.ok) throw new Error('Network error');

                        const htmlText = await response.text();
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(htmlText, 'text/html');
                        
                        // Extract ONLY the main content area from the fetched page
                        const newMain = doc.getElementById('spa-main-content');
                        
                        if (newMain) {
                            mainContent.innerHTML = newMain.innerHTML;
                            window.history.pushState({ path: targetUrl }, '', targetUrl); // Update Browser URL secretly
                            
                            // Highlight the correct link in the sidebar dynamically
                            document.querySelectorAll('.sidebar .nav-link, #mobileSidebar .nav-link').forEach(nav => {
                                nav.className = 'nav-link py-3 text-white';
                                nav.style = '';
                            });
                            document.querySelectorAll(`.nav-link[href="${targetUrl}"]`).forEach(activeNav => {
                                activeNav.className = 'nav-link active py-3 fw-bold';
                                activeNav.style = 'background-color: rgba(255,255,255,0.1); border-left: 4px solid white; color: white;';
                            });

                            // Critical: Force any scripts inside the new content to actually run!
                            mainContent.querySelectorAll('script').forEach(script => {
                                const newScript = document.createElement('script');
                                if (script.src) newScript.src = script.src;
                                else newScript.textContent = script.textContent;
                                document.body.appendChild(newScript);
                                document.body.removeChild(newScript); // Clean it up instantly
                            });
                        } else {
                            window.location.href = targetUrl; // Fallback if extraction fails
                        }
                    } catch(err) {
                        window.location.href = targetUrl; // Fallback on network error
                    } finally {
                        mainContent.classList.remove('spa-loading'); // Remove fade effect
                    }
                });
            });

            // Handle the browser's Back and Forward arrows safely
            window.addEventListener('popstate', () => window.location.reload());
        });
    </script>
</body>
</html>
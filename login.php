<?php 
session_start(); 
require_once 'classes/database.php';

if (isset($_SESSION['admin_id']) && isset($_SESSION['logged_in'])) {
    header("Location: /dti/admin/dashboard.php");
    exit();
}

// =======================================================================
// IT OVERRIDE & MANAGEMENT LOGIC
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['it_action'])) {
    
    try {
        $database = new Database();
        $conn = $database->getConnection();

        // ---------------------------------------------------------
        // ACTION: UNLOCK IT CONSOLE
        // ---------------------------------------------------------
        if ($_POST['it_action'] === 'unlock_console') {
            $it_user = htmlspecialchars(trim($_POST['it_username']));
            $it_pass = $_POST['it_password'];

            $stmt = $conn->prepare("SELECT password, role FROM admin WHERE username = ? LIMIT 1");
            $stmt->execute([$it_user]);
            $it_account = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($it_account && ($it_account['role'] === 'System Administrator' || $it_account['role'] === 'IT')) {
                if (password_verify($it_pass, $it_account['password']) || $it_pass === $it_account['password']) {
                    $_SESSION['it_console_unlocked'] = true;
                    $_SESSION['it_auth_user'] = $it_user;
                    $_SESSION['success'] = "IT Console successfully unlocked. Welcome, $it_user.";
                } else {
                    $_SESSION['error'] = "Authentication Failed: Invalid IT password.";
                }
            } else {
                $_SESSION['error'] = "Authentication Failed: Invalid credentials or insufficient privileges.";
            }
            header("Location: login.php?itpersonnel=1");
            exit();
        }

        // ---------------------------------------------------------
        // ACTION: LOCK IT CONSOLE
        // ---------------------------------------------------------
        if ($_POST['it_action'] === 'lock_console') {
            unset($_SESSION['it_console_unlocked']);
            unset($_SESSION['it_auth_user']);
            $_SESSION['success'] = "IT Console securely locked.";
            header("Location: login.php?itpersonnel=1");
            exit();
        }

        // ---------------------------------------------------------
        // SECURE ACTIONS (REQUIRE UNLOCKED CONSOLE + PASSWORD CONFIRMATION)
        // ---------------------------------------------------------
        if (isset($_SESSION['it_console_unlocked']) && $_SESSION['it_console_unlocked'] === true) {
            $it_user = $_SESSION['it_auth_user'];
            $it_pass_confirm = $_POST['it_password'] ?? '';

            // Verify the confirmation password
            $stmt = $conn->prepare("SELECT password FROM admin WHERE username = ? LIMIT 1");
            $stmt->execute([$it_user]);
            $hash = $stmt->fetchColumn();

            if ($hash && (password_verify($it_pass_confirm, $hash) || $it_pass_confirm === $hash)) {
                
                // ACTION 1: UNLOCK OR RESET PASSWORD
                if ($_POST['it_action'] === 'manage_user') {
                    $target_user = htmlspecialchars(trim($_POST['target_username']));
                    $temp_pass = trim($_POST['temp_password']);

                    $check = $conn->prepare("SELECT id FROM admin WHERE username = ?");
                    $check->execute([$target_user]);
                    
                    if ($check->rowCount() > 0) {
                        if (!empty($temp_pass)) {
                            $new_hash = password_hash($temp_pass, PASSWORD_DEFAULT);
                            $upd = $conn->prepare("UPDATE admin SET password = ?, failed_attempts = 0, lockout_until = NULL, is_locked = 0, require_password_change = 1 WHERE username = ?");
                            $upd->execute([$new_hash, $target_user]);
                            $_SESSION['success'] = "Account '$target_user' unlocked and password reset successfully.";
                        } else {
                            $upd = $conn->prepare("UPDATE admin SET failed_attempts = 0, lockout_until = NULL, is_locked = 0 WHERE username = ?");
                            $upd->execute([$target_user]);
                            $_SESSION['success'] = "Account '$target_user' successfully unlocked.";
                        }
                        unset($_SESSION['lockout_timestamp']);
                    } else {
                        $_SESSION['error'] = "Target account '$target_user' not found.";
                    }

                // ACTION 2: ADD NEW USER
                } elseif ($_POST['it_action'] === 'add_user') {
                    $new_user = htmlspecialchars(trim($_POST['new_username']));
                    $new_pass = trim($_POST['new_password']);
                    $new_role = trim($_POST['new_role']);
                    $new_first = htmlspecialchars(trim($_POST['new_firstname']));
                    $new_last = htmlspecialchars(trim($_POST['new_lastname']));

                    $check = $conn->prepare("SELECT id FROM admin WHERE username = ?");
                    $check->execute([$new_user]);
                    
                    if ($check->rowCount() > 0) {
                        $_SESSION['error'] = "Username '$new_user' already exists!";
                    } else {
                        $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
                        $ins = $conn->prepare("INSERT INTO admin (username, password, firstname, lastname, role, failed_attempts, is_locked, require_password_change) VALUES (?, ?, ?, ?, ?, 0, 0, 1)");
                        $ins->execute([$new_user, $new_hash, $new_first, $new_last, $new_role]);
                        $_SESSION['success'] = "New $new_role account '$new_user' created successfully.";
                    }
                
                // ACTION 3: CHANGE IT PASSWORD
                } elseif ($_POST['it_action'] === 'change_it_password') {
                    $new_it_pass = trim($_POST['new_it_password']);
                    if (!empty($new_it_pass)) {
                        $new_hash = password_hash($new_it_pass, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE admin SET password = ? WHERE username = ?");
                        $upd->execute([$new_hash, $it_user]);
                        $_SESSION['success'] = "Your IT Master Password has been successfully updated.";
                    } else {
                        $_SESSION['error'] = "New password cannot be empty.";
                    }
                }

            } else {
                $_SESSION['error'] = "Action Denied: Incorrect IT password confirmation.";
            }
        } else {
            $_SESSION['error'] = "Action Denied: IT Console is locked.";
        }

    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
    
    header("Location: login.php?itpersonnel=1");
    exit();
}

// Determine if there is an active lockout timer for the current terminal
$lockout_seconds = 0;
if (isset($_SESSION['lockout_timestamp'])) {
    $remaining = $_SESSION['lockout_timestamp'] - time();
    if ($remaining > 0) {
        $lockout_seconds = $remaining;
    } else {
        unset($_SESSION['lockout_timestamp']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - DTI Price Monitoring System</title>
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="bootstrap/icons/bootstrap-icons.css">
    <style>
        /* Custom styles for IT Modal Tabs */
        .it-nav-tabs .nav-link { color: #6c757d; border-radius: 0; font-weight: bold; border: none; border-bottom: 3px solid transparent; }
        .it-nav-tabs .nav-link.active { color: #0A0A3A; background: transparent; border-bottom: 3px solid #0A0A3A; }
        .it-nav-tabs .nav-link:hover { border-color: #e9ecef; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm custom-navbar">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <span class="fw-bold">Price Monitoring System</span>
            </a>
        </div>
    </nav>

    <div class="login-background" style="background-image: url('assets/images/bg.png'); background:linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)),  url('assets/images/bg.png') no-repeat center center/cover">
    <div class="login-card bg-white p-5 text-center">
        <div class="mb-4">
            <img src="assets/images/DTI_PH-Logo.png" alt="DTI Logo" class="img-fluid" style="max-height: 80px;">
        </div>
        
        <h4 class="fw-bold mb-1" style="color: #0A0A3A;">Welcome Back</h4>
        <p class="text-secondary small mb-4">Please log in to manage Region IX Price Monitoring</p>

        <?php if (isset($_SESSION['error']) && !isset($_GET['itpersonnel'])): ?>
            <div class="alert alert-danger small p-2 mb-3 text-start" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <span id="errorText"><?= htmlspecialchars($_SESSION['error']); ?></span>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success']) && !isset($_GET['itpersonnel'])): ?>
            <div class="alert alert-success small p-2 mb-3 text-start" role="alert">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?= htmlspecialchars($_SESSION['success']); ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if ($lockout_seconds > 0): ?>
            <div class="alert alert-danger p-3 mb-3 text-center shadow-sm" id="lockoutContainer">
                <i class="bi bi-shield-lock-fill fs-3 text-danger d-block mb-1" id="lockIcon"></i>
                <span class="fw-bold text-danger d-block mb-2" id="errorText">Account Temporarily Locked</span>
                <div class="d-inline-block p-2 bg-white rounded border border-danger">
                    <i class="bi bi-stopwatch text-danger" id="watchIcon"></i>
                    <span id="lockoutTimer" class="fw-bold fs-5 text-danger ms-1">--:--</span>
                </div>
            </div>
        <?php endif; ?>

        <form action="process_login.php" method="POST">
            <div class="mb-3 text-start">
                <label class="form-label small fw-bold text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-secondary"></i></span>
                    <input type="text" name="username" class="form-control border-start-0" placeholder="Enter your username" required <?= $lockout_seconds > 0 ? 'readonly' : '' ?>>
                </div>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label small fw-bold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-secondary"></i></span>
                    <input type="password" name="password" class="form-control border-start-0" placeholder="Enter your password" required <?= $lockout_seconds > 0 ? 'readonly' : '' ?>>
                </div>
            </div>
             <p class="small text-secondary mt-2 mb-4 text-end">
                <a href="#" class="text-decoration-none" style="color: #8B0000;" data-bs-toggle="modal" data-bs-target="#forgotPasswordModal"><i class="bi bi-question-circle" style="color: #8B0000;"></i> Forgot Password</a>
            </p>
            <button type="submit" id="loginBtn" class="btn btn-login w-100 mb-3 shadow-sm" style="background-color: #0A0A3A;color: white; font-weight: bold; border-radius: 4px; padding: 12px; transition: 0.3s;" <?= $lockout_seconds > 0 ? 'disabled' : '' ?>>
                <?= $lockout_seconds > 0 ? '<i class="bi bi-lock-fill"></i> LOCKED OUT' : 'LOG IN' ?>
            </button>
            
            <p class="small text-secondary mt-3">
                <i class="bi bi-shield-lock me-1"></i> Authorized Personnel Only
            </p>
        </form>
    </div>
    </div>
    <footer class="footer footer-expand-lg navbar-dark shadow-sm custom-navbar">
        &copy; <?= date("Y"); ?> DTI Region IX Price Monitoring. All rights reserved.
    </footer>

    <div class="modal fade" id="forgotPasswordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #8B0000;">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-shield-lock me-2" style="color: #8B0000;"></i>Password Recovery</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-3 text-secondary text-center">
                    <i class="bi bi-exclamation-triangle fs-1 text-warning mb-3 d-block"></i>
                    <p class="mb-0">For security purposes, automated password recovery is disabled on this local terminal.</p>
                    <p class="mt-2 mb-0 fw-bold text-dark">Please contact your IT Personnel or System Administrator to issue a temporary password or unlock your account.</p>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 px-4 d-flex justify-content-center">
                    <button type="button" class="btn btn-dark fw-bold px-5 shadow-sm" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="itOverrideModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 12px; border-top: 5px solid #0A0A3A; overflow: hidden;">
                
                <div class="modal-header bg-light border-0 pb-2 pt-3">
                    <h5 class="modal-title fw-bold" style="color: #0A0A3A;"><i class="bi bi-terminal-fill me-2"></i>IT Command Console</h5>
                    <button type="button" class="btn-close" onclick="window.location.href='login.php'" aria-label="Close"></button>
                </div>

                <?php if (!isset($_SESSION['it_console_unlocked']) || $_SESSION['it_console_unlocked'] !== true): ?>
                    <div class="bg-light px-3 border-bottom">
                        <ul class="nav nav-tabs it-nav-tabs border-0" id="itLockedTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#it-login" type="button" role="tab">Authenticate</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link text-danger" data-bs-toggle="tab" data-bs-target="#it-failsafe" type="button" role="tab">Failsafe</button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content">
                        <?php if (isset($_SESSION['error']) && isset($_GET['itpersonnel'])): ?>
                            <div class="alert alert-danger small m-3 mb-0" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($_SESSION['success']) && isset($_GET['itpersonnel'])): ?>
                            <div class="alert alert-success small m-3 mb-0" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="tab-pane fade show active" id="it-login" role="tabpanel">
                            <form method="POST" action="login.php">
                                <div class="modal-body p-4 pt-3 text-start">
                                    <input type="hidden" name="it_action" value="unlock_console">
                                    <p class="small text-secondary mb-4">Please authenticate with your IT Master Credentials to access the system recovery and provisioning tools.</p>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">IT Username</label>
                                        <input type="text" name="it_username" class="form-control bg-light" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">IT Password</label>
                                        <input type="password" name="it_password" class="form-control bg-light" required>
                                    </div>
                                </div>
                                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                                    <button type="submit" class="btn w-100 fw-bold shadow-sm" style="background-color: #0A0A3A; color: white;">Unlock Console</button>
                                </div>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="it-failsafe" role="tabpanel">
                            <form id="ajaxHashForm" onsubmit="generateAjaxHash(event)">
                                <div class="modal-body p-4 pt-3 text-start">
                                    <div class="alert alert-warning p-2 small mb-4">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Offline Disaster Recovery:</strong> Generate a hash to manually inject via PHPMyAdmin if you are locked out.
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">Desired Password Phrase</label>
                                        <input type="text" id="ajax_string_to_hash" class="form-control bg-light" placeholder="e.g. DtiAdmin2026!" required>
                                    </div>
                                    
                                    <div id="hashResultContainer" class="d-none">
                                        <p class="small fw-bold text-success mb-1"><i class="bi bi-check-circle-fill"></i> Hash Generated Successfully:</p>
                                        <code id="hashOutput" class="user-select-all bg-white p-2 d-block border text-dark fw-bold mb-3" style="word-break: break-all; font-size: 0.85rem;"></code>
                                    </div>
                                    <div id="hashErrorContainer" class="alert alert-danger p-2 small d-none"></div>

                                </div>
                                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                                    <button type="submit" id="hashGenBtn" class="btn btn-dark w-100 fw-bold shadow-sm">Generate Offline Hash</button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="bg-light px-3 border-bottom d-flex justify-content-between align-items-center">
                        <ul class="nav nav-tabs it-nav-tabs border-0" id="itTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#unlock" type="button" role="tab">Recovery</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#add" type="button" role="tab">Provision</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#utils" type="button" role="tab">Utilities</button>
                            </li>
                        </ul>
                        <form method="POST" action="login.php" class="m-0">
                            <input type="hidden" name="it_action" value="lock_console">
                            <button type="submit" class="btn btn-sm btn-outline-danger fw-bold shadow-sm" title="Lock Console"><i class="bi bi-lock-fill"></i></button>
                        </form>
                    </div>

                    <div class="tab-content" id="itTabContent">
                        
                        <?php if (isset($_SESSION['error']) && isset($_GET['itpersonnel'])): ?>
                            <div class="alert alert-danger small m-3 mb-0" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_SESSION['success']) && isset($_GET['itpersonnel'])): ?>
                            <div class="alert alert-success small m-3 mb-0" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i>
                                <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="tab-pane fade show active" id="unlock" role="tabpanel">
                            <form method="POST" action="login.php">
                                <div class="modal-body p-4 pt-3 text-start">
                                    <input type="hidden" name="it_action" value="manage_user">
                                    
                                    <div class="alert alert-primary p-2 small mb-4">
                                        <i class="bi bi-info-circle-fill me-1"></i> Use this to instantly unlock an account. Enter a temp password to reset it, or leave blank to just unlock.
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">Target Username</label>
                                        <input type="text" name="target_username" class="form-control" placeholder="User to modify" required>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label small fw-bold text-secondary">Temporary Password (Optional)</label>
                                        <input type="text" name="temp_password" class="form-control" placeholder="Leave blank to keep current password">
                                    </div>

                                    <hr class="text-secondary">
                                    <p class="small fw-bold text-danger mb-2">IT Authorization Required</p>
                                    <input type="password" name="it_password" class="form-control bg-light border-danger" placeholder="Confirm your IT Password to execute" required>
                                </div>
                                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                                    <button type="submit" class="btn w-100 fw-bold shadow-sm" style="background-color: #0A0A3A; color: white;">Execute Override</button>
                                </div>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="add" role="tabpanel">
                            <form method="POST" action="login.php">
                                <div class="modal-body p-4 pt-3 text-start">
                                    <input type="hidden" name="it_action" value="add_user">

                                    <div class="row g-2 mb-3">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-secondary">First Name</label>
                                            <input type="text" name="new_firstname" class="form-control" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-secondary">Last Name</label>
                                            <input type="text" name="new_lastname" class="form-control" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">New Username</label>
                                        <input type="text" name="new_username" class="form-control" required>
                                    </div>

                                    <div class="row g-2 mb-4">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-secondary">Password</label>
                                            <input type="text" name="new_password" class="form-control" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold text-secondary">Role</label>
                                            <select name="new_role" class="form-select" required>
                                                <option value="Regional Admin">Regional Admin</option>
                                                <option value="System Administrator">System Administrator (IT)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <hr class="text-secondary">
                                    <p class="small fw-bold text-danger mb-2">IT Authorization Required</p>
                                    <input type="password" name="it_password" class="form-control bg-light border-danger" placeholder="Confirm your IT Password to execute" required>
                                </div>
                                <div class="modal-footer border-0 pt-0 pb-4 px-4">
                                    <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm">Provision User</button>
                                </div>
                            </form>
                        </div>

                        <div class="tab-pane fade" id="utils" role="tabpanel">
                            <div class="modal-body p-4 pt-3 text-start">
                                
                                <form method="POST" action="login.php" class="mb-4">
                                    <input type="hidden" name="it_action" value="change_it_password">
                                    <h6 class="fw-bold" style="color: #0A0A3A;"><i class="bi bi-key me-2"></i>Change IT Password</h6>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">New IT Password</label>
                                        <input type="text" name="new_it_password" class="form-control bg-light" placeholder="Enter new password" required>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-8">
                                            <input type="password" name="it_password" class="form-control border-danger" placeholder="Confirm current IT pass" required>
                                        </div>
                                        <div class="col-4">
                                            <button type="submit" class="btn btn-warning w-100 fw-bold shadow-sm">Update</button>
                                        </div>
                                    </div>
                                </form>

                                <hr class="text-secondary">

                                <form id="ajaxHashFormUtils" onsubmit="generateAjaxHashUtils(event)">
                                    <h6 class="fw-bold" style="color: #0A0A3A;"><i class="bi bi-hash me-2"></i>Manual Hash Generator</h6>
                                    
                                    <div class="mb-3">
                                        <label class="form-label small fw-bold text-secondary">Desired Password Phrase</label>
                                        <input type="text" id="utils_string_to_hash" class="form-control bg-light" placeholder="e.g. DtiAdmin2026!" required>
                                    </div>
                                    
                                    <div id="utilsHashResultContainer" class="d-none">
                                        <p class="small fw-bold text-success mb-1"><i class="bi bi-check-circle-fill"></i> Hash Generated Successfully:</p>
                                        <code id="utilsHashOutput" class="user-select-all bg-white p-2 d-block border text-dark fw-bold mb-3" style="word-break: break-all; font-size: 0.85rem;"></code>
                                    </div>
                                    <div id="utilsHashErrorContainer" class="alert alert-danger p-2 small d-none"></div>

                                    <button type="submit" id="utilsHashGenBtn" class="btn btn-dark w-100 fw-bold shadow-sm">Generate Hash</button>
                                </form>

                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Secret IT override trigger: http://localhost/dti/login.php?itpersonnel=1
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('itpersonnel') && urlParams.get('itpersonnel') === '1') {
                var itModal = new bootstrap.Modal(document.getElementById('itOverrideModal'));
                itModal.show();
            }

            // Live Countdown Timer Logic
            let remainingSeconds = <?= (int)$lockout_seconds ?>;
            
            if (remainingSeconds > 0) {
                const timerDisplay = document.getElementById('lockoutTimer');
                const loginBtn = document.getElementById('loginBtn');
                const inputs = document.querySelectorAll('input[name="username"], input[name="password"]');
                const errorText = document.getElementById('errorText');
                const container = document.getElementById('lockoutContainer');
                const lockIcon = document.getElementById('lockIcon');
                const watchIcon = document.getElementById('watchIcon');

                if (timerDisplay) {
                    const interval = setInterval(() => {
                        remainingSeconds--;
                        let m = Math.floor(remainingSeconds / 60);
                        let s = remainingSeconds % 60;
                        
                        timerDisplay.innerText = m + ":" + (s < 10 ? '0' : '') + s;

                        if (remainingSeconds <= 0) {
                            clearInterval(interval);
                            timerDisplay.innerText = "0:00";
                            
                            if (loginBtn) {
                                loginBtn.disabled = false;
                                loginBtn.innerHTML = "LOG IN";
                            }
                            
                            inputs.forEach(input => input.readOnly = false);
                            
                            if (errorText) {
                                errorText.innerText = "Lockout expired. You may log in now.";
                                errorText.classList.replace('text-danger', 'text-success');
                            }
                            
                            if (container) {
                                container.classList.replace('alert-danger', 'alert-success');
                                container.querySelector('.border-danger').classList.replace('border-danger', 'border-success');
                            }
                            
                            if (lockIcon) {
                                lockIcon.classList.replace('bi-shield-lock-fill', 'bi-shield-check-fill');
                                lockIcon.classList.replace('text-danger', 'text-success');
                            }
                            
                            if (watchIcon) {
                                watchIcon.classList.replace('text-danger', 'text-success');
                                timerDisplay.classList.replace('text-danger', 'text-success');
                            }
                        }
                    }, 1000);
                }
            }
        });

        // =======================================================================
        // AJAX HASH GENERATOR LOGIC (No Page Reload)
        // =======================================================================
        async function generateAjaxHash(e) {
            e.preventDefault();
            const phraseInput = document.getElementById('ajax_string_to_hash').value;
            const btn = document.getElementById('hashGenBtn');
            const resultBox = document.getElementById('hashResultContainer');
            const errorBox = document.getElementById('hashErrorContainer');
            const output = document.getElementById('hashOutput');

            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Generating...';
            errorBox.classList.add('d-none');
            resultBox.classList.add('d-none');

            let fd = new FormData();
            fd.append('ajax_action', 'generate_hash');
            fd.append('string_to_hash', phraseInput);

            try {
                let res = await fetch('process_login.php', { method: 'POST', body: fd });
                let data = await res.json();
                
                if (data.status === 'success') {
                    output.innerText = data.hash;
                    resultBox.classList.remove('d-none');
                } else {
                    errorBox.innerText = data.message;
                    errorBox.classList.remove('d-none');
                }
            } catch (err) {
                errorBox.innerText = "Connection error while generating hash.";
                errorBox.classList.remove('d-none');
            }

            btn.disabled = false;
            btn.innerHTML = 'Generate Offline Hash';
        }

        async function generateAjaxHashUtils(e) {
            e.preventDefault();
            const phraseInput = document.getElementById('utils_string_to_hash').value;
            const btn = document.getElementById('utilsHashGenBtn');
            const resultBox = document.getElementById('utilsHashResultContainer');
            const errorBox = document.getElementById('utilsHashErrorContainer');
            const output = document.getElementById('utilsHashOutput');

            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Generating...';
            errorBox.classList.add('d-none');
            resultBox.classList.add('d-none');

            let fd = new FormData();
            fd.append('ajax_action', 'generate_hash');
            fd.append('string_to_hash', phraseInput);

            try {
                let res = await fetch('process_login.php', { method: 'POST', body: fd });
                let data = await res.json();
                
                if (data.status === 'success') {
                    output.innerText = data.hash;
                    resultBox.classList.remove('d-none');
                } else {
                    errorBox.innerText = data.message;
                    errorBox.classList.remove('d-none');
                }
            } catch (err) {
                errorBox.innerText = "Connection error while generating hash.";
                errorBox.classList.remove('d-none');
            }

            btn.disabled = false;
            btn.innerHTML = 'Generate Hash';
        }
    </script>
</body>
</html>
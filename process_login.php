<?php
session_start();
require_once 'classes/database.php';

// =======================================================================
// BACKGROUND AJAX HANDLER FOR HASH GENERATOR
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'generate_hash') {
    header('Content-Type: application/json');
    $raw_string = trim($_POST['string_to_hash']);
    
    if (!empty($raw_string)) {
        $generated_hash = password_hash($raw_string, PASSWORD_DEFAULT);
        echo json_encode(['status' => 'success', 'hash' => $generated_hash]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Phrase cannot be empty.']);
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = htmlspecialchars(trim($_POST['username'] ?? ''));
    $password = htmlspecialchars(trim($_POST['password'] ?? ''));

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Please enter both username and password.";
        header("Location: login.php");
        exit();
    }

    try {
        $database = new Database();
        $conn = $database->getConnection();

        // Include the new require_password_change column
        $stmt = $conn->prepare("SELECT id, username, password, failed_attempts, lockout_until, is_locked, role, require_password_change FROM admin WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            // 1. CHECK HARD LOCK
            if ($admin['is_locked'] == 1) {
                $_SESSION['error'] = "ACCOUNT FROZEN. Due to too many failed attempts, your account has been locked. Please contact IT Personnel to unlock it.";
                header("Location: login.php");
                exit();
            }

            // 2. CHECK 30-MINUTE TIMEOUT
            if ($admin['lockout_until'] !== null) {
                $lockout_time = strtotime($admin['lockout_until']);
                $current_time = time();

                if ($current_time < $lockout_time) {
                    $_SESSION['lockout_timestamp'] = $lockout_time;
                    $_SESSION['error'] = "Account temporarily locked. Please wait for the timer to expire.";
                    header("Location: login.php");
                    exit();
                } else {
                    $upd = $conn->prepare("UPDATE admin SET lockout_until = NULL WHERE id = ?");
                    $upd->execute([$admin['id']]);
                }
            }

            $login_success = false;

            // 3. VERIFY PASSWORD
            if (password_verify($password, $admin['password'])) {
                $login_success = true;
            } elseif ($password === $admin['password']) {
                $login_success = true;
                $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE admin SET password = ? WHERE id = ?");
                $update_stmt->execute([$new_hashed_password, $admin['id']]);
            }

            // 4. HANDLE SUCCESS OR FAILURE
            if ($login_success) {
                $upd = $conn->prepare("UPDATE admin SET failed_attempts = 0, lockout_until = NULL, is_locked = 0 WHERE id = ?");
                $upd->execute([$admin['id']]);

                session_regenerate_id(true);

                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['role'] = $admin['role']; // Save role to session for UI restrictions
                $_SESSION['logged_in'] = true;
                
                // Set the forced reset session flag if needed
                if ($admin['require_password_change'] == 1) {
                    $_SESSION['require_password_change'] = true;
                }

                header("Location: admin/dashboard.php");
                exit();
            } else {
                $new_attempts = $admin['failed_attempts'] + 1;
                $err_msg = "Invalid password. ";

                if ($new_attempts >= 5) {
                    $upd = $conn->prepare("UPDATE admin SET failed_attempts = ?, is_locked = 1 WHERE id = ?");
                    $upd->execute([$new_attempts, $admin['id']]);
                    $err_msg = "ACCOUNT FROZEN. Due to 5 failed attempts, your account has been locked. Please contact IT Personnel.";
                } elseif ($new_attempts == 3) {
                    $lockout_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $upd = $conn->prepare("UPDATE admin SET failed_attempts = ?, lockout_until = ? WHERE id = ?");
                    $upd->execute([$new_attempts, $lockout_until, $admin['id']]);
                    
                    $_SESSION['lockout_timestamp'] = strtotime('+30 minutes');
                    $err_msg = "Too many failed attempts. Your account is locked for 30 minutes.";
                } else {
                    $upd = $conn->prepare("UPDATE admin SET failed_attempts = ? WHERE id = ?");
                    $upd->execute([$new_attempts, $admin['id']]);
                    $tries_left = 3 - $new_attempts;
                    if ($tries_left > 0) {
                        $err_msg .= "You have $tries_left attempt(s) left before a temporary lockout.";
                    } elseif ($new_attempts == 4) {
                        $err_msg .= "WARNING: 1 attempt left before permanent account freeze.";
                    }
                }

                $_SESSION['error'] = $err_msg;
                header("Location: login.php");
                exit();
            }
        } else {
            $_SESSION['error'] = "Account not found. Please check your username.";
            header("Location: login.php");
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: login.php");
        exit();
    }
} else {
    header("Location: login.php");
    exit();
}
?>
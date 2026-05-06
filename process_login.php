<?php
session_start();
require_once 'classes/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // We keep htmlspecialchars for username, but remove it from password 
    // so special characters in passwords don't break during the hash verification.
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

        $stmt = $conn->prepare("SELECT id, username, password FROM admin WHERE username = :username LIMIT 1");
        $stmt->bindParam(':username', $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            $login_success = false;

            // SECURITY UPGRADE: Verify password using secure hashes
            if (password_verify($password, $admin['password'])) {
                // Password is correct and already securely hashed
                $login_success = true;
            } 
            // AUTO-MIGRATION: If password is correct but still in plain text
            elseif ($password === $admin['password']) {
                $login_success = true;
                
                // Automatically generate a secure hash and update the database
                $new_hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE admin SET password = :new_password WHERE id = :id");
                $update_stmt->execute([
                    ':new_password' => $new_hashed_password,
                    ':id' => $admin['id']
                ]);
            }

            if ($login_success) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['logged_in'] = true;

                // Directs accurately into the admin folder dashboard
                header("Location: admin/dashboard.php");
                exit();
            } else {
                $_SESSION['error'] = "Invalid password. Please try again.";
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
<?php 
session_start(); 

if (isset($_SESSION['admin_id']) && isset($_SESSION['logged_in'])) {
    header("Location: /dti/admin/dashboard.php");
    exit();
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
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm custom-navbar">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <span class="fw-bold">Price Monitoring System</span>
            </a>
            <div class="ms-auto">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link text-white me-3" href="index.php">Home</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="login-background" style="background-image: url('assets/images/bg.png'); background:linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)),  url('assets/images/bg.png') no-repeat center center/cover">
    <div class="login-card bg-white p-5 text-center">
        <div class="mb-4">
            <img src="assets/images/DTI_PH-Logo.png" alt="DTI Logo" class="img-fluid" style="max-height: 80px;">
        </div>
        
        <h4 class="fw-bold mb-1" style="color: #0A0A3A;">Welcome Back</h4>
        <p class="text-secondary small mb-4">Please log in to manage Region IX Price Monitoring</p>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger small p-2 mb-3 text-start" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <?= htmlspecialchars($_SESSION['error']); ?>
            </div>
            <?php unset($_SESSION['error']);  ?>
        <?php endif; ?>
        <form action="process_login.php" method="POST">
            <div class="mb-3 text-start">
                <label class="form-label small fw-bold text-secondary">Username</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-person text-secondary"></i></span>
                    <input type="text" name="username" class="form-control border-start-0" placeholder="Enter your username" required>
                </div>
            </div>

            <div class="mb-3 text-start">
                <label class="form-label small fw-bold text-secondary">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-secondary"></i></span>
                    <input type="password" name="password" class="form-control border-start-0" placeholder="Enter your password" required>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="rememberMe" name="rememberMe">
                    <label class="form-check-label small text-secondary" for="rememberMe">Remember me</label>
                </div>
                <a href="#" class="small text-decoration-none fw-bold" style="color: #8B0000;">Forgot Password?</a>
            </div>

            <button type="submit" class="btn btn-login w-100 mb-3 shadow-sm" style="background-color: #0A0A3A;color: white; font-weight: bold; border-radius: 4px; padding: 12px; transition: 0.3s;">
                LOG IN
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
    <script src="/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
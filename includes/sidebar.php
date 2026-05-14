<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 
?>

<nav class="col-md-2 d-md-block sidebar py-4">
    <div class="position-sticky">
        <h5 class="text-white px-3 pb-2 border-bottom border-secondary">Admin Menu</h5>
        <ul class="nav flex-column mt-3 px-2">
            <li class="nav-item">
                <a class="nav-link py-3 <?php echo ($currentPage == 'dashboard.php') ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="bi bi-card-heading me-2"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-3 <?php echo ($currentPage == 'upload.php') ? 'active' : ''; ?>" href="upload.php">
                    <i class="bi bi-upload me-2"></i> Upload Files
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-3 <?php echo ($currentPage == 'provincial.php') ? 'active' : ''; ?>" href="provincial.php">
                    <i class="bi bi-file-earmark-text me-2"></i> Provincial Reports
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-3 <?php echo ($currentPage == 'regional.php') ? 'active' : ''; ?>" href="regional.php">
                    <i class="bi bi-folder me-2"></i> Regional Summary
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link py-3 <?php echo ($currentPage == 'trends.php') ? 'active' : ''; ?>" href="trends.php">
                    <i class="bi bi-graph-up me-2"></i> Price Trends
                </a>
            </li>
        </ul>
        
        <div class="px-3 mt-5 pt-5">
            <a href="logout.php" class="btn btn-dark w-100 text-start py-2">
                <i class="bi bi-box-arrow-left me-2"></i> Logout
            </a>
        </div>
    </div>
</nav>
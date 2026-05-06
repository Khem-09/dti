<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Region IX - DTI</title>
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="bootstrap/icons/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/index.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark shadow-sm custom-navbar">
        <div class="ms-auto">
            <ul>
                <li class="nav-item"><a class="nav-link text-white me-3" href="login.php">Login</a></li>
            </ul>
        </div>
    </nav>
    <div class="background" style="background-image: url('assets/images/bg.png'); background:linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)),  url('assets/images/bg.png') no-repeat center center/cover">
        <div class="container text-center my-5">
            <img src="assets/images/DTI_PH-Logo.png" alt="DTI Logo" class="img-fluid mb-4" style="max-width: 150px;">
            <h1 class="display-4">Welcome to DTI Region IX Price Monitoring</h1>
            <p class="lead mt-3 text">Your trusted source for accurate and up-to-date price information in Region IX.</p>
        </div>
    </div>
    <div>
        <div class="container my-5 about-section">
            <div>
            <h2 class="mb-4">DTI Region IX Price Monitoring</h2>
            <p class="lead">Our Regional Price Monitoring is a dedicated offline solution designed to automate the consolidation of prime commodity data across Region 9. By transforming raw Excel uploads from Zamboanga City, Isabela City, Zamboanga del Sur, Zamboanga del Norte and Zamboanga Sibugay into comprehensive weekly, monthly or yearly reports, we provide instant visibility into price ranges and market trends. From tracking Stock Keeping Units (SKUs) to generating regional KPIs, this system ensures that price monitoring is accurate, automated, and ready for official export.</p>
            </div>
            <img src="assets/images/map.png" alt="Region IX Map" class="img-fluid mt-4" style="max-width: 600px;">
        </div>
    </div>
    <footer class="footer footer-expand-lg navbar-dark shadow-sm custom-navbar">
        &copy; <?= date("Y"); ?> DTI Region IX Price Monitoring. All rights reserved.
    </footer>
</body>
</html>
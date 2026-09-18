<?php
require_once __DIR__ . '/auth.php';
$user = currentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Count pending releases and new contacts for admin
$pending_count = 0;
$contacts_count = 0;
if (isAdmin()) {
    $pending_count = $pdo->query("SELECT COUNT(*) FROM releases WHERE status = 'pending'")->fetchColumn();
    $contacts_count = $pdo->query("SELECT COUNT(*) FROM contacts WHERE status = 'new'")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' | Nexus Tune Portal' : 'Nexus Tune Portal | Global Music Distribution' ?></title>
        <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon.png">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Nexus Tune Artist Portal">
    <meta property="og:description" content="Manage your music distribution, royalties, and streaming analytics.">
    <meta property="og:image" content="assets/images/logo.png">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="assets/images/logo.png">
    
    <!-- FontAwesome & Fonts -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/portal.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Prevent Duplicate Form Resubmission on Page Refresh (PRG) -->
    <script>
    if (window.history.replaceState) {
        window.history.replaceState(null, null, window.location.href);
    }
    </script>
</head>
<body>
<div class="app-wrapper">

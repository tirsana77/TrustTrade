<?php
$errorMessage = $errorMessage ?? 'Something went wrong.';
?>
<!DOCTYPE html>
<html>
<head>
    <title>TrustTrade</title>
    <link rel="stylesheet" href="../public/css/style.css">
</head>
<body class="app-body">
    <div class="page-content" style="max-width:520px; text-align:center;">
        <img src="../public/images/logo.svg" alt="TrustTrade" style="height:44px; margin-bottom:1.5rem;">
        <div class="detail-card">
            <h1>Sorry</h1>
            <p><?= htmlspecialchars($errorMessage) ?></p>
            <p>
                <a href="javascript:history.back()" class="btn-outline">← Go back</a>
                <a href="dashboard.php" class="btn-inline">Home</a>
            </p>
        </div>
    </div>
</body>
</html>
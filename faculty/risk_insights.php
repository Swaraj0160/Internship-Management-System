<?php
// Backward compatibility router bridge
$qs = $_SERVER['QUERY_STRING'] ?? '';
$dest = "reports_analytics.php" . ($qs ? "?$qs" : "");
header("Location: $dest");
exit();

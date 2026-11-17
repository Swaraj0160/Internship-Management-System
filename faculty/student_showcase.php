<?php
// Backward compatibility router bridge
$qs = $_SERVER['QUERY_STRING'] ?? '';
$dest = "internship_oversight.php?tab=showcase" . ($qs ? "&$qs" : "");
header("Location: $dest");
exit();

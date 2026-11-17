<?php
// Backward compatibility router bridge
$qs = $_SERVER['QUERY_STRING'] ?? '';
$dest = "evaluations_marks.php?cohort=TY" . ($qs ? "&$qs" : "");
header("Location: $dest");
exit();
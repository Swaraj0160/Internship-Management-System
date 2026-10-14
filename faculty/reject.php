<?php

$id = intval($_GET['id'] ?? 0);

header("Location: reject_internship.php?id=" . $id);

exit();

?>

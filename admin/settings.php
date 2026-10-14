<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — this file was previously just the bare string
   "<h2>Settings Module Coming Soon</h2>" with no <html>/<head>/<body>
   structure, no session/auth check, and no styling at all (it would
   have rendered as unstyled text floating at the top-left of the
   browser, outside any layout). Wrapped in the shared sidebar shell
   for consistency with the rest of the admin pages, and added the
   admin auth check that was missing. Still a placeholder page —
   no new functionality was invented here.
   ----------------------------------------------------------------- */

$activePage = 'settings.php';

?>
<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>
body{ margin:0; background:#f5f7fb; }
.page-inner{ padding:40px 25px 60px; }
.placeholder-box{
    max-width:700px;
    margin:0 auto;
    background:#fff;
    border-radius:20px;
    border:1px solid #eef1f6;
    box-shadow:0 18px 45px rgba(15,23,42,.06);
    padding:40px;
    text-align:center;
}
.placeholder-box h2{
    font-weight:800;
    color:#0f172a;
    margin-bottom:8px;
}
.placeholder-box p{
    color:#64748b;
    margin:0;
}
</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
    <div class="placeholder-box">
        <h2>Settings Module Coming Soon</h2>
        <p>This section is still under development.</p>
    </div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>

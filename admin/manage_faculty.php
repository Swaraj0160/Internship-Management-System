<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation (batch 2) + fixes found while
   rewiring this page:

   1. SQL injection: search / division were string-concatenated raw
      from $_GET into the query. Converted to a prepared statement.
   2. XSS: full_name, faculty_division, email were echoed with no
      escaping. All now go through htmlspecialchars().
   3. Multi-division display gap: this page only ever showed
      users.faculty_division (the legacy single-value column), so a
      faculty member assigned to BOTH IT-DA and IT-SMAD via the
      faculty_divisions table displayed only one division here (or
      none, if faculty_division was left blank when using the newer
      multi-division assignment). Added a LEFT JOIN +
      GROUP_CONCAT(faculty_divisions.division) so the Division column
      and the division filter both see every division a faculty
      member is assigned to, falling back to the legacy column only
      when faculty_divisions has no rows for that faculty member.
   4. Division filter dropdown was missing IT-DA/IT-SMAD entirely
      (only had SY-1..22) — added, matching every other division
      dropdown in the project.
   5. Ordering: replaced the REPLACE(UPPER(...),'SY-','') trick with
      the IT-DA-first / IT-SMAD-second / SY-* CASE ordering used
      elsewhere.
   ----------------------------------------------------------------- */

$search = trim($_GET['search'] ?? '');
$division = trim($_GET['division'] ?? '');

$where = ["users.role='faculty'"];
$params = [];
$types = "";

if($search !== '')
{
    $where[] = "(users.full_name LIKE ? OR users.email LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if($division !== '')
{
    $where[] = "(users.faculty_division=? OR EXISTS (
        SELECT 1 FROM faculty_divisions fd2
        WHERE fd2.faculty_id = users.id AND fd2.division = ?
    ))";
    $params[] = $division;
    $params[] = $division;
    $types .= "ss";
}

$whereSql = implode(" AND ", $where);

$query = "
    SELECT
        users.id,
        users.full_name,
        users.email,
        users.faculty_division,
        GROUP_CONCAT(DISTINCT faculty_divisions.division ORDER BY
            CASE faculty_divisions.division
                WHEN 'IT-DA'   THEN 0
                WHEN 'IT-SMAD' THEN 1
                ELSE 2
            END, faculty_divisions.division
            SEPARATOR ', ') AS divisions_list

    FROM users

    LEFT JOIN faculty_divisions
        ON faculty_divisions.faculty_id = users.id

    WHERE $whereSql

    GROUP BY users.id

    ORDER BY
        CASE
            WHEN MIN(CASE WHEN faculty_divisions.division='IT-DA' THEN 0 END) IS NOT NULL THEN 0
            WHEN MIN(CASE WHEN faculty_divisions.division='IT-SMAD' THEN 0 END) IS NOT NULL THEN 1
            WHEN users.faculty_division='IT-DA' THEN 0
            WHEN users.faculty_division='IT-SMAD' THEN 1
            ELSE 2
        END ASC,
        users.full_name ASC
";

$stmt = $conn->prepare($query);

if(count($params) > 0)
{
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$totalFaculty = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) total
         FROM users
         WHERE role='faculty'"
    )
)['total'];

$activePage = 'manage_faculty.php';

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Faculty</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@2.47.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

*{
    font-family:'Inter','Segoe UI',system-ui,-apple-system,sans-serif;
}

body{
    background:radial-gradient(circle at 0% 0%, #eef2f9 0%, #f1f5f9 40%, #f1f5f9 100%);
    margin:0;
}

.page-inner{
    padding:0 20px 40px;
}

.container-box{
    width:100%;
    margin:30px auto;
    background:#fff;
    padding:36px 38px;
    border-radius:24px;
    box-shadow:0 1px 2px rgba(15,23,42,.04), 0 20px 44px -20px rgba(15,23,42,.14);
    border:1px solid #F1F5F9;
}

table{
    font-size:14px;
}

/* ===================== Filter Toolbar ===================== */

.filter-toolbar{
    background:linear-gradient(180deg,#FAFBFC 0%,#F6F8FA 100%);
    border:1px solid #E7EAEE;
    border-radius:16px;
    padding:16px 18px;
    display:flex;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.filter-toolbar-label{
    display:flex;
    align-items:center;
    gap:8px;
    font-size:12.5px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:#64748B;
    padding-right:4px;
    flex-shrink:0;
}

.filter-toolbar-label i{
    font-size:15px;
    color:#2563EB;
}

.search-box{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    flex:1;
    align-items:center;
}
.form-control,
.form-select{
    height:46px;
    border-radius:11px;
    border:1px solid #DFE3E8;
    font-size:13.5px;
    color:#1E293B;
    background:#fff;
    transition:border-color .2s ease, box-shadow .2s ease;
}

.form-control:focus,
.form-select:focus{
    border-color:#93C5FD;
    box-shadow:0 0 0 4px rgba(37,99,235,.10);
}

.form-control::placeholder{
    color:#94A3B8;
}

.btn-search{
    height:46px;
    border-radius:11px;
    padding:0 24px;
    font-weight:600;
    font-size:13.5px;
    letter-spacing:.01em;
    box-shadow:0 8px 16px -8px rgba(22,163,74,.45);
    transition:transform .18s ease, box-shadow .18s ease;
}

.btn-search:hover{
    transform:translateY(-2px);
    box-shadow:0 12px 20px -8px rgba(22,163,74,.5);
}

.btn-reset{
    height:46px;
    border-radius:11px;
    padding:0 22px;
    font-weight:600;
    font-size:13.5px;
    background:#fff;
    border:1px solid #DFE3E8;
    color:#475569;
    transition:.18s ease;
}

.btn-reset:hover{
    background:#F1F5F9;
    color:#1E293B;
}

/* ===================== Table ===================== */

.table-responsive{
    border:1px solid #ECEFF3;
    border-radius:16px;
    overflow:auto;
    max-height:640px;
    box-shadow:0 1px 2px rgba(15,23,42,.03);
}

table td{
    vertical-align:middle;
    padding-top:14px;
    padding-bottom:14px;
    color:#334155;
    border-bottom-color:#F1F3F6;
}

.table thead th{
    position:sticky;
    top:0;
    z-index:5;
    border-bottom:none;
    font-size:11.5px;
    font-weight:700;
    letter-spacing:.06em;
    text-transform:uppercase;
    padding-top:16px;
    padding-bottom:16px;
}

.table tbody tr{
    transition:background-color .15s ease;
}

.table tbody tr:hover{
    background:#F8FAFC;
}

.table .btn-sm{
    border-radius:9px;
    font-weight:600;
    font-size:12.5px;
    padding:6px 14px;
    letter-spacing:.01em;
    box-shadow:0 2px 6px -2px rgba(15,23,42,.18);
    transition:transform .15s ease, box-shadow .15s ease;
}

.table .btn-sm:hover{
    transform:translateY(-1px);
}

/* ===================== Premium Stat Cards ===================== */

.stats-row{
    display:flex;
    flex-wrap:wrap;
    gap:20px;
    margin-bottom:28px;
}

.stat-card{
    --accent:#2563EB;
    --accent-soft:rgba(37,99,235,.16);
    --accent-glow:rgba(37,99,235,.24);
    --accent-border:rgba(37,99,235,.35);

    flex:1 1 200px;
    min-width:200px;
    background:linear-gradient(165deg, #FFFFFF 0%, #FFFFFF 55%, var(--accent-soft) 160%);
    border:1px solid #EAECEF;
    border-top:4px solid var(--accent);
    border-radius:18px;
    padding:20px 20px 18px;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
    min-height:148px;
    box-shadow:0 1px 2px rgba(15,23,42,.05), 0 14px 28px -16px rgba(15,23,42,.16);
    transition:transform .3s cubic-bezier(.2,.8,.2,1), box-shadow .3s ease, border-color .3s ease;
    position:relative;
    overflow:hidden;
}

.stat-card::after{
    content:"";
    position:absolute;
    top:-50px;
    right:-50px;
    width:170px;
    height:170px;
    border-radius:50%;
    background:radial-gradient(circle, var(--accent-glow) 0%, rgba(255,255,255,0) 70%);
    pointer-events:none;
}

.stat-card:hover{
    transform:translateY(-7px);
    border-color:var(--accent);
    box-shadow:0 28px 48px -18px var(--accent-glow), 0 10px 22px -8px rgba(15,23,42,.12), inset 0 0 0 1px var(--accent-border);
}

.stat-card:hover .stat-icon{
    transform:scale(1.12) rotate(-2deg);
}

.stat-card .stat-icon{
    width:46px;
    height:46px;
    border-radius:13px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    margin-bottom:14px;
    flex-shrink:0;
    background:var(--accent-soft);
    color:var(--accent);
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.5), 0 6px 14px -6px var(--accent-glow);
    transition:transform .3s cubic-bezier(.2,.8,.2,1);
    position:relative;
    z-index:1;
}

.stat-card .stat-title{
    font-size:11px;
    font-weight:700;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#64748B;
    margin-bottom:7px;
    position:relative;
    z-index:1;
}

.stat-card .stat-value{
    font-size:29px;
    font-weight:800;
    letter-spacing:-.02em;
    line-height:1.05;
    color:var(--accent);
    margin-bottom:6px;
    position:relative;
    z-index:1;
    font-variant-numeric:tabular-nums;
}

.stat-card .stat-desc{
    font-size:12.5px;
    font-weight:500;
    letter-spacing:.01em;
    color:#94A3B8;
    position:relative;
    z-index:1;
}

/* Total Faculty */
.stat-card.stat-total{
    --accent:#2563EB;
    --accent-soft:rgba(37,99,235,.16);
    --accent-glow:rgba(37,99,235,.26);
    --accent-border:rgba(37,99,235,.35);
}

/* Total Divisions */
.stat-card.stat-approved{
    --accent:#16A34A;
    --accent-soft:rgba(22,163,74,.16);
    --accent-glow:rgba(22,163,74,.26);
    --accent-border:rgba(22,163,74,.35);
}

/* Assigned Faculty */
.stat-card.stat-pending{
    --accent:#F59E0B;
    --accent-soft:rgba(245,158,11,.18);
    --accent-glow:rgba(245,158,11,.28);
    --accent-border:rgba(245,158,11,.38);
}

@media (max-width: 992px){
    .stat-card{
        flex:1 1 45%;
    }
}

@media (max-width: 576px){
    .stat-card{
        flex:1 1 100%;
    }
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

      <div class="d-flex justify-content-between align-items-center mb-4">

<div>

<h2 class="fw-bold mb-1">
Manage Faculty
</h2>

<p class="text-muted mb-0">
Manage faculty records and assigned divisions.
</p>

</div>

<div>

<a href="add_faculty.php"
class="btn btn-success px-4 py-2"
style="border-radius:10px;font-weight:600;">
+ Add Faculty
</a>

</div>

</div>

<div class="row g-4 mb-4">

<div class="col-lg-4 col-md-6">

<div class="stat-card stat-total">

    <div>
        <div class="stat-icon">
            <i class="bi bi-person-workspace"></i>
        </div>

        <div class="stat-title">
            Total Faculty
        </div>

        <div class="stat-value">
            <?php echo number_format($totalFaculty); ?>
        </div>
    </div>

    <div class="stat-desc">
        Registered Faculty
    </div>

</div>

</div>

<div class="col-lg-4 col-md-6">

<div class="stat-card stat-approved">

    <div>
        <div class="stat-icon">
            <i class="bi bi-diagram-3-fill"></i>
        </div>

        <div class="stat-title">
            Total Divisions
        </div>

        <div class="stat-value">
            22
        </div>
    </div>

    <div class="stat-desc">
        Active Divisions
    </div>

</div>

</div>

<div class="col-lg-4 col-md-6">

<div class="stat-card stat-pending">

    <div>
        <div class="stat-icon">
            <i class="bi bi-person-check-fill"></i>
        </div>

        <div class="stat-title">
            Assigned Faculty
        </div>

        <div class="stat-value">
            <?php echo number_format($totalFaculty); ?>
        </div>
    </div>

    <div class="stat-desc">
        Currently Assigned
    </div>

</div>

</div>

</div>

    <div class="card shadow-sm border-0 mb-4"
style="border-radius:18px;">

<div class="card-body">

<form method="GET">

        <div class="filter-toolbar">

        <div class="filter-toolbar-label">
            <i class="bi bi-sliders"></i>
            Filters
        </div>

        <div class="search-box">

            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Search Faculty Name or Email"
                   value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"
                   style="width:300px;">

            <select name="division"
                    class="form-control"
                    style="width:220px;">

                <option value="">
                    All Divisions
                </option>

                <option value="IT-SMAD"
                <?php if($division=="IT-SMAD") echo "selected"; ?>>
                IT-SMAD
                </option>

                <option value="IT-DA"
                <?php if($division=="IT-DA") echo "selected"; ?>>
                IT-DA
                </option>

                <?php for($i=1; $i<=22; $i++): $sy = "SY-$i"; ?>
                <option value="<?php echo $sy; ?>"
                <?php if($division==$sy) echo "selected"; ?>>
                <?php echo $sy; ?>
                </option>
                <?php endfor; ?>

            </select>

            <button type="submit"
                    class="btn btn-success btn-search px-4">

                Search

            </button>

            <a href="manage_faculty.php"
               class="btn btn-secondary btn-reset px-4">

               Reset

            </a>

        </div>

        </div>

    </form>

</div>

</div>

    <div class="table-responsive">

<table class="table table-hover align-middle mb-0">

        <thead style="
background:#F8FAFC;
color:#475569;
border-bottom:1px solid #E7EAEE;
box-shadow:0 1px 0 rgba(15,23,42,.04);
">

            <tr>

                <th>Name</th>
                <th>Division</th>
                <th>Email</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        while($row = mysqli_fetch_assoc($result))
        {
            $divisionsDisplay = $row['divisions_list'] !== null && $row['divisions_list'] !== ''
                ? $row['divisions_list']
                : ($row['faculty_division'] ?? '');

        ?>

        <tr>

            <td>
                <?php echo htmlspecialchars($row['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($divisionsDisplay, ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>
                <?php echo htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </td>

            <td>

<div class="d-flex gap-2">
<a href="faculty_detail.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-success btn-sm">View</a>
<a href="edit_faculty.php?id=<?php echo (int)$row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>" class="btn btn-primary btn-sm">Edit</a>
<a href="delete_faculty.php?id=<?php echo (int)$row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete Faculty?')">Delete</a>
</div>

</td>

        </tr>

        <?php

        }

        ?>

        </tbody>

    </table>

</div>

</div>
</div>

</main>
</div>

<script>

window.addEventListener("beforeunload", function () {
    sessionStorage.setItem("manageFacultyScroll", window.scrollY);
});

window.addEventListener("load", function () {

    let scroll = sessionStorage.getItem("manageFacultyScroll");

    if(scroll !== null)
    {
        window.scrollTo(0, parseInt(scroll));
    }

});

</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>

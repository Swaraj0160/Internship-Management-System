<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$division = "";

$search = "";
$type = "";

if(isset($_GET['search']))
{
    $search = trim($_GET['search']);
}

if(isset($_GET['type']))
{
    $type = trim($_GET['type']);
}


if(isset($_GET['division']))
{
    $division = $_GET['division'];
}

$where_division = "";

if($division != "")
{
    $where_division = " AND users.division='$division'";
}



/* ===========================
   Statistics Cards
=========================== */

$total_query = mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM internships
JOIN users ON internships.student_id = users.id
WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)
$where_division
");

$total_applications = mysqli_fetch_assoc($total_query)['total'];

/* APPROVED COUNT */

$approved_query = mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM internships
JOIN users ON internships.student_id = users.id
WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)
AND internships.status='Approved'
$where_division
");

$approved_count = mysqli_fetch_assoc($approved_query)['total'];

/* PENDING COUNT */

$pending_query = mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM internships
JOIN users ON internships.student_id = users.id
WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)
AND internships.status='Pending'
$where_division
");

$pending_count = mysqli_fetch_assoc($pending_query)['total'];

/* REJECTED COUNT */

$rejected_query = mysqli_query($conn,"
SELECT COUNT(*) AS total
FROM internships
JOIN users ON internships.student_id = users.id
WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)
AND internships.status='Rejected'
$where_division
");

$rejected_count = mysqli_fetch_assoc($rejected_query)['total'];

$type_query = mysqli_query($conn,"
SELECT
TRIM(internship_type) AS internship_type,
COUNT(*) AS total
FROM internships
JOIN users ON internships.student_id = users.id
WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)
AND internships.status='Approved'
$where_division
GROUP BY TRIM(internship_type)
");

$type_counts = [];

while($row=mysqli_fetch_assoc($type_query))
{
    $type_counts[$row['internship_type']] = $row['total'];
}

$problem_count = $type_counts['Problem-based Internship'] ?? 0;

$training_count =
($type_counts['Training + Mini Project'] ?? 0)
+
($type_counts['Training + Mini Project Internship'] ?? 0);

$research_count = $type_counts['Research Internship'] ?? 0;

$international_count = $type_counts['International Internship / Visit'] ?? 0;

$realworld_count = $type_counts['Real-world Exposure Internship (Field Work)'] ?? 0;

/* ===========================
   Main Table Query
=========================== */

$query = "

SELECT internships.*, users.full_name, users.division, users.enrollment_no
FROM internships
JOIN users ON internships.student_id = users.id

WHERE internships.id IN
(
    SELECT MAX(id)
    FROM internships
    GROUP BY student_id
)

";

if($division != "")
{
    $query .= " AND users.division='$division'";
}

if($search != "")
{
    $query .= " AND (
        users.full_name LIKE '%$search%'
        OR
        users.enrollment_no LIKE '%$search%'
    )";
}

if($type != "")
{
    $query .= " AND internships.internship_type='$type'";
}

$query .= " ORDER BY
CAST(SUBSTRING(users.division, 4) AS UNSIGNED) ASC,
users.roll_no ASC";

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>
<html>

<head>

<title>All Internships</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>

body{

background:
linear-gradient(135deg,#eef4ff,#f8faff,#eef4ff);

}

.container-box{
    width:97%;
    margin:30px auto;
    padding:28px;
    border-radius:28px;
    background:rgba(255,255,255,.92);
    backdrop-filter:blur(16px);
    border:1px solid rgba(231,225,247,.95);
    box-shadow:0 20px 50px rgba(73,45,150,.08);
}

.table{
    font-size:14px;
    border-radius:18px;
    overflow:hidden;
    margin-bottom:0;
}

.table thead{
    background:#20242b;
}

.table thead th{
    color:#fff;
    font-weight:700;
    padding:15px;
    white-space:nowrap;
    border:none;
}

.table tbody td{
    padding:14px;
    vertical-align:middle;
    border-color:#eef2f7;
}

.table tbody tr{
    transition:all .25s ease;
}

.table tbody tr{

    transition:.25s;

}

.table tbody tr:hover{

    background:#eef6ff;

    transform:translateY(-2px);

    box-shadow:0 6px 16px rgba(0,0,0,.05);

}

.table thead th{

    position:sticky;

    top:0;

    z-index:20;

}
.badge{

    padding:7px 14px;

    border-radius:20px;

    font-size:12px;

    font-weight:600;

}

.btn-sm{
    font-size:11px;
    padding:4px 8px;
    white-space: nowrap;
}

/* ===== Professional Buttons ===== */

.btn-primary{
    background:linear-gradient(135deg,#2563eb,#3b82f6);
    border:none;
    border-radius:12px;
    padding:10px 20px;
    font-weight:600;
    transition:all .3s ease;
}

.btn-primary:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(37,99,235,.25);
}

.btn-success{
    background:linear-gradient(135deg,#14965a,#22c55e);
    border:none;
    border-radius:12px;
    padding:10px 20px;
    font-weight:600;
    transition:all .3s ease;
}

.btn-success:hover{
    transform:translateY(-2px);
    box-shadow:0 10px 20px rgba(34,197,94,.25);
}

.btn-secondary{
    border-radius:12px;
    padding:10px 20px;
    transition:all .3s ease;
}

.btn-secondary:hover{
    transform:translateY(-2px);
}

.stat-card{

    border-radius:22px;

    padding:20px;

    color:white;

    height:145px;

    display:flex;

    flex-direction:column;

    justify-content:flex-start;

    transition:.35s;

    box-shadow:0 15px 35px rgba(0,0,0,.12);

    overflow:hidden;

    position:relative;

}
.stat-card:hover{

    transform:translateY(-8px);

    box-shadow:0 30px 45px rgba(0,0,0,.18);

}

.stat-card{

    animation:cardUp .6s ease;

}

@keyframes cardUp{

from{

opacity:0;

transform:translateY(30px);

}

to{

opacity:1;

transform:translateY(0);

}

}
    
.offer-btn{
    white-space: nowrap;
    font-size:11px;
    padding:4px 8px;
}

.stat-card h6{
    font-size:13px;
    margin-bottom:-2px;
    line-height:1;
}

.stat-card h3{
    margin-top:12px;      /* Creates gap between title and number */
    margin-bottom:0;
    font-size:28px;
    font-weight:700;
}

.total-count{
    font-size:25px;
    line-height:1;
    margin-top:-6px;
    margin-bottom:2px !important;
}

.status-list{

    margin-top:0px;

}

.status-item{

    display:flex;

    justify-content:space-between;

    align-items:center;

    font-size:11px;

    padding:1px 0;

    border-bottom:1px solid rgba(255,255,255,.08);

}

.status-item:last-child{

    border-bottom:none;

}

.status-item span{

    font-weight:500;

}

.status-item strong{

    font-size:14px;

    font-weight:700;

}

.status-item.approved{

    color:#22c55e;

}

.status-item.pending{

    color:#facc15;

}

.status-item.rejected{

    color:#ef4444;

}

.page-header h2{
    font-size:2rem;
    font-weight:800;
    color:#24304d;
    margin-bottom:6px;
}

.page-header p{
    color:#6b7280;
    margin:0;
    font-size:15px;
}

.problem-card{
background:linear-gradient(135deg,#1f6bff,#438cff);
}

.training-card{
background:linear-gradient(135deg,#14965a,#2cbf74);
}

.real-card{
background:linear-gradient(135deg,#00b8e6,#28c8f0);
}

.research-card{
background:linear-gradient(135deg,#ffb400,#ffc82c);
color:#222;
}

.international-card{
background:linear-gradient(135deg,#e53e5d,#ff5d7c);
}

.search-panel{
    background:#fff;
    border-radius:20px;
    padding:22px;
    margin-bottom:25px;
    box-shadow:0 12px 30px rgba(0,0,0,.06);
    border:1px solid #eef2f7;
}

.search-panel .form-control,
.search-panel .form-select{
    height:48px;
    border-radius:12px;
    border:1px solid #dbe4f0;
    box-shadow:none;
}

.search-panel .form-control:focus,
.search-panel .form-select:focus{
    border-color:#4f46e5;
    box-shadow:0 0 0 4px rgba(79,70,229,.12);
}

.search-panel .btn{
    height:48px;
    border-radius:12px;
    font-weight:600;
}

.select2-results__options{
    max-height:180px !important;
}

.select2-container--default .select2-selection--single{
    height:48px;
    border-radius:12px;
    padding:9px 12px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow{
    height:46px;
}

.stat-card h6 + .total-count{
    margin-top:8px;
}


</style>

</head>

<body>

<div class="container-box">
    
<a href="dashboard.php"
   class="btn btn-primary"
   style="border-radius:8px;margin-bottom:15px;">
   ← Back
</a>

<div class="page-header mb-4">

    <div>

        <h2>All Internships</h2>

        <p>
            Monitor internship applications, approvals and student records.
        </p>

    </div>

</div>

<div class="row mb-4">

<div class="col-xl-2 col-lg-4 col-md-6 col-sm-6 mb-3">
        <div class="stat-card bg-dark">

    <h6>Total Applications</h6>

    <h2 class="fw-bold total-count mb-0">
    <?php echo $total_applications; ?>
</h2>

    <div class="status-list mt-0">

        <div class="status-item approved">
            <span>✔ Approved</span>
            <strong><?php echo $approved_count; ?></strong>
        </div>
        


        <div class="status-item pending">
            <span>⏳ Pending</span>
            <strong><?php echo $pending_count; ?></strong>
        </div>

        <div class="status-item rejected">
            <span>✖ Rejected</span>
            <strong><?php echo $rejected_count; ?></strong>
        </div>

    </div>

</div>
</div>
    <div class="col-md-2">
        <div class="stat-card problem-card">
            <h6>Problem Based</h6>
            <h3><?php echo $problem_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card training-card">
            <h6>Training + Project</h6>
            <h3><?php echo $training_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card real-card">
            <h6>Real World</h6>
            <h3><?php echo $realworld_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card research-card text-dark">
            <h6>Research</h6>
            <h3><?php echo $research_count; ?></h3>
        </div>
    </div>

    <div class="col-md-2">
        <div class="stat-card international-card">
            <h6>International</h6>
            <h3><?php echo $international_count; ?></h3>
        </div>
    </div>

</div>

<div class="search-panel">

<form method="GET" class="row g-3 align-items-end">

    <div class="col-md-3">
<select name="division" id="division" class="form-select">
<option value="">All Divisions</option>

<option value="SY-1" <?php if($division=="SY-1") echo "selected"; ?>>SY-1</option>
<option value="SY-2" <?php if($division=="SY-2") echo "selected"; ?>>SY-2</option>
<option value="SY-3" <?php if($division=="SY-3") echo "selected"; ?>>SY-3</option>
<option value="SY-4" <?php if($division=="SY-4") echo "selected"; ?>>SY-4</option>
<option value="SY-5" <?php if($division=="SY-5") echo "selected"; ?>>SY-5</option>
<option value="SY-6" <?php if($division=="SY-6") echo "selected"; ?>>SY-6</option>
<option value="SY-7" <?php if($division=="SY-7") echo "selected"; ?>>SY-7</option>
<option value="SY-8" <?php if($division=="SY-8") echo "selected"; ?>>SY-8</option>
<option value="SY-9" <?php if($division=="SY-9") echo "selected"; ?>>SY-9</option>
<option value="SY-10" <?php if($division=="SY-10") echo "selected"; ?>>SY-10</option>
<option value="SY-11" <?php if($division=="SY-11") echo "selected"; ?>>SY-11</option>
<option value="SY-12" <?php if($division=="SY-12") echo "selected"; ?>>SY-12</option>
<option value="SY-13" <?php if($division=="SY-13") echo "selected"; ?>>SY-13</option>
<option value="SY-14" <?php if($division=="SY-14") echo "selected"; ?>>SY-14</option>
<option value="SY-15" <?php if($division=="SY-15") echo "selected"; ?>>SY-15</option>
<option value="SY-16" <?php if($division=="SY-16") echo "selected"; ?>>SY-16</option>
<option value="SY-17" <?php if($division=="SY-17") echo "selected"; ?>>SY-17</option>
<option value="SY-18" <?php if($division=="SY-18") echo "selected"; ?>>SY-18</option>
<option value="SY-19" <?php if($division=="SY-19") echo "selected"; ?>>SY-19</option>
<option value="SY-20" <?php if($division=="SY-20") echo "selected"; ?>>SY-20</option>
<option value="SY-21" <?php if($division=="SY-21") echo "selected"; ?>>SY-21</option>
<option value="SY-22" <?php if($division=="SY-22") echo "selected"; ?>>SY-22</option>

</select>
    </div>

    <div class="col-md-3">
        <input type="search"
       name="search"
       class="form-control"
       placeholder="Search Student Name or Enrollment No"
       value="<?php echo htmlspecialchars($search); ?>"
       autocomplete="new-password"
       spellcheck="false">
    </div>

    <div class="col-md-3">

        <select name="type" class="form-select">

<option value="">All Internship Types</option>

<option value="Problem-based Internship"
<?php if($type=="Problem-based Internship") echo "selected"; ?>>
Problem-based Internship
</option>

<option value="Training + Mini Project"
<?php if($type=="Training + Mini Project") echo "selected"; ?>>
Training + Mini Project
</option>

<option value="Real-world Exposure Internship (Field Work)"
<?php if($type=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
Real-world Exposure Internship (Field Work)
</option>

<option value="Research Internship"
<?php if($type=="Research Internship") echo "selected"; ?>>
Research Internship
</option>

<option value="International Internship / Visit"
<?php if($type=="International Internship / Visit") echo "selected"; ?>>
International Internship / Visit
</option>

</select>

    </div>

    <div class="col-md-3 d-flex gap-2">

        <button type="submit" class="btn btn-success flex-fill">
            <i class="bi bi-search"></i> Search
        </button>

        <a href="all_internships.php"
           class="btn btn-secondary flex-fill">
            Reset
        </a>

    </div>

</form>

</div>

<div class="table-responsive shadow-sm rounded-4">

<table class="table table-hover align-middle mb-0">

<thead class="table-dark">

<tr>
    <th>Division</th>
    <th>Student</th>
    <th>Enrollment No</th>
    <th>Company</th>
    <th>Role</th>
    <th>Internship Type</th>
    <th>Offer Letter</th>
    <th>Status</th>
</tr>

</thead>

<tbody>

<?php

if(mysqli_num_rows($result) > 0)
{
    while($row = mysqli_fetch_assoc($result))
    {

?>

<tr>

<td><?php echo $row['division']; ?></td>

<td><?php echo $row['full_name']; ?></td>

<td><?php echo $row['enrollment_no']; ?></td>

<td><?php echo $row['company_name']; ?></td>

<td><?php echo $row['internship_role']; ?></td>

<td>
<?php
if($row['status'] == 'Approved')
{
    echo $row['internship_type'];
}
else
{
    echo "-";
}
?>
</td>

<td>

<?php

if($row['status']=='Approved' && !empty($row['offer_letter']))
{
?>

<a href="../uploads/offer_letters/<?php echo $row['division']; ?>/<?php echo $row['offer_letter']; ?>"
target="_blank"
class="btn btn-sm btn-primary offer-btn">

<i class="bi bi-file-earmark-pdf"></i> View

</a>

<?php
}
else
{
    echo "-";
}
?>

</td>

<td>

<?php

if($row['status']=='Approved')
{
    echo "<span class='badge bg-success'>Approved</span>";
}
elseif($row['status']=='Rejected')
{
    echo "<span class='badge bg-danger'>Rejected</span>";
}
else
{
    echo "<span class='badge bg-warning text-dark'>Pending</span>";
}

?>

</td>

</tr>

<?php

    }
}
else
{

?>

<tr>
<td colspan="7" class="text-center">
No Internship Records Found
</td>
</tr>

<?php

}

?>

</tbody>

</table>

</div> <!-- table-responsive -->

</div> <!-- container-box -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</body>
</html>
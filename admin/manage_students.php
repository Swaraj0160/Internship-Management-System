<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$search = "";
$division = "";
$internship_status = "";
$internship_type = "";

$query = "
SELECT users.*,
       internships.status AS internship_status,
       internships.internship_type

FROM users

LEFT JOIN internships
ON internships.id =
(
    SELECT id
    FROM internships i2
    WHERE i2.student_id = users.id
    ORDER BY i2.id DESC
    LIMIT 1
)

WHERE users.role='student'
";

if(isset($_GET['search']) && $_GET['search'] != "")
{
    $search = $_GET['search'];

    $query .= "
    AND
(
users.enrollment_no LIKE '%$search%'
OR
users.full_name LIKE '%$search%'
)
    ";
}

if(isset($_GET['division']) && $_GET['division'] != "")
{
    $division = $_GET['division'];

    $query .= "
    AND users.division='$division'
    ";
}

if(isset($_GET['internship_status']) && $_GET['internship_status'] != "")
{
    $internship_status = $_GET['internship_status'];

    if($internship_status == "Not Submitted")
    {
        $query .= "
        AND internships.id IS NULL
        ";
    }
    else
    {
        $query .= "
        AND internships.status='$internship_status'
        ";
    }
}

if(isset($_GET['internship_type']) && $_GET['internship_type'] != "")
{
    $internship_type = $_GET['internship_type'];

    $query .= "
    AND internships.internship_type='$internship_type'
    ";
}

$query .= "
ORDER BY
CAST(
    REPLACE(
        UPPER(users.division),
        'SY-',
        ''
    ) AS UNSIGNED
) ASC,
CAST(users.roll_no AS UNSIGNED) ASC
";

$result = mysqli_query($conn, $query);

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Students</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.container-box{
    width:98%;
    margin:30px auto;
    background:#fff;
    padding:35px;
    border-radius:22px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}

table{
    font-size:14px;
}

.search-box{
    display:flex;
    gap:10px;
    margin-bottom:20px;
    flex-wrap:wrap;
}
.form-control,
.form-select{
    height:48px;
    border-radius:12px;
}

.btn-search{
    height:48px;
    border-radius:12px;
    padding:0 26px;
}

.btn-reset{
    height:48px;
    border-radius:12px;
    padding:0 26px;
}

table td{
    vertical-align:middle;
}

.email-col{
    max-width:220px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.table tbody tr:hover{
    background:#f8fafc;
    transition:.2s;
}
.name-col{
    white-space:nowrap;
}

</style>

</head>

<body>

<div class="container-box">
    
    <div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <a href="dashboard.php"
           class="btn btn-primary mb-3"
           style="border-radius:10px;">
            ← Back
        </a>

        <h2 class="fw-bold mb-1">
            Manage Students
        </h2>

        <p class="text-muted mb-0">
            Manage student records, internship status and account information.
        </p>

    </div>

    <div>

        <a href="add_student.php"
           class="btn btn-success px-4 py-2"
           style="border-radius:10px;font-weight:600;">
            + Add Student
        </a>

    </div>

</div>

    <div class="card shadow-sm border-0 mb-4"
     style="border-radius:18px;">

<div class="card-body">

<form method="GET">
    
        <?php

$total_students = mysqli_fetch_assoc(mysqli_query($conn,
"SELECT COUNT(*) AS total
FROM users
WHERE role='student'"))['total'];

$approved = mysqli_fetch_assoc(mysqli_query($conn,
"SELECT COUNT(*) AS total
FROM internships
WHERE status='Approved'"))['total'];

$pending = mysqli_fetch_assoc(mysqli_query($conn,
"SELECT COUNT(*) AS total
FROM internships
WHERE status='Pending'"))['total'];

$rejected = mysqli_fetch_assoc(mysqli_query($conn,
"SELECT COUNT(*) AS total
FROM internships
WHERE status='Rejected'"))['total'];

$notSubmitted = $total_students - ($approved + $pending + $rejected);

?>

<div class="row g-4 mb-4">

    <div class="col-lg col-md-6">

        <div class="card border-0 shadow-sm text-white"
             style="background:#1f2937;border-radius:18px;">

            <div class="card-body">

                <small class="fw-semibold">
                    Total Students
                </small>

                <h2 class="fw-bold mt-2">
                    <?php echo $total_students; ?>
                </h2>

            </div>

        </div>

    </div>

    <div class="col-lg col-md-6">

        <div class="card border-0 shadow-sm text-white"
             style="background:#16a34a;border-radius:18px;">

            <div class="card-body">

                <small class="fw-semibold">
                    Approved
                </small>

                <h2 class="fw-bold mt-2">
                    <?php echo $approved; ?>
                </h2>

            </div>

        </div>

    </div>

    <div class="col-lg col-md-6">

        <div class="card border-0 shadow-sm text-dark"
             style="background:#facc15;border-radius:18px;">

            <div class="card-body">

                <small class="fw-semibold">
                    Pending
                </small>

                <h2 class="fw-bold mt-2">
                    <?php echo $pending; ?>
                </h2>

            </div>

        </div>

    </div>

    <div class="col-lg col-md-6">

        <div class="card border-0 shadow-sm text-white"
             style="background:#ef4444;border-radius:18px;">

            <div class="card-body">

                <small class="fw-semibold">
                    Rejected
                </small>

                <h2 class="fw-bold mt-2">
                    <?php echo $rejected; ?>
                </h2>

            </div>

        </div>

    </div>

    <div class="col-lg col-md-6">

        <div class="card border-0 shadow-sm text-white"
             style="background:#6b7280;border-radius:18px;">

            <div class="card-body">

                <small class="fw-semibold">
                    Not Submitted
                </small>

                <h2 class="fw-bold mt-2">
                    <?php echo $notSubmitted; ?>
                </h2>

            </div>

        </div>

    </div>

</div>

        <div class="search-box">

            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Search Enrollment No"
                   value="<?php echo $search; ?>"
                   style="width:250px;">

            <select name="division"
                    class="form-control"
                    style="width:220px;">

                <option value="">
                    All Divisions
                </option>

                <option value="SY-1"
                <?php if($division=="SY-1") echo "selected"; ?>>
                SY-1
                </option>

                <option value="SY-2"
                <?php if($division=="SY-2") echo "selected"; ?>>
                SY-2
                </option>

                <option value="SY-3"
                <?php if($division=="SY-3") echo "selected"; ?>>
                SY-3
                </option>

                <option value="SY-4"
                <?php if($division=="SY-4") echo "selected"; ?>>
                SY-4
                </option>

                <option value="SY-5"
                <?php if($division=="SY-5") echo "selected"; ?>>
                SY-5
                </option>
                
                <option value="SY-6"
                <?php if($division=="SY-6") echo "selected"; ?>>
                SY-6
                </option>
                
                <option value="SY-7"
                <?php if($division=="SY-7") echo "selected"; ?>>
                SY-7
                </option>
                
                <option value="SY-8"
                <?php if($division=="SY-8") echo "selected"; ?>>
                SY-8
                </option>
                
                <option value="SY-9"
                <?php if($division=="SY-9") echo "selected"; ?>>
                SY-9
                </option>
                
                <option value="SY-10"
                <?php if($division=="SY-10") echo "selected"; ?>>
                SY-10
                </option>
                
                <option value="SY-11"
                <?php if($division=="SY-11") echo "selected"; ?>>
                SY-11
                </option>
                
                <option value="SY-12"
                <?php if($division=="SY-12") echo "selected"; ?>>
                SY-12
                </option>
                
                <option value="SY-13"
                <?php if($division=="SY-13") echo "selected"; ?>>
                SY-13
                </option>
                
                <option value="SY-14"
                <?php if($division=="SY-14") echo "selected"; ?>>
                SY-14
                </option>
                
                
                <option value="SY-15"
                <?php if($division=="SY-15") echo "selected"; ?>>
                SY-15
                </option>
                
                <option value="SY-16"
                <?php if($division=="SY-16") echo "selected"; ?>>
                SY-16
                </option>
                
                <option value="SY-17"
                <?php if($division=="SY-17") echo "selected"; ?>>
                SY-17
                </option>
                
                <option value="SY-18"
                <?php if($division=="SY-18") echo "selected"; ?>>
                SY-18
                </option>
                
                <option value="SY-19"
                <?php if($division=="SY-19") echo "selected"; ?>>
                SY-19
                </option>
                
                <option value="SY-20"
                <?php if($division=="SY-20") echo "selected"; ?>>
                SY-20
                </option>
                
                <option value="SY-21"
                <?php if($division=="SY-21") echo "selected"; ?>>
                SY-21
                </option>
                
                <option value="SY-22"
                <?php if($division=="SY-22") echo "selected"; ?>>
                SY-22
                </option>

            </select>

            <select name="internship_status"
                    class="form-control"
                    style="width:250px;">

                <option value="">
                    All Internship Status
                </option>

                <option value="Approved"
                <?php if($internship_status=="Approved") echo "selected"; ?>>
                Approved
                </option>

                <option value="Pending"
                <?php if($internship_status=="Pending") echo "selected"; ?>>
                Pending
                </option>

                <option value="Rejected"
                <?php if($internship_status=="Rejected") echo "selected"; ?>>
                Rejected
                </option>

                <option value="Not Submitted"
                <?php if($internship_status=="Not Submitted") echo "selected"; ?>>
                Not Submitted
                </option>

            </select>

<select name="internship_type"
        class="form-control"
        style="width:300px;">

    <option value="">
        All Internship Types
    </option>

    <option value="Problem-based Internship"
    <?php if($internship_type=="Problem-based Internship") echo "selected"; ?>>
    Problem-based Internship
    </option>

    <option value="Training + Mini Project"
    <?php if($internship_type=="Training + Mini Project") echo "selected"; ?>>
    Training + Mini Project
    </option>

    <option value="Real-world Exposure Internship (Field Work)"
    <?php if($internship_type=="Real-world Exposure Internship (Field Work)") echo "selected"; ?>>
    Real-world Exposure Internship (Field Work)
    </option>

    <option value="Research Internship"
    <?php if($internship_type=="Research Internship") echo "selected"; ?>>
    Research Internship
    </option>

    <option value="International Internship / Visit"
<?php if($internship_type=="International Internship / Visit") echo "selected"; ?>>
International Internship / Visit
</option>

</select>

            <button type="submit"
                    class="btn btn-success">

                Search

            </button>

            <a href="manage_students.php"
               class="btn btn-secondary">

               Reset

            </a>

        </div>

</form>

    </div>
</div>

    <div class="table-responsive">

<table class="table table-hover align-middle mb-0">

        <thead style="
background:#1f2937;
color:white;
">

            <tr>

                <th>Name</th>
                <th>Enrollment No</th>
                <th>Division</th>
                <th>Roll No</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Department</th>
                <th>Internship Status</th>
                <th>Internship Type</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        while($row = mysqli_fetch_assoc($result))
        {

        ?>

        <tr>

            <td>
                <?php echo $row['full_name']; ?>
            </td>

            <td>
                <?php echo $row['enrollment_no']; ?>
            </td>

            <td>
                <?php echo $row['division']; ?>
            </td>

            <td>
                <?php echo $row['roll_no']; ?>
            </td>

            <td class="email-col">
<?php echo $row['email']; ?>
</td>

            <td>
                <?php echo $row['phone']; ?>
            </td>

            <td>
                <?php echo $row['department']; ?>
            </td>

            <td>

                <?php

                if($row['internship_status'] == "")
                {
                    echo "Not Submitted";
                }
                else
                {
                    if($row['internship_status']=="Approved")
{
    echo '<span class="badge bg-success">Approved</span>';
}
elseif($row['internship_status']=="Pending")
{
    echo '<span class="badge bg-warning text-dark">Pending</span>';
}
elseif($row['internship_status']=="Rejected")
{
    echo '<span class="badge bg-danger">Rejected</span>';
}
else
{
    echo '<span class="badge bg-secondary">Not Submitted</span>';
}
                }

                ?>

            </td>

            <td>

                <?php

                if($row['internship_type'] != "")
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

<div class="d-flex gap-2">

<a href="edit_student.php?id=<?php echo $row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
   class="btn btn-primary btn-sm">
    Edit
</a>

<a href="delete_student.php?id=<?php echo $row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
   class="btn btn-danger btn-sm"
   onclick="return confirm('Are you sure you want to delete this student?');">
    Delete
</a>

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

<script>
window.addEventListener("beforeunload", function () {
    sessionStorage.setItem("manageStudentsScroll", window.scrollY);
});

window.addEventListener("load", function () {

    const scroll = sessionStorage.getItem("manageStudentsScroll");

    if(scroll !== null)
    {
        window.scrollTo(0, parseInt(scroll));
    }

});
</script>

</body>
</html>
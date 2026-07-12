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

$query = "
SELECT *
FROM users
WHERE role='faculty'
";

if(isset($_GET['search']) && $_GET['search'] != "")
{
    $search = $_GET['search'];

    $query .= "
    AND
    (
        full_name LIKE '%$search%'
        OR
        email LIKE '%$search%'
    )
    ";
}

if(isset($_GET['division']) && $_GET['division'] != "")
{
    $division = $_GET['division'];

    $query .= "
    AND faculty_division='$division'
    ";
}

$query .= "
ORDER BY
CAST(
    REPLACE(
        UPPER(faculty_division),
        'SY-',
        ''
    ) AS UNSIGNED
) ASC,
full_name ASC
";

$result = mysqli_query($conn, $query);
$totalFaculty = mysqli_fetch_assoc(
    mysqli_query(
        $conn,
        "SELECT COUNT(*) total
         FROM users
         WHERE role='faculty'"
    )
)['total'];

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Faculty</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.container-box{
    width:95%;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:35px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

.search-box{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:25px;
}

table{
    font-size:15px;
}

.table tbody tr:hover{
    background:#f8fafc;
    transition:.2s;
}

.card{
    border-radius:18px;
}

.table-responsive{
    border-radius:15px;
    overflow:hidden;
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

<div class="col-lg-4">

<div class="card border-0 shadow-sm text-white"
style="background:#2563eb;border-radius:18px;">

<div class="card-body">

<small>Total Faculty</small>

<h2 class="fw-bold mt-2">
<?php echo $totalFaculty; ?>
</h2>

</div>

</div>

</div>

<div class="col-lg-4">

<div class="card border-0 shadow-sm text-white"
style="background:#16a34a;border-radius:18px;">

<div class="card-body">

<small>Total Divisions</small>

<h2 class="fw-bold mt-2">
22
</h2>

</div>

</div>

</div>

<div class="col-lg-4">

<div class="card border-0 shadow-sm text-white"
style="background:#f59e0b;border-radius:18px;">

<div class="card-body">

<small>Assigned Faculty</small>

<h2 class="fw-bold mt-2">
<?php echo $totalFaculty; ?>
</h2>

</div>

</div>

</div>

</div>
       
    <div class="card shadow-sm border-0 mb-4"
style="border-radius:18px;">

<div class="card-body">

<form method="GET">

        <div class="search-box">

            <input type="text"
                   name="search"
                   class="form-control"
                   placeholder="Search Faculty Name or Email"
                   value="<?php echo $search; ?>"
                   style="width:300px;">

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

            <button type="submit"
                    class="btn btn-success px-4">

                Search

            </button>

            <a href="manage_faculty.php"
               class="btn btn-secondary px-4">

               Reset

            </a>

        </div>

    </form>

</div>

</div>

    <div class="table-responsive">

<table class="table table-hover align-middle">

        <thead style="background:#1f2937;color:white;">

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

        ?>

        <tr>

            <td>
                <?php echo $row['full_name']; ?>
            </td>

            <td>
                <?php echo $row['faculty_division']; ?>
            </td>

            <td>
                <?php echo $row['email']; ?>
            </td>

            <td>

<div class="d-flex gap-2">

<a href="edit_faculty.php?id=<?php echo $row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
class="btn btn-sm btn-primary">

✏ Edit

</a>

<a href="delete_faculty.php?id=<?php echo $row['id']; ?>&return=<?php echo urlencode($_SERVER['QUERY_STRING']); ?>"
class="btn btn-sm btn-danger"
onclick="return confirm('Delete Faculty?')">

🗑 Delete

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

</body>
</html>
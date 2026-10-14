<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
$return = $_GET['return'] ?? "";

/* Only allow safe query-string characters in the return target so it
   can never be used to inject markup/JS when echoed back below. */
$return = preg_replace('/[^A-Za-z0-9=&_\-\.%]/', '', $return);

$stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='student'");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$user = mysqli_fetch_assoc($result);

if(!$user)
{
    die("Student not found.");
}

$update_message = "";

if(isset($_POST['update_student']))
{
    $full_name     = trim($_POST['full_name']);
    $email         = trim($_POST['email']);
    $department    = trim($_POST['department']);
    $enrollment_no = trim($_POST['enrollment_no']);
    $division      = trim($_POST['division']);
    $roll_no       = trim($_POST['roll_no']);
    $phone         = trim($_POST['phone']);

    $update = $conn->prepare(
        "UPDATE users SET
            full_name=?,
            email=?,
            department=?,
            enrollment_no=?,
            division=?,
            roll_no=?,
            phone=?
         WHERE id=? AND role='student'"
    );

    $update->bind_param(
        "sssssssi",
        $full_name,
        $email,
        $department,
        $enrollment_no,
        $division,
        $roll_no,
        $phone,
        $id
    );

    $update->execute();

    /* refresh local copy so the form below shows the saved values */
    $user['full_name']     = $full_name;
    $user['email']         = $email;
    $user['department']    = $department;
    $user['enrollment_no'] = $enrollment_no;
    $user['division']      = $division;
    $user['roll_no']       = $roll_no;
    $user['phone']         = $phone;

    $redirect_target = $return !== ""
        ? "manage_students.php?" . $return
        : "manage_students.php";

    $update_message = $redirect_target;
}

function opt($value, $current)
{
    $selected = ($current === $value) ? " selected" : "";
    return "<option value=\"" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "\"$selected>" .
           htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "</option>";
}

$divisions = [
    "IT-SMAD", "IT-DA",
    "SY-1","SY-2","SY-3","SY-4","SY-5","SY-6","SY-7","SY-8","SY-9","SY-10",
    "SY-11","SY-12","SY-13","SY-14","SY-15","SY-16","SY-17","SY-18","SY-19",
    "SY-20","SY-21","SY-22"
];

?>

<!DOCTYPE html>
<html>
<head>

<title>Edit Student</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.form-box{
    width:600px;
    margin:auto;
    margin-top:40px;
    background:white;
    padding:30px;
    border-radius:15px;
}

</style>

</head>

<body>

<div class="form-box">

    <h2 class="mb-4">
        Edit Student
    </h2>

    <form method="POST">

        <div class="mb-3">

            <label>Full Name</label>

            <input type="text"
                   name="full_name"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   required>

        </div>

        <div class="mb-3">

            <label>Email</label>

            <input type="email"
                   name="email"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                   required>

        </div>

        <div class="mb-3">

            <label>Department</label>

            <input type="text"
                   name="department"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['department'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div class="mb-3">

            <label>Enrollment Number</label>

            <input type="text"
                   name="enrollment_no"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div class="mb-3">

            <label>Division</label>

            <select name="division" class="form-control" required>
                <option value="">Select Division</option>
                <?php
                $current_division = $user['division'] ?? '';
                foreach($divisions as $d)
                {
                    echo opt($d, $current_division);
                }
                ?>
            </select>

        </div>

        <div class="mb-3">

            <label>Roll Number</label>

            <input type="text"
                   name="roll_no"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <div class="mb-3">

            <label>Phone Number</label>

            <input type="text"
                   name="phone"
                   class="form-control"
                   value="<?php echo htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        </div>

        <button type="submit"
                name="update_student"
                class="btn btn-primary w-100">

            Update Student

        </button>

    </form>

</div>

<?php if($update_message !== ""): ?>
<script>
alert('Student Updated Successfully');
window.location = <?php echo json_encode($update_message); ?>;
</script>
<?php endif; ?>

</body>
</html>

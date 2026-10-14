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

$stmt = $conn->prepare("SELECT * FROM users WHERE id=? AND role='faculty'");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$user = mysqli_fetch_assoc($result);

if(!$user)
{
    die("Faculty not found.");
}

$update_message = "";

if(isset($_POST['update_faculty']))
{
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $faculty_division = trim($_POST['faculty_division']);

    $update = $conn->prepare(
        "UPDATE users SET
            full_name=?,
            email=?,
            faculty_division=?
         WHERE id=? AND role='faculty'"
    );

    $update->bind_param(
        "sssi",
        $full_name,
        $email,
        $faculty_division,
        $id
    );

    $update->execute();

    /* Best-effort sync of the faculty_divisions mapping table used by
       faculty/dashboard.php. If that table's columns differ from
       (faculty_id, division) on your server, prepare() returns false
       and this is skipped safely — verify manually in phpMyAdmin if
       so. Existing mapping rows for this faculty are replaced with the
       single division chosen here; if this faculty should keep
       multiple divisions, manage faculty_divisions directly in
       phpMyAdmin instead of through this form. */
    $del = $conn->prepare("DELETE FROM faculty_divisions WHERE faculty_id=?");
    if($del)
    {
        $del->bind_param("i", $id);
        $del->execute();
    }

    if($faculty_division !== "")
    {
        $ins = $conn->prepare("INSERT INTO faculty_divisions (faculty_id, division) VALUES (?, ?)");
        if($ins)
        {
            $ins->bind_param("is", $id, $faculty_division);
            $ins->execute();
        }
    }

    $user['full_name'] = $full_name;
    $user['email'] = $email;
    $user['faculty_division'] = $faculty_division;

    $redirect_target = $return !== ""
        ? "manage_faculty.php?" . $return
        : "manage_faculty.php";

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

<title>Edit Faculty</title>

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
        Edit Faculty
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

            <label>Faculty Division</label>

            <select name="faculty_division" class="form-control" required>
                <option value="">Select Division</option>
                <?php
                $current_division = $user['faculty_division'] ?? '';
                foreach($divisions as $d)
                {
                    echo opt($d, $current_division);
                }
                ?>
            </select>

            <small class="text-muted d-block mt-1">
                This faculty may also have additional divisions mapped in
                faculty_divisions (multi-division support); saving this
                form sets/keeps a single primary division here.
            </small>

        </div>

        <button type="submit"
                name="update_faculty"
                class="btn btn-success w-100">

            Update Faculty

        </button>

    </form>

</div>

<?php if($update_message !== ""): ?>
<script>
alert('Faculty Updated Successfully');
window.location = <?php echo json_encode($update_message); ?>;
</script>
<?php endif; ?>

</body>
</html>

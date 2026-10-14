<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

if(isset($_POST['add_faculty']))
{
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $faculty_division = trim($_POST['faculty_division']);

    $hashed_password = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check_result = $check->get_result();

    if(mysqli_num_rows($check_result) > 0)
    {
        echo "<script>alert('Email Already Exists');</script>";
    }
    else
    {
        $stmt = $conn->prepare(
            "INSERT INTO users
            (
                full_name,
                email,
                password,
                role,
                faculty_division
            )
            VALUES
            (?, ?, ?, 'faculty', ?)"
        );

        $stmt->bind_param(
            "ssss",
            $full_name,
            $email,
            $hashed_password,
            $faculty_division
        );

        $stmt->execute();

        /* Best-effort: keep the faculty_divisions mapping table in sync
           so this faculty immediately shows up correctly on
           faculty/dashboard.php, which reads faculty_divisions first
           and only falls back to users.faculty_division for legacy
           records. This block is defensive: if faculty_divisions has a
           different column layout than expected (faculty_id, division)
           on your server, prepare() will simply return false and this
           is skipped without breaking faculty creation. Verify the
           table's actual columns in phpMyAdmin; add the mapping row
           manually there if this silently does not insert. */
        $new_faculty_id = $stmt->insert_id;

        if($new_faculty_id > 0 && $faculty_division !== "")
        {
            $fd_stmt = $conn->prepare(
                "INSERT INTO faculty_divisions (faculty_id, division) VALUES (?, ?)"
            );

            if($fd_stmt)
            {
                $fd_stmt->bind_param("is", $new_faculty_id, $faculty_division);
                $fd_stmt->execute();
            }
        }

        echo "<script>alert('Faculty Added Successfully');</script>";
    }
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Add Faculty</title>

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
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

</style>

</head>

<body>

<div class="form-box">

    <h2 class="mb-4">
        Add Faculty
    </h2>

    <form method="POST">

        <div class="mb-3">

            <label>Full Name</label>

            <input type="text"
                   name="full_name"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label>Email</label>

            <input type="email"
                   name="email"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label>Password</label>

            <input type="text"
                   name="password"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label>Faculty Division</label>

            <select name="faculty_division"
                    class="form-control"
                    required>

                <option value="">Select Division</option>

                <option>IT-SMAD</option>
                <option>IT-DA</option>

                <option>SY-1</option>
                <option>SY-2</option>
                <option>SY-3</option>
                <option>SY-4</option>
                <option>SY-5</option>
                <option>SY-6</option>
                <option>SY-7</option>
                <option>SY-8</option>
                <option>SY-9</option>
                <option>SY-10</option>
                <option>SY-11</option>
                <option>SY-12</option>
                <option>SY-13</option>
                <option>SY-14</option>
                <option>SY-15</option>
                <option>SY-16</option>
                <option>SY-17</option>
                <option>SY-18</option>
                <option>SY-19</option>
                <option>SY-20</option>
                <option>SY-21</option>
                <option>SY-22</option>

            </select>

        </div>

        <button type="submit"
                name="add_faculty"
                class="btn btn-success w-100">

            Add Faculty

        </button>

    </form>

</div>

</body>
</html>

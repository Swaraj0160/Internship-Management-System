<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

if(isset($_POST['add_student']))
{
    $full_name = $_POST['full_name'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $department = $_POST['department'];

    $enrollment_no = $_POST['enrollment_no'];
    $division = $_POST['division'];
    $roll_no = $_POST['roll_no'];
    $phone = $_POST['phone'];

    $hashed_password = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $check = "SELECT * FROM users
              WHERE email='$email'";

    $check_result = mysqli_query($conn, $check);

    if(mysqli_num_rows($check_result) > 0)
    {
        echo "<script>alert('Email Already Exists');</script>";
    }
    else
    {
        $query = "INSERT INTO users
        (
            full_name,
            email,
            password,
            role,
            department,
            enrollment_no,
            division,
            roll_no,
            phone
        )

        VALUES
        (
            '$full_name',
            '$email',
            '$hashed_password',
            'student',
            '$department',
            '$enrollment_no',
            '$division',
            '$roll_no',
            '$phone'
        )";

        mysqli_query($conn, $query);

        echo "<script>alert('Student Added Successfully');</script>";
    }
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Add Student</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f1f5f9;
}

.form-box{
    width:650px;
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
        Add Student
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

            <label>Enrollment Number</label>

            <input type="text"
                   name="enrollment_no"
                   class="form-control"
                   placeholder="ADT24SOCB1415"
                   required>

        </div>

        <div class="mb-3">

            <label>Division</label>

            <select name="division"
                    class="form-control"
                    required>

                <option value="">Select Division</option>

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

        <div class="mb-3">

            <label>Roll Number</label>

            <input type="text"
                   name="roll_no"
                   class="form-control"
                   required>

        </div>

        <div class="mb-3">

            <label>Phone Number</label>

            <input type="text"
                   name="phone"
                   class="form-control"
                   placeholder="9876543210"
                   required>

        </div>

        <div class="mb-3">

            <label>Department</label>

            <input type="text"
                   name="department"
                   class="form-control"
                   required>

        </div>

        <button type="submit"
                name="add_student"
                class="btn btn-primary w-100">

            Add Student

        </button>

    </form>

</div>

</body>
</html>
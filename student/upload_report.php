<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];

$getStudent = mysqli_query(
    $conn,
    "SELECT division
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($getStudent);

$division = $studentData['division'];

$query = "SELECT * FROM internships
          WHERE student_id='$student_id'
          AND status='Approved'";

$result = mysqli_query($conn, $query);

if(isset($_POST['submit']))
{
    $internship_id = $_POST['internship_id'];

    $check = mysqli_query(
        $conn,
        "SELECT *
         FROM final_reports
         WHERE internship_id='$internship_id'"
    );

    if(mysqli_num_rows($check) > 0)
    {
        echo "<script>
                alert('Certificate already submitted');
              </script>";
    }
    else
    {
        $getStudentInfo = mysqli_query(
    $conn,
    "SELECT full_name,enrollment_no
     FROM users
     WHERE id='$student_id'"
);

$studentInfo = mysqli_fetch_assoc($getStudentInfo);

$student_name = preg_replace(
    '/[^A-Za-z0-9]/',
    '_',
    $studentInfo['full_name']
);

$file_size = $_FILES['certificate_file']['size'];

if($file_size > 512000)
{
    echo "<script>
            alert('File size must be less than or equal to 500 KB');
          </script>";
}
else
{

$file_extension = pathinfo(
    $_FILES['certificate_file']['name'],
    PATHINFO_EXTENSION
);

$certificate_file =
$studentInfo['enrollment_no']
."_"
.$student_name
."_Certificate."
.$file_extension;

        $upload_folder =
        "../uploads/certificates/".$division."/";

        if(!file_exists($upload_folder))
        {
            mkdir($upload_folder,0777,true);
        }

        if(
            move_uploaded_file(
                $_FILES['certificate_file']['tmp_name'],
                $upload_folder.$certificate_file
            )
        )
        {
            mysqli_query(
                $conn,
                "INSERT INTO final_reports
                (
                    internship_id,
                    certificate_file
                )
                VALUES
                (
                    '$internship_id',
                    '$certificate_file'
                )"
            );

            echo "<script>
                    alert('Certificate Uploaded Successfully');
                    window.location='upload_report.php';
                  </script>";
        }
        else
        {
            echo "<script>
                    alert('File Upload Failed');
                  </script>";
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Upload Internship Certificate</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

<style>

/* ---------- Base ---------- */
*, *::before, *::after { box-sizing: border-box; }

body{
    background:#f1f5f9;
    font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
    color:#1e293b;
    min-height:100vh;
    padding:20px 25px;
}

/* ---------- Page header ---------- */
.page-header{
    width:100%;
    margin-bottom:15px;
    display:flex;
    justify-content:flex-start;
    align-items:center;
    padding-left:8px;
}

.page-header-text h1 {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    color: #1e293b;
}

.page-header-text p {
    font-size: 16px;
    color: #64748b;
    margin: 2px 0 0;
}

.btn-back{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#1d6ef5;
    color:#fff;
    padding:10px 18px;
    border-radius:10px;
    text-decoration:none;
    font-size:18px;
    font-weight:600;
    transition:0.3s;
    border:none;
}

.btn-back i{
    font-size:18px;
}

.btn-back:hover{
    background:#0b5ed7;
    color:#fff;
}

.btn-back:hover{

    background:#0d5be1;
    color:white;

}

.btn-back:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
    color: #1e293b;
}

/* ---------- Card ---------- */
.form-box {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    background: #fff;
    border-radius: 16px;
    border: 0.5px solid #e2e8f0;
    overflow: hidden;
}

/* ---------- Card header strip ---------- */
.card-header-strip {
    height: 6px;
    background: linear-gradient(90deg, #4b0082 0%, #7c3aed 100%);
}

.card-inner{
    padding:28px;
}

/* ---------- Card title ---------- */
.card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.card-title-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    background: #ede9fe;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.card-title-icon i {
    font-size: 30px;
    color: #5b21b6;
}

.card-title h2 {
    font-size: 28px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
    line-height: 1.3;
}

.card-title p {
    font-size: 15px;
    color: #94a3b8;
    margin: 2px 0 0;
}

/* ---------- Section label ---------- */
.section-label {
    font-size: 11px;
    font-weight: 500;
    color: #94a3b8;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 0.5px solid #f1f5f9;
}

/* ---------- Fields ---------- */
.field {
    margin-bottom: 18px;
}

.field label {
    font-size: 15px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 6px;
    display: block;
}

.field .form-control,
.field .form-select {
    height: 52px;
    border: 0.5px solid #e2e8f0;
    border-radius: 9px;
    font-size: 16px;
    color: #1e293b;
    background: #fff;
    padding: 0 15px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    box-shadow: none;
}

.field .form-control:focus,
.field .form-select:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,.08);
}

/* ---------- Upload zone ---------- */
.upload-zone {
    border: 0.5px dashed #c4b5fd;
    border-radius: 10px;
    padding:40px 20px;
    text-align: center;
    background: #faf5ff;
    position: relative;
    transition: border-color .15s, background .15s;
    cursor: pointer;
}

.upload-zone:hover {
    border-color: #7c3aed;
    background: #f5f0ff;
}

.upload-zone i {
    font-size:34px;
    color: #a78bfa;
    display: block;
    margin-bottom: 6px;
}

.upload-zone .upload-title {
    font-size:16px;
    font-weight: 600;
    color: #5b21b6;
    margin: 0;
}

.upload-zone .upload-hint {
    font-size: 13px;
    color: #94a3b8;
    margin: 3px 0 0;
}

.upload-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}

/* ---------- Submit button ---------- */
.btn-submit{

    width:100%;

    height:54px;

    background:linear-gradient(90deg,#5f45ff,#6b4cff);

    border:none;

    color:#fff;

    font-size:18px;

    font-weight:600;

    border-radius:12px;

}

.btn-submit:hover { background: #3b0066; }

/* ---------- Success state ---------- */
.success-box {
    text-align: center;
    padding: 32px 20px;
}

.success-icon {
    width: 90px;
    height: 90px;
    background: #f0fdf4;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    border: 0.5px solid #bbf7d0;
}

.success-icon i {
    font-size: 42px;
    color: #16a34a;
}

.success-box h3 {
    font-size: 28px;
    font-weight: 700;
    color: #1e293b;
    margin: 0 0 6px;
}

.success-box p {
    font-size: 16px;
    color: #64748b;
    margin: 0;
}

.page-title{

    max-width:1200px;
    margin:0 auto 15px;

}

.page-title h1{
    font-size:40px;
    line-height:1.15;
    font-weight:700;
    color:#173b80;
    margin-bottom:10px;
}

.page-title p{
        font-size:17px;
    margin-bottom:0;
    color:#6c757d;
}

.info-card{

    background:#f8f9ff;

    border-radius:16px;

        padding:22px;


    height:100%;

}

.info-card h3{

    color:#4b4df6;

    font-size:22px;

    margin-bottom:15px;

    font-weight:700;

}

.info-card h4{

    color:#4b4df6;

    font-size:18px;
    margin-top:18px;

}

.info-card ul{

    padding-left:20px;

}

.info-card li{

    margin-bottom:10px;
    font-size:15px;
    line-height:1.45;

    color:#555;

}

.info-card p{

    font-size:16px;

    color:#666;

}

</style>

</head>
<body>

<!-- Page header with back button -->
<div class="page-header">

    <a href="dashboard.php" class="btn-back">
        <i class="ti ti-arrow-left"></i>
        Back
    </a>

</div>

<div class="page-title">

    <h1>Final Internship Certificate</h1>

    <p>
        Upload your internship completion certificate.
    </p>

</div>

<!-- Card -->
<div class="form-box">

    <div class="card-header-strip"></div>

    <div class="card-inner">

<div class="row g-4 align-items-start">

<div class="col-lg-8">

        <div class="card-title">
            <div class="card-title-icon">
                <i class="ti ti-certificate" aria-hidden="true"></i>
            </div>
            <div>
                <h2>Final internship certificate</h2>
                <p>Approved internships only &middot; PDF, max 500 KB</p>
            </div>
        </div>

        <?php

$alreadySubmitted = mysqli_query(
    $conn,
    "SELECT final_reports.*
     FROM final_reports
     JOIN internships
     ON final_reports.internship_id = internships.id
     WHERE internships.student_id='$student_id'"
);

if(mysqli_num_rows($alreadySubmitted) > 0)
{
?>

        <div class="success-box">
            <div class="success-icon">
                <i class="ti ti-circle-check" aria-hidden="true"></i>
            </div>
            <h3>Certificate already submitted</h3>
            <p>Your final internship certificate has been uploaded successfully.</p>
        </div>

<?php
}
else
{
?>

        <p class="section-label">Submission details</p>

        <form method="POST" enctype="multipart/form-data">

            <div class="field">

                <label for="internship_id">Select internship</label>

                <select name="internship_id"
                        id="internship_id"
                        class="form-control form-select"
                        required>

                    <?php

                    while($row = mysqli_fetch_assoc($result))
                    {

                    ?>

                    <option value="<?php echo $row['id']; ?>">

                        <?php echo htmlspecialchars($row['company_name']); ?>

                    </option>

                    <?php

                    }

                    ?>

                </select>

            </div>

            <div class="field">

                <label>Certificate file</label>

                <div class="upload-zone">
                    <i class="ti ti-upload" aria-hidden="true"></i>
                    <p class="upload-title" id="uploadTitle">

Drag & Drop your certificate here

</p>

<p class="upload-hint">

or click to browse files

</p>

<br>

<p class="upload-hint">

Allowed : PDF

<br>

Maximum Size : <b>500 KB</b>

</p>
                    <input type="file"
       id="certificate_file"
       name="certificate_file"
       accept=".pdf"
       required>

<div id="selectedFile"
     style="
        margin-top:15px;
        font-weight:600;
        color:#5b21b6;
        display:none;
     ">
</div>
                </div>

            </div>

            <button type="submit"
                    name="submit"
                    class="btn-submit">
                <i class="ti ti-upload" style="font-size:16px" aria-hidden="true"></i>
                Upload certificate
            </button>

        </form>
        
        </div>

<div class="col-lg-4">

<div class="info-card">

<h3>
<i class="ti ti-info-circle"></i>

Important Information

</h3>

<ul>

<li>Please upload your internship completion certificate.</li>

<li>Certificate should contain your Name.</li>

<li>Certificate should contain Company Name.</li>

<li>Certificate should contain Internship Duration.</li>

<li>Only PDF file is accepted.</li>

<li>Maximum file size is <strong>500 KB</strong>.</li>

</ul>

<hr>

<h4>

<i class="ti ti-help-circle"></i>

Need Help?

</h4>

<p>

If you face any issue while uploading the certificate,
contact your faculty coordinator.

</p>

</div>

</div>

</div>


    <?php
}
?>

    </div>
</div>

<script>

const fileInput=document.getElementById("certificate_file");
const selectedFile=document.getElementById("selectedFile");

fileInput.addEventListener("change",function(){

    if(this.files.length>0){

        const file=this.files[0];

        if(file.size>512000){

            alert("Only files up to 500 KB are allowed.");

            this.value="";

            selectedFile.style.display="none";

            return;
        }

        selectedFile.style.display="block";

        document.getElementById("uploadTitle").innerHTML=file.name;

selectedFile.innerHTML=
"<i class='ti ti-circle-check'></i> File Selected Successfully";
    }

});

</script>

</body>
</html>
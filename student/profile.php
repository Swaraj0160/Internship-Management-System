<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'student')
{
    header("Location: ../login.php");
    exit();
}

$student_id = $_SESSION['user_id'];

$query = "SELECT * FROM users WHERE id='$student_id'";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

if(isset($_POST['update']))
{
    $full_name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $phone     = mysqli_real_escape_string($conn, $_POST['phone']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);

    $photo_name = $_FILES['profile_photo']['name'];

    if($photo_name != "")
    {
        $photo_type = strtolower(pathinfo($photo_name, PATHINFO_EXTENSION));
        $allowed_types = ['jpg','jpeg','png'];

        if(!in_array($photo_type, $allowed_types))
        {
            die("Only JPG, JPEG, PNG files allowed");
        }

        $new_photo_name = time()."_".$photo_name;
        $photo_tmp = $_FILES['profile_photo']['tmp_name'];

        if(move_uploaded_file($photo_tmp, "../uploads/profile_photos/".$new_photo_name))
        {
            $update = "UPDATE users SET
                       full_name='$full_name', phone='$phone',
                       department='$department', profile_photo='$new_photo_name'
                       WHERE id='$student_id'";
        }
        else
        {
            die("Image Upload Failed");
        }
    }
    else
    {
        $update = "UPDATE users SET
                   full_name='$full_name', phone='$phone',
                   department='$department'
                   WHERE id='$student_id'";
    }

    mysqli_query($conn, $update);

    echo "<script>alert('Profile updated successfully.');</script>";
    echo "<script>window.location='profile.php';</script>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">

<style>

/* ---------- Base ---------- */
*, *::before, *::after { box-sizing: border-box; }

body{
    background:#f1f5f9;
    font-family:system-ui,-apple-system,"Segoe UI",sans-serif;
    min-height:100vh;
    padding:20px;
    color:#1e293b;
}

/* ---------- Page header ---------- */
.page-header{
    width:100%;
    display:flex;
    align-items:center;
    margin-bottom:15px;
    padding-left:8px;
}

.page-header-text-wrapper{
    margin-left:20px;
}
.page-header-text h1 { font-size: 20px; font-weight: 500; margin: 0; }
.page-header-text p  { font-size: 13px; color: #64748b; margin: 2px 0 0; }
.btn-back{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:#1d6ef5;
    color:#fff;
    padding:9px 18px;
    border-radius:10px;
    text-decoration:none;
    font-size:16px;
    font-weight:600;
    transition:.3s;
}

.btn-back i{
    font-size:18px;
}

.btn-back:hover{
    background:#0b5ed7;
    color:#fff;
}

.btn-back i{
    font-size:18px;
}

.btn-back:hover{
    background:#0b5ed7;
    color:#fff;
}
.btn-back:hover { border-color: #cbd5e1; background: #f8fafc; color: #1e293b; }

/* ---------- Card ---------- */
.profile-card {
    width:100%;
    max-width:1200px;
    margin:auto;
    background: #fff;
    border-radius: 16px;
    border: 0.5px solid #e2e8f0;
    overflow: hidden;
}

/* ---------- Banner + avatar ---------- */
.profile-banner {
    height: 90px;
    background: linear-gradient(135deg, #4b0082 0%, #7c3aed 100%);
    position: relative;
}

.avatar-wrap {
    position: absolute;
    bottom:-45px;
    left:30px;
}

.profile-photo {
    width:95px;
    height:95px;
    border-radius: 50%;
    object-fit: cover;
    border: 5px solid #fff;
    box-shadow: 0 2px 8px rgba(0,0,0,.12);
    display: block;
}

/* ---------- Card body ---------- */
.card-body {
    padding:58px 28px 24px;
}

/* ---------- User meta ---------- */
.user-meta { margin-bottom: 18px; }
.user-meta h2 { font-size: 22px; font-weight: 700; margin: 0; }
.user-meta .email { font-size: 14px; color: #64748b; margin: 3px 0 8px; }
.badge-role {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #ede9fe;
    color: #5b21b6;
    font-size: 14px;
    font-weight: 500;
    padding: 6px 14px;
    border-radius: 20px;
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
.field-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:14px;
    margin-bottom:14px;
}

@media (max-width: 520px) {
    .field-grid { grid-template-columns: 1fr; }
}

.field label {
    font-size: 15px;
    font-weight: 600;
    color: #475569;
    margin-bottom: 5px;
    display: block;
}

.field input {
    width: 100%;
    height: 44px;
    border: 0.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0 12px;
    font-size: 15px;
    color: #1e293b;
    background: #fff;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
}

.field input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,.08);
}

.field input:disabled {
    background: #f8fafc;
    color: #94a3b8;
    cursor: not-allowed;
}

/* ---------- Upload zone ---------- */
.upload-zone {
    border: 0.5px dashed #c4b5fd;
    border-radius: 10px;
    padding:22px 20px;
    text-align: center;
    background: #faf5ff;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    margin-bottom: 18px;
    position: relative;
}

.upload-zone:hover {
    border-color: #7c3aed;
    background: #f5f0ff;
}

.upload-zone i {
    font-size: 28px;
    color: #a78bfa;
    display: block;
    margin-bottom: 6px;
}

.upload-zone .upload-title {
    font-size: 16px;
    font-weight: 600;
    color: #5b21b6;
}

.upload-zone .upload-hint {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 2px;
}

.upload-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}

/* ---------- Actions ---------- */
.form-actions {
    display: flex;
    gap: 10px;
}

.btn-cancel {
    height: 46px;
    padding: 0 20px;
    background: #fff;
    color: #475569;
    border: 0.5px solid #e2e8f0;
    border-radius: 9px;
    border-radius:10px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
}

.btn-cancel:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
    color: #334155;
}

.btn-save {
    flex: 1;
    height: 46px;
    background: #4b0082;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}


.btn-save:hover { background: #3b0066; }

</style>
</head>
<body>

<div class="page-header">

    <a href="dashboard.php" class="btn-back">
        <i class="ti ti-arrow-left"></i>
        Back
    </a>

    <div class="page-header-text-wrapper">

        <div class="page-header-text">
            <h1>    My Profile</h1>
            <p>Manage your personal information and preferences</p>
        </div>

    </div>

</div>

<div class="profile-card">

    <!-- Banner + avatar -->
    <div class="profile-banner">
        <div class="avatar-wrap">
            <?php if(!empty($user['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($user['profile_photo']); ?>"
                     class="profile-photo" alt="Profile photo">
            <?php else: ?>
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png"
                     class="profile-photo" alt="Default profile photo">
            <?php endif; ?>
        </div>
    </div>

    <div class="card-body">

        <!-- Name + role badge -->
        <div class="user-meta">
            <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <p class="email"><?php echo htmlspecialchars($user['email']); ?></p>
            <span class="badge-role">
                <i class="ti ti-user-check" style="font-size:11px"></i> Student
            </span>
        </div>

        <form method="POST" enctype="multipart/form-data">

            <!-- Personal info -->
            <p class="section-label">Personal information</p>

            <div class="field-grid">

                <div class="field">
                    <label for="full_name">Full name</label>
                    <input type="text"
                           id="full_name"
                           name="full_name"
                           value="<?php echo htmlspecialchars($user['full_name']); ?>"
                           placeholder="Enter your full name"
                           required>
                </div>

                <div class="field">
                    <label for="phone">Phone number</label>
                    <input type="text"
                           id="phone"
                           name="phone"
                           value="<?php echo htmlspecialchars($user['phone']); ?>"
                           placeholder="Enter phone number">
                </div>

                <div class="field">
                    <label for="department">Department</label>
                    <input type="text"
                           id="department"
                           name="department"
                           value="<?php echo htmlspecialchars($user['department']); ?>"
                           placeholder="Enter your department">
                </div>

                <div class="field">
                    <label>Email address</label>
                    <input type="email"
                           value="<?php echo htmlspecialchars($user['email']); ?>"
                           disabled>
                </div>

            </div>

            <!-- Photo upload -->
            <p class="section-label">Profile photo</p>

            <div class="upload-zone">
                <i class="ti ti-upload" aria-hidden="true"></i>
                <p class="upload-title">Click to upload a new photo</p>
                <p class="upload-hint">JPG, JPEG or PNG &mdash; max 5 MB</p>
                <input type="file" name="profile_photo" accept=".jpg,.jpeg,.png">
            </div>

            <!-- Actions -->
            <div class="form-actions">
                <a href="profile.php" class="btn-cancel">Cancel</a>
                <button type="submit" name="update" class="btn-save">
                    <i class="ti ti-check" style="font-size:16px"></i>
                    Save changes
                </button>
            </div>

        </form>
    </div>
</div>

</body>
</html>
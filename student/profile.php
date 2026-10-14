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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

/* ---------- Base ---------- */
*, *::before, *::after { box-sizing: border-box; }

body{
    background:#f5f7fb;
    font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;
    color:#0f172a;
    min-height:100vh;
    margin:0;
}

.page-inner{
    padding:28px 25px 60px;
}

/* ---------- Page header ---------- */
.page-header{
    max-width:1200px;
    width:100%;
    margin:0 auto 18px;
    display:flex;
    justify-content:flex-start;
    align-items:center;
}

.btn-back{
    display:inline-flex;
    align-items:center;
    gap:8px;
    background:linear-gradient(90deg,#2563eb,#3b82f6);
    color:#fff;
    padding:11px 22px;
    border-radius:999px;
    text-decoration:none;
    font-size:16px;
    font-weight:600;
    transition:transform .15s ease, box-shadow .15s ease;
    border:none;
    box-shadow:0 6px 16px rgba(37,99,235,.28);
}

.btn-back i{
    font-size:18px;
}

.btn-back:hover{
    color:#fff;
    transform:translateY(-1px);
    box-shadow:0 8px 20px rgba(37,99,235,.35);
}

/* ---------- Page title ---------- */
.page-title{
    max-width:1200px;
    margin:0 auto 22px;
}

.page-title h1{
    font-size:38px;
    line-height:1.15;
    font-weight:800;
    color:#0f172a;
    margin-bottom:6px;
    letter-spacing:-.01em;
}

.page-title p{
    font-size:17px;
    margin-bottom:0;
    color:#64748b;
}

/* ---------- Card ---------- */
.form-box {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    background: #fff;
    border-radius: 20px;
    border: 1px solid #eef1f6;
    overflow: hidden;
    box-shadow: 0 18px 45px rgba(15,23,42,.06);
    animation: fadeIn .35s ease;
}

@keyframes fadeIn{
    from{ opacity:0; transform:translateY(6px); }
    to{ opacity:1; transform:translateY(0); }
}

/* ---------- Card header strip ---------- */
.card-header-strip {
    height: 5px;
    background: linear-gradient(90deg, #2563eb 0%, #7c3aed 100%);
}

.card-inner{
    padding:32px;
}

/* ---------- Card title ---------- */
.card-title {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 22px;
}

.card-title-icon {
    width: 56px;
    height: 56px;
    border-radius: 15px;
    background: #ede9fe;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.card-title-icon i {
    font-size: 26px;
    color: #7c3aed;
}

.card-title h2 {
    font-size: 22px;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    line-height: 1.3;
}

.card-title p {
    font-size: 14px;
    color: #94a3b8;
    margin: 2px 0 0;
}

/* ---------- Profile summary row (replaces the old banner) ---------- */
.profile-summary{
    display:flex;
    align-items:center;
    gap:20px;
    background:#f8fafc;
    border:1px solid #eef1f6;
    border-radius:14px;
    padding:22px 26px;
    margin-bottom:26px;
}

.profile-summary-avatar{
    width:84px;
    height:84px;
    border-radius:50%;
    object-fit:cover;
    border:3px solid #fff;
    box-shadow:0 6px 16px rgba(15,23,42,.1);
    flex-shrink:0;
    display:block;
}

.profile-summary-text h2{
    font-size:22px;
    font-weight:800;
    color:#0f172a;
    margin:0 0 4px;
    letter-spacing:-.01em;
}

.profile-summary-text .email{
    font-size:14.5px;
    color:#64748b;
    margin:0 0 10px;
}

.status-pill{
    display:inline-flex;
    align-items:center;
    gap:6px;
    background:#ede9fe;
    color:#5b21b6;
    font-size:13px;
    font-weight:700;
    padding:4px 12px;
    border-radius:999px;
}

/* ---------- Section label ---------- */
.section-label {
    font-size: 12px;
    font-weight: 700;
    color: #94a3b8;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 16px;
}

/* ---------- Fields ---------- */
.field-grid{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:0 20px;
}

@media (max-width: 520px){
    .field-grid{ grid-template-columns:1fr; }
}

.field {
    margin-bottom: 20px;
}

.field label {
    font-size: 15px;
    font-weight: 600;
    color: #334155;
    margin-bottom: 8px;
    display: block;
}

.field .form-control,
.field .form-select {
    height: 50px;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    font-size: 15px;
    color: #0f172a;
    background: #fff;
    padding: 0 15px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    box-shadow: none;
    width:100%;
}

.field .form-control:focus,
.field .form-select:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}

.field .form-control:disabled{
    background:#f8fafc;
    color:#94a3b8;
    cursor:not-allowed;
}

/* ---------- Upload zone ---------- */
.upload-zone {
    border: 1.5px dashed #c4b5fd;
    border-radius: 14px;
    padding:44px 20px;
    text-align: center;
    background: #faf7ff;
    position: relative;
    transition: border-color .15s, background .15s;
    cursor: pointer;
}

.upload-zone.dragover,
.upload-zone:hover {
    border-color: #7c3aed;
    background: #f4edff;
}

.upload-zone i {
    font-size:36px;
    color: #7c3aed;
    display: block;
    margin-bottom: 10px;
    background:#ede9fe;
    width:64px;
    height:64px;
    line-height:64px;
    border-radius:50%;
    margin-left:auto;
    margin-right:auto;
}

.upload-zone .upload-title {
    font-size:17px;
    font-weight: 700;
    color: #1e293b;
    margin: 0;
}

.upload-zone .upload-hint {
    font-size: 14px;
    color: #94a3b8;
    margin: 4px 0 0;
}

.upload-zone input[type="file"] {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
    width: 100%;
    height: 100%;
}

.upload-selected-file{
    margin-top:15px;
    font-weight:600;
    color:#16a34a;
    display:none;
    align-items:center;
    justify-content:center;
    gap:6px;
}

.upload-selected-file i{
    font-size:18px;
}

/* ---------- Actions ---------- */
.form-actions {
    display: flex;
    gap: 14px;
    margin-top:6px;
}

.btn-cancel{
    flex:1;
    height:54px;
    border-radius:12px;
    font-size:15px;
    font-weight:700;
    background:#fff;
    border:1px solid #e2e8f0;
    color:#334155;
    cursor:pointer;
    text-decoration:none;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:background .15s ease, border-color .15s ease, transform .15s ease;
}

.btn-cancel:hover{
    background:#f8fafc;
    border-color:#cbd5e1;
    color:#334155;
    transform:translateY(-1px);
}

.btn-submit{
    flex:2;
    height:54px;
    background:linear-gradient(90deg,#7c3aed,#4f46e5);
    border:none;
    color:#fff;
    font-size:17px;
    font-weight:700;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    transition:transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    box-shadow:0 10px 24px rgba(79,70,229,.25);
    cursor:pointer;
}

.btn-submit:hover {
    transform:translateY(-1px);
    box-shadow:0 12px 28px rgba(79,70,229,.32);
    color:#fff;
}

@media (max-width: 520px){
    .form-actions{ flex-direction:column; }
    .btn-submit{ flex:1; }
}

/* ---------- Info card ---------- */
.info-card{
    background:#f5f3ff;
    border-radius:16px;
    padding:24px;
    height:100%;
}

.info-card h3{
    color:#4f46e5;
    font-size:19px;
    margin-bottom:16px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:8px;
}

.info-card h4{
    color:#4f46e5;
    font-size:16px;
    margin-top:20px;
    margin-bottom:8px;
    font-weight:700;
    display:flex;
    align-items:center;
    gap:8px;
}

.info-card ul{
    padding-left:20px;
    margin-bottom:0;
}

.info-card li{
    margin-bottom:10px;
    font-size:14.5px;
    line-height:1.5;
    color:#475569;
}

.info-card p{
    font-size:14.5px;
    color:#64748b;
    line-height:1.5;
    margin:0;
}

.info-card hr{
    border-color:#e0e0fa;
    margin:18px 0 0;
}

/* ---------- Responsive ---------- */
@media (max-width: 767px){
    .page-title h1{ font-size:30px; }
    .card-inner{ padding:22px; }
    .profile-summary{ flex-direction:column; text-align:center; }
}

</style>

</head>
<body>

<?php $activePage = 'profile.php'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

<div class="page-title">

    <h1>My Profile</h1>

    <p>
        Manage your personal information and preferences.
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
                <i class="ti ti-user-circle" aria-hidden="true"></i>
            </div>
            <div>
                <h2>Profile information</h2>
                <p>Update your details and profile photo</p>
            </div>
        </div>

        <div class="profile-summary">
            <?php if(!empty($user['profile_photo'])): ?>
                <img src="../uploads/profile_photos/<?php echo htmlspecialchars($user['profile_photo']); ?>"
                     class="profile-summary-avatar" id="avatarPreview" alt="Profile photo">
            <?php else: ?>
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png"
                     class="profile-summary-avatar" id="avatarPreview" alt="Default profile photo">
            <?php endif; ?>
            <div class="profile-summary-text">
                <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <p class="email"><?php echo htmlspecialchars($user['email']); ?></p>
                <span class="status-pill">
                    <i class="ti ti-user-check" style="font-size:12px"></i> Student
                </span>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">

            <p class="section-label">Personal information</p>

            <div class="field-grid">

                <div class="field">
                    <label for="full_name">Full name</label>
                    <input type="text"
                           id="full_name"
                           name="full_name"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['full_name']); ?>"
                           placeholder="Enter your full name"
                           required>
                </div>

                <div class="field">
                    <label for="phone">Phone number</label>
                    <input type="text"
                           id="phone"
                           name="phone"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['phone']); ?>"
                           placeholder="Enter phone number">
                </div>

                <div class="field">
                    <label for="department">Department</label>
                    <input type="text"
                           id="department"
                           name="department"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['department']); ?>"
                           placeholder="Enter your department">
                </div>

                <div class="field">
                    <label for="email_display">Email address</label>
                    <input type="email"
                           id="email_display"
                           class="form-control"
                           value="<?php echo htmlspecialchars($user['email']); ?>"
                           disabled>
                </div>

            </div>

            <p class="section-label">Profile photo</p>

            <div class="field">

                <div class="upload-zone" id="photoUploadZone">
                    <i class="ti ti-photo-up" aria-hidden="true"></i>
                    <p class="upload-title" id="photoUploadTitle">Click to upload a new photo</p>
                    <p class="upload-hint">Drag &amp; drop, or click to browse</p>
                    <br>
                    <p class="upload-hint">
                        Allowed : JPG, JPEG, PNG
                        <br>
                        Maximum Size : <b>5 MB</b>
                    </p>
                    <input type="file"
                           id="profile_photo"
                           name="profile_photo"
                           accept=".jpg,.jpeg,.png">
                    <div id="photoSelectedFile" class="upload-selected-file"></div>
                </div>

            </div>

            <div class="form-actions">
                <a href="profile.php" class="btn-cancel">Cancel</a>
                <button type="submit" name="update" class="btn-submit">
                    <i class="ti ti-check" style="font-size:16px" aria-hidden="true"></i>
                    Save changes
                </button>
            </div>

        </form>

        </div>

<div class="col-lg-4">

<div class="info-card">

<h3>
<i class="ti ti-info-circle"></i>

Profile Tips

</h3>

<ul>

<li>Keep your full name accurate — it's used on official documents like your internship certificate.</li>

<li>Add a phone number so your faculty coordinator can reach you quickly.</li>

<li>Only JPG, JPEG or PNG files are accepted for your profile photo.</li>

<li>Maximum photo size is <strong>5 MB</strong>.</li>

</ul>

<hr>

<h4>

<i class="ti ti-help-circle"></i>

Need Help?

</h4>

<p>

If you face any issue while updating your profile,
contact your faculty coordinator.

</p>

</div>

</div>

</div>

    </div>
</div>

</div><!-- /.page-inner -->

</main>
</div>

<script>

const photoInput = document.getElementById("profile_photo");
const photoSelectedFile = document.getElementById("photoSelectedFile");
const photoUploadZone = document.getElementById("photoUploadZone");
const photoUploadTitle = document.getElementById("photoUploadTitle");
const avatarPreview = document.getElementById("avatarPreview");

if(photoInput){

    photoInput.addEventListener("change", function(){

        if(this.files.length > 0){

            const file = this.files[0];
            const nameLower = file.name.toLowerCase();
            const allowedExt = [".jpg", ".jpeg", ".png"];
            const isAllowed = allowedExt.some(function(ext){
                return nameLower.endsWith(ext);
            });

            if(!isAllowed){
                alert("Only JPG, JPEG, PNG files allowed");
                this.value = "";
                photoSelectedFile.style.display = "none";
                return;
            }

            if(file.size > 5 * 1024 * 1024){
                alert("Only files up to 5 MB are allowed.");
                this.value = "";
                photoSelectedFile.style.display = "none";
                return;
            }

            photoUploadTitle.innerHTML = file.name;

            photoSelectedFile.style.display = "flex";
            photoSelectedFile.innerHTML =
                "<i class='ti ti-circle-check'></i> Photo Selected Successfully";

            if(avatarPreview){
                const reader = new FileReader();
                reader.onload = function(e){
                    avatarPreview.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        }

    });

}

if(photoUploadZone){
    photoUploadZone.addEventListener("dragover", function(e){
        e.preventDefault();
        photoUploadZone.classList.add("dragover");
    });
    photoUploadZone.addEventListener("dragleave", function(){
        photoUploadZone.classList.remove("dragover");
    });
    photoUploadZone.addEventListener("drop", function(){
        photoUploadZone.classList.remove("dragover");
    });
}

</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
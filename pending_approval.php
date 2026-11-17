<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Pending Approval — InternHub | MIT-ADT University</title>
    <link rel="icon" type="image/png" href="assets/images/mit_adt_crest.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #0d0722 url('assets/images/campus_bg_reference.webp') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            color: #334155;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 7, 34, 0.88) 0%, rgba(20, 10, 45, 0.75) 50%, rgba(10, 5, 25, 0.85) 100%);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1;
        }

        .status-card {
            position: relative;
            z-index: 2;
            background: #ffffff;
            border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
            padding: 44px;
            max-width: 520px;
            width: 100%;
            text-align: center;
        }

        .status-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: #ede9fe;
            color: #6d28d9;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            margin-bottom: 20px;
        }

        .btn-action {
            background: linear-gradient(135deg, #6d28d9 0%, #43248b 100%);
            color: #ffffff;
            border: none;
            height: 48px;
            border-radius: 12px;
            width: 100%;
            font-weight: 700;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(109, 40, 217, 0.28);
            margin-top: 24px;
        }

        .btn-action:hover {
            color: #ffffff;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<div class="status-card">
    <div class="status-icon">
        <i class="bi bi-clock-history"></i>
    </div>
    <h2 style="font-weight: 800; color: #1e1b4b; margin-bottom: 8px;">Pending Admin Approval</h2>
    <p style="color: #64748b; font-size: 0.95rem; line-height: 1.6; margin-bottom: 24px;">
        Your faculty registration has been submitted and is currently being reviewed by the MIT-ADT University Central Administrator.
    </p>

    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 14px; padding: 18px; text-align: left; font-size: 0.85rem; color: #475569;">
        <div style="font-weight: 700; color: #1e293b; margin-bottom: 6px;"><i class="bi bi-info-circle-fill text-primary me-1"></i> What happens next?</div>
        <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
            <li>The administrator verifies your departmental employee records.</li>
            <li>Upon approval, your login will be activated and you will receive an email confirmation.</li>
            <li>If you have an urgent evaluation deadline, please contact <a href="mailto:internship@mitadt.edu.in" style="color: #6d28d9; font-weight: 600;">internship@mitadt.edu.in</a>.</li>
        </ul>
    </div>

    <a href="login.php" class="btn-action">
        <i class="bi bi-arrow-left"></i>
        <span>Return to Login</span>
    </a>
</div>

</body>
</html>

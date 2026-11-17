<?php
/* -----------------------------------------------------------------
   InternHub — Faculty Help & Mentorship Support
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$activePage = 'help.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Help & Support | InternHub</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .help-container {
            padding: 0 32px 36px;
        }

        .help-header {
            margin-bottom: 24px;
        }

        .help-header h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
        }

        .help-header p {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 3px;
        }

        .faq-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .faq-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        }

        .faq-q {
            font-size: 0.98rem;
            font-weight: 700;
            color: #1e1b4b;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .faq-a {
            font-size: 0.85rem;
            color: #475569;
            line-height: 1.6;
        }

        .support-box {
            background: linear-gradient(135deg, #f5edfd, #ffffff);
            border: 1px solid #d8b4fe;
            border-radius: 16px;
            padding: 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        @media (max-width: 992px) {
            .faq-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="help-container">
    <div class="help-header">
        <h1>Faculty Mentorship Guide & Support</h1>
        <p>Frequently asked questions and official university guidelines for student internship mentorship.</p>
    </div>

    <div class="faq-grid">
        <div class="faq-card">
            <div class="faq-q">
                <i class="fa-solid fa-circle-question" style="color: #7c3aed;"></i>
                <span>How do I approve or reject an internship application?</span>
            </div>
            <p class="faq-a">
                Navigate to <strong>Internship Oversight</strong> &rarr; <strong>Applications</strong>. Click "Approve" to authorize valid submissions, or "Reject" to return the application with required constructive feedback to help the student revise their offer details.
            </p>
        </div>

        <div class="faq-card">
            <div class="faq-q">
                <i class="fa-solid fa-graduation-cap" style="color: #10b981;"></i>
                <span>How is the continuous evaluation score calculated?</span>
            </div>
            <p class="faq-a">
                Continuous score combines Week 1 to Week 4 milestone marks (10 marks each = 40 total), Mentor Evaluation (40 marks max), and Final Evaluation (20 marks max set by departmental committee). The grand sum of 100 is automatically scaled to 50 marks.
            </p>
        </div>

        <div class="faq-card">
            <div class="faq-q">
                <i class="fa-solid fa-wand-magic-sparkles" style="color: #fbbf24;"></i>
                <span>What are the capabilities of the AI Mentor Assistant?</span>
            </div>
            <p class="faq-a">
                The AI Mentor Assistant assists faculty in generating structured rubric feedback, summarizing multi-week student progress, and flagging submission risks. Crucially, the AI only generates draft suggestions and will never autonomously commit marks or decisions.
            </p>
        </div>

        <div class="faq-card">
            <div class="faq-q">
                <i class="fa-solid fa-clock" style="color: #ef4444;"></i>
                <span>How do I set submission deadlines for my division?</span>
            </div>
            <p class="faq-a">
                From the Faculty Dashboard, click "Manage" on the Deadlines widget, select the week milestone (Week 1 to Week 4), pick the cutoff datetime, and save. Students in your division will immediately see the updated deadline countdown.
            </p>
        </div>
    </div>

    <div class="support-box">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #1e1b4b;">Need Technical Assistance or Role Updates?</h3>
            <p style="font-size: 0.84rem; color: #64748b; margin-top: 4px;">Contact the MIT-ADT University Internship Coordination Cell & System Administrator.</p>
        </div>
        <a href="mailto:internship.cell@mituniversity.edu.in" style="padding: 10px 20px; border-radius: 10px; background: #7c3aed; color: #ffffff; font-weight: 700; font-size: 0.85rem; text-decoration: none;">
            Contact Support Desk
        </a>
    </div>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>

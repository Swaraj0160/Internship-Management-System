<?php
/**
 * InternHub — Student Help & Support (help.php)
 * Student platform documentation, guidelines, FAQs, and coordinator contacts
 */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'help.php';

if (!function_exists('h')) {
    function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help & Support — InternHub | MIT-ADT University</title>
    <?php require_once __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
    .help-wrapper {
        padding: 0 32px 32px;
        display: flex;
        flex-direction: column;
        gap: 24px;
        max-width: 1000px;
    }

    .help-card {
        background: #ffffff;
        border-radius: 20px;
        padding: 26px 30px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 4px 18px rgba(0,0,0,0.035);
    }

    .help-card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.15rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 16px;
        padding-bottom: 12px;
        border-bottom: 1px solid #f1f5f9;
    }
    .help-card-header i { color: #7c3aed; }

    .faq-list {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }

    .faq-item {
        background: #f8fafc;
        border-radius: 14px;
        padding: 16px 20px;
        border: 1px solid #e2e8f0;
    }

    .faq-q {
        font-size: 0.92rem;
        font-weight: 800;
        color: #1e1b4b;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .faq-q i { color: #7c3aed; font-size: 0.85rem; }

    .faq-a {
        font-size: 0.84rem;
        color: #475569;
        line-height: 1.5;
    }

    .contacts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 16px;
        margin-top: 14px;
    }

    .contact-box {
        background: #f8fafc;
        border-radius: 16px;
        padding: 18px;
        border: 1px solid #e2e8f0;
    }

    .contact-title {
        font-size: 0.92rem;
        font-weight: 800;
        color: #1e1b4b;
    }

    .contact-sub {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 2px;
    }

    .contact-detail {
        font-size: 0.82rem;
        color: #5b21b6;
        font-weight: 700;
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    @media (max-width: 768px) {
        .help-wrapper { padding: 0 16px 24px; }
        .contacts-grid { grid-template-columns: 1fr; }
    }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="help-wrapper">

    <!-- Card 1: Frequently Asked Questions -->
    <div class="help-card">
        <div class="help-card-header">
            <i class="fa-solid fa-circle-question"></i>
            <span>Frequently Asked Questions & Guidelines</span>
        </div>

        <div class="faq-list">
            <div class="faq-item">
                <div class="faq-q"><i class="fa-solid fa-chevron-right"></i> How do I apply for an internship?</div>
                <div class="faq-a">Navigate to <strong>My Internship → Apply / Edit</strong>. Fill in the company details, mentor contact, duration, and attach your official Offer Letter or NOC document in PDF or image format. Your submission will immediately be routed to your assigned department faculty mentor for review.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q"><i class="fa-solid fa-chevron-right"></i> When should I submit weekly reports?</div>
                <div class="faq-a">Weekly reports must be submitted at the end of each working week (by Sunday 11:59 PM). Use the <strong>✨ AI Polish Report</strong> button to refine your rough engineering notes into concise bullet points before submitting for faculty feedback.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q"><i class="fa-solid fa-chevron-right"></i> When are marks and evaluations released?</div>
                <div class="faq-a">Marks are awarded continuously based on your attendance, weekly report quality (TA1 and TA2), and final presentation/viva. Scores are published on your <strong>Marks & Evaluation</strong> tab once finalized by the evaluation committee.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q"><i class="fa-solid fa-chevron-right"></i> How does the student portfolio work?</div>
                <div class="faq-a">Under <strong>My Portfolio</strong>, you can showcase capstone projects, add GitHub repository and live demo links, upload your PDF resume, and list verified skills. You can share your public portfolio link with recruiters and industry contacts.</div>
            </div>

            <div class="faq-item">
                <div class="faq-q"><i class="fa-solid fa-chevron-right"></i> What are the rules for AI assistance?</div>
                <div class="faq-a">The built-in AI Assistant is strictly an aid for structuring, grammar refinement, and resume optimization. It never invents false claims or fabricates credentials. All AI outputs are saved as drafts and require your personal review and approval.</div>
            </div>
        </div>
    </div>

    <!-- Card 2: Department Coordinators -->
    <div class="help-card">
        <div class="help-card-header">
            <i class="fa-solid fa-address-book"></i>
            <span>Department Coordinators & Support Contacts</span>
        </div>

        <div class="contacts-grid">
            <div class="contact-box">
                <div class="contact-title">School of Computing (SOC)</div>
                <div class="contact-sub">Internship Coordinator Desk</div>
                <div class="contact-detail"><i class="fa-solid fa-envelope"></i> soc.internship@mitadt.edu.in</div>
            </div>

            <div class="contact-box">
                <div class="contact-title">Training & Placement Cell</div>
                <div class="contact-sub">Corporate Relations & NOC Support</div>
                <div class="contact-detail"><i class="fa-solid fa-envelope"></i> tpo@mitadt.edu.in</div>
            </div>

            <div class="contact-box">
                <div class="contact-title">Portal Technical Support</div>
                <div class="contact-sub">Team-Apex Engineering Helpdesk</div>
                <div class="contact-detail"><i class="fa-solid fa-headset"></i> support@internhub.mitadt.edu.in</div>
            </div>
        </div>
    </div>

</div>

</main>
</div>

<?php require_once __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>

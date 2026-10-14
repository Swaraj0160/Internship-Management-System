<?php
session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'marks.php';

// Safe escaping
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

// Fetch mentor name from approved internship
$mentor_name = null;
$internshipQuery = "SELECT mentor_name FROM internships WHERE student_id = ? AND status = 'Approved' ORDER BY id DESC LIMIT 1";
$stmt = $conn->prepare($internshipQuery);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$intResult = $stmt->get_result();
if ($intResult && $intResult->num_rows > 0) {
    $row = $intResult->fetch_assoc();
    if (!empty($row['mentor_name'])) {
        $mentor_name = $row['mentor_name'];
    }
}
$stmt->close();

// Fetch student marks
$query = "SELECT * FROM student_evaluation WHERE student_id = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$eval_result = $stmt->get_result();

$is_evaluated = false;
$eval = null;

if ($eval_result && $eval_result->num_rows > 0) {
    $eval = $eval_result->fetch_assoc();
    $is_evaluated = true;
}
$stmt->close();

// We must display exactly what's in the DB. If there is no DB record, marks are null.
$week1 = $is_evaluated ? (int)($eval['week1_marks'] ?? 0) : null;
$week2 = $is_evaluated ? (int)($eval['week2_marks'] ?? 0) : null;
$week3 = $is_evaluated ? (int)($eval['week3_marks'] ?? 0) : null;
$week4 = $is_evaluated ? (int)($eval['week4_marks'] ?? 0) : null;
$weekly_total = $is_evaluated ? (int)($eval['weekly_total'] ?? 0) : null;
$mentor_eval = $is_evaluated ? (int)($eval['mentor_evaluation'] ?? 0) : null;
$final_eval = $is_evaluated ? (int)($eval['final_evaluation'] ?? 0) : null;
$total_marks = $is_evaluated ? (int)($eval['total_marks'] ?? 0) : null;

// Helpers
function displayMark($mark) {
    if ($mark === null) {
        return '<span class="pending-badge">Pending</span>';
    }
    return '<span class="score-value">' . h($mark) . '</span>';
}

function progressPct($mark, $max) {
    if ($mark === null || $max == 0) return 0;
    return min(100, max(0, ($mark / $max) * 100));
}

function getProgressColor($pct) {
    if ($pct >= 80) return 'var(--success)';
    if ($pct >= 50) return 'var(--info)';
    if ($pct >= 30) return 'var(--warning)';
    return 'var(--danger)';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Marks | InternHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        :root{
            --bg:#f5f7fb;
            --surface:#ffffff;
            --text:#0f172a;
            --text-muted:#64748b;
            --border:#e2e8f0;
            --primary:#4f46e5;
            --success:#10b981;
            --warning:#f59e0b;
            --danger:#ef4444;
            --info:#3b82f6;
            --shadow:0 4px 6px -1px rgba(0,0,0,0.05), 0 10px 15px -3px rgba(0,0,0,0.05);
            --shadow-soft:0 10px 25px rgba(0,0,0,0.03);
            --radius:16px;
        }
        
        body {
            background-color: var(--bg);
            font-family: 'Inter', sans-serif;
            color: var(--text);
            margin: 0;
            min-height: 100vh;
        }

        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 22px 30px;
        }
        .hero-title {
            display: flex; flex-direction: column; gap: 4px;
        }
        .hero-title .eyebrow {
            font-size: 0.85rem; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px;
        }
        .hero-title h1 {
            font-size: 1.75rem; font-weight: 800; margin: 0; color: var(--text); letter-spacing: -0.02em;
        }
        .hero-title .sub {
            font-size: 0.95rem; color: var(--text-muted);
        }

        .page-content {
            padding: 32px 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .marks-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
        }
        @media (min-width: 992px) {
            .marks-grid {
                grid-template-columns: 2fr 1fr;
            }
        }

        .card-panel {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header h2 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
            color: var(--text);
        }
        .card-icon {
            width: 36px; height: 36px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            color: white;
            flex-shrink: 0;
        }
        .icon-blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .icon-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .icon-green { background: linear-gradient(135deg, #10b981, #059669); }
        .icon-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .icon-gold { background: linear-gradient(135deg, #eab308, #ca8a04); }

        .card-body {
            padding: 24px;
        }

        /* Final Score Hero */
        .score-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
            text-align: center;
            background: linear-gradient(135deg, #ffffff, #f8fafc);
        }
        .circular-progress {
            position: relative;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: conic-gradient(var(--primary) calc(var(--pct) * 1%), #e2e8f0 0);
            box-shadow: inset 0 0 0 16px var(--surface);
            margin-bottom: 24px;
        }
        .circular-progress::before {
            content: '';
            position: absolute;
            inset: 12px;
            border-radius: 50%;
            background: var(--surface);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        .circular-progress .inner {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .circular-progress .inner .val {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1;
            letter-spacing: -1px;
        }
        .circular-progress .inner .max {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-top: 4px;
        }
        .score-hero-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }
        .score-hero-desc {
            font-size: 0.95rem;
            color: var(--text-muted);
            max-width: 300px;
        }

        /* Weekly Table */
        .marks-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .marks-table th {
            padding: 12px 16px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            font-weight: 700;
            border-bottom: 2px solid var(--border);
            text-align: left;
        }
        .marks-table th.text-right { text-align: right; }
        .marks-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .marks-table tr:last-child td { border-bottom: none; }
        .marks-table .week-label {
            font-weight: 600;
            color: var(--text);
        }
        .marks-table .score-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
        }
        .marks-table .score-max {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .progress-indicator {
            width: 100%;
            height: 6px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 8px;
            position: relative;
        }
        .progress-bar-fill {
            height: 100%;
            border-radius: 4px;
            background: var(--info);
            transition: width 0.5s ease;
        }
        .row-total {
            background: #f8fafc;
            font-weight: 700;
        }
        .row-total td {
            border-top: 2px solid var(--border);
        }

        /* Detailed Breakdowns */
        .eval-section {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .eval-card {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            background: #ffffff;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .eval-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-soft);
        }
        .eval-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .eval-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .eval-card-score {
            text-align: right;
        }
        .eval-card-score .score-value {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--text);
        }
        .eval-card-meta {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pending-badge {
            background: #f1f5f9;
            color: #64748b;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #e2e8f0;
        }

        /* Summary Panel */
        .summary-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 1px dashed var(--border);
        }
        .summary-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 2px solid var(--border);
        }
        .summary-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--text-muted);
        }
        .summary-val {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }
        .summary-item.total .summary-label {
            color: var(--text);
            font-size: 1rem;
            font-weight: 800;
        }
        .summary-item.total .summary-val {
            font-size: 1.25rem;
            color: var(--primary);
            font-weight: 800;
        }
        .summary-math {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-align: center;
            margin-top: 16px;
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        @media (max-width: 768px) {
            .topbar { padding: 16px 20px; }
            .page-content { padding: 20px 16px; }
            .circular-progress { width: 130px; height: 130px; }
            .circular-progress .inner .val { font-size: 2rem; }
            .marks-table td { padding: 12px 8px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="topbar">
    <div class="topbar-inner">
        <div class="hero-title">
            <div class="eyebrow">Academic Performance</div>
            <h1>Evaluation Marks</h1>
            <div class="sub">
                <span>View your weekly and final evaluation scores</span>
            </div>
        </div>
    </div>
</div>

<div class="page-content">
    
    <?php if (!$is_evaluated): ?>
        <div class="alert alert-info d-flex align-items-center mb-4" style="background:#eff6ff; border-color:#bfdbfe; color:#1e3a8a; border-radius: 12px; padding: 20px;">
            <i class="fa-solid fa-circle-info fs-4 me-3"></i>
            <div>
                <h5 class="mb-1 fw-bold">Not Yet Evaluated</h5>
                <p class="mb-0" style="font-size:0.9rem;">Your internship evaluation marks have not been posted yet. Please check back later or contact your mentor.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="marks-grid">
        
        <!-- Left Column -->
        <div class="d-flex flex-column gap-4">
            
            <!-- Weekly Evaluation Table -->
            <div class="card-panel">
                <div class="card-header">
                    <div class="card-icon icon-blue"><i class="fa-solid fa-calendar-week"></i></div>
                    <h2>Weekly Evaluation</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="marks-table">
                            <thead>
                                <tr>
                                    <th>Week</th>
                                    <th class="text-right">Marks Obtained</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $weeks = [
                                    1 => $week1,
                                    2 => $week2,
                                    3 => $week3,
                                    4 => $week4
                                ];
                                foreach ($weeks as $w => $mark): 
                                    $pct = progressPct($mark, 10);
                                    $color = getProgressColor($pct);
                                ?>
                                <tr>
                                    <td style="width:50%;">
                                        <div class="week-label">Week <?php echo $w; ?></div>
                                        <div class="progress-indicator">
                                            <div class="progress-bar-fill" style="width: <?php echo $mark !== null ? $pct : 0; ?>%; background: <?php echo $color; ?>"></div>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <?php if ($mark === null): ?>
                                            <span class="pending-badge">Pending</span>
                                        <?php else: ?>
                                            <span class="score-value"><?php echo $mark; ?></span> <span class="score-max">/ 10</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="row-total">
                                    <td>Weekly Total</td>
                                    <td class="text-right">
                                        <?php if ($weekly_total === null): ?>
                                            <span class="pending-badge">Pending</span>
                                        <?php else: ?>
                                            <span class="score-value text-primary"><?php echo $weekly_total; ?></span> <span class="score-max">/ 40</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Detailed Breakdowns -->
            <div class="eval-section">
                <!-- Mentor Evaluation -->
                <div class="eval-card">
                    <div class="eval-card-header">
                        <h3 class="eval-card-title"><i class="fa-solid fa-chalkboard-user text-purple"></i> Mentor Evaluation</h3>
                        <div class="eval-card-score">
                            <?php if ($mentor_eval === null): ?>
                                <span class="pending-badge">Pending</span>
                            <?php else: ?>
                                <span class="score-value"><?php echo $mentor_eval; ?></span> <span class="score-max text-muted">/ 40</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($mentor_eval !== null): ?>
                        <div class="progress-indicator" style="height: 8px;">
                            <div class="progress-bar-fill" style="width: <?php echo progressPct($mentor_eval, 40); ?>%; background: <?php echo getProgressColor(progressPct($mentor_eval, 40)); ?>"></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($mentor_name)): ?>
                        <div class="eval-card-meta">
                            <i class="fa-solid fa-user-tie"></i> Evaluated by: <?php echo h($mentor_name); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Final Evaluation -->
                <div class="eval-card">
                    <div class="eval-card-header">
                        <h3 class="eval-card-title"><i class="fa-solid fa-file-signature text-orange"></i> Final Evaluation</h3>
                        <div class="eval-card-score">
                            <?php if ($final_eval === null): ?>
                                <span class="pending-badge">Pending</span>
                            <?php else: ?>
                                <span class="score-value"><?php echo $final_eval; ?></span> <span class="score-max text-muted">/ 20</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($final_eval !== null): ?>
                        <div class="progress-indicator" style="height: 8px;">
                            <div class="progress-bar-fill" style="width: <?php echo progressPct($final_eval, 20); ?>%; background: <?php echo getProgressColor(progressPct($final_eval, 20)); ?>"></div>
                        </div>
                    <?php endif; ?>
                    <div class="eval-card-meta">
                        <i class="fa-solid fa-building-columns"></i> University Internal/External Panel
                    </div>
                </div>
            </div>

        </div>

        <!-- Right Column -->
        <div class="d-flex flex-column gap-4">
            
            <!-- Final Score Hero -->
            <div class="card-panel">
                <div class="card-header">
                    <div class="card-icon icon-gold"><i class="fa-solid fa-award"></i></div>
                    <h2>Final Internship Score</h2>
                </div>
                <div class="card-body score-hero">
                    <?php 
                    $final_pct = progressPct($total_marks, 50);
                    $final_color = getProgressColor($final_pct);
                    if ($total_marks === null): ?>
                        <div class="circular-progress" style="--pct: 0; background: #f1f5f9; box-shadow: inset 0 0 0 16px var(--surface);">
                            <div class="inner">
                                <span class="val" style="font-size: 1.5rem; color: #94a3b8;"><i class="fa-solid fa-hourglass-half"></i></span>
                            </div>
                        </div>
                        <div class="score-hero-title">Evaluation Pending</div>
                        <div class="score-hero-desc">Your final score will appear here once all evaluations are complete.</div>
                    <?php else: ?>
                        <div class="circular-progress" style="--pct: <?php echo $final_pct; ?>; background: conic-gradient(<?php echo $final_color; ?> calc(var(--pct) * 1%), #f1f5f9 0);">
                            <div class="inner">
                                <span class="val"><?php echo $total_marks; ?></span>
                                <span class="max">OUT OF 50</span>
                            </div>
                        </div>
                        <div class="score-hero-title">
                            <?php 
                            if ($final_pct >= 80) echo "Excellent Performance!";
                            elseif ($final_pct >= 60) echo "Good Performance!";
                            elseif ($final_pct >= 40) echo "Satisfactory Performance.";
                            else echo "Needs Improvement.";
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Evaluation Summary -->
            <div class="card-panel">
                <div class="card-header">
                    <div class="card-icon icon-purple"><i class="fa-solid fa-calculator"></i></div>
                    <h2>Evaluation Summary</h2>
                </div>
                <div class="card-body">
                    <div class="summary-list">
                        <div class="summary-item">
                            <span class="summary-label">Weekly Total</span>
                            <span class="summary-val"><?php echo displayMark($weekly_total); ?> <span class="text-muted" style="font-weight:500; font-size:0.85rem;">/ 40</span></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Mentor Evaluation</span>
                            <span class="summary-val"><?php echo displayMark($mentor_eval); ?> <span class="text-muted" style="font-weight:500; font-size:0.85rem;">/ 40</span></span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">Final Evaluation</span>
                            <span class="summary-val"><?php echo displayMark($final_eval); ?> <span class="text-muted" style="font-weight:500; font-size:0.85rem;">/ 20</span></span>
                        </div>
                        <div class="summary-item total">
                            <span class="summary-label">Final Score</span>
                            <span class="summary-val"><?php echo displayMark($total_marks); ?> <span class="text-muted" style="font-weight:700; font-size:1rem; color:var(--text);">/ 50</span></span>
                        </div>
                    </div>
                    
                    <div class="summary-math">
                        <strong>Formula:</strong> (Weekly + Mentor + Final) &divide; 2<br>
                        <span style="opacity:0.8; font-size:0.75rem;">Calculated according to MIT-ADT University guidelines.</span>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>

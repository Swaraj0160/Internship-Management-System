<?php
/* -----------------------------------------------------------------
   InternHub — Evaluations & Continuous Marks Management Console
   Consolidates continuous assessment rubrics, SY and TY cohorts,
   real-time score calculation, and institutional grading constraints.
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Fetch faculty profile & assigned divisions
$faculty_res = mysqli_query($conn, "SELECT faculty_division, full_name FROM users WHERE id='$faculty_id' LIMIT 1");
$faculty_data = $faculty_res ? mysqli_fetch_assoc($faculty_res) : [];

$faculty_divisions = [];
$fd_res = mysqli_query(
    $conn,
    "SELECT DISTINCT division FROM faculty_divisions
     WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> ''
     ORDER BY division ASC"
);
if ($fd_res) {
    while ($row = mysqli_fetch_assoc($fd_res)) {
        $v = trim((string)$row['division']);
        if ($v !== '') $faculty_divisions[] = $v;
    }
}
if (empty($faculty_divisions)) {
    $legacy = trim((string)($faculty_data['faculty_division'] ?? ''));
    if ($legacy !== '') $faculty_divisions[] = $legacy;
}
$faculty_divisions = array_values(array_unique($faculty_divisions));

$division_sql_values = array_map(fn($d) => "'" . mysqli_real_escape_string($conn, $d) . "'", $faculty_divisions);
$division_list_sql = implode(',', $division_sql_values);
$faculty_division = !empty($faculty_divisions) ? implode(', ', $faculty_divisions) : 'Faculty Guide';

$cohort = $_GET['cohort'] ?? 'SY';
$selected_div = trim($_GET['division'] ?? ($faculty_divisions[0] ?? ''));
$search = trim($_GET['search'] ?? '');

$save_msg = '';

// Handle Marks Save POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marks'])) {
    if (isset($_POST['mentor']) && is_array($_POST['mentor'])) {
        $saved_count = 0;
        foreach ($_POST['mentor'] as $student_id => $mentor_raw) {
            $student_id = (int)$student_id;
            $mentor_raw = max(0, min(40, (int)$mentor_raw));

            $w1 = max(0, min(10, (int)($_POST['week1'][$student_id] ?? 0)));
            $w2 = max(0, min(10, (int)($_POST['week2'][$student_id] ?? 0)));
            $w3 = max(0, min(10, (int)($_POST['week3'][$student_id] ?? 0)));
            $w4 = max(0, min(10, (int)($_POST['week4'][$student_id] ?? 0)));
            $weekly_total = $w1 + $w2 + $w3 + $w4;

            // Final evaluation is read-only for faculty, preserved from database
            $eval_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT final_evaluation FROM student_evaluation WHERE student_id='$student_id'"));
            $final_raw = max(0, min(20, (int)($eval_row['final_evaluation'] ?? 0)));

            // Formula: (weekly_total (40) + mentor_evaluation (40) + final_evaluation (20)) / 2 = scaled to 50
            $total_scaled = round(($weekly_total + $mentor_raw + $final_raw) / 2);

            $chk = mysqli_query($conn, "SELECT id FROM student_evaluation WHERE student_id='$student_id'");
            if ($chk && mysqli_num_rows($chk) > 0) {
                mysqli_query($conn, "UPDATE student_evaluation SET
                    week1_marks='$w1',
                    week2_marks='$w2',
                    week3_marks='$w3',
                    week4_marks='$w4',
                    weekly_total='$weekly_total',
                    mentor_evaluation='$mentor_raw',
                    total_marks='$total_scaled',
                    updated_at=NOW()
                    WHERE student_id='$student_id'");
            } else {
                mysqli_query($conn, "INSERT INTO student_evaluation
                    (student_id, week1_marks, week2_marks, week3_marks, week4_marks, weekly_total, mentor_evaluation, final_evaluation, total_marks, created_at)
                    VALUES ('$student_id', '$w1', '$w2', '$w3', '$w4', '$weekly_total', '$mentor_raw', '$final_raw', '$total_scaled', NOW())");
            }
            $saved_count++;
        }
        $save_msg = "Successfully saved continuous evaluation marks for $saved_count students.";
    }
}

// Query Students & Existing Marks
$where_clauses = ["u.role='student'"];
if ($cohort === 'SY') {
    if (!empty($selected_div)) {
        $esc_div = mysqli_real_escape_string($conn, $selected_div);
        $where_clauses[] = "u.division = '$esc_div'";
    } elseif (!empty($division_list_sql)) {
        $where_clauses[] = "u.division IN ($division_list_sql)";
    }
} else {
    $where_clauses[] = "(u.year_of_study='TY' OR u.division LIKE 'TY%')";
}

if ($search !== '') {
    $s = mysqli_real_escape_string($conn, $search);
    $where_clauses[] = "(u.full_name LIKE '%$s%' OR u.roll_no LIKE '%$s%' OR u.email LIKE '%$s%')";
}

$where_sql = implode(' AND ', $where_clauses);

$eval_query = mysqli_query(
    $conn,
    "SELECT u.id, u.full_name, u.roll_no, u.division, u.department,
            se.week1_marks, se.week2_marks, se.week3_marks, se.week4_marks,
            se.weekly_total, se.mentor_evaluation, se.final_evaluation, se.total_marks
     FROM users u
     LEFT JOIN student_evaluation se ON se.student_id = u.id
     WHERE $where_sql
     ORDER BY CAST(TRIM(u.roll_no) AS UNSIGNED) ASC, u.full_name ASC"
);

$students = [];
if ($eval_query) {
    while ($r = mysqli_fetch_assoc($eval_query)) $students[] = $r;
}

$activePage = 'evaluations_marks.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluations & Continuous Marks | InternHub</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .eval-container {
            padding: 0 32px 36px;
        }

        .eval-header-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .eval-title-group h1 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e1b4b;
            letter-spacing: -0.02em;
        }

        .eval-title-group p {
            font-size: 0.88rem;
            color: #64748b;
            margin-top: 3px;
        }

        .cohort-nav-strip {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffffff;
            padding: 6px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
        }

        .cohort-tab {
            padding: 8px 18px;
            font-size: 0.84rem;
            font-weight: 700;
            color: #64748b;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.15s ease;
        }

        .cohort-tab.active {
            background: #7c3aed;
            color: #ffffff;
        }

        /* Filter Controls */
        .eval-filter-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 14px 18px;
            border: 1px solid #e2e8f0;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .eval-search-box {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 8px 14px;
            flex: 1;
            max-width: 320px;
        }

        .eval-search-box input {
            border: none;
            outline: none;
            background: transparent;
            font-size: 0.85rem;
            width: 100%;
            font-family: inherit;
        }

        /* Marks Table */
        .marks-table-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            overflow: hidden;
            margin-bottom: 24px;
        }

        .marks-table {
            width: 100%;
            border-collapse: collapse;
        }

        .marks-table th {
            background: #f8fafc;
            padding: 12px 14px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            text-align: center;
        }

        .marks-table th.th-left { text-align: left; }

        .marks-table td {
            padding: 10px 12px;
            font-size: 0.85rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            text-align: center;
        }

        .marks-table td.td-left { text-align: left; }

        .marks-table tr:hover { background: #fafafc; }

        .mark-input {
            width: 58px;
            padding: 6px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            text-align: center;
            font-size: 0.86rem;
            font-weight: 600;
            color: #1e1b4b;
            font-family: inherit;
            outline: none;
            transition: all 0.15s ease;
        }

        .mark-input:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }

        .mark-readonly {
            background: #f1f5f9;
            color: #64748b;
            border-color: #e2e8f0;
            cursor: not-allowed;
        }

        .total-badge {
            font-weight: 800;
            font-size: 0.90rem;
            color: #047857;
            background: #d1fae5;
            padding: 4px 10px;
            border-radius: 999px;
            display: inline-block;
        }

        .save-bar {
            position: sticky;
            bottom: 20px;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 14px 24px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 100;
        }

        .btn-save-all {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.25);
            transition: all 0.2s ease;
        }

        .btn-save-all:hover {
            background: #6d28d9;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="eval-container">
    <div class="eval-header-box">
        <div class="eval-title-group">
            <h1>Evaluations & Continuous Marks</h1>
            <p>Enter continuous assessment rubrics, review milestone totals, and finalize departmental grades.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="export_student_evaluations_pdf.php" target="_blank" style="padding: 8px 16px; border-radius: 10px; background: #ffffff; border: 1px solid #cbd5e1; font-size: 0.82rem; font-weight: 600; color: #334155; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-file-pdf" style="color: #ef4444;"></i> Export PDF
            </a>
            <a href="export_students.php" style="padding: 8px 16px; border-radius: 10px; background: #ffffff; border: 1px solid #cbd5e1; font-size: 0.82rem; font-weight: 600; color: #334155; text-decoration: none; display: flex; align-items: center; gap: 6px;">
                <i class="fa-solid fa-file-excel" style="color: #10b981;"></i> Export CSV
            </a>
        </div>
    </div>

    <!-- Cohort Selector -->
    <div class="cohort-nav-strip">
        <a href="?cohort=SY" class="cohort-tab <?php echo $cohort === 'SY' ? 'active' : ''; ?>">Second Year (SY) Cohort</a>
        <a href="?cohort=TY" class="cohort-tab <?php echo $cohort === 'TY' ? 'active' : ''; ?>">Third Year (TY) Cohort</a>
    </div>

    <?php if ($save_msg): ?>
        <div style="background: #d1fae5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 12px; padding: 12px 18px; font-size: 0.85rem; font-weight: 600; margin-bottom: 20px;">
            <i class="fa-solid fa-circle-check" style="margin-right: 6px;"></i> <?php echo $save_msg; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <form method="GET" action="" class="eval-filter-card">
        <input type="hidden" name="cohort" value="<?php echo h($cohort); ?>">
        <div style="display: flex; align-items: center; gap: 12px; flex: 1;">
            <div class="eval-search-box">
                <i class="fa-solid fa-magnifying-glass" style="color: #94a3b8;"></i>
                <input type="text" name="search" placeholder="Search student name or PRN..." value="<?php echo h($search); ?>">
            </div>

            <?php if (!empty($faculty_divisions)): ?>
                <select name="division" onchange="this.form.submit()" style="padding: 9px 14px; border-radius: 10px; border: 1px solid #cbd5e1; font-size: 0.85rem; font-family: inherit;">
                    <?php foreach ($faculty_divisions as $fd): ?>
                        <option value="<?php echo h($fd); ?>" <?php echo $selected_div === $fd ? 'selected' : ''; ?>>Division <?php echo h($fd); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="submit" style="padding: 9px 18px; border-radius: 10px; background: #7c3aed; color: #fff; border: none; font-size: 0.82rem; font-weight: 600; cursor: pointer;">Filter</button>
        </div>

        <div style="font-size: 0.80rem; color: #64748b; font-weight: 600;">
            Rubric: W1–W4 (10 ea, 40 max) + Mentor (40 max) + Final (20 max) &divide; 2 = Total / 50
        </div>
    </form>

    <!-- Marks Entry Form -->
    <form method="POST" action="">
        <div class="marks-table-card">
            <div style="overflow-x: auto;">
                <table class="marks-table">
                    <thead>
                        <tr>
                            <th class="th-left">Student</th>
                            <th>PRN / Roll</th>
                            <th>Week 1<br><small>(10)</small></th>
                            <th>Week 2<br><small>(10)</small></th>
                            <th>Week 3<br><small>(10)</small></th>
                            <th>Week 4<br><small>(10)</small></th>
                            <th>Weekly Total<br><small>(40)</small></th>
                            <th>Mentor Eval<br><small>(40)</small></th>
                            <th>Final Eval<br><small>(20 read-only)</small></th>
                            <th>Total Scaled<br><small>(50)</small></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $stu): ?>
                                <?php
                                $sid = (int)$stu['id'];
                                $w1 = (int)($stu['week1_marks'] ?? 0);
                                $w2 = (int)($stu['week2_marks'] ?? 0);
                                $w3 = (int)($stu['week3_marks'] ?? 0);
                                $w4 = (int)($stu['week4_marks'] ?? 0);
                                $w_tot = (int)($stu['weekly_total'] ?? ($w1 + $w2 + $w3 + $w4));
                                $mentor = (int)($stu['mentor_evaluation'] ?? 0);
                                $final = (int)($stu['final_evaluation'] ?? 0);
                                $total_scaled = (int)($stu['total_marks'] ?? round(($w_tot + $mentor + $final) / 2));
                                ?>
                                <tr id="row-<?php echo $sid; ?>">
                                    <td class="td-left">
                                        <div style="font-weight: 700; color: #1e1b4b;"><?php echo h($stu['full_name']); ?></div>
                                        <div style="font-size: 0.72rem; color: #64748b;"><?php echo h($stu['division']); ?></div>
                                    </td>
                                    <td><?php echo h($stu['roll_no'] ?: 'N/A'); ?></td>
                                    <td>
                                        <input type="number" min="0" max="10" name="week1[<?php echo $sid; ?>]" id="w1-<?php echo $sid; ?>" class="mark-input" value="<?php echo $w1; ?>" onchange="recalcRow(<?php echo $sid; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" min="0" max="10" name="week2[<?php echo $sid; ?>]" id="w2-<?php echo $sid; ?>" class="mark-input" value="<?php echo $w2; ?>" onchange="recalcRow(<?php echo $sid; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" min="0" max="10" name="week3[<?php echo $sid; ?>]" id="w3-<?php echo $sid; ?>" class="mark-input" value="<?php echo $w3; ?>" onchange="recalcRow(<?php echo $sid; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" min="0" max="10" name="week4[<?php echo $sid; ?>]" id="w4-<?php echo $sid; ?>" class="mark-input" value="<?php echo $w4; ?>" onchange="recalcRow(<?php echo $sid; ?>)">
                                    </td>
                                    <td>
                                        <span id="wtot-<?php echo $sid; ?>" style="font-weight: 700; color: #475569;"><?php echo $w_tot; ?></span>
                                    </td>
                                    <td>
                                        <input type="number" min="0" max="40" name="mentor[<?php echo $sid; ?>]" id="mentor-<?php echo $sid; ?>" class="mark-input" style="width: 64px;" value="<?php echo $mentor; ?>" onchange="recalcRow(<?php echo $sid; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" class="mark-input mark-readonly" id="final-<?php echo $sid; ?>" value="<?php echo $final; ?>" readonly title="Final evaluation marks are set by departmental evaluation committee.">
                                    </td>
                                    <td>
                                        <span class="total-badge" id="total-<?php echo $sid; ?>"><?php echo $total_scaled; ?> / 50</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" style="text-align: center; color: #94a3b8; padding: 32px;">No student records found for this cohort division.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($students)): ?>
            <div class="save-bar">
                <div style="font-size: 0.85rem; color: #475569;">
                    <i class="fa-solid fa-info-circle" style="color: #7c3aed; margin-right: 6px;"></i>
                    Make sure to click <strong>Save All Marks</strong> before switching divisions or cohorts.
                </div>
                <button type="submit" name="save_marks" class="btn-save-all">
                    <i class="fa-solid fa-floppy-disk" style="margin-right: 6px;"></i> Save All Marks
                </button>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
function recalcRow(sid) {
    const w1 = Math.max(0, Math.min(10, parseInt(document.getElementById('w1-' + sid).value) || 0));
    const w2 = Math.max(0, Math.min(10, parseInt(document.getElementById('w2-' + sid).value) || 0));
    const w3 = Math.max(0, Math.min(10, parseInt(document.getElementById('w3-' + sid).value) || 0));
    const w4 = Math.max(0, Math.min(10, parseInt(document.getElementById('w4-' + sid).value) || 0));
    const mentor = Math.max(0, Math.min(40, parseInt(document.getElementById('mentor-' + sid).value) || 0));
    const final = parseInt(document.getElementById('final-' + sid).value) || 0;

    const wTot = w1 + w2 + w3 + w4;
    document.getElementById('wtot-' + sid).innerText = wTot;

    const totalScaled = Math.round((wTot + mentor + final) / 2);
    document.getElementById('total-' + sid).innerText = totalScaled + ' / 50';
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>

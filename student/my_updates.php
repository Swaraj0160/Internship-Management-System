<?php

session_start();
include("../config.php");

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_id = (int)$_SESSION['user_id'];
$activePage = 'my_updates.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* FETCH STUDENT DIVISION */
$getStudent = mysqli_query(
    $conn,
    "SELECT division
     FROM users
     WHERE id='$student_id'"
);

$studentData = mysqli_fetch_assoc($getStudent);
$division = $studentData['division'] ?? '';

$internshipQuery = mysqli_query($conn, "
    SELECT status, offer_letter
    FROM internships
    WHERE student_id='$student_id'
    LIMIT 1
");

$internship = mysqli_fetch_assoc($internshipQuery);

$canSubmitWeekly = false;
if (
    $internship &&
    $internship['status'] === 'Approved' &&
    !empty($internship['offer_letter'])
) {
    $canSubmitWeekly = true;
}

/* FETCH STUDENT WEEKLY UPDATES */
$query = mysqli_query(
    $conn,
    "SELECT
        weekly_updates.*,
        internships.company_name,
        internships.internship_role,
        weekly_deadlines.deadline_date
     FROM weekly_updates
     INNER JOIN internships ON weekly_updates.internship_id = internships.id
     LEFT JOIN weekly_deadlines ON weekly_deadlines.week_no = weekly_updates.week_no AND weekly_deadlines.division = '$division'
     WHERE internships.student_id='$student_id'
     ORDER BY weekly_updates.week_no ASC"
);

$updates = [];
$stats = ['total' => 0, 'on_time' => 0, 'accepted' => 0, 'pending' => 0];

if ($query && mysqli_num_rows($query) > 0) {
    while ($row = mysqli_fetch_assoc($query)) {
        $updates[] = $row;
        $stats['total']++;
        if ($row['review_status'] === 'Accepted') {
            $stats['accepted']++;
        } else {
            $stats['pending']++;
        }

        if (!empty($row['deadline_date'])) {
            if (strtotime($row['submitted_at']) <= strtotime($row['deadline_date'])) {
                $stats['on_time']++;
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
    <title>My Weekly Work | MIT-ADT University</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        :root {
            --bg-main: #f8fafc;
            --surface: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --brand-purple: #4f46e5;
            --brand-purple-hover: #4338ca;
            --radius-card: 16px;
        }

        body {
            background-color: var(--bg-main);
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            margin: 0;
        }

        .page-inner {
            padding: 0 24px 40px;
            max-width: 1280px;
            margin: 0 auto;
        }

        /* Top Hero Header Box */
        .page-header-box {
            background: linear-gradient(135deg, #1e1b4b 0%, #312e81 40%, #4338ca 100%);
            border-radius: 20px;
            padding: 32px;
            color: white;
            margin: 24px 0 28px 0;
            box-shadow: 0 10px 25px -5px rgba(67, 56, 202, 0.25);
            position: relative;
            overflow: hidden;
        }

        .page-header-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-title {
            font-size: 1.85rem;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.85);
            max-width: 680px;
            margin: 0;
            line-height: 1.5;
        }

        /* Stat Summary Cards */
        .stat-card {
            background: var(--surface);
            border-radius: 14px;
            padding: 20px 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.06);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-val {
            font-size: 1.6rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 2px;
        }

        .stat-lbl {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Filter Toolbar */
        .filter-toolbar {
            background: var(--surface);
            border-radius: 14px;
            padding: 16px 20px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        /* Content Section Card */
        .content-card {
            background: var(--surface);
            border-radius: 18px;
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);
        }

        /* Table Design */
        .table-responsive {
            border-radius: 14px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table {
            margin-bottom: 0;
            font-size: 0.92rem;
        }

        .table thead {
            background: #f8fafc;
        }

        .table thead th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
            color: var(--text-muted);
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            color: #334155;
        }

        .badge-pill {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        /* Mobile Card Design (shown under 992px) */
        .mobile-update-card {
            display: none;
            background: var(--surface);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .work-preview-text {
            font-size: 0.92rem;
            line-height: 1.55;
            color: #334155;
        }

        .read-more-link {
            color: var(--brand-purple);
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            cursor: pointer;
        }
        .read-more-link:hover {
            text-decoration: underline;
        }

        /* Modals Z-Index Fix */
        .modal {
            z-index: 1060 !important;
        }
        .modal-backdrop {
            z-index: 1050 !important;
        }
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        .modal-header {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 20px 24px;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            background: #f8fafc;
            border-top: 1px solid var(--border-color);
            padding: 16px 24px;
            border-bottom-left-radius: 20px;
            border-bottom-right-radius: 20px;
        }

        @media (max-width: 992px) {
            .desktop-table-container {
                display: none;
            }
            .mobile-update-card {
                display: block;
            }
            .page-header-box {
                padding: 24px;
            }
            .page-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">

    <!-- Top Hero Header Box -->
    <div class="page-header-box d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h1 class="page-title"><i class="fa-solid fa-clipboard-list me-2"></i> My Weekly Work</h1>
            <p class="page-subtitle">Review your submitted weekly progress reports, track faculty review feedback, download report files, and manage your progress logs.</p>
        </div>
        <div>
            <?php if ($canSubmitWeekly): ?>
                <a href="add_update.php" class="btn btn-light fw-bold px-4 py-2 shadow-sm text-primary rounded-3">
                    <i class="fa-solid fa-plus me-2"></i> Add Weekly Update
                </a>
            <?php else: ?>
                <button class="btn btn-light text-muted fw-bold px-4 py-2 shadow-sm rounded-3" disabled title="Approved internship & offer letter required">
                    <i class="fa-solid fa-lock me-2"></i> Add Weekly Update
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Stats Bar -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['total']; ?></div>
                    <div class="stat-lbl">Total Submitted</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-clock-check"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['on_time']; ?></div>
                    <div class="stat-lbl">On-Time Reports</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['accepted']; ?></div>
                    <div class="stat-lbl">Accepted by Faculty</div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div>
                    <div class="stat-val"><?php echo $stats['pending']; ?></div>
                    <div class="stat-lbl">Pending Review</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="filter-toolbar">
        <div class="row g-3 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="searchInput" class="form-control border-start-0 ps-0" placeholder="Search company, role, work summary, feedback..." onkeyup="filterWork()">
                </div>
            </div>
            <div class="col-md-3 col-6">
                <select id="reviewFilter" class="form-select" onchange="filterWork()">
                    <option value="">All Review Statuses</option>
                    <option value="Accepted">Accepted / Approved</option>
                    <option value="Pending">Pending Review</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <select id="timelinessFilter" class="form-select" onchange="filterWork()">
                    <option value="">All Timeliness</option>
                    <option value="Submitted On Time">Submitted On Time</option>
                    <option value="Late Submission">Late Submission</option>
                </select>
            </div>
            <div class="col-md-2 text-end">
                <button type="button" class="btn btn-outline-secondary w-100 fw-semibold" onclick="resetFilters()">
                    <i class="fa-solid fa-rotate-left me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>

    <!-- Weekly Submissions Container -->
    <div id="workContainer">
        <?php if (empty($updates)): ?>
            <!-- Empty State Box -->
            <div class="content-card text-center py-5">
                <div class="empty-icon mx-auto mb-3 text-primary bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width:70px;height:70px;font-size:2rem;">
                    <i class="fa-solid fa-clipboard-question"></i>
                </div>
                <h3 class="fw-bold mb-2">No Weekly Updates Submitted Yet</h3>
                <p class="text-muted max-w-md mx-auto mb-4" style="max-width:480px;">Submit your weekly progress logs to report tasks, upload report PDFs, and receive faculty mentor feedback.</p>
                <?php if ($canSubmitWeekly): ?>
                    <a href="add_update.php" class="btn btn-primary fw-bold px-4 py-2 rounded-3">
                        <i class="fa-solid fa-plus me-2"></i> Submit Your First Update
                    </a>
                <?php else: ?>
                    <div class="alert alert-warning d-inline-block px-4 py-2 mb-0">
                        <i class="fa-solid fa-lock me-2"></i> Weekly updates can be submitted after your internship is approved and the offer letter is uploaded.
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <!-- A. DESKTOP & TABLET TABLE VIEW -->
            <div class="content-card desktop-table-container p-0">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th style="width:80px;">Week</th>
                                <th style="width:160px;">Company & Role</th>
                                <th style="min-width:220px;">Work Done Summary</th>
                                <th style="width:140px;">Submitted At</th>
                                <th style="width:130px;">Timeliness</th>
                                <th style="width:130px;">Faculty Review</th>
                                <th style="width:100px;">Report File</th>
                                <th style="width:160px;">Faculty Feedback</th>
                                <th style="width:110px;text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($updates as $row): 
                                $status = "Late Submission";
                                $status_class = "bg-danger-subtle text-danger border border-danger-subtle";
                                $status_icon = "fa-triangle-exclamation";

                                if (!empty($row['deadline_date'])) {
                                    if (strtotime($row['submitted_at']) <= strtotime($row['deadline_date'])) {
                                        $status = "Submitted On Time";
                                        $status_class = "bg-success-subtle text-success border border-success-subtle";
                                        $status_icon = "fa-circle-check";
                                    }
                                } else {
                                    $status = "No Deadline";
                                    $status_class = "bg-secondary-subtle text-secondary border border-secondary-subtle";
                                    $status_icon = "fa-clock";
                                }

                                $review_status = $row['review_status'] ?? 'Pending';
                                $review_class = "bg-warning-subtle text-warning-emphasis border border-warning-subtle";
                                $review_icon = "fa-hourglass-half";
                                if ($review_status === 'Accepted') {
                                    $review_class = "bg-success-subtle text-success border border-success-subtle";
                                    $review_icon = "fa-circle-check";
                                } elseif ($review_status === 'Rejected') {
                                    $review_class = "bg-danger-subtle text-danger border border-danger-subtle";
                                    $review_icon = "fa-circle-xmark";
                                }

                                $full_text = trim($row['work_done'] ?? '');
                                $short_text = (strlen($full_text) > 90) ? substr($full_text, 0, 90) . "..." : $full_text;
                            ?>
                                <tr class="work-row"
                                    data-search="<?php echo h(strtolower($row['company_name'] . ' ' . $row['internship_role'] . ' ' . $row['work_done'] . ' ' . $row['faculty_feedback'])); ?>"
                                    data-review="<?php echo h($review_status); ?>"
                                    data-timeliness="<?php echo h($status); ?>">
                                    
                                    <td class="fw-bold text-dark">
                                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-3 fs-6">
                                            Week <?php echo (int)$row['week_no']; ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="fw-bold text-dark"><?php echo h($row['company_name']); ?></div>
                                        <div class="text-muted small"><i class="fa-solid fa-user-gear me-1"></i> <?php echo h($row['internship_role']); ?></div>
                                    </td>

                                    <td>
                                        <div id="short_desk_<?php echo $row['id']; ?>" class="work-preview-text">
                                            <?php echo nl2br(h($short_text)); ?>
                                            <?php if (strlen($full_text) > 90): ?>
                                                <br><a href="javascript:void(0)" class="read-more-link" onclick="toggleWork('desk_', <?php echo $row['id']; ?>)">Read More <i class="fa-solid fa-chevron-down small"></i></a>
                                            <?php endif; ?>
                                        </div>
                                        <div id="full_desk_<?php echo $row['id']; ?>" class="work-preview-text" style="display:none;">
                                            <?php echo nl2br(h($full_text)); ?>
                                            <br><a href="javascript:void(0)" class="read-more-link" onclick="toggleWork('desk_', <?php echo $row['id']; ?>)">Show Less <i class="fa-solid fa-chevron-up small"></i></a>
                                        </div>
                                    </td>

                                    <td class="text-muted small">
                                        <i class="fa-regular fa-clock me-1"></i> <?php echo date("d M Y, h:i A", strtotime($row['submitted_at'])); ?>
                                    </td>

                                    <td>
                                        <span class="badge-pill <?php echo $status_class; ?>">
                                            <i class="fa-solid <?php echo $status_icon; ?>"></i> <?php echo $status; ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge-pill <?php echo $review_class; ?>">
                                            <i class="fa-solid <?php echo $review_icon; ?>"></i> <?php echo h($review_status==='Accepted'?'Accepted':($review_status==='Rejected'?'Rejected':'Pending Review')); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php if (!empty($row['update_file'])): ?>
                                            <a href="../uploads/weekly_reports/<?php echo rawurlencode($row['update_file']); ?>" target="_blank" class="btn btn-sm btn-outline-danger fw-bold rounded-2">
                                                <i class="fa-solid fa-file-pdf"></i> View PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($row['faculty_feedback'])): ?>
                                            <div class="text-dark small"><i class="fa-solid fa-comment-dots text-primary me-1"></i> <?php echo nl2br(h($row['faculty_feedback'])); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small"><i class="fa-regular fa-comment me-1"></i> No feedback yet</span>
                                        <?php endif; ?>
                                    </td>

                                    <td style="text-align:right;">
                                        <div class="d-flex justify-content-end gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick='openWorkDetailsModal(<?php echo json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, "<?php echo h($status); ?>")' title="View Full Details">
                                                <i class="fa-solid fa-eye"></i>
                                            </button>
                                            <?php if ($review_status !== 'Accepted'): ?>
                                                <a href="edit_update.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-warning fw-semibold" title="Edit Weekly Report">
                                                    <i class="fa-solid fa-pen-to-square"></i> Edit
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small" title="Report Approved & Locked">
                                                    <i class="fa-solid fa-lock"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- B. MOBILE CARD VIEW (Shown under 992px) -->
            <div class="mobile-card-container">
                <?php foreach ($updates as $row): 
                    $status = "Late Submission";
                    $status_class = "bg-danger-subtle text-danger border border-danger-subtle";
                    $status_icon = "fa-triangle-exclamation";

                    if (!empty($row['deadline_date'])) {
                        if (strtotime($row['submitted_at']) <= strtotime($row['deadline_date'])) {
                            $status = "Submitted On Time";
                            $status_class = "bg-success-subtle text-success border border-success-subtle";
                            $status_icon = "fa-circle-check";
                        }
                    } else {
                        $status = "No Deadline";
                        $status_class = "bg-secondary-subtle text-secondary border border-secondary-subtle";
                        $status_icon = "fa-clock";
                    }

                    $review_status = $row['review_status'] ?? 'Pending';
                    $review_class = "bg-warning-subtle text-warning-emphasis border border-warning-subtle";
                    $review_icon = "fa-hourglass-half";
                    if ($review_status === 'Accepted') {
                        $review_class = "bg-success-subtle text-success border border-success-subtle";
                        $review_icon = "fa-circle-check";
                    } elseif ($review_status === 'Rejected') {
                        $review_class = "bg-danger-subtle text-danger border border-danger-subtle";
                        $review_icon = "fa-circle-xmark";
                    }

                    $full_text = trim($row['work_done'] ?? '');
                    $short_text = (strlen($full_text) > 90) ? substr($full_text, 0, 90) . "..." : $full_text;
                ?>
                    <div class="mobile-update-card work-card-mobile"
                         data-search="<?php echo h(strtolower($row['company_name'] . ' ' . $row['internship_role'] . ' ' . $row['work_done'] . ' ' . $row['faculty_feedback'])); ?>"
                         data-review="<?php echo h($review_status); ?>"
                         data-timeliness="<?php echo h($status); ?>">
                        
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1 rounded-3 fw-bold">
                                Week <?php echo (int)$row['week_no']; ?>
                            </span>
                            <span class="badge-pill <?php echo $review_class; ?>">
                                <i class="fa-solid <?php echo $review_icon; ?>"></i> <?php echo h($review_status==='Accepted'?'Accepted':($review_status==='Rejected'?'Rejected':'Pending Review')); ?>
                            </span>
                        </div>

                        <h5 class="fw-bold text-dark mb-1"><?php echo h($row['company_name']); ?></h5>
                        <div class="text-muted small mb-3"><i class="fa-solid fa-user-gear me-1"></i> <?php echo h($row['internship_role']); ?></div>

                        <div class="mb-3">
                            <div class="text-uppercase text-muted fw-bold small mb-1" style="font-size:0.7rem;">Work Done Summary</div>
                            <div id="short_mob_<?php echo $row['id']; ?>" class="work-preview-text">
                                <?php echo nl2br(h($short_text)); ?>
                                <?php if (strlen($full_text) > 90): ?>
                                    <br><a href="javascript:void(0)" class="read-more-link" onclick="toggleWork('mob_', <?php echo $row['id']; ?>)">Read More <i class="fa-solid fa-chevron-down small"></i></a>
                                <?php endif; ?>
                            </div>
                            <div id="full_mob_<?php echo $row['id']; ?>" class="work-preview-text" style="display:none;">
                                <?php echo nl2br(h($full_text)); ?>
                                <br><a href="javascript:void(0)" class="read-more-link" onclick="toggleWork('mob_', <?php echo $row['id']; ?>)">Show Less <i class="fa-solid fa-chevron-up small"></i></a>
                            </div>
                        </div>

                        <?php if (!empty($row['faculty_feedback'])): ?>
                            <div class="alert alert-info py-2 px-3 rounded-3 small mb-3">
                                <i class="fa-solid fa-comment-dots me-1"></i> <strong>Faculty Feedback:</strong> <?php echo nl2br(h($row['faculty_feedback'])); ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="badge-pill <?php echo $status_class; ?>">
                                <i class="fa-solid <?php echo $status_icon; ?>"></i> <?php echo $status; ?>
                            </span>
                            
                            <div class="d-flex gap-2">
                                <?php if (!empty($row['update_file'])): ?>
                                    <a href="../uploads/weekly_reports/<?php echo rawurlencode($row['update_file']); ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                                        <i class="fa-solid fa-file-pdf"></i> PDF
                                    </a>
                                <?php endif; ?>
                                <?php if ($review_status !== 'Accepted'): ?>
                                    <a href="edit_update.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-sm btn-warning fw-semibold">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php endif; ?>
    </div>

</div>

</main>
</div>

<!-- ========================================== -->
<!-- MODAL: WORK DETAILS MODAL -->
<!-- ========================================== -->
<div class="modal fade" id="workDetailsModal" tabindex="-1" aria-labelledby="workDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="workDetailsModalLabel"><i class="fa-solid fa-clipboard-check text-primary me-2"></i> Weekly Work Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Company Name</label>
                        <div class="fw-bold fs-6 text-dark" id="modal_company"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Internship Role</label>
                        <div class="fw-bold fs-6 text-dark" id="modal_role"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Week Number</label>
                        <div><span class="badge bg-primary px-3 py-2 fs-6" id="modal_week"></span></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Submitted At</label>
                        <div class="fw-semibold text-dark" id="modal_submitted_at"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Submission Timeliness</label>
                        <div id="modal_timeliness"></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small text-uppercase fw-bold">Faculty Review Status</label>
                        <div id="modal_review_status"></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted small text-uppercase fw-bold">Work Done Summary</label>
                        <div class="p-3 bg-light rounded-3 text-dark fw-semibold" style="white-space:pre-line;" id="modal_work_done"></div>
                    </div>
                    <div class="col-12" id="modal_feedback_box" style="display:none;">
                        <label class="form-label text-primary small text-uppercase fw-bold">Faculty Feedback</label>
                        <div class="alert alert-info mb-0 fw-semibold" id="modal_feedback"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="modal_footer">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
function toggleWork(prefix, id) {
    const shortDiv = document.getElementById("short_" + prefix + id);
    const fullDiv = document.getElementById("full_" + prefix + id);

    if (fullDiv.style.display === "none") {
        fullDiv.style.display = "block";
        shortDiv.style.display = "none";
    } else {
        fullDiv.style.display = "none";
        shortDiv.style.display = "block";
    }
}

function getOrCreateModal(modalId) {
    const el = document.getElementById(modalId);
    if (!el) return null;
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) {
        modal = new bootstrap.Modal(el, { backdrop: true, keyboard: true });
    }
    return modal;
}

function openWorkDetailsModal(row, timeliness) {
    document.getElementById('modal_company').innerText = row.company_name || '—';
    document.getElementById('modal_role').innerText = row.internship_role || '—';
    document.getElementById('modal_week').innerText = 'Week ' + (row.week_no || '1');
    document.getElementById('modal_submitted_at').innerText = row.submitted_at ? new Date(row.submitted_at).toLocaleString() : '—';
    document.getElementById('modal_work_done').innerText = row.work_done || '—';

    document.getElementById('modal_timeliness').innerHTML = `<span class="badge bg-${timeliness.includes('On Time')?'success':'danger'} px-3 py-2 fs-6">${timeliness}</span>`;
    
    const rev = row.review_status || 'Pending';
    document.getElementById('modal_review_status').innerHTML = `<span class="badge bg-${rev==='Accepted'?'success':(rev==='Rejected'?'danger':'warning')} px-3 py-2 fs-6">${rev}</span>`;

    const feedBox = document.getElementById('modal_feedback_box');
    if (row.faculty_feedback && row.faculty_feedback.trim() !== '') {
        document.getElementById('modal_feedback').innerText = row.faculty_feedback;
        feedBox.style.display = 'block';
    } else {
        feedBox.style.display = 'none';
    }

    const footer = document.getElementById('modal_footer');
    let fileBtn = '';
    if (row.update_file && row.update_file.trim() !== '') {
        fileBtn = `<a href="../uploads/weekly_reports/${encodeURIComponent(row.update_file)}" target="_blank" class="btn btn-danger px-4"><i class="fa-solid fa-file-pdf me-2"></i> View PDF Report</a>`;
    }
    footer.innerHTML = `${fileBtn}<button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>`;

    const modal = getOrCreateModal('workDetailsModal');
    if (modal) modal.show();
}

function filterWork() {
    const searchVal = document.getElementById('searchInput').value.toLowerCase().trim();
    const reviewVal = document.getElementById('reviewFilter').value;
    const timelinessVal = document.getElementById('timelinessFilter').value;

    const rows = document.querySelectorAll('.work-row');
    const cards = document.querySelectorAll('.work-card-mobile');

    const filterItem = (el) => {
        const searchBlob = el.getAttribute('data-search') || '';
        const review = el.getAttribute('data-review') || '';
        const timeliness = el.getAttribute('data-timeliness') || '';

        let matchesSearch = searchVal === '' || searchBlob.includes(searchVal);
        let matchesReview = reviewVal === '' || review === reviewVal;
        let matchesTimeliness = timelinessVal === '' || timeliness.includes(timelinessVal);

        if (matchesSearch && matchesReview && matchesTimeliness) {
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    };

    rows.forEach(filterItem);
    cards.forEach(filterItem);
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('reviewFilter').value = '';
    document.getElementById('timelinessFilter').value = '';
    filterWork();
}

document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('workDetailsModal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (document.querySelectorAll('.modal.show').length === 0) {
                document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>
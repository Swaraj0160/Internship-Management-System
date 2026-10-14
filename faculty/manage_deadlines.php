<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — sidebar propagation (Phase 2) + real fixes made while
   wiring this page into the shared sidebar:

   1. Dual-division gap: this page only ever read
      users.faculty_division (a single legacy value), so a faculty
      member assigned to BOTH IT-DA and IT-SMAD could only manage
      deadlines for one of their two divisions. Rewired to the
      standard faculty_divisions-table-first/legacy-fallback pattern
      used everywhere else, with a division selector added to the
      Save form when a faculty member has more than one division.
   2. SQL injection: division / week_no / deadline_date / delete id
      were concatenated directly into queries. Converted every query
      here to a prepared statement.
   3. XSS: division and week_no were echoed with no escaping.
   ----------------------------------------------------------------- */

$faculty_id = (int)$_SESSION['user_id'];

/* FACULTY DIVISIONS */
$faculty_divisions = [];

$division_stmt = $conn->prepare("
    SELECT DISTINCT division
    FROM faculty_divisions
    WHERE faculty_id=?
      AND division IS NOT NULL
      AND TRIM(division) <> ''
    ORDER BY division ASC
");
$division_stmt->bind_param("i", $faculty_id);
$division_stmt->execute();
$division_res = $division_stmt->get_result();

while($row = mysqli_fetch_assoc($division_res))
{
    $v = trim((string)$row['division']);
    if($v !== '' && !in_array($v, $faculty_divisions, true))
    {
        $faculty_divisions[] = $v;
    }
}

if(empty($faculty_divisions))
{
    $legacy_stmt = $conn->prepare("SELECT faculty_division FROM users WHERE id=?");
    $legacy_stmt->bind_param("i", $faculty_id);
    $legacy_stmt->execute();
    $legacy_row = $legacy_stmt->get_result()->fetch_assoc();
    $legacy = trim((string)($legacy_row['faculty_division'] ?? ''));
    if($legacy !== '')
    {
        $faculty_divisions[] = $legacy;
    }
}

$division_display = implode(', ', $faculty_divisions);

/* The division a save/delete action applies to — validated against
   the faculty member's own assigned divisions only. */
$selected_division = trim($_POST['division'] ?? $_GET['division'] ?? '');
if(!in_array($selected_division, $faculty_divisions, true))
{
    $selected_division = $faculty_divisions[0] ?? '';
}

$activePage = 'manage_deadlines.php';

/* DELETE DEADLINE */

if(isset($_GET['delete']))
{
    $id = (int)$_GET['delete'];

    if($selected_division !== '')
    {
        $del_stmt = $conn->prepare("DELETE FROM weekly_deadlines WHERE id=? AND division=?");
        $del_stmt->bind_param("is", $id, $selected_division);
        $del_stmt->execute();
    }

    echo "
    <script>
        alert('Deadline Deleted Successfully');
        window.location='manage_deadlines.php';
    </script>
    ";
    exit();
}

/* SAVE DEADLINE */

if(isset($_POST['save_deadline']))
{
    $week_no = (int)($_POST['week_no'] ?? 0);
    $deadline = trim($_POST['deadline_date'] ?? '');

    if($selected_division !== '' && $week_no > 0 && $deadline !== '')
    {
        $check_stmt = $conn->prepare("SELECT id FROM weekly_deadlines WHERE division=? AND week_no=?");
        $check_stmt->bind_param("si", $selected_division, $week_no);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if($check_result->num_rows > 0)
        {
            $upd_stmt = $conn->prepare("UPDATE weekly_deadlines SET deadline_date=? WHERE division=? AND week_no=?");
            $upd_stmt->bind_param("ssi", $deadline, $selected_division, $week_no);
            $upd_stmt->execute();
        }
        else
        {
            $ins_stmt = $conn->prepare("INSERT INTO weekly_deadlines (division, week_no, deadline_date) VALUES (?, ?, ?)");
            $ins_stmt->bind_param("sis", $selected_division, $week_no, $deadline);
            $ins_stmt->execute();
        }

        echo "<script>alert('Deadline Saved Successfully');</script>";
    }
    else
    {
        echo "<script>alert('Please select a division, week and deadline.');</script>";
    }
}

/* FETCH DEADLINES — all assigned divisions, IT-DA first / IT-SMAD second */

$deadlines = false;

if(!empty($faculty_divisions))
{
    $ph = implode(',', array_fill(0, count($faculty_divisions), '?'));
    $types = str_repeat('s', count($faculty_divisions));

    $fetch_stmt = $conn->prepare("
        SELECT * FROM weekly_deadlines
        WHERE division IN ($ph)
        ORDER BY
            CASE division
                WHEN 'IT-DA'   THEN 0
                WHEN 'IT-SMAD' THEN 1
                ELSE 2
            END ASC,
            division ASC,
            week_no ASC
    ");
    $fetch_stmt->bind_param($types, ...$faculty_divisions);
    $fetch_stmt->execute();
    $deadlines = $fetch_stmt->get_result();
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Manage Deadlines</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f1f5f9;
    margin:0;
}

.page-inner{
    padding:0 24px 40px;
}

.container-box{
    width:100%;
    margin:auto;
    margin-top:24px;
    background:white;
    padding:30px;
    border-radius:15px;
    box-shadow:0px 2px 10px rgba(0,0,0,0.1);
}

</style>

</head>

<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="container-box">

    <h2 class="mb-4">
        Weekly Report Deadlines
    </h2>

    <h5 class="mb-4">
        Division: <?php echo htmlspecialchars($division_display); ?>
    </h5>

    <form method="POST">

        <div class="row">

            <?php if(count($faculty_divisions) > 1): ?>
            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Division
                </label>

                <select name="division" class="form-control" required>

                    <?php foreach($faculty_divisions as $div_opt): ?>
                    <option value="<?php echo htmlspecialchars($div_opt); ?>"
                        <?php echo ($div_opt === $selected_division) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($div_opt); ?>
                    </option>
                    <?php endforeach; ?>

                </select>

            </div>
            <?php else: ?>
            <input type="hidden" name="division" value="<?php echo htmlspecialchars($selected_division); ?>">
            <?php endif; ?>

            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Week Number
                </label>

                <select name="week_no"
                        class="form-control"
                        required>

                    <option value="">
                        Select Week
                    </option>

                    <?php
                    for($i=1; $i<=4; $i++)
                    {
                    ?>

                    <option value="<?php echo $i; ?>">
                        Week <?php echo $i; ?>
                    </option>

                    <?php
                    }
                    ?>

                </select>

            </div>

            <div class="col-md-4 mb-3">

                <label class="form-label">
                    Deadline Date & Time
                </label>

                <input type="datetime-local"
                       name="deadline_date"
                       class="form-control"
                       required>

            </div>

            <div class="col-md-4 mb-3 d-flex align-items-end">

                <button type="submit"
                        name="save_deadline"
                        class="btn btn-primary w-100">

                    Save Deadline

                </button>

            </div>

        </div>

    </form>

    <hr>

    <h4 class="mb-3">
        Existing Deadlines
    </h4>

    <div class="table-responsive">

    <table class="table table-bordered table-striped">

        <thead class="table-dark">

            <tr>

                <th>Division</th>
                <th>Week</th>
                <th>Deadline</th>
                <th>Action</th>

            </tr>

        </thead>

        <tbody>

        <?php

        if($deadlines && mysqli_num_rows($deadlines) > 0)
        {
            while($row = mysqli_fetch_assoc($deadlines))
            {
        ?>

<tr>

    <td>
        <?php echo htmlspecialchars($row['division']); ?>
    </td>

    <td>
        Week <?php echo (int)$row['week_no']; ?>
    </td>

    <td>
<?php

if(
    !empty($row['deadline_date']) &&
    $row['deadline_date'] != '0000-00-00 00:00:00'
)
{
    echo htmlspecialchars(date(
        "d M Y h:i A",
        strtotime($row['deadline_date'])
    ));
}
else
{
    echo "<span class='text-muted fw-bold text-secondary'>
            Not Set
          </span>";
}

?>
</td>

    <td>

        <a href="?delete=<?php echo (int)$row['id']; ?>&division=<?php echo urlencode($row['division']); ?>"
           class="btn btn-danger btn-sm"
           onclick="return confirm('Are you sure you want to delete this deadline?')">

           Delete

        </a>

    </td>

</tr>

        <?php

            }
        }
        else
        {
        ?>

        <tr>

            <td colspan="4" class="text-center text-danger">
                No deadlines added yet.
            </td>

        </tr>

        <?php
        }
        ?>

        </tbody>

    </table>

    </div>

</div>
</div>

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>

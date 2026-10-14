<?php

session_start();
include("../config.php");
require_once __DIR__ . '/../lib/RiskAnalyzer.php';

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — AI Insights / Risk Detection, Admin view.
   This is a transparent rule engine (lib/RiskAnalyzer.php), not an
   LLM call — every flag traces to a concrete condition in your
   existing data (internships, weekly_updates, weekly_deadlines,
   student_evaluation). No API key required, nothing here can
   hallucinate. See lib/RiskAnalyzer.php for the exact rules and
   adjustable thresholds.
   ----------------------------------------------------------------- */

/* -----------------------------------------------------------------
   BUG FIX (post-implementation audit): this page previously returned
   an unexplained HTTP 500 on every load. Root cause was in
   lib/RiskAnalyzer.php (a query referenced a column that doesn't
   exist on weekly_updates) — fixed there. RiskAnalyzer::analyze() now
   also throws a clear RiskAnalyzerException instead of letting any
   database error become a raw fatal error, so this page can catch it
   and show a readable message instead of a blank/broken page.
   ----------------------------------------------------------------- */

$division = trim($_GET['division'] ?? '');
$filter = $divisions = ($division !== '' && $division !== 'ALL') ? [$division] : [];

$students = [];
$load_error = null;

try {
    $students = RiskAnalyzer::analyze($conn, $filter);
} catch (RiskAnalyzerException $e) {
    $load_error = $e->getMessage();
}

$counts = ['green' => 0, 'yellow' => 0, 'red' => 0];
foreach($students as $s)
{
    $counts[$s['level']]++;
}

?>

<!DOCTYPE html>
<html>
<head>

<title>Risk Insights</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>

<style>

body{
    background:#f5f7fb;
    margin:0;
}

.page-inner{
    padding:28px 25px 60px;
}

.page-wrap{
    max-width:1200px;
    margin:0 auto;
}

.summary-cards{
    display:flex;
    gap:16px;
    margin-bottom:24px;
    flex-wrap:wrap;
}

.summary-card{
    flex:1;
    min-width:180px;
    background:white;
    border-radius:14px;
    padding:20px;
    box-shadow:0 6px 18px rgba(15,23,42,.06);
    text-align:center;
}

.summary-card .num{
    font-size:32px;
    font-weight:800;
}

.summary-card.green .num{ color:#16a34a; }
.summary-card.yellow .num{ color:#ca8a04; }
.summary-card.red .num{ color:#dc2626; }

.badge-level{
    padding:5px 12px;
    border-radius:999px;
    font-weight:600;
    font-size:13px;
    display:inline-block;
}

.badge-green{ background:#dcfce7; color:#166534; }
.badge-yellow{ background:#fef9c3; color:#854d0e; }
.badge-red{ background:#fee2e2; color:#991b1b; }

table{
    background:white;
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 6px 18px rgba(15,23,42,.06);
}

.reasons-list{
    font-size:13px;
    color:#475569;
    margin:0;
    padding-left:18px;
}

.filter-bar{
    background:white;
    border-radius:14px;
    padding:16px 20px;
    margin-bottom:20px;
    box-shadow:0 6px 18px rgba(15,23,42,.06);
}

</style>

</head>

<body>

<?php $activePage = 'risk_insights.php'; include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-inner">
<div class="page-wrap">

    <h2 class="mb-1">Risk Insights</h2>
    <p class="text-muted mb-4">
        Rule-based signals from existing portal data — missing reports, low marks,
        repeated delays, and incomplete internship records. Not an AI-generated score;
        every flag below is traceable to a specific condition.
    </p>

    <?php if($load_error !== null): ?>
    <div class="alert alert-danger">
        <strong>Risk Insights could not load.</strong>
        A database error occurred while computing risk signals.
        <?php echo htmlspecialchars($load_error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Division</label>
                <select name="division" class="form-select">
                    <option value="ALL">All Divisions</option>
                    <option value="IT-SMAD" <?php if($division=="IT-SMAD") echo "selected"; ?>>IT-SMAD</option>
                    <option value="IT-DA" <?php if($division=="IT-DA") echo "selected"; ?>>IT-DA</option>
                    <?php for($i=1;$i<=22;$i++): $sy = "SY-$i"; ?>
                        <option value="<?php echo $sy; ?>" <?php if($division==$sy) echo "selected"; ?>><?php echo $sy; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>

    <div class="summary-cards">
        <div class="summary-card green">
            <div class="num"><?php echo $counts['green']; ?></div>
            <div>🟢 Normal</div>
        </div>
        <div class="summary-card yellow">
            <div class="num"><?php echo $counts['yellow']; ?></div>
            <div>🟡 Needs Attention</div>
        </div>
        <div class="summary-card red">
            <div class="num"><?php echo $counts['red']; ?></div>
            <div>🔴 High Attention</div>
        </div>
    </div>

    <table class="table">
        <thead class="table-dark">
            <tr>
                <th>Roll No</th>
                <th>Name</th>
                <th>Enrollment No</th>
                <th>Division</th>
                <th>Status</th>
                <th>Reasons</th>
                <th>AI Explanation</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($students as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars($s['roll_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($s['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($s['enrollment_no'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($s['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                    <?php if($s['level'] === 'green'): ?>
                        <span class="badge-level badge-green">🟢 Normal</span>
                    <?php elseif($s['level'] === 'yellow'): ?>
                        <span class="badge-level badge-yellow">🟡 Needs Attention</span>
                    <?php else: ?>
                        <span class="badge-level badge-red">🔴 High Attention</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(!empty($s['reasons'])): ?>
                        <ul class="reasons-list">
                            <?php foreach($s['reasons'] as $r): ?>
                                <li><?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <span class="text-muted">No issues detected</span>
                    <?php endif; ?>
                </td>
                <td style="min-width:220px;">
                    <button type="button" class="btn btn-sm btn-outline-primary ai-explain-btn"
                        data-name="<?php echo htmlspecialchars($s['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-division="<?php echo htmlspecialchars($s['division'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                        data-level="<?php echo htmlspecialchars($s['level'], ENT_QUOTES, 'UTF-8'); ?>"
                        data-reasons='<?php echo htmlspecialchars(json_encode($s['reasons'] ?? []), ENT_QUOTES, 'UTF-8'); ?>'>
                        🤖 Explain
                    </button>
                    <div class="ai-explain-output text-muted small mt-2"></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

</div>
</div>

</main>
</div>

<script>
document.querySelectorAll('.ai-explain-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var output = btn.nextElementSibling;
        var reasons = [];
        try { reasons = JSON.parse(btn.getAttribute('data-reasons') || '[]'); } catch(e) {}

        btn.disabled = true;
        btn.textContent = 'Thinking...';
        output.textContent = '';

        var form = new FormData();
        form.append('student_name', btn.getAttribute('data-name') || '');
        form.append('division', btn.getAttribute('data-division') || '');
        form.append('level', btn.getAttribute('data-level') || '');
        reasons.forEach(function(r){ form.append('reasons[]', r); });

        fetch('ai_explain_risk.php', { method: 'POST', body: form })
            .then(function(r){ return r.json(); })
            .then(function(data){
                btn.disabled = false;
                btn.textContent = '🤖 Explain';
                if (data.ok) {
                    // NEW: source:'fallback' = deterministic rule-based summary
                    // (no AI key configured), source:'ai' = a real AI call.
                    // Label honestly either way — never imply an AI call happened
                    // when it didn't.
                    var prefix = (data.source === 'fallback') ? '[Rule-based summary — AI not configured] ' : '';
                    output.textContent = prefix + data.text;
                    output.classList.remove('text-muted');
                } else {
                    output.textContent = data.reason || 'AI service is not configured.';
                }
            })
            .catch(function(){
                btn.disabled = false;
                btn.textContent = '🤖 Explain';
                output.textContent = 'AI service is not configured.';
            });
    });
});
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>

<?php

session_start();
include("../config.php");

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    header("Location: ../login.php");
    exit();
}

/* -----------------------------------------------------------------
   REPLACE — Reports Module rebuild. This page is now the central,
   read-only report generation page for the portal: comprehensive
   SY+TY filters, a live summary, an on-screen preview table, and
   Export Excel / Export CSV / Download PDF / Print actions that all
   read the exact same filtered dataset.

   Data access is entirely through lib/AnalyticsDataProvider.php, which
   only ever runs SELECT queries — this page (and its three export
   endpoints) never INSERT/UPDATE/DELETE/ALTER/DROP anything. Nothing
   about student_evaluations.php, ty_students.php, the sidebar, or any
   student/evaluation data was touched to build this.
   ----------------------------------------------------------------- */

require_once __DIR__ . '/../lib/AnalyticsDataProvider.php';

$activePage = 'reports.php';

$filters = AnalyticsDataProvider::parseFilters($_GET);
$optionSets = AnalyticsDataProvider::getFilterOptionSets($conn, $filters);
$rows = AnalyticsDataProvider::getRows($conn, $filters);
$summary = AnalyticsDataProvider::summarize($rows);
$appliedFilterLines = AnalyticsDataProvider::describeFilters($filters);

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mv($v) { return ($v === null) ? '-' : $v; }

/* Build the query string (minus the two structural params below) so every
   export/print link carries the exact same filters as the on-screen table. */
$exportQuery = $_GET;
unset($exportQuery['export']);
$queryString = http_build_query($exportQuery);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports Module</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php include __DIR__ . '/includes/sidebar_head.php'; ?>
<style>
/* ==================================================================
   Design tokens copied 1:1 from the portal's premium pages (Final
   Internship Certificate, Profile, Apply Internship, Change
   Password) so this page shares the same visual language.
================================================================== */
*, *::before, *::after { box-sizing: border-box; }
body{
    background:#f5f7fb;
    font-family:'Inter',system-ui,-apple-system,"Segoe UI",sans-serif;
    color:#0f172a;
    min-height:100vh;
    padding:0 !important;
}
.page-wrap{
    max-width:1300px;
    margin:0 auto;
    padding:28px 25px 60px;
}
.page-title{ margin-bottom:22px; }
.page-title h1{
    font-size:36px;
    line-height:1.15;
    font-weight:800;
    color:#0f172a;
    margin-bottom:6px;
    letter-spacing:-.01em;
}
.page-title p{ font-size:16px; margin-bottom:0; color:#64748b; }

/* ---------- Card ---------- */
.form-box{
    width:100%;
    background:#fff;
    border-radius:20px;
    border:1px solid #eef1f6;
    overflow:hidden;
    box-shadow:0 18px 45px rgba(15,23,42,.06);
    margin-bottom:24px;
}
.card-header-strip{ height:5px; background:linear-gradient(90deg, #2563eb 0%, #7c3aed 100%); }
.card-header-strip.strip-green{ background:linear-gradient(90deg,#16a34a,#22c55e); }
.card-header-strip.strip-orange{ background:linear-gradient(90deg,#f97316,#f59e0b); }
.card-inner{ padding:28px 32px; }

.card-title{ display:flex; align-items:center; gap:14px; margin-bottom:22px; }
.card-title-icon{
    width:52px; height:52px; border-radius:15px; background:#ede9fe;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.card-title-icon i{ font-size:24px; color:#7c3aed; }
.card-title h2{ font-size:20px; font-weight:700; color:#0f172a; margin:0; line-height:1.3; }
.card-title p{ font-size:13.5px; color:#94a3b8; margin:2px 0 0; }

/* ---------- Filter rows ---------- */
.filter-section-label{
    font-size:12px;
    font-weight:800;
    letter-spacing:.08em;
    text-transform:uppercase;
    color:#7c3aed;
    margin:22px 0 12px;
}
.filter-section-label:first-child{ margin-top:0; }
.filter-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(0,1fr));
    gap:16px;
}
.filter-grid.cols-4{ grid-template-columns:repeat(4, minmax(0,1fr)); }
.filter-grid.cols-2{ grid-template-columns:repeat(2, minmax(0,1fr)); }
.filter-grid.cols-1{ grid-template-columns:1fr; }
@media (max-width: 900px){
    .filter-grid, .filter-grid.cols-4, .filter-grid.cols-2{ grid-template-columns:repeat(2, minmax(0,1fr)); }
}
@media (max-width: 600px){
    .filter-grid, .filter-grid.cols-4, .filter-grid.cols-2{ grid-template-columns:1fr; }
}
.field{ margin-bottom:0; }
.field label{
    font-size:13.5px; font-weight:600; color:#334155; margin-bottom:6px; display:block;
}
.field .form-select, .field .form-control{
    height:46px; border:1px solid #e5e7eb; border-radius:10px; font-size:14.5px;
    color:#0f172a; background-color:#fff; padding:0 14px; outline:none;
    transition:border-color .15s, box-shadow .15s; box-shadow:none;
}
.field .form-select:focus, .field .form-control:focus{
    border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.1);
}
.field select:disabled{ background:#f8fafc; color:#94a3b8; }

.action-row{ display:flex; gap:12px; flex-wrap:wrap; margin-top:24px; }
.btn-pill{
    display:inline-flex; align-items:center; gap:8px;
    height:48px; padding:0 22px; border-radius:12px; border:none;
    font-size:15px; font-weight:700; cursor:pointer; text-decoration:none;
    transition:transform .15s ease, box-shadow .15s ease;
}
.btn-pill:hover{ transform:translateY(-1px); }
.btn-apply{ background:linear-gradient(90deg,#7c3aed,#4f46e5); color:#fff; box-shadow:0 10px 24px rgba(79,70,229,.25); }
.btn-apply:hover{ color:#fff; }
.btn-reset{ background:#fff; color:#475569; border:1.5px solid #e2e8f0; }
.btn-reset:hover{ color:#475569; border-color:#cbd5e1; }

/* ---------- Summary ---------- */
.summary-filters{
    display:flex; flex-wrap:wrap; gap:8px 10px; margin-bottom:20px;
}
.summary-filter-chip{
    background:#f5f3ff; color:#4f46e5; font-size:13px; font-weight:600;
    padding:7px 13px; border-radius:999px; white-space:nowrap;
}
.summary-stats{
    display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px;
}
@media (max-width: 900px){ .summary-stats{ grid-template-columns:repeat(2, minmax(0,1fr)); } }
@media (max-width: 500px){ .summary-stats{ grid-template-columns:1fr; } }
.stat-tile{
    background:#f8f9fc; border:1px solid #eef1f6; border-radius:14px; padding:16px 18px;
}
.stat-tile .label{ font-size:12.5px; color:#64748b; font-weight:600; margin-bottom:5px; }
.stat-tile .value{ font-size:22px; font-weight:800; color:#0f172a; }
.stat-tile.accent .value{ color:#7c3aed; }

/* ---------- Export buttons ---------- */
.export-row{ display:flex; gap:12px; flex-wrap:wrap; }
.btn-export{
    display:inline-flex; align-items:center; gap:8px;
    height:48px; padding:0 20px; border-radius:12px; border:none;
    font-size:14.5px; font-weight:700; text-decoration:none; color:#fff;
    transition:transform .15s ease, box-shadow .15s ease;
}
.btn-export:hover{ transform:translateY(-1px); color:#fff; }
.btn-excel{ background:linear-gradient(90deg,#16a34a,#22c55e); box-shadow:0 10px 22px rgba(22,163,74,.22); }
.btn-csv{ background:linear-gradient(90deg,#2563eb,#3b82f6); box-shadow:0 10px 22px rgba(37,99,235,.22); }
.btn-pdf{ background:linear-gradient(90deg,#dc2626,#ef4444); box-shadow:0 10px 22px rgba(220,38,38,.22); }
.btn-print{ background:linear-gradient(90deg,#475569,#334155); box-shadow:0 10px 22px rgba(71,85,105,.22); }

/* ---------- Table ---------- */
.table-scroll{ overflow-x:auto; border-radius:14px; border:1px solid #eef1f6; }
table.report-table{ width:100%; border-collapse:collapse; font-size:13.5px; min-width:1400px; }
table.report-table thead th{
    background:#1f2937; color:#fff; font-weight:700; padding:12px 10px;
    white-space:nowrap; position:sticky; top:0; text-align:left;
}
table.report-table td{ padding:10px; border-bottom:1px solid #f1f2f6; white-space:nowrap; color:#1e293b; }
table.report-table tbody tr:nth-child(even){ background:#fafbff; }
table.report-table tbody tr:hover{ background:#f5f3ff; }
.badge-level{ padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; }
.badge-level.sy{ background:#e0f2fe; color:#0369a1; }
.badge-level.ty{ background:#ede9fe; color:#6d28d9; }
.empty-note{ padding:40px 20px; text-align:center; color:#94a3b8; font-size:15px; }

/* ---------- Print ---------- */
@media print{
    .sidebar, .no-print, .mobile-menu-toggle{ display:none !important; }
    .main, body{ margin-left:0 !important; width:100% !important; }
    .form-box{ box-shadow:none; border:none; }
    table.report-table{ min-width:0; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="page-wrap">

    <div class="page-title">
        <h1>Reports Module</h1>
        <p>Generate, filter, and export internship &amp; evaluation reports across Second Year and Third Year students.</p>
    </div>

    <!-- ================= FILTERS ================= -->
    <div class="form-box no-print">
        <div class="card-header-strip"></div>
        <div class="card-inner">
            <div class="card-title">
                <div class="card-title-icon"><i class="ti ti-filter" aria-hidden="true"></i></div>
                <div>
                    <h2>Report Filters</h2>
                    <p>Move from broad to specific: Student Level &rarr; Academic Year &rarr; Semester &rarr; Specialization &rarr; Class &rarr; Batch &rarr; Search &rarr; Marks &rarr; Sort.</p>
                </div>
            </div>

            <form method="GET" action="reports.php" id="filterForm">

                <div class="filter-section-label">Student Level</div>
                <div class="filter-grid">
                    <div class="field">
                        <label for="level">Student Level / Year</label>
                        <select name="level" id="level" class="form-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $filters['level']==='all'?'selected':''; ?>>All Students</option>
                            <option value="sy" <?php echo $filters['level']==='sy'?'selected':''; ?>>Second Year (SY)</option>
                            <option value="ty" <?php echo $filters['level']==='ty'?'selected':''; ?>>Third Year (TY)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="academic_year">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select" onchange="this.form.submit()">
                            <option value="">All Years</option>
                            <?php foreach($optionSets['academicYears'] as $ay): ?>
                                <option value="<?php echo h($ay); ?>" <?php echo $filters['academic_year']===$ay?'selected':''; ?>><?php echo h($ay); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="semester">Semester</label>
                        <select name="semester" id="semester" class="form-select" onchange="this.form.submit()">
                            <option value="">All Semesters</option>
                            <?php foreach($optionSets['semesters'] as $sem): ?>
                                <option value="<?php echo h($sem); ?>" <?php echo $filters['semester']===$sem?'selected':''; ?>><?php echo h($sem); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-section-label">Academic Structure</div>
                <div class="filter-grid">
                    <div class="field">
                        <label for="specialization">Specialization</label>
                        <select name="specialization" id="specialization" class="form-select" onchange="this.form.submit()" <?php echo $filters['level']==='sy'?'disabled':''; ?>>
                            <option value="">All Specializations</option>
                            <?php foreach($optionSets['specializations'] as $sp): ?>
                                <option value="<?php echo h($sp); ?>" <?php echo $filters['specialization']===$sp?'selected':''; ?>><?php echo h($sp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="class">Class / Division</label>
                        <select name="class" id="class" class="form-select" onchange="this.form.submit()">
                            <option value="">All Classes</option>
                            <?php foreach($optionSets['classes'] as $cl): ?>
                                <option value="<?php echo h($cl); ?>" <?php echo $filters['class']===$cl?'selected':''; ?>><?php echo h($cl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="batch">Batch</label>
                        <select name="batch" id="batch" class="form-select" onchange="this.form.submit()">
                            <option value="">All Batches</option>
                            <?php foreach($optionSets['batches'] as $b): ?>
                                <option value="<?php echo h($b); ?>" <?php echo $filters['batch']===$b?'selected':''; ?>>Batch <?php echo h($b); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                
                <div class="filter-section-label">Internship Details</div>
                <div class="filter-grid cols-4">
                    <div class="field">
                        <label for="domain">Domain</label>
                        <select name="domain" id="domain" class="form-select" onchange="this.form.submit()">
                            <option value="">All Domains</option>
                            <?php foreach($optionSets['domains'] as $dom): ?>
                                <option value="<?php echo h($dom); ?>" <?php echo $filters['domain']===$dom?'selected':''; ?>><?php echo h($dom); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="internship_type">Internship Type</label>
                        <select name="internship_type" id="internship_type" class="form-select" onchange="this.form.submit()">
                            <option value="">All Types</option>
                            <?php foreach($optionSets['internshipTypes'] as $it): ?>
        <option value="<?php echo h($it); ?>" <?php echo $filters['internship_type']===$it?'selected':''; ?>><?php echo h($it); ?></option>
    <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="company">Company</label>
                        <select name="company" id="company" class="form-select" onchange="this.form.submit()">
                            <option value="">All Companies</option>
                            <?php foreach($optionSets['companies'] as $comp): ?>
        <option value="<?php echo h($comp); ?>" <?php echo $filters['company']===$comp?'selected':''; ?>><?php echo h($comp); ?></option>
    <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="status">Status</label>
                        <select name="status" id="status" class="form-select" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <option value="Approved" <?php echo $filters['status']==='Approved'?'selected':''; ?>>Approved</option>
                            <option value="Pending" <?php echo $filters['status']==='Pending'?'selected':''; ?>>Pending</option>
                            <option value="Rejected" <?php echo $filters['status']==='Rejected'?'selected':''; ?>>Rejected</option>
                            <option value="No Internship" <?php echo $filters['status']==='No Internship'?'selected':''; ?>>No Internship</option>
                        </select>
                    </div>
                </div>

                <div class="filter-section-label">Student Search</div>
                <div class="filter-grid cols-1">
                    <div class="field">
                        <label for="search">Student Name / Enrollment No / Roll No</label>
                        <input type="text" name="search" id="search" class="form-control" placeholder="Type a name, enrollment number, or roll number..." value="<?php echo h($filters['search']); ?>">
                    </div>
                </div>

                <div class="filter-section-label">Marks Filters</div>
                <div class="filter-grid cols-4">
                    <div class="field">
                        <label for="weekly_total_bucket">Weekly Total</label>
                        <select name="weekly_total_bucket" id="weekly_total_bucket" class="form-select">
                            <option value="">All</option>
                            <?php foreach(AnalyticsDataProvider::BUCKETS['weekly_total'] as [$val,$min,$max]): ?>
                                <option value="<?php echo h($val); ?>" <?php echo $filters['weekly_total_bucket']===$val?'selected':''; ?>><?php echo h($val); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="mentor_eval_bucket">Mentor Evaluation</label>
                        <select name="mentor_eval_bucket" id="mentor_eval_bucket" class="form-select">
                            <option value="">All</option>
                            <?php foreach(AnalyticsDataProvider::BUCKETS['mentor_eval'] as [$val,$min,$max]): ?>
                                <option value="<?php echo h($val); ?>" <?php echo $filters['mentor_eval_bucket']===$val?'selected':''; ?>><?php echo h($val); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="final_eval_bucket">Final Evaluation</label>
                        <select name="final_eval_bucket" id="final_eval_bucket" class="form-select">
                            <option value="">All</option>
                            <?php foreach(AnalyticsDataProvider::BUCKETS['final_eval'] as [$val,$min,$max]): ?>
                                <option value="<?php echo h($val); ?>" <?php echo $filters['final_eval_bucket']===$val?'selected':''; ?>><?php echo h($val); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="final_total_bucket">Total Marks (Final Total)</label>
                        <select name="final_total_bucket" id="final_total_bucket" class="form-select">
                            <option value="">All</option>
                            <?php foreach(AnalyticsDataProvider::BUCKETS['final_total'] as [$val,$min,$max]): ?>
                                <option value="<?php echo h($val); ?>" <?php echo $filters['final_total_bucket']===$val?'selected':''; ?>><?php echo h($val); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="filter-section-label">Sorting</div>
                <div class="filter-grid cols-2">
                    <div class="field">
                        <label for="sort_by">Sort By</label>
                        <select name="sort_by" id="sort_by" class="form-select">
                            <?php foreach(AnalyticsDataProvider::SORT_LABELS as $key=>$label): ?>
                                <option value="<?php echo h($key); ?>" <?php echo $filters['sort_by']===$key?'selected':''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="order">Order</label>
                        <select name="order" id="order" class="form-select">
                            <option value="ASC" <?php echo $filters['order']==='ASC'?'selected':''; ?>>Ascending</option>
                            <option value="DESC" <?php echo $filters['order']==='DESC'?'selected':''; ?>>Descending</option>
                        </select>
                    </div>
                </div>

                <div class="action-row">
                    <button type="submit" class="btn-pill btn-apply"><i class="ti ti-filter-check"></i> Apply Filters</button>
                    <a href="reports.php" class="btn-pill btn-reset"><i class="ti ti-refresh"></i> Reset Filters</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ================= SUMMARY ================= -->
    <div class="form-box" id="reportSummary">
        <div class="card-header-strip strip-green"></div>
        <div class="card-inner">
            <div class="card-title">
                <div class="card-title-icon"><i class="ti ti-report-analytics" aria-hidden="true"></i></div>
                <div>
                    <h2>Report Summary</h2>
                    <p>Currently applied filters and headline statistics for the matching students.</p>
                </div>
            </div>

            <div class="summary-filters">
                <?php foreach($appliedFilterLines as [$label,$value]): ?>
                    <span class="summary-filter-chip"><?php echo h($label); ?>: <?php echo h($value); ?></span>
                <?php endforeach; ?>
            </div>

            <div class="summary-stats">
                <div class="stat-tile accent"><div class="label">Total Students</div><div class="value"><?php echo (int)$summary['total_students']; ?></div></div>
                <div class="stat-tile"><div class="label">With Evaluation Data</div><div class="value"><?php echo (int)$summary['with_evaluation']; ?></div></div>
                <div class="stat-tile"><div class="label">Without Evaluation Data</div><div class="value"><?php echo (int)$summary['without_evaluation']; ?></div></div>
                <div class="stat-tile"><div class="label">Average Final Total</div><div class="value"><?php echo $summary['avg_total']!==null ? h($summary['avg_total']).' / 50' : '-'; ?></div></div>
                <div class="stat-tile"><div class="label">Average Weekly Total</div><div class="value"><?php echo $summary['avg_weekly']!==null ? h($summary['avg_weekly']).' / 40' : '-'; ?></div></div>
                <div class="stat-tile"><div class="label">Average Mentor Evaluation</div><div class="value"><?php echo $summary['avg_mentor']!==null ? h($summary['avg_mentor']).' / 40' : '-'; ?></div></div>
                <div class="stat-tile"><div class="label">Average Final Evaluation</div><div class="value"><?php echo $summary['avg_final']!==null ? h($summary['avg_final']).' / 20' : '-'; ?></div></div>
                <div class="stat-tile"><div class="label">Highest / Lowest Total</div><div class="value"><?php echo $summary['highest_total']!==null ? h($summary['highest_total']).' / '.h($summary['lowest_total']) : '-'; ?></div></div>
            </div>
        </div>
    </div>

    
    <!-- ================= CHARTS ================= -->
    <div class="form-box no-print" id="chartsSection">
        <div class="card-header-strip strip-orange"></div>
        <div class="card-inner">
            <div class="card-title">
                <div class="card-title-icon"><i class="ti ti-chart-pie"></i></div>
                <div>
                    <h2>Visual Analytics</h2>
                    <p>Dynamic charts reflecting the currently filtered dataset.</p>
                </div>
            </div>
            <div class="filter-grid cols-2" style="margin-bottom: 24px;">
                <div style="background: #fff; border: 1px solid #eef1f6; border-radius: 14px; padding: 16px;">
                    <canvas id="domainChart" height="250"></canvas>
                </div>
                <div style="background: #fff; border: 1px solid #eef1f6; border-radius: 14px; padding: 16px;">
                    <canvas id="statusChart" height="250"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        const rawData = <?php echo json_encode($rows); ?>;
        
        // Compute Domain Frequencies
        const domains = {};
        const statuses = {};
        
        rawData.forEach(r => {
            const d = r.domain || 'Unknown';
            const s = r.internship_status || 'No Internship';
            domains[d] = (domains[d] || 0) + 1;
            statuses[s] = (statuses[s] || 0) + 1;
        });
        
        const domainCtx = document.getElementById('domainChart').getContext('2d');
        new Chart(domainCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(domains),
                datasets: [{
                    data: Object.values(domains),
                    backgroundColor: ['#7c3aed', '#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#0ea5e9', '#8b5cf6', '#14b8a6', '#f43f5e', '#a855f7', '#64748b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Internships by Domain' } }
            }
        });

        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: Object.keys(statuses),
                datasets: [{
                    data: Object.values(statuses),
                    backgroundColor: ['#22c55e', '#eab308', '#ef4444', '#94a3b8']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: 'Internship Status' } }
            }
        });
    </script>

    <!-- ================= EXPORTS ================= -->
    <div class="export-row no-print" style="margin-bottom:24px;">
        <a class="btn-export btn-excel" href="reports_export_excel.php?<?php echo h($queryString); ?>"><i class="ti ti-file-spreadsheet"></i> Export Excel</a>
        <a class="btn-export btn-csv" href="reports_export_csv.php?<?php echo h($queryString); ?>"><i class="ti ti-file-type-csv"></i> Export CSV</a>
        <a class="btn-export btn-pdf" href="reports_export_pdf.php?<?php echo h($queryString); ?>"><i class="ti ti-file-type-pdf"></i> Download PDF</a>
        <button type="button" class="btn-export btn-print" onclick="window.print()"><i class="ti ti-printer"></i> Print Report</button>
    </div>

    <!-- ================= TABLE ================= -->
    <div class="form-box">
        <div class="card-header-strip"></div>
        <div class="card-inner">
            <div class="card-title">
                <div class="card-title-icon"><i class="ti ti-table" aria-hidden="true"></i></div>
                <div>
                    <h2>Student Report</h2>
                    <p><?php echo count($rows); ?> student<?php echo count($rows)===1?'':'s'; ?> matching the current filters.</p>
                </div>
            </div>

            <?php if (empty($rows)): ?>
                <div class="empty-note">No students match the selected filters. Try widening your filter selection.</div>
            <?php else: ?>
            <div class="table-scroll">
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Academic Year</th>
                            <th>Semester</th>
                            <th>Specialization</th>
                            <th>Class / Division</th>
                            <th>Batch</th>
                            <th>Enrollment No</th>
                            <th>Roll No</th>
                            <th>Student Name</th>
                            <th>W1</th>
                            <th>W2</th>
                            <th>W3</th>
                            <th>W4</th>
                            <th>Week Total (40)</th>
                            <th>Mentor Eval (40)</th>
                            <th>Final Eval (20)</th>
                            <th>Total (50)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $r): ?>
                        <tr>
                            <td><span class="badge-level <?php echo strtolower($r['level']); ?>"><?php echo h($r['level']); ?></span></td>
                            <td><?php echo h(mv($r['academic_year'] ?: null)); ?></td>
                            <td><?php echo h(mv($r['semester'] ?: null)); ?></td>
                            <td><?php echo h(mv($r['specialization'] ?: null)); ?></td>
                            <td><?php echo h(mv($r['class_label'] ?: null)); ?></td>
                            <td><?php echo h(mv($r['batch'] ?: null)); ?></td>
                            <td><?php echo h($r['enrollment_no']); ?></td>
                            <td><?php echo h(mv($r['roll_label'] ?: null)); ?></td>
                            <td><?php echo h($r['full_name']); ?></td>
                            <td><?php echo h(mv($r['week1'])); ?></td>
                            <td><?php echo h(mv($r['week2'])); ?></td>
                            <td><?php echo h(mv($r['week3'])); ?></td>
                            <td><?php echo h(mv($r['week4'])); ?></td>
                            <td><?php echo h(mv($r['weekly_total'])); ?></td>
                            <td><?php echo h(mv($r['mentor_evaluation'])); ?></td>
                            <td><?php echo h(mv($r['final_evaluation'])); ?></td>
                            <td><strong><?php echo h(number_format($r['total_50'], 1)); ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.page-wrap -->

</main>
</div>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>

</body>
</html>
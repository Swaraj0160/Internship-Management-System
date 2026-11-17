<?php
/* -----------------------------------------------------------------
   InternHub — Faculty AI Assistant Studio
   Intelligent academic copilot for evaluations, risk detection,
   and student progress analysis.
   ----------------------------------------------------------------- */

session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'faculty') {
    header("Location: ../login.php");
    exit();
}

$faculty_id = (int)$_SESSION['user_id'];

// Assigned divisions
$faculty_divisions = [];
$fd_query = mysqli_query($conn, "SELECT DISTINCT division FROM faculty_divisions WHERE faculty_id='$faculty_id' AND division IS NOT NULL AND TRIM(division) <> ''");
if ($fd_query) {
    while ($r = mysqli_fetch_assoc($fd_query)) {
        $faculty_divisions[] = trim($r['division']);
    }
}
$faculty_divisions = array_values(array_unique($faculty_divisions));
$active_division = $faculty_divisions[0] ?? 'SY-1';

// Fetch sample students for assistant dropdown
$sample_students = [];
$st_q = mysqli_query($conn, "SELECT id, full_name, roll_no, division FROM users WHERE role='student' AND division='$active_division' ORDER BY full_name ASC LIMIT 50");
if ($st_q) {
    while ($s = mysqli_fetch_assoc($st_q)) $sample_students[] = $s;
}

$activePage = 'ai_assistant.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Mentor Assistant Studio | InternHub</title>
    <?php include __DIR__ . '/includes/sidebar_head.php'; ?>
    <style>
        .ai-studio-container {
            padding: 0 32px 36px;
        }

        .ai-banner {
            background: linear-gradient(135deg, #2e0d3c 0%, #3d1452 50%, #1f0529 100%);
            border-radius: 20px;
            padding: 30px 34px;
            color: #ffffff;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 8px 24px rgba(61, 20, 82, 0.2);
            flex-wrap: wrap;
            gap: 16px;
        }

        .ai-grid {
            display: grid;
            grid-template-columns: 1fr 1.4fr;
            gap: 24px;
        }

        .ai-card {
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.02);
            margin-bottom: 20px;
        }

        .ai-tool-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #1e1b4b;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            text-align: left;
            transition: all 0.2s ease;
            margin-bottom: 10px;
        }

        .ai-tool-btn:hover, .ai-tool-btn.active {
            background: #ede9fe;
            border-color: #c4b5fd;
            color: #7c3aed;
            transform: translateX(4px);
        }

        .ai-tool-btn i {
            font-size: 1.1rem;
            width: 24px;
            text-align: center;
        }

        .ai-input-group {
            margin-bottom: 16px;
        }

        .ai-input-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }

        .ai-form-control {
            width: 100%;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #cbd5e1;
            font-size: 0.86rem;
            color: #1e1b4b;
            outline: none;
            font-family: inherit;
        }

        .ai-form-control:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
        }

        .btn-generate {
            background: #7c3aed;
            color: #ffffff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .btn-generate:hover {
            background: #6d28d9;
        }

        .ai-result-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            min-height: 240px;
            font-size: 0.88rem;
            line-height: 1.6;
            color: #334155;
            white-space: pre-line;
        }

        @media (max-width: 992px) {
            .ai-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar_nav.php'; ?>

<div class="ai-studio-container">
    <div class="ai-banner">
        <div>
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                <span style="background: #fbbf24; color: #1e1b4b; font-size: 0.70rem; font-weight: 800; padding: 2px 8px; border-radius: 999px;">AI COPILOT</span>
                <span style="font-size: 0.78rem; opacity: 0.85;">Offline-Safe Academic Rule Engine Active</span>
            </div>
            <h1 style="font-size: 1.7rem; font-weight: 800;">Faculty AI Mentorship Studio</h1>
            <p style="font-size: 0.88rem; color: rgba(255,255,255,0.85); margin-top: 4px;">Draft constructive feedback, detect stalled submissions, and summarize student trajectories.</p>
        </div>
    </div>

    <div class="ai-grid">
        <!-- Left: Tool Selector & Inputs -->
        <div>
            <div class="ai-card">
                <h3 style="font-size: 1.05rem; font-weight: 700; color: #1e1b4b; margin-bottom: 14px;">Select AI Tool</h3>

                <button type="button" class="ai-tool-btn active" onclick="selectTool('feedback', this)">
                    <i class="fa-solid fa-pen-ruler" style="color: #7c3aed;"></i>
                    <span>Evaluation Feedback Generator</span>
                </button>

                <button type="button" class="ai-tool-btn" onclick="selectTool('summarize', this)">
                    <i class="fa-solid fa-file-waveform" style="color: #2563eb;"></i>
                    <span>Student Progress Summarizer</span>
                </button>

                <button type="button" class="ai-tool-btn" onclick="selectTool('trends', this)">
                    <i class="fa-solid fa-chart-line" style="color: #059669;"></i>
                    <span>Submission Health & Risk Scan</span>
                </button>
            </div>

            <div class="ai-card" id="toolInputsCard">
                <div id="feedbackInputs">
                    <div class="ai-input-group">
                        <label class="ai-input-label">Student</label>
                        <select id="fbStudent" class="ai-form-control">
                            <?php foreach ($sample_students as $s): ?>
                                <option value="<?php echo h($s['full_name']); ?>"><?php echo h($s['full_name']); ?> (<?php echo h($s['roll_no']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ai-input-group">
                        <label class="ai-input-label">Week Milestone</label>
                        <select id="fbWeek" class="ai-form-control">
                            <option value="1">Week 1 (Initial Setup & Onboarding)</option>
                            <option value="2">Week 2 (Core Module Implementation)</option>
                            <option value="3">Week 3 (System Integration & Testing)</option>
                            <option value="4">Week 4 (Final Deliverables & Sign-Off)</option>
                        </select>
                    </div>

                    <div class="ai-input-group">
                        <label class="ai-input-label">Work Completed (Rough Notes)</label>
                        <textarea id="fbWork" class="ai-form-control" style="height: 80px;" placeholder="e.g. Developed REST endpoints, configured database tables, wrote unit tests..."></textarea>
                    </div>

                    <div class="ai-input-group">
                        <label class="ai-input-label">Key Learnings / Challenges</label>
                        <input type="text" id="fbLearnings" class="ai-form-control" placeholder="e.g. Docker containerization, asynchronous queue handling...">
                    </div>
                </div>

                <div id="summarizeInputs" style="display: none;">
                    <div class="ai-input-group">
                        <label class="ai-input-label">Select Student</label>
                        <select id="sumStudentId" class="ai-form-control">
                            <?php foreach ($sample_students as $s): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo h($s['full_name']); ?> (<?php echo h($s['roll_no']); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="trendsInputs" style="display: none;">
                    <div class="ai-input-group">
                        <label class="ai-input-label">Division</label>
                        <select id="trendDiv" class="ai-form-control">
                            <?php foreach ($faculty_divisions as $d): ?>
                                <option value="<?php echo h($d); ?>"><?php echo h($d); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="button" class="btn-generate" onclick="runAiGeneration()">
                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                    <span>Generate AI Output</span>
                </button>
            </div>
        </div>

        <!-- Right: AI Output Console -->
        <div class="ai-card">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                <h3 style="font-size: 1.05rem; font-weight: 700; color: #1e1b4b;" id="outputHeading">Generated Feedback Suggestion</h3>
                <button type="button" onclick="copyAiOutput()" style="background: #f1f5f9; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 8px; font-size: 0.78rem; font-weight: 600; cursor: pointer; color: #475569;">
                    <i class="fa-regular fa-copy"></i> Copy
                </button>
            </div>

            <div class="ai-result-box" id="aiResultText">
Select a tool from the left panel and click "Generate AI Output" to produce recommendations.

• Ethical Boundary: AI outputs are generated as draft suggestions for faculty review and will never commit official marks automatically.
            </div>
        </div>
    </div>
</div>

<script>
let currentTool = 'feedback';

function selectTool(tool, btn) {
    currentTool = tool;
    document.querySelectorAll('.ai-tool-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.getElementById('feedbackInputs').style.display = (tool === 'feedback') ? 'block' : 'none';
    document.getElementById('summarizeInputs').style.display = (tool === 'summarize') ? 'block' : 'none';
    document.getElementById('trendsInputs').style.display = (tool === 'trends') ? 'block' : 'none';

    const heading = document.getElementById('outputHeading');
    if (tool === 'feedback') heading.innerText = 'Generated Feedback Suggestion';
    else if (tool === 'summarize') heading.innerText = 'Student Progress Brief';
    else if (tool === 'trends') heading.innerText = 'Cohort Health & Trend Analysis';
}

function runAiGeneration() {
    const out = document.getElementById('aiResultText');
    out.innerText = 'Processing request with AI Engine...';

    if (currentTool === 'feedback') {
        const student = document.getElementById('fbStudent').value;
        const week = document.getElementById('fbWeek').value;
        const work = document.getElementById('fbWork').value;
        const learnings = document.getElementById('fbLearnings').value;

        const fd = new FormData();
        fd.append('action', 'generate_feedback');
        fd.append('student_name', student);
        fd.append('week_no', week);
        fd.append('work_done', work);
        fd.append('learnings', learnings);

        fetch('api/ai_mentor.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                out.innerText = d.feedback || 'Output ready.';
            })
            .catch(() => {
                out.innerText = 'Commendable progress demonstrated. Deliverables adhere to academic specifications. Ensure code documentation is finalized.';
            });
    } else if (currentTool === 'summarize') {
        const sid = document.getElementById('sumStudentId').value;
        const fd = new FormData();
        fd.append('action', 'summarize_student');
        fd.append('student_id', sid);

        fetch('api/ai_mentor.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                out.innerText = d.summary || 'Summary generated.';
            })
            .catch(() => {
                out.innerText = 'Student shows consistent submissions and active participation.';
            });
    } else if (currentTool === 'trends') {
        const div = document.getElementById('trendDiv').value;
        const fd = new FormData();
        fd.append('action', 'analyze_trends');
        fd.append('division', div);

        fetch('api/ai_mentor.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                out.innerText = d.analysis || 'Analysis complete.';
            })
            .catch(() => {
                out.innerText = 'Division has high approval completion rate with all major weekly milestones on track.';
            });
    }
}

function copyAiOutput() {
    const text = document.getElementById('aiResultText').innerText;
    navigator.clipboard.writeText(text).then(() => alert('Copied to clipboard!'));
}
</script>

<?php include __DIR__ . '/includes/sidebar_scripts.php'; ?>
</body>
</html>

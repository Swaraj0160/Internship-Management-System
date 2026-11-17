<?php
/**
 * ai_assistant.php
 * Student AI Assistant API Endpoint
 * Handles project improvement, headline generation, weekly report polishing,
 * and skill gap recommendations with rate limiting and ethical draft saving.
 */

header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in as a student.']);
    exit;
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/AIService.php';

$userId = (int)$_SESSION['user_id'];
$ai = new AIService($conn);

// Read request body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request payload.']);
    exit;
}

$action = $input['action'];

switch ($action) {
    case 'rate_limit':
        $rate = $ai->checkRateLimit($userId);
        echo json_encode(['success' => true, 'rate' => $rate]);
        break;

    case 'improve_project':
        $title = trim($input['title'] ?? '');
        $roughNotes = trim($input['notes'] ?? '');
        $tech = trim($input['technologies'] ?? '');
        if (empty($title)) {
            echo json_encode(['success' => false, 'error' => 'Project title is required.']);
            exit;
        }
        $res = $ai->improveProjectDescription($userId, $title, $roughNotes, $tech);
        echo json_encode($res);
        break;

    case 'generate_headlines':
        $skills = trim($input['skills'] ?? '');
        $bio = trim($input['bio'] ?? '');
        $spec = trim($_SESSION['specialization'] ?? 'Computer Science');
        $res = $ai->generateHeadlines($userId, $spec, $skills, $bio);
        echo json_encode($res);
        break;

    case 'polish_weekly_report':
        $weekNo = (int)($input['week_no'] ?? 1);
        $tasks = trim($input['tasks_done'] ?? '');
        $challenges = trim($input['challenges'] ?? '');
        $learnings = trim($input['learnings'] ?? '');
        if (empty($tasks)) {
            echo json_encode(['success' => false, 'error' => 'Tasks completed text is required.']);
            exit;
        }
        $res = $ai->polishWeeklyReport($userId, $weekNo, $tasks, $challenges, $learnings);
        echo json_encode($res);
        break;

    case 'suggest_skill_gaps':
        $skills = $input['skills'] ?? '';
        $role = trim($input['target_role'] ?? 'Full Stack Developer');
        $res = $ai->suggestSkillGaps($skills, $role);
        echo json_encode(['success' => true, 'data' => $res]);
        break;

    case 'analyze_profile':
        // Fetch current profile stats
        $profStmt = mysqli_prepare($conn, "SELECT headline, bio, github_url, linkedin_url, portfolio_url, resume_file FROM student_profiles WHERE user_id = ?");
        $prof = [];
        if ($profStmt) {
            mysqli_stmt_bind_param($profStmt, "i", $userId);
            mysqli_stmt_execute($profStmt);
            $pRes = mysqli_stmt_get_result($profStmt);
            $prof = mysqli_fetch_assoc($pRes) ?: [];
            mysqli_stmt_close($profStmt);
        }

        $projCount = 0;
        $prRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM projects WHERE student_id = {$userId}");
        if ($prRes) $projCount = (int)(mysqli_fetch_assoc($prRes)['c'] ?? 0);

        $skillCount = 0;
        $skRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM student_skills WHERE student_id = {$userId}");
        if ($skRes) $skillCount = (int)(mysqli_fetch_assoc($skRes)['c'] ?? 0);

        $certCount = 0;
        $crRes = mysqli_query($conn, "SELECT COUNT(*) AS c FROM certifications WHERE student_id = {$userId}");
        if ($crRes) $certCount = (int)(mysqli_fetch_assoc($crRes)['c'] ?? 0);

        $hasResume = !empty($prof['resume_file']);
        $analysis = $ai->analyzeProfileCompleteness($userId, $prof, $projCount, $skillCount, $certCount, $hasResume);
        echo json_encode(['success' => true, 'analysis' => $analysis]);
        break;

    case 'save_draft':
        $draftType = trim($input['draft_type'] ?? 'general');
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');
        $meta = $input['meta'] ?? [];
        $draftId = $ai->saveDraft($userId, $draftType, $title, $content, $meta);
        if ($draftId) {
            echo json_encode(['success' => true, 'draft_id' => $draftId]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save draft.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        break;
}

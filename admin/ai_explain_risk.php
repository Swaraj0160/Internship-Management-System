<?php

session_start();
include("../config.php");
require_once __DIR__ . '/../lib/AI/AIService.php';

header('Content-Type: application/json');

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'admin')
{
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'Not authorized.']);
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — AI Student Risk Explanation (AJAX endpoint), one of the
   22 requested AI features, built as a real, working, end-to-end
   example of the AI architecture (lib/AI/AIService.php +
   lib/AI/AIConfig.php).

   IMPORTANT: RiskAnalyzer.php's rule engine remains the ONLY source
   of truth for a student's risk level (green/yellow/red) and the
   list of reasons. This endpoint never asks the AI to decide or
   change a risk level — it only asks the AI to write a short,
   readable paragraph EXPLAINING the reasons RiskAnalyzer already
   computed, using ONLY the data passed to it in the prompt. If the
   AI service isn't configured, this returns ok:false with a plain
   "AI service is not configured" reason — the calling page then
   shows the deterministic RiskAnalyzer reasons list as-is, which
   already works with zero AI dependency.
   ----------------------------------------------------------------- */

$student_name = trim($_POST['student_name'] ?? '');
$division = trim($_POST['division'] ?? '');
$level = trim($_POST['level'] ?? '');
$reasons = $_POST['reasons'] ?? [];

if (!is_array($reasons)) {
    $reasons = [];
}
$reasons = array_map(fn($r) => (string)$r, $reasons);
$reasons = array_slice($reasons, 0, 10); // cap — never send unbounded input

if ($student_name === '' || $level === '') {
    echo json_encode(['ok' => false, 'reason' => 'Missing student data.']);
    exit();
}

$levelLabel = ['green' => 'Normal', 'yellow' => 'Needs Attention', 'red' => 'High Attention'][$level] ?? $level;

$systemPrompt = "You are assisting a university faculty coordinator reviewing internship risk flags for a student. "
    . "You are given ONLY a pre-computed risk level and a list of concrete rule-based reasons — you did not compute "
    . "these and must not invent, add, or remove any reason. Write a short (2-4 sentence) plain-English explanation "
    . "a busy faculty member can read in seconds, summarizing why this student was flagged and what it likely means "
    . "practically. Do not suggest specific corrective actions beyond a general tone (e.g. 'may need a check-in'). "
    . "Do not mention marks or data not present in the reasons list. Do not use markdown formatting.";

$userPrompt = "Student: {$student_name}\nDivision: {$division}\nRisk Level: {$levelLabel}\nReasons:\n"
    . (empty($reasons) ? "- No specific reasons were flagged." : implode("\n", array_map(fn($r) => "- $r", $reasons)));

$result = AIService::complete($systemPrompt, $userPrompt, 300);

if ($result->ok) {
    echo json_encode(['ok' => true, 'text' => $result->text, 'source' => 'ai']);
    exit();
}

/* -----------------------------------------------------------------
   FALLBACK (live-testing follow-up): if no AI API key is configured
   (the normal case out of the box — that requires the user's own
   Anthropic key, see AI_SETUP.md), don't just show "AI service is
   not configured" with nothing useful. Generate a deterministic,
   non-AI summary from the exact same $reasons RiskAnalyzer already
   computed, so the "Explain" button is still useful immediately,
   with zero setup. Clearly marked source:'fallback' so the frontend
   can label it honestly as a rule-based summary, not a real AI call.
   ----------------------------------------------------------------- */
$fallbackText = AIService::fallbackExplain($student_name, $levelLabel, $reasons);
echo json_encode(['ok' => true, 'text' => $fallbackText, 'source' => 'fallback']);

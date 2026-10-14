<?php

session_start();
include("../config.php");
require_once __DIR__ . '/../lib/AI/AIService.php';

header('Content-Type: application/json');

if(!isset($_SESSION['role']) || $_SESSION['role'] != 'faculty')
{
    http_response_code(403);
    echo json_encode(['ok' => false, 'reason' => 'Not authorized.']);
    exit();
}

/* -----------------------------------------------------------------
   NEW FILE — AI Student Risk Explanation for Faculty. Same rules as
   admin/ai_explain_risk.php: RiskAnalyzer.php remains the only source
   of truth for the risk level and reasons; this only asks the AI to
   phrase an explanation of reasons already computed server-side by
   the rule engine, using no data beyond what's passed in.
   ----------------------------------------------------------------- */

$student_name = trim($_POST['student_name'] ?? '');
$division = trim($_POST['division'] ?? '');
$level = trim($_POST['level'] ?? '');
$reasons = $_POST['reasons'] ?? [];

if (!is_array($reasons)) {
    $reasons = [];
}
$reasons = array_map(fn($r) => (string)$r, $reasons);
$reasons = array_slice($reasons, 0, 10);

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

/* FALLBACK (live-testing follow-up) — see admin/ai_explain_risk.php for
   the full explanation. Same deterministic, non-AI summary so this
   button is useful with zero AI setup. */
$fallbackText = AIService::fallbackExplain($student_name, $levelLabel, $reasons);
echo json_encode(['ok' => true, 'text' => $fallbackText, 'source' => 'fallback']);

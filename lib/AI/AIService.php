<?php

require_once __DIR__ . '/AIConfig.php';

/* -----------------------------------------------------------------
   NEW FILE — the single choke point every AI feature in this project
   calls through. No feature-specific PHP file talks to the Anthropic
   API directly; they all go through AIService::complete(). That
   means:

     - One place enforces the "gracefully degrade, never fabricate"
       rule (see complete() below).
     - One place enforces the request/response size and timeout
       limits, so a stuck AI call can't hang a page indefinitely.
     - Swapping providers later only means changing this one file.

   FAILURE HANDLING: if the API key isn't configured, if the HTTP
   call fails, times out, or the response can't be parsed, complete()
   returns an AIResult with ok=false and a human-readable reason.
   Every calling page must check ->ok before using ->text — this is
   enforced by convention (grep for "->ok" before "->text" when
   reviewing new AI call sites), not by the type system, since PHP
   has no algebraic result type.
   ----------------------------------------------------------------- */

class AIResult
{
    public bool $ok;
    public string $text;
    public string $reason;

    public function __construct(bool $ok, string $text = '', string $reason = '')
    {
        $this->ok = $ok;
        $this->text = $text;
        $this->reason = $reason;
    }

    public static function success(string $text): AIResult
    {
        return new self(true, $text, '');
    }

    public static function failure(string $reason): AIResult
    {
        return new self(false, '', $reason);
    }
}

class AIService
{
    public static function isConfigured(): bool
    {
        return AIConfig::isConfigured();
    }

    /**
     * Send a single prompt to the configured AI provider and return
     * its text response. $systemPrompt should always instruct the
     * model to stay strictly within the provided context and never
     * invent data not present in it — every AI feature in this
     * project passes real, already-fetched database rows as context,
     * never lets the model query the database itself.
     */
    public static function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 700): AIResult
    {
        if (!self::isConfigured()) {
            return AIResult::failure('AI service is not configured.');
        }

        $apiKey = AIConfig::getApiKey();
        $model = AIConfig::getModel();

        $payload = json_encode([
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if (!function_exists('curl_init')) {
            return AIResult::failure('AI service is not configured (PHP curl extension unavailable on this server).');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return AIResult::failure('AI service request failed: ' . $curlError);
        }

        if ($httpCode !== 200) {
            return AIResult::failure('AI service returned an error (HTTP ' . $httpCode . ').');
        }

        $decoded = json_decode($response, true);
        $text = $decoded['content'][0]['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            return AIResult::failure('AI service returned an unexpected response format.');
        }

        return AIResult::success(trim($text));
    }

    /**
     * NEW (live-testing follow-up): a deterministic, non-AI fallback
     * explanation used ONLY when no API key is configured (see
     * AIConfig — this requires the user's own Anthropic API key,
     * which cannot be supplied by this codebase). Without this,
     * clicking "Explain" on every flagged student just showed "AI
     * service is not configured" with no useful information, even
     * though the underlying reasons were already known and correct.
     *
     * This is NOT an AI call — it is plain PHP string composition
     * from the exact same reasons list RiskAnalyzer already computed
     * (never invents anything not in $reasons), so it stays truthful
     * and useful with zero external dependency or configuration.
     * Callers must label this distinctly from a real AI response
     * (see ai_explain_risk.php's 'source' field) so nobody mistakes
     * it for a live model call.
     */
    public static function fallbackExplain(string $studentName, string $levelLabel, array $reasons): string
    {
        if (empty($reasons)) {
            return "{$studentName} has no specific risk signals on record right now.";
        }

        $lower = array_map(fn($r) => strtolower($r), $reasons);
        $hasLateSubmissions = (bool)array_filter($lower, fn($r) => str_contains($r, 'late submission'));
        $hasLowMarks = (bool)array_filter($lower, fn($r) => str_contains($r, 'marks below threshold') || str_contains($r, 'total marks'));
        $hasMissingReports = (bool)array_filter($lower, fn($r) => str_contains($r, 'missing') || str_contains($r, 'no weekly'));
        $hasRejected = (bool)array_filter($lower, fn($r) => str_contains($r, 'rejected'));
        $hasIncomplete = (bool)array_filter($lower, fn($r) => str_contains($r, 'incomplete'));

        $parts = [];
        if ($hasRejected) {
            $parts[] = "their internship application was rejected";
        }
        if ($hasIncomplete) {
            $parts[] = "their internship record is incomplete";
        }
        if ($hasMissingReports) {
            $parts[] = "one or more weekly reports are missing past the deadline";
        }
        if ($hasLateSubmissions) {
            $parts[] = "they have repeatedly submitted weekly reports late";
        }
        if ($hasLowMarks) {
            $parts[] = "their recorded marks are below the expected threshold";
        }

        if (empty($parts)) {
            // Reasons exist but didn't match a known phrase — fall back to a literal recap.
            return "{$studentName} was flagged as {$levelLabel} for: " . implode('; ', $reasons) . ".";
        }

        $sentence = "{$studentName} was flagged as {$levelLabel} because " . implode(', and ', $parts) . ".";
        $sentence .= " This is a rule-based summary of the reasons already listed — it may be worth a quick check-in.";

        return $sentence;
    }
}

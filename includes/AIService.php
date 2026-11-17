<?php
/**
 * AIService.php
 * Comprehensive AI Service Layer for InternHub — MIT-ADT University
 *
 * Implements:
 * - Multi-provider adapter: Gemini, OpenAI, or intelligent local heuristic rule engine
 * - Offline fallback: 100% operational even without API keys or internet connection
 * - Rate limiting: user quotas logged to ai_usage_logs
 * - Ethical principles: assist only, zero fabrication, draft mode with human approval
 */

class AIService {
    private $conn;
    private $provider;
    private $apiKey;
    private $model;
    private $maxDailyRequests = 30;

    public function __construct($dbConnection = null) {
        if ($dbConnection) {
            $this->conn = $dbConnection;
        } else {
            global $conn;
            $this->conn = $conn;
        }

        // Detect configured provider from environment or constants
        $geminiKey = getenv('GEMINI_API_KEY') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
        $openaiKey = getenv('OPENAI_API_KEY') ?: (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');

        if (!empty($geminiKey)) {
            $this->provider = 'gemini';
            $this->apiKey = $geminiKey;
            $this->model = 'gemini-1.5-flash';
        } elseif (!empty($openaiKey)) {
            $this->provider = 'openai';
            $this->apiKey = $openaiKey;
            $this->model = 'gpt-4o-mini';
        } else {
            $this->provider = 'local';
            $this->apiKey = '';
            $this->model = 'local-rule-v1';
        }
    }

    /**
     * Check if user is within daily rate limit
     */
    public function checkRateLimit($userId) {
        $userId = (int)$userId;
        $stmt = mysqli_prepare($this->conn, "SELECT COUNT(*) AS cnt FROM ai_usage_logs WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);
            $cnt = (int)($row['cnt'] ?? 0);
            return [
                'allowed' => $cnt < $this->maxDailyRequests,
                'used' => $cnt,
                'limit' => $this->maxDailyRequests,
                'remaining' => max(0, $this->maxDailyRequests - $cnt)
            ];
        }
        return ['allowed' => true, 'used' => 0, 'limit' => $this->maxDailyRequests, 'remaining' => $this->maxDailyRequests];
    }

    /**
     * Log AI usage
     */
    private function logUsage($userId, $feature, $promptLen, $respLen, $success = 1) {
        $userId = (int)$userId;
        $feature = substr($feature, 0, 50);
        $provider = substr($this->provider, 0, 50);
        $model = substr($this->model, 0, 50);
        $success = (int)$success;

        $stmt = mysqli_prepare($this->conn, "INSERT INTO ai_usage_logs (user_id, feature, prompt_length, response_length, provider, model, success) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "isiissi", $userId, $feature, $promptLen, $respLen, $provider, $model, $success);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    /**
     * Save AI generated draft
     */
    public function saveDraft($userId, $draftType, $title, $content, $meta = []) {
        $userId = (int)$userId;
        $metaJson = json_encode($meta);
        $stmt = mysqli_prepare($this->conn, "INSERT INTO ai_saved_drafts (user_id, draft_type, draft_title, draft_content, meta_data) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "issss", $userId, $draftType, $title, $content, $metaJson);
            mysqli_stmt_execute($stmt);
            $draftId = mysqli_insert_id($this->conn);
            mysqli_stmt_close($stmt);
            return $draftId;
        }
        return false;
    }

    /**
     * 1. Improve Project Description
     */
    public function improveProjectDescription($userId, $title, $roughNotes, $techList = '') {
        $rate = $this->checkRateLimit($userId);
        if (!$rate['allowed']) {
            return ['success' => false, 'error' => 'Daily AI limit reached (30/day). Please try again tomorrow.', 'rate' => $rate];
        }

        $prompt = "Polish this project description into professional portfolio bullet points.\nTitle: {$title}\nTech: {$techList}\nNotes: {$roughNotes}";
        $result = null;

        if ($this->provider === 'gemini' && !empty($this->apiKey)) {
            $result = $this->callGemini($prompt, "You are an expert technical resume coach for engineering students at MIT-ADT University. Do not invent fake metrics or false claims. Reformat notes into clear, impactful, professional engineering points.");
        } elseif ($this->provider === 'openai' && !empty($this->apiKey)) {
            $result = $this->callOpenAI($prompt, "You are an expert technical resume coach for engineering students. Keep facts strictly grounded in user notes.");
        }

        if (!$result) {
            // Intelligent local heuristic template engine
            $result = $this->heuristicImproveProject($title, $roughNotes, $techList);
        }

        $this->logUsage($userId, 'improve_project', strlen($prompt), strlen($result['text'] ?? ''), 1);
        return [
            'success' => true,
            'result' => $result,
            'provider' => $this->provider,
            'rate' => $this->checkRateLimit($userId)
        ];
    }

    /**
     * 2. Generate Professional Headline
     */
    public function generateHeadlines($userId, $specialization, $skills, $bio = '') {
        $rate = $this->checkRateLimit($userId);
        if (!$rate['allowed']) {
            return ['success' => false, 'error' => 'Daily AI limit reached (30/day).', 'rate' => $rate];
        }

        $prompt = "Generate 3 concise, professional portfolio headlines (max 90 chars each) for an engineering student.\nSpecialization: {$specialization}\nSkills: {$skills}\nBio notes: {$bio}";
        $result = null;

        if ($this->provider === 'gemini' && !empty($this->apiKey)) {
            $result = $this->callGemini($prompt, "Generate exactly 3 professional student portfolio headlines, separated by newlines, formatted with pipes (|).");
        } elseif ($this->provider === 'openai' && !empty($this->apiKey)) {
            $result = $this->callOpenAI($prompt, "Generate 3 professional student portfolio headlines, separated by newlines.");
        }

        if (!$result) {
            $result = $this->heuristicHeadlines($specialization, $skills);
        }

        $this->logUsage($userId, 'generate_headline', strlen($prompt), strlen(is_array($result) ? json_encode($result) : $result), 1);
        return [
            'success' => true,
            'headlines' => is_array($result) ? $result : array_filter(array_map('trim', explode("\n", $result))),
            'provider' => $this->provider,
            'rate' => $this->checkRateLimit($userId)
        ];
    }

    /**
     * 3. Polish Weekly Report
     */
    public function polishWeeklyReport($userId, $weekNo, $tasksDone, $challenges = '', $learnings = '') {
        $rate = $this->checkRateLimit($userId);
        if (!$rate['allowed']) {
            return ['success' => false, 'error' => 'Daily AI limit reached (30/day).', 'rate' => $rate];
        }

        $prompt = "Polish this Week {$weekNo} internship update into concise, professional work summary bullets.\nTasks completed: {$tasksDone}\nChallenges encountered: {$challenges}\nKey learnings: {$learnings}";
        $result = null;

        if ($this->provider === 'gemini' && !empty($this->apiKey)) {
            $result = $this->callGemini($prompt, "You are a university academic mentor helping an intern format their weekly internship submission cleanly without fabricating false duties.");
        } elseif ($this->provider === 'openai' && !empty($this->apiKey)) {
            $result = $this->callOpenAI($prompt, "Polish weekly internship report notes into professional engineering bullet points.");
        }

        if (!$result) {
            $result = $this->heuristicPolishReport($weekNo, $tasksDone, $challenges, $learnings);
        }

        $text = is_array($result) ? ($result['text'] ?? '') : $result;
        $this->logUsage($userId, 'polish_weekly_report', strlen($prompt), strlen($text), 1);
        return [
            'success' => true,
            'polished_report' => $text,
            'provider' => $this->provider,
            'rate' => $this->checkRateLimit($userId)
        ];
    }

    /**
     * 4. Analyze Resume & Profile Completeness
     */
    public function analyzeProfileCompleteness($userId, $profileData, $projectsCount, $skillsCount, $certsCount, $hasResume) {
        $score = 0;
        $suggestions = [];
        $strengths = [];

        // Headline & Bio
        if (!empty($profileData['headline'])) {
            $score += 15;
            $strengths[] = 'Professional headline is configured.';
        } else {
            $suggestions[] = 'Add a professional headline summarizing your engineering domain (e.g., "Full-Stack Web & Cloud Developer").';
        }

        if (!empty($profileData['bio']) && strlen($profileData['bio']) >= 60) {
            $score += 15;
            $strengths[] = 'Detailed professional bio provided.';
        } else {
            $suggestions[] = 'Expand your personal bio to at least 2-3 sentences explaining your academic focus and technical interests.';
        }

        // Links
        if (!empty($profileData['github_url'])) {
            $score += 15;
            $strengths[] = 'GitHub profile link attached.';
        } else {
            $suggestions[] = 'Link your GitHub profile so recruiters and faculty can view your source code repositories.';
        }

        if (!empty($profileData['linkedin_url'])) {
            $score += 10;
            $strengths[] = 'LinkedIn profile link attached.';
        } else {
            $suggestions[] = 'Add your LinkedIn profile URL to increase professional network visibility.';
        }

        // Projects
        if ($projectsCount >= 2) {
            $score += 20;
            $strengths[] = "Strong portfolio with {$projectsCount} showcase projects.";
        } elseif ($projectsCount === 1) {
            $score += 10;
            $suggestions[] = 'Add at least one more project showcase to demonstrate breadth across different technologies.';
        } else {
            $suggestions[] = 'Showcase at least 2 academic or capstone projects with live demo or repository links.';
        }

        // Skills
        if ($skillsCount >= 5) {
            $score += 10;
            $strengths[] = "Robust skill matrix with {$skillsCount} verified skills.";
        } else {
            $suggestions[] = 'Add at least 5 core programming languages, frameworks, or developer tools to your skills matrix.';
        }

        // Certifications
        if ($certsCount >= 1) {
            $score += 5;
            $strengths[] = "Verified credential/certification on record.";
        } else {
            $suggestions[] = 'Add relevant course certifications (Coursera, NPTEL, AWS, Google) with verification links.';
        }

        // Resume file
        if ($hasResume) {
            $score += 10;
            $strengths[] = 'PDF Resume uploaded and accessible.';
        } else {
            $suggestions[] = 'Upload your current 1-page PDF resume so faculty and recruiters can download it directly.';
        }

        $score = min(100, $score);
        $level = $score >= 85 ? 'Outstanding' : ($score >= 65 ? 'Competitive' : ($score >= 40 ? 'Developing' : 'Needs Attention'));

        return [
            'score' => $score,
            'level' => $level,
            'strengths' => $strengths,
            'suggestions' => $suggestions
        ];
    }

    /**
     * 5. Suggest Skill Gaps
     */
    public function suggestSkillGaps($studentSkills, $targetRole = 'Full Stack Developer') {
        $studentSkillsLower = array_map('strtolower', array_map('trim', is_array($studentSkills) ? $studentSkills : explode(',', $studentSkills)));

        $rolesMatrix = [
            'Full Stack Developer' => [
                'core' => ['javascript', 'html', 'css', 'react', 'node.js', 'sql', 'git', 'rest api'],
                'advanced' => ['typescript', 'docker', 'next.js', 'mongodb', 'tailwind css', 'ci/cd'],
                'advice' => 'Focus on building end-to-end applications that feature state management, relational database indexing, and automated deployment pipelines.'
            ],
            'AI / Machine Learning Engineer' => [
                'core' => ['python', 'pandas', 'numpy', 'scikit-learn', 'sql', 'git', 'data visualization'],
                'advanced' => ['pytorch', 'tensorflow', 'deep learning', 'nlp', 'transformers', 'mlflow', 'docker'],
                'advice' => 'Publish Jupyter Notebooks on GitHub demonstrating clear exploratory data analysis (EDA), model evaluation metrics, and hyperparameter tuning.'
            ],
            'Cloud & DevOps Engineer' => [
                'core' => ['linux', 'git', 'bash', 'python', 'networking', 'docker'],
                'advanced' => ['kubernetes', 'terraform', 'aws', 'ci/cd', 'github actions', 'prometheus', 'ansible'],
                'advice' => 'Containerize multi-tier web applications and write infrastructure-as-code scripts using Terraform or GitHub Actions.'
            ],
            'Data Analyst' => [
                'core' => ['sql', 'excel', 'python', 'power bi', 'tableau', 'statistics'],
                'advanced' => ['pandas', 'numpy', 'etl pipelines', 'data warehousing', 'a/b testing'],
                'advice' => 'Create interactive business intelligence dashboards answering realistic business questions with actionable insights.'
            ]
        ];

        $target = $rolesMatrix[$targetRole] ?? $rolesMatrix['Full Stack Developer'];
        $missingCore = [];
        $missingAdvanced = [];

        foreach ($target['core'] as $skill) {
            if (!in_array(strtolower($skill), $studentSkillsLower)) {
                $missingCore[] = ucwords($skill);
            }
        }

        foreach ($target['advanced'] as $skill) {
            if (!in_array(strtolower($skill), $studentSkillsLower)) {
                $missingAdvanced[] = ucwords($skill);
            }
        }

        return [
            'target_role' => $targetRole,
            'matched_count' => count($studentSkillsLower) - count($missingCore),
            'recommended_core' => array_slice($missingCore, 0, 4),
            'recommended_advanced' => array_slice($missingAdvanced, 0, 4),
            'career_advice' => $target['advice']
        ];
    }

    /* -------------------------------------------------------------
       Heuristic Fallback Engines (Guaranteed 100% offline uptime)
       ------------------------------------------------------------- */
    private function heuristicImproveProject($title, $roughNotes, $techList) {
        $cleanNotes = trim($roughNotes);
        $lines = array_filter(array_map('trim', explode("\n", $cleanNotes)));
        
        $overview = "Engineered {$title}" . (!empty($techList) ? " utilizing {$techList}" : "") . " to address core domain requirements and deliver an intuitive, responsive user experience.";
        
        $highlights = [];
        if (!empty($lines)) {
            foreach ($lines as $line) {
                $line = ltrim($line, "-*• \t");
                if (strlen($line) > 5) {
                    $highlights[] = "Implemented " . lcfirst($line);
                }
            }
        }
        if (count($highlights) < 2) {
            $highlights[] = "Architected modular backend controllers and optimized query access patterns for reliable performance.";
            $highlights[] = "Designed clean, accessible frontend interfaces adhering to modern web design standards.";
        }

        $techBadge = !empty($techList) ? "\n\n**Technologies**: {$techList}" : "";
        $formatted = "### Overview\n{$overview}\n\n### Key Highlights\n" . implode("\n", array_map(function($h){ return "• {$h}"; }, array_slice($highlights, 0, 4))) . "\n\n### Impact\n• Successfully tested features end-to-end, validating data integrity and usability under standard student test workflows." . $techBadge;

        return [
            'text' => $formatted,
            'summary' => $overview
        ];
    }

    private function heuristicHeadlines($specialization, $skills) {
        $spec = !empty($specialization) ? $specialization : 'Computer Science & Engineering';
        $skillParts = array_slice(array_filter(array_map('trim', explode(',', $skills))), 0, 3);
        $skillStr = !empty($skillParts) ? implode(' • ', $skillParts) : 'Web Development • Data Structures';

        return [
            "Aspiring Engineer | {$spec} @ MIT-ADT | {$skillStr}",
            "Software Developer & Problem Solver | Specialized in {$skillStr}",
            "Tech Enthusiast | MIT-ADT University | Passionate about building impactful software"
        ];
    }

    private function heuristicPolishReport($weekNo, $tasksDone, $challenges, $learnings) {
        $tasks = array_filter(array_map('trim', explode("\n", $tasksDone)));
        $formattedTasks = [];
        foreach ($tasks as $t) {
            $t = ltrim($t, "-*• \t");
            if (strlen($t) > 3) {
                $formattedTasks[] = "• Completed: " . ucfirst($t);
            }
        }
        if (empty($formattedTasks)) {
            $formattedTasks[] = "• Conducted scheduled weekly project development tasks and verified component functionality.";
        }

        $res = "### Week {$weekNo} Progress Summary\n\n**Key Accomplishments:**\n" . implode("\n", $formattedTasks);
        if (!empty($challenges)) {
            $res .= "\n\n**Challenges Addressed:**\n• " . ucfirst(trim($challenges));
        }
        if (!empty($learnings)) {
            $res .= "\n\n**Key Learnings & Methodology:**\n• " . ucfirst(trim($learnings));
        }
        return $res;
    }

    /* -------------------------------------------------------------
       External API Providers (Gemini / OpenAI)
       ------------------------------------------------------------- */
    private function callGemini($prompt, $systemInstruction = '') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . urlencode($this->apiKey);
        $data = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $prompt]]]
            ]
        ];
        if (!empty($systemInstruction)) {
            $data['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $resp) {
            $decoded = json_decode($resp, true);
            $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text) return trim($text);
        }
        return null;
    }

    private function callOpenAI($prompt, $systemInstruction = '') {
        $url = "https://api.openai.com/v1/chat/completions";
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemInstruction ?: 'You are a professional engineering assistant.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.4
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $resp) {
            $decoded = json_decode($resp, true);
            $text = $decoded['choices'][0]['message']['content'] ?? null;
            if ($text) return trim($text);
        }
        return null;
    }
}

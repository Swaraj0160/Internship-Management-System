<?php
/* -----------------------------------------------------------------
   NEW FILE — AI configuration loader.

   SECURITY RULE: the API key is NEVER hardcoded in any PHP file, and
   NEVER exposed to browser JavaScript. It is read from one of two
   places, in this order:

     1. An environment variable: AI_API_KEY (preferred — set this in
        Hostinger's hPanel under Advanced -> PHP Configuration /
        Environment Variables if your plan supports it, or in a
        server-level .htaccess / php.ini `SetEnv` directive).

     2. A config file OUTSIDE public_html:
        one directory above public_html, i.e. at the same level as
        public_html itself: /home/<hostinger-user>/ai_config.php
        This keeps the key out of the web-servable document root
        entirely — even a server misconfiguration that exposes PHP
        source could not leak it, because it isn't inside
        public_html at all.

   If neither is present, AIService::isConfigured() returns false and
   every AI feature in the project degrades to a plain
   "AI service is not configured" message — nothing fabricates a
   response, nothing pretends to call an AI that isn't there.

   See AI_SETUP.md for exact setup steps.
   ----------------------------------------------------------------- */

class AIConfig
{
    private static ?string $cachedKey = null;
    private static bool $checked = false;

    public static function getApiKey(): ?string
    {
        if (self::$checked) {
            return self::$cachedKey;
        }
        self::$checked = true;

        // 1. Environment variable
        $envKey = getenv('AI_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            self::$cachedKey = trim($envKey);
            return self::$cachedKey;
        }

        // 2. Config file one directory above public_html (outside the
        //    web-servable document root).
        $outsideConfig = __DIR__ . '/../../../ai_config.php';
        if (is_file($outsideConfig)) {
            $loaded = include $outsideConfig;
            // Support either `return ['api_key' => '...'];` or a
            // defined constant AI_API_KEY inside that file.
            if (is_array($loaded) && !empty($loaded['api_key'])) {
                self::$cachedKey = trim((string)$loaded['api_key']);
                return self::$cachedKey;
            }
            if (defined('AI_API_KEY') && trim(AI_API_KEY) !== '') {
                self::$cachedKey = trim(AI_API_KEY);
                return self::$cachedKey;
            }
        }

        self::$cachedKey = null;
        return null;
    }

    public static function isConfigured(): bool
    {
        return self::getApiKey() !== null;
    }

    /** Model name — overridable via AI_MODEL env var, sensible default otherwise. */
    public static function getModel(): string
    {
        $envModel = getenv('AI_MODEL');
        if (is_string($envModel) && trim($envModel) !== '') {
            return trim($envModel);
        }
        return 'claude-3-5-sonnet-latest';
    }
}

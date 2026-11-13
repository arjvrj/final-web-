<?php
/**
 * ai_key.php — the AI key, baked into the server.
 *
 * Put a provider key in the array below and the app uses it without anyone
 * typing anything in the owner panel. A key saved in the panel always wins over
 * this one, so you can still change or remove it there later.
 *
 * Safety:
 *   - this file lives under includes/, which .htaccess answers with 404 for a
 *     browser request, so nobody can download it;
 *   - it RETURNS an array and prints nothing — opening it in a browser yields an
 *     empty page even if the .htaccess rule is missing;
 *   - the key is never sent to a page, never put in a JSON response and never
 *     written to a log (the logger redacts it);
 *   - leave a provider out, or use '', to disable it.
 *
 * Whoever holds this file holds the key. Treat the ZIP like a password: do not
 * put this file in a public repository or share the package with the key inside.
 * If the package has been passed around, rotate the key at
 * https://console.groq.com/keys and paste the new one here.
 */

/* Direct request to this file from a browser: nothing to see. */
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    exit;
}

return [
    /* Groq — https://console.groq.com/keys */
    /* Empty in the repository and the ZIP. Paste your own key here on the
       server, or save it in Owner panel → Settings. Never commit a real key. */
    'groq' => '',

    /* xAI Grok — https://console.x.ai  (leave empty unless you use Grok) */
    'xai'  => '',
];

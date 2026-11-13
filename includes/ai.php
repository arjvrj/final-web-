<?php
/**
 * ai.php — AI review generation. One code path for every caller.
 *
 * Why this file exists
 * --------------------
 * review.php (the customer page) and includes/functions.php each carried their
 * own copy of the same cURL call. They had already drifted: different models,
 * different request bodies, different error text. Both pointed at api.groq.com,
 * which is Groq — a different company from xAI's Grok. A key issued by
 * console.x.ai therefore came back 401 from every request, and the customer page
 * turned that into "Could not reach AI service", which is why the failure looked
 * like a broken integration rather than a wrong endpoint.
 *
 * Everything AI-related now goes through this file:
 *
 *   rg_ai_config()            what to call, with which key, never echoing it
 *   rg_ai_request()           the only place cURL is used
 *   rg_ai_chat()              one chat completion
 *   rg_ai_generate_reviews()   the customer flow
 *   rg_ai_test_connection()    the owner's "Test connection" button
 *
 * The HTTP transport is injectable (the same idiom as rg_razorpay_request in
 * includes/payments.php), so every failure branch is testable without a network
 * and without a real key.
 *
 * Providers
 * ---------
 *   groq — https://api.groq.com/openai/v1   keys start gsk_
 *   xai  — https://api.x.ai/v1              keys start xai-   (this is "Grok")
 *
 * The active provider is the `ai_provider` setting. groq is the default, so an
 * existing install behaves exactly as before until someone changes it.
 *
 * Nothing in this file ever returns the API key. rg_ai_config() is internal-use
 * only; every function that builds a public/admin response copies the fields it
 * needs and leaves api_key behind.
 */

/** How many drafts the customer flow asks for. */
const RG_AI_MAX_DRAFTS = 3;

/** Default request limits, in seconds. */
const RG_AI_DEFAULT_TIMEOUT = 25;
const RG_AI_DEFAULT_CONNECT_TIMEOUT = 6;

/**
 * The two providers, as data. `models` is only a suggestion list for the admin
 * datalist — any model string is allowed, because providers add and retire
 * models faster than this file changes.
 */
function rg_ai_providers(): array
{
    return [
        'groq' => [
            'label'     => 'Groq',
            'base'      => 'https://api.groq.com/openai/v1',
            'key_hint'  => 'gsk_',
            'console'   => 'https://console.groq.com/keys',
            'model'     => 'openai/gpt-oss-20b',
            'json_mode' => true,
            'models'    => [
                'openai/gpt-oss-20b',          // fast, cheap, good writing — default
                'openai/gpt-oss-120b',         // heavier, nicer prose, slower
                'qwen/qwen3.8-27b',
                'allam-2-7b',
            ],
        ],
        'xai' => [
            'label'     => 'xAI Grok',
            'base'      => 'https://api.x.ai/v1',
            'key_hint'  => 'xai-',
            'console'   => 'https://console.x.ai/team/default/api-keys',
            'model'     => 'grok-4-1-fast-non-reasoning',
            'json_mode' => false,          // not guaranteed on every Grok model
            'models'    => [
                'grok-4-1-fast-non-reasoning',
                'grok-4-1-fast-reasoning',
                'grok-4-fast-non-reasoning',
                'grok-3-mini',
                'grok-3',
            ],
        ],
    ];
}

/** Metadata for one provider, falling back to groq for anything unknown. */
function rg_ai_provider_meta(string $provider): array
{
    $all = rg_ai_providers();
    $key = strtolower(trim($provider));
    return $all[$key] ?? $all['groq'];
}

/**
 * Models the providers have retired. A site upgraded from an older build can
 * easily have one of these saved in the settings table; the provider then answers
 * "model does not exist" and nothing works, even though the default is fine.
 */
function rg_ai_retired_models(): array
{
    return [
        'llama-3.1-8b-instant',
        'llama-3.3-70b-versatile',
        'llama3-8b-8192',
        'llama3-70b-8192',
        'llama-3.2-1b-preview',
        'llama-3.2-3b-preview',
        'mixtral-8x7b-32768',
        'gemma2-9b-it',
        'gemma-7b-it',
    ];
}

/** True when the provider has taken this model away. */
function rg_ai_model_retired(string $model): bool
{
    $model = strtolower(trim($model));
    foreach (rg_ai_retired_models() as $gone) {
        if ($model === strtolower($gone)) return true;
    }
    return false;
}

/**
 * Reasoning models (gpt-oss, qwen3, grok-*-reasoning) think before they answer,
 * and those thinking tokens are billed against max_tokens. With a small budget
 * the reply comes back with finish_reason=length and empty content — which looks
 * like "the provider returned no text" even though the call worked.
 */
function rg_ai_is_reasoning_model(string $model): bool
{
    $model = strtolower($model);
    if (strpos($model, 'gpt-oss') !== false) return true;
    if (strpos($model, 'qwen3') !== false) return true;
    /* "grok-4-1-fast-reasoning" thinks; "…-non-reasoning" does not. */
    return strpos($model, '-reasoning') !== false && strpos($model, '-non-reasoning') === false;
}

/** Model suggestions for the admin datalist. */
function rg_ai_model_choices(string $provider): array
{
    return rg_ai_provider_meta($provider)['models'];
}

/**
 * Which provider an API key belongs to, judged by its public prefix.
 * Returns 'groq', 'xai' or '' when the prefix is not recognised.
 *
 * This exists to catch the exact mistake that produced this bug report: an xAI
 * key pasted into a field wired to Groq.
 */
function rg_ai_key_provider(string $key): string
{
    $key = trim($key);
    if ($key === '') return '';
    if (strpos($key, 'xai-') === 0) return 'xai';
    if (strpos($key, 'gsk_') === 0) return 'groq';
    // Groq keys have also been issued as bare base62 strings; xAI keys always
    // carry the xai- prefix, so an unprefixed key is treated as Groq.
    return 'groq';
}

/**
 * Reads the AI settings out of the key/value settings table.
 * Kept separate from rg_ai_config() so tests can build a config with no DB.
 */
function rg_ai_settings_from_db(): array
{
    $out = [];
    foreach ([
        'ai_provider', 'ai_model', 'groq_model', 'groq_api_key', 'grok_api_key',
        'ai_timeout', 'ai_connect_timeout',
    ] as $key) {
        $out[$key] = function_exists('getSetting') ? (string)getSetting($key, '') : '';
    }
    return rg_ai_merge_seed_keys($out, rg_ai_seed_keys());
}

/**
 * Keys baked into includes/ai_key.php, if that file exists.
 *
 * This is the "no typing in the panel" option: the owner drops the key into one
 * server-side file and the app uses it. The file sits under includes/, which
 * .htaccess answers 404 for, it returns an array and prints nothing, and the key
 * is never sent to a browser or written to a log.
 */
function rg_ai_seed_keys(): array
{
    $file = __DIR__ . '/ai_key.php';
    if (!is_file($file)) return [];
    $data = include $file;
    return is_array($data) ? $data : [];
}

/**
 * A key saved in the panel always wins over the baked-in one, so the owner can
 * change or clear a key without editing files. Pure: no file, no database.
 */
function rg_ai_merge_seed_keys(array $settings, array $seed): array
{
    $map = ['groq_api_key' => 'groq', 'grok_api_key' => 'xai'];
    foreach ($map as $setting => $provider) {
        $current = trim((string)($settings[$setting] ?? ''));
        $baked   = trim((string)($seed[$provider] ?? ''));
        if ($current === '' && $baked !== '') {
            $settings[$setting] = $baked;
        }
    }
    return $settings;
}

/**
 * Builds the call plan. Pure: no database, no network, no side effects.
 *
 * Returns api_key for internal use. Never json_encode() the whole array into a
 * response — use rg_ai_public_config() for anything that leaves the server.
 */
function rg_ai_config(array $settings, string $providerOverride = ''): array
{
    $requested = strtolower(trim($providerOverride !== '' ? $providerOverride : (string)($settings['ai_provider'] ?? '')));
    if ($requested === '' || !array_key_exists($requested, rg_ai_providers())) {
        $requested = 'groq';
    }
    $meta = rg_ai_provider_meta($requested);

    $keys = [
        'groq' => trim((string)($settings['groq_api_key'] ?? '')),
        'xai'  => trim((string)($settings['grok_api_key'] ?? '')),
    ];
    $apiKey = $keys[$requested] ?? '';

    $model = trim((string)($settings['ai_model'] ?? ''));
    if ($model === '' && $requested === 'groq') {
        $model = trim((string)($settings['groq_model'] ?? ''));   // the old setting, still honoured
    }
    /* A retired model saved by an older build would break every call, so it is
       replaced with the provider default and reported, never silently used. */
    $modelReplaced = '';
    if (rg_ai_model_retired($model)) {
        $modelReplaced = $model;
        $model = $meta['model'];
    }
    if ($model === '') {
        $model = $meta['model'];
    }

    $timeout = (int)($settings['ai_timeout'] ?? 0);
    if ($timeout <= 0) $timeout = RG_AI_DEFAULT_TIMEOUT;
    $timeout = max(5, min(60, $timeout));

    $connect = (int)($settings['ai_connect_timeout'] ?? 0);
    if ($connect <= 0) $connect = RG_AI_DEFAULT_CONNECT_TIMEOUT;
    $connect = max(2, min(20, $connect));

    $keyProvider = rg_ai_key_provider($apiKey);

    return [
        'provider'        => $requested,
        'label'           => $meta['label'],
        'base'            => rtrim($meta['base'], '/'),
        'console'         => $meta['console'],
        'key_hint'        => $meta['key_hint'],
        'api_key'         => $apiKey,
        'model'           => $model,
        'model_replaced_from' => $modelReplaced,
        'json_mode'       => (bool)$meta['json_mode'],
        'timeout'         => $timeout,
        'connect_timeout' => $connect,
        'configured'      => $apiKey !== '',
        'key_provider'    => $keyProvider,
        /* True when the key clearly belongs to the other provider. */
        'key_mismatch'    => $keyProvider !== '' && $keyProvider !== $requested,
    ];
}

/**
 * The parts of the config that are safe to show a page or return as JSON.
 * api_key is deliberately absent, not blanked — a missing key cannot leak.
 */
function rg_ai_public_config(array $cfg): array
{
    return [
        'provider'     => $cfg['provider'],
        'label'        => $cfg['label'],
        'model'        => $cfg['model'],
        'configured'   => (bool)$cfg['configured'],
        'key_provider' => $cfg['key_provider'],
        'key_mismatch' => (bool)$cfg['key_mismatch'],
        'timeout'      => (int)$cfg['timeout'],
    ];
}

/** Removes anything that looks like the key from a log line. */
function rg_ai_redact(string $text, string $key = ''): string
{
    if ($key !== '' && strlen($key) >= 6) {
        $text = str_replace($key, '[redacted]', $text);
    }
    // Belt and braces: catch keys this function was not told about.
    $text = preg_replace('/\b(xai-|gsk_)[A-Za-z0-9_\-]{8,}/', '$1[redacted]', $text);
    return (string)$text;
}

/** error_log that can never write a key to disk. */
function rg_ai_log(string $message, string $key = ''): void
{
    error_log('rg-ai: ' . rg_ai_redact($message, $key));
}

/**
 * Turns an HTTP status plus provider payload into one stable code.
 * These codes are what the UI, the logs and the tests all agree on.
 */
function rg_ai_classify(int $status, array $data, string $curlError = '', bool $curlMissing = false): array
{
    if ($curlMissing) {
        return ['code' => 'no_curl', 'message' => 'The PHP cURL extension is not enabled on this server.'];
    }

    if ($curlError !== '') {
        $lower = strtolower($curlError);
        if (strpos($lower, 'timeout') !== false || strpos($lower, 'timed out') !== false) {
            return ['code' => 'timeout', 'message' => 'The provider did not answer in time.'];
        }
        return ['code' => 'network', 'message' => 'Could not reach the provider (' . $curlError . ').'];
    }

    if ($status === 0) {
        return ['code' => 'network', 'message' => 'No HTTP response from the provider.'];
    }

    if ($status === 401 || $status === 403) {
        return ['code' => 'invalid_key', 'message' => 'The provider rejected the API key (HTTP ' . $status . ').'];
    }

    if ($status === 429) {
        return ['code' => 'rate_limited', 'message' => 'The provider is rate limiting this key (HTTP 429).'];
    }

    if ($status === 404) {
        return ['code' => 'model_unavailable', 'message' => 'The endpoint or model was not found (HTTP 404).'];
    }

    if ($status === 400) {
        $msg = strtolower((string)($data['error']['message'] ?? $data['message'] ?? $data['error'] ?? ''));
        if (strpos($msg, 'model') !== false) {
            return ['code' => 'model_unavailable', 'message' => 'That model is not available to this key.'];
        }
        return ['code' => 'bad_request', 'message' => 'The provider rejected the request (HTTP 400).'];
    }

    if ($status >= 500) {
        return ['code' => 'provider_error', 'message' => 'The provider failed with HTTP ' . $status . '.'];
    }

    if ($status >= 200 && $status < 300) {
        return ['code' => '', 'message' => ''];
    }

    return ['code' => 'http_error', 'message' => 'Unexpected HTTP ' . $status . '.'];
}

/**
 * The only place in the application that speaks HTTP to an AI provider.
 *
 * $transport, when given, receives (method, url, headers, bodyOrNull) and must
 * return ['status' => int, 'body' => string] — optionally 'error' => string to
 * simulate a transport-level failure such as a timeout. This is how the test
 * suite exercises 401 / 404 / 429 / 500 / timeout / malformed without a network.
 *
 * The returned 'message' has the API key redacted. The returned array never
 * contains the key.
 */
function rg_ai_request(array $cfg, string $method, string $path, ?array $body = null, ?callable $transport = null): array
{
    $url = $cfg['base'] . $path;
    $payload = $body === null ? null : json_encode($body);
    $headers = [
        'Authorization: Bearer ' . $cfg['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $curlError = '';
    $curlMissing = false;

    if ($transport !== null) {
        $raw = $transport($method, $url, $headers, $payload);
        $status = (int)($raw['status'] ?? 0);
        $text = (string)($raw['body'] ?? '');
        $curlError = (string)($raw['error'] ?? '');
    } elseif (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => (int)$cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);
        $text = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch) !== 0) {
            $curlError = (string)curl_error($ch);
            $status = 0;
        }
        curl_close($ch);
    } else {
        $curlMissing = true;
        $status = 0;
        $text = '';
    }

    $decoded = json_decode($text, true);
    $data = is_array($decoded) ? $decoded : [];

    $class = rg_ai_classify($status, $data, $curlError, $curlMissing);

    /* A 2xx that is not JSON is still a failure, not a success. */
    if ($class['code'] === '' && $decoded === null) {
        $class = ['code' => 'bad_response', 'message' => 'The provider answered with something that is not JSON.'];
    }
    if ($class['code'] === '' && !is_array($decoded)) {
        $class = ['code' => 'bad_response', 'message' => 'The provider answered with unexpected JSON.'];
    }

    $providerMessage = '';
    if (isset($data['error']['message'])) {
        $providerMessage = (string)$data['error']['message'];
    } elseif (isset($data['error']) && is_string($data['error'])) {
        $providerMessage = (string)$data['error'];
    }

    return [
        'ok'       => $class['code'] === '',
        'status'   => $status,
        'data'     => $data,
        'error'    => $class['code'] === '' ? null : $class['code'],
        'message'  => rg_ai_redact($providerMessage !== '' ? $providerMessage : $class['message'], $cfg['api_key']),
    ];
}

/**
 * One chat completion. Returns the message content, or a typed error.
 *
 * $opts: max_tokens, temperature, json (bool, default follows the provider),
 *        model (override).
 */
function rg_ai_chat(array $cfg, array $messages, array $opts = [], ?callable $transport = null): array
{
    if (!$cfg['configured']) {
        return ['ok' => false, 'error' => 'not_configured', 'message' => 'No API key is saved.', 'content' => ''];
    }

    $body = [
        'model'       => (string)($opts['model'] ?? $cfg['model']),
        'messages'    => $messages,
        'temperature' => (float)($opts['temperature'] ?? 0.7),
        'max_tokens'  => (int)($opts['max_tokens'] ?? 800),
        'stream'      => false,
    ];

    $jsonMode = array_key_exists('json', $opts) ? (bool)$opts['json'] : (bool)$cfg['json_mode'];
    if ($jsonMode) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    /* Keep the thinking short so the answer itself always fits in max_tokens. */
    if (rg_ai_is_reasoning_model((string)$body['model'])) {
        $body['reasoning_effort'] = (string)($opts['reasoning_effort'] ?? 'low');
    }

    $response = rg_ai_request($cfg, 'POST', '/chat/completions', $body, $transport);

    if (!$response['ok']) {
        rg_ai_log(
            sprintf('%s chat failed: %s (HTTP %d) %s', $cfg['label'], (string)$response['error'], (int)$response['status'], (string)$response['message']),
            $cfg['api_key']
        );
        return [
            'ok'      => false,
            'error'   => (string)$response['error'],
            'message' => (string)$response['message'],
            'content' => '',
            'status'  => (int)$response['status'],
        ];
    }

    $content = $response['data']['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        $finish = (string)($response['data']['choices'][0]['finish_reason'] ?? '');
        $usage  = is_array($response['data']['usage'] ?? null) ? $response['data']['usage'] : [];
        $thought = (int)($usage['completion_tokens_details']['reasoning_tokens'] ?? 0);
        rg_ai_log(sprintf(
            'empty completion from %s (finish_reason=%s, reasoning_tokens=%d, max_tokens=%d)',
            $cfg['label'], $finish, $thought, (int)$body['max_tokens']
        ), $cfg['api_key']);
        return [
            'ok'      => false,
            'error'   => 'bad_response',
            'message' => $finish === 'length'
                ? 'The model used up its token budget while thinking and never wrote an answer.'
                : 'The provider returned no text.',
            'content' => '',
            'status'  => (int)$response['status'],
        ];
    }

    return [
        'ok'      => true,
        'error'   => null,
        'message' => '',
        'content' => (string)$content,
        'status'  => (int)$response['status'],
        'usage'   => is_array($response['data']['usage'] ?? null) ? $response['data']['usage'] : [],
    ];
}

/** Human name of a language code. Unknown codes fall back to English. */
function rg_ai_language_name(string $code): string
{
    $map = [
        'en' => 'English',
        'hi' => 'Hindi, written in Devanagari script',
        'te' => 'Telugu, written in Telugu script',
    ];
    return $map[strtolower(trim($code))] ?? 'English';
}

/** The three languages the customer page offers. */
function rg_ai_languages(): array
{
    return ['en' => 'English', 'hi' => 'हिन्दी', 'te' => 'తెలుగు'];
}

/**
 * Builds the system + user messages for the customer flow.
 *
 * These rules are the product requirement, not decoration: the draft may only
 * contain what the customer said. Anything the model is tempted to add — a
 * dish, a staff name, a discount — is a fabricated fact attributed to a real
 * person, so the prompt forbids it twice (rule 1 and rule 2) and again in the
 * user turn.
 */
function rg_ai_build_prompt(array $input): array
{
    $business = trim((string)($input['business_name'] ?? ''));
    if ($business === '') $business = 'the business';
    $type     = trim((string)($input['business_type'] ?? ''));
    $rating   = max(1, min(5, (int)($input['rating'] ?? 5)));
    $details  = trim((string)($input['details'] ?? ''));
    $keywords = array_values(array_filter(array_map('trim', (array)($input['keywords'] ?? [])), function ($k) {
        return $k !== '';
    }));
    $count    = max(1, min(RG_AI_MAX_DRAFTS, (int)($input['count'] ?? RG_AI_MAX_DRAFTS)));
    $language = rg_ai_language_name((string)($input['language'] ?? 'en'));

    $system = implode("\n", [
        'You write drafts of customer reviews. The customer edits the draft and posts it themselves.',
        '',
        'Rules, in order of importance:',
        '1. Use ONLY the facts in the customer\'s own message and the topics they picked.',
        '2. Never invent anything: no products, dishes, treatments, rooms, staff names, prices,',
        '   discounts, offers, dates, waiting times or events. If the customer did not say it,',
        '   it does not go in the draft.',
        '3. Write in the first person, the way a real customer types. 1 to 3 sentences, 25 to 60 words.',
        '4. EVERY draft must contain at least two emojis, and never more than three. Pick ones that',
        '   match what the customer mentioned, and place them where a real person would — not bunched',
        '   together, and never as the first character. No star ratings, no hashtags, no quotation',
        '   marks around the draft, no headings.',
        '5. Avoid AI-sounding filler. Never use, or paraphrase: "I recently had the pleasure",',
        '   "In today\'s fast-paced world", "I cannot recommend ... enough", "a testament to",',
        '   "nestled in", "hidden gem", "the staff went above and beyond" (unless the customer',
        '   said so), "5 stars", "A+ experience", "look no further".',
        '6. Match the customer\'s own mood. If they are unhappy, keep the draft honest and calm.',
        '   Never add praise that is not in their message. Never argue with them.',
        '7. Write entirely in ' . $language . '.',
        '8. Reply with JSON only, exactly this shape: {"reviews":["draft one","draft two","draft three"]}',
    ]);

    $user = 'Business: ' . $business . ($type !== '' ? ' (' . $type . ')' : '') . "\n"
          . 'The customer chose ' . $rating . ' out of 5 stars.' . "\n"
          . 'Topics the customer picked: ' . ($keywords ? implode(', ', $keywords) : 'none') . "\n";

    if ($details !== '') {
        $user .= "What the customer wrote, in their own words:\n\"\"\"\n" . $details . "\n\"\"\"\n";
    } else {
        $user .= "The customer did not write anything.\n"
               . "Write short, neutral drafts that mention only the business name and the topics above.\n"
               . "Do not add any specific detail, because there is none to use.\n";
    }

    $user .= 'Write ' . $count . ' different drafts of the same review, each opening differently. '
           . 'Return only the JSON object described in the rules.';

    return [$system, $user];
}

/**
 * Reads draft text out of a model answer.
 *
 * Tolerant on purpose: providers differ in whether they honour JSON mode, and a
 * model that answers with three plain paragraphs is more useful than a hard
 * failure. Only genuinely unusable answers return an empty array.
 */
function rg_ai_parse_reviews(string $content, int $limit = RG_AI_MAX_DRAFTS): array
{
    $content = trim($content);
    if ($content === '') return [];

    $stripped = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $content);
    $stripped = trim((string)$stripped);

    $decoded = json_decode($stripped, true);
    if (!is_array($decoded) && preg_match('/\{[\s\S]*\}/', $stripped, $m)) {
        $decoded = json_decode($m[0], true);
    }

    $list = [];
    if (is_array($decoded)) {
        if (isset($decoded['reviews']) && is_array($decoded['reviews'])) {
            $list = $decoded['reviews'];
        } elseif (isset($decoded['drafts']) && is_array($decoded['drafts'])) {
            $list = $decoded['drafts'];
        } else {
            $first = reset($decoded);
            if (is_array($first)) {
                $list = $first;
            } elseif (is_string($first)) {
                $list = [$first];
            }
        }
    } else {
        /* Plain text: one draft per paragraph, if there are paragraphs. */
        $paragraphs = preg_split('/\n\s*\n/', $stripped) ?: [];
        $list = count($paragraphs) > 1 ? $paragraphs : [$stripped];
    }

    $out = [];
    foreach ($list as $item) {
        if (!is_string($item)) continue;
        $item = trim(preg_replace('/\s+/u', ' ', $item) ?? $item);
        $item = trim($item, " \t\n\r\0\x0B\"'");
        $length = function_exists('mb_strlen') ? mb_strlen($item, 'UTF-8') : strlen($item);
        if ($length < 10) continue;
        if ($length > 1200) {
            $item = function_exists('mb_substr') ? mb_substr($item, 0, 1200, 'UTF-8') : substr($item, 0, 1200);
        }
        $out[] = $item;
        if (count($out) >= $limit) break;
    }

    return $out;
}

/**
 * The emoji ranges a review draft can realistically use.
 *
 * Counted as whole clusters, so a family emoji joined with ZWJ, or an emoji with a
 * variation selector, counts once — not three times.
 */
function rg_ai_emoji_pattern(): string
{
    return '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}]\x{FE0F}?'
         . '(?:\x{200D}[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]\x{FE0F}?)*/u';
}

/** How many emojis a piece of text carries. */
function rg_ai_count_emojis(string $text): int
{
    return (int)preg_match_all(rg_ai_emoji_pattern(), $text);
}

/**
 * Guarantees the two-emoji rule in code, not only in the prompt.
 *
 * The prompt asks for two or three; models (small ones especially) drift and
 * sometimes answer with one. The draft is never re-worded — emojis are only added
 * at the end, or extras beyond the third are dropped — so nothing the customer said
 * is changed. An unhappy customer gets calmer emojis than a happy one.
 */
function rg_ai_ensure_emojis(string $text, int $min = 2, int $max = 3, int $rating = 5): string
{
    $pattern = rg_ai_emoji_pattern();
    $have    = rg_ai_count_emojis($text);

    if ($have > $max) {
        $seen = 0;
        $text = (string)preg_replace_callback($pattern, static function ($m) use (&$seen, $max) {
            $seen++;
            return $seen > $max ? '' : $m[0];
        }, $text);
        $text = trim((string)preg_replace('/\s{2,}/u', ' ', $text));
        $have = $max;
    }

    if (trim($text) === '') return $text;
    if ($have >= $min) return $text;

    /* Calm set for 1–3 stars, so a complaint never ends in party emojis. */
    $pool = $rating <= 3
        ? ['🙂', '🙏', '👌', '😕']
        : ['😊', '👍', '✨', '🙌', '💚'];

    $present = [];
    preg_match_all($pattern, $text, $found);
    foreach ($found[0] as $emoji) $present[$emoji] = true;

    $add = [];
    foreach ($pool as $emoji) {
        if (count($add) >= $min - $have) break;
        if (isset($present[$emoji])) continue;
        $add[] = $emoji;
    }
    /* Pool exhausted (rare): reuse from the start rather than leave a draft short. */
    foreach ($pool as $emoji) {
        if (count($add) >= $min - $have) break;
        $add[] = $emoji;
    }

    /* If the draft already ends on an emoji, keep the pair tight (😊👍) instead
       of spacing it out — that is how people actually type it. */
    $tailPattern = substr(rg_ai_emoji_pattern(), 0, -2) . '$/u'; // drop the trailing "/u"
    $endsOnEmoji = preg_match($tailPattern, rtrim($text)) === 1;
    return rtrim($text) . ($endsOnEmoji ? '' : ' ') . implode('', $add);
}

/**
 * The customer flow. Returns ['ok' => true, 'reviews' => [...]] or a typed error.
 */
function rg_ai_generate_reviews(array $cfg, array $input, ?callable $transport = null): array
{
    if (!$cfg['configured']) {
        return ['ok' => false, 'error' => 'not_configured', 'message' => 'No AI key is saved.'];
    }
    if ($cfg['key_mismatch']) {
        rg_ai_log(sprintf(
            'key looks like %s but provider is %s — requests will be rejected until they agree',
            $cfg['key_provider'],
            $cfg['provider']
        ), $cfg['api_key']);
    }

    [$system, $user] = rg_ai_build_prompt($input);

    $chat = rg_ai_chat($cfg, [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => $user],
    ], ['max_tokens' => 1600, 'temperature' => 0.7, 'reasoning_effort' => 'low'], $transport);

    if (!$chat['ok']) {
        return [
            'ok'      => false,
            'error'   => (string)$chat['error'],
            'message' => (string)$chat['message'],
            'provider' => $cfg['provider'],
            'model'   => $cfg['model'],
        ];
    }

    $reviews = rg_ai_parse_reviews($chat['content'], RG_AI_MAX_DRAFTS);

    /* Two to three emojis per draft — enforced here so it cannot depend on the
       model having read rule 4. */
    $rating = (int)($input['rating'] ?? 5);
    foreach ($reviews as $i => $draft) {
        $reviews[$i] = rg_ai_ensure_emojis($draft, 2, 3, $rating);
    }
    if (!$reviews) {
        rg_ai_log('model answer contained no usable draft: ' . substr($chat['content'], 0, 200), $cfg['api_key']);
        return [
            'ok'      => false,
            'error'   => 'bad_response',
            'message' => 'The AI answer could not be read as review drafts.',
            'provider' => $cfg['provider'],
            'model'   => $cfg['model'],
        ];
    }

    return [
        'ok'       => true,
        'reviews'  => $reviews,
        'provider' => $cfg['provider'],
        'model'    => $cfg['model'],
        'usage'    => $chat['usage'] ?? [],
    ];
}

/**
 * The owner's "Test connection" button.
 *
 * Two steps, because they fail for different reasons and the owner needs to know
 * which: GET /models proves the key is accepted and says whether the chosen model
 * is offered; a tiny completion then proves the model actually answers.
 *
 * Never returns the key. 'latency_ms' is measured, not guessed.
 */
function rg_ai_test_connection(array $cfg, ?callable $transport = null): array
{
    $started = microtime(true);

    $base = [
        'provider'     => $cfg['provider'],
        'label'        => $cfg['label'],
        'model'        => $cfg['model'],
        'key_provider' => $cfg['key_provider'],
        'key_mismatch' => (bool)$cfg['key_mismatch'],
        'latency_ms'   => 0,
    ];

    if (!$cfg['configured']) {
        return $base + [
            'ok'      => false,
            'code'    => 'not_configured',
            'message' => 'No key is saved for ' . $cfg['label'] . '. Add one below, then test again.',
        ];
    }

    /* ---- step 1: is the key accepted, and is the model offered? ---- */
    $models = rg_ai_request($cfg, 'GET', '/models', null, $transport);

    $available = [];
    foreach (($models['data']['data'] ?? []) as $row) {
        if (is_array($row) && isset($row['id']) && is_string($row['id'])) {
            $available[] = $row['id'];
        }
    }
    $modelInList = $available ? in_array($cfg['model'], $available, true) : null;

    if (!$models['ok'] && in_array($models['error'], ['invalid_key', 'no_curl', 'network', 'timeout'], true)) {
        $base['latency_ms'] = (int)round((microtime(true) - $started) * 1000);
        return $base + [
            'ok'      => false,
            'code'    => (string)$models['error'],
            'message' => rg_ai_test_message((string)$models['error'], $cfg, (string)$models['message']),
        ];
    }

    /* ---- step 2: can the chosen model actually answer? ---- */
    $chat = rg_ai_chat($cfg, [
        ['role' => 'user', 'content' => 'Reply with the single word OK and nothing else.'],
    ], ['max_tokens' => 64, 'temperature' => 0.0, 'json' => false, 'reasoning_effort' => 'low'], $transport);

    $latency = (int)round((microtime(true) - $started) * 1000);
    $base['latency_ms'] = $latency;
    $base['model_in_list'] = $modelInList;
    $base['models_seen'] = count($available);

    if (!$chat['ok']) {
        $code = (string)$chat['error'];
        /* /models can 404 on some gateways while chat still works, so only the
           chat failure decides the verdict. */
        return $base + [
            'ok'      => false,
            'code'    => $code,
            'message' => rg_ai_test_message($code, $cfg, (string)$chat['message']),
        ];
    }

    $answer = trim((string)$chat['content']);
    $base['ok'] = true;
    $base['code'] = 'ok';
    $base['message'] = sprintf(
        'Connection successful. %s answered with the model %s in %d ms%s.',
        $cfg['label'],
        $cfg['model'],
        $latency,
        $modelInList === false ? ' — note: that model was not in the model list, so double-check the name' : ''
    );
    /* If an old build left a retired model in the settings table, say so
       instead of letting the owner wonder why the panel shows a different name. */
    if (!empty($cfg['model_replaced_from'])) {
        $base['message'] .= ' The model saved in your settings ("' . $cfg['model_replaced_from']
            . '") has been retired by ' . $cfg['label'] . ', so this test used the default '
            . $cfg['model'] . '. Press Save on this page to store the new model.';
        $base['model_replaced_from'] = $cfg['model_replaced_from'];
    }

    $base['sample'] = (function_exists('mb_substr') ? mb_substr($answer, 0, 40, 'UTF-8') : substr($answer, 0, 40));

    return $base;
}

/**
 * One clear sentence per failure code. This is the wording the owner sees.
 */
function rg_ai_test_message(string $code, array $cfg, string $detail = ''): string
{
    $hint = '';
    if (!empty($cfg['key_mismatch'])) {
        $otherMeta  = rg_ai_provider_meta($cfg['key_provider']);
        $otherLabel = $otherMeta['label'];
        $hint = ' This key looks like a ' . $otherLabel . ' key: it starts with '
              . $otherMeta['key_hint'] . ', while ' . $cfg['label'] . ' keys start with '
              . $cfg['key_hint'] . '. Switch the provider to ' . $otherLabel . '.';
    }

    switch ($code) {
        case 'ok':
            return 'Connection successful.';
        case 'not_configured':
            return 'No key is saved for ' . $cfg['label'] . '. Add one, save, then test again.';
        case 'invalid_key':
            return 'Invalid API key. ' . $cfg['label'] . ' rejected it.' . $hint;
        case 'model_unavailable':
            return 'Model unavailable: "' . $cfg['model'] . '" is not offered to this key. Pick another model.' . $hint;
        case 'timeout':
            return 'Request timeout. ' . $cfg['label'] . ' did not answer in ' . (int)$cfg['timeout'] . ' seconds.';
        case 'rate_limited':
            return 'Rate limited (HTTP 429). Wait a minute and test again.';
        case 'no_curl':
            return 'The PHP cURL extension is not enabled on this server, so no AI call can be made.';
        case 'network':
            return 'Could not reach ' . $cfg['label'] . ': ' . ($detail !== '' ? $detail : 'no response.');
        case 'provider_error':
            return 'API request failed: ' . $cfg['label'] . ' returned a server error. ' . $detail;
        case 'bad_response':
            return 'API request failed: the reply could not be read. ' . $detail;
        default:
            return 'API request failed (' . $code . '). ' . $detail;
    }
}

/**
 * What an anonymous customer is allowed to be told.
 *
 * Deliberately vague about configuration (a stranger must not learn whether a key
 * is missing or wrong) but specific about what they can do about it.
 */
function rg_ai_customer_message(string $code): string
{
    switch ($code) {
        case 'timeout':
        case 'network':
            return 'The writing assistant took too long to answer. Please try again.';
        case 'rate_limited':
            return 'The writing assistant is busy right now. Please try again in a minute.';
        case 'model_unavailable':
        case 'invalid_key':
        case 'not_configured':
        case 'no_curl':
            return 'The writing assistant is not available at the moment. You can still write your own review.';
        case 'bad_response':
            return 'The writing assistant sent something we could not use. Please try again.';
        default:
            return 'The writing assistant did not work. Please try again, or write your own review.';
    }
}

<?php
/**
 * links.php — one place that decides what a business link may be.
 *
 * Why this exists
 * ---------------
 * The business settings form stored whatever was typed: `trim($_POST[...])` and
 * straight into review_cards.details. htmlspecialchars() escapes quotes, but it
 * does not escape a scheme, so a saved value of `javascript:…` became a live
 * href on a public page. There was also no normalisation of phone numbers, so
 * "98765 43210", "+91 98765 43210" and "wa.me/919876543210" all produced
 * different, mostly broken WhatsApp links.
 *
 * Everything that accepts or renders an outgoing business link now goes through
 * this file:
 *
 *   rg_safe_url()        http/https only, no credentials, no protocol-relative
 *   rg_url_host()        the host of an accepted URL (for the platform hint)
 *   rg_url_platform_hint() a soft "that does not look like Instagram" note
 *   rg_wa_normalise()    any human phone format → E.164 digits, or ''
 *   rg_wa_url()          digits → wa.me link with an optional draft message
 *   rg_safe_local_redirect() the same-site path check login.php uses
 *
 * Rules of the house:
 *   - reject rather than repair anything that could change destination
 *   - an empty string always means "not configured", never a fallback URL
 *   - nothing here invents a destination (no generic Google search, no wa.me
 *     pointing at a number nobody entered)
 */

require_once __DIR__ . '/payments.php';   // rg_whatsapp_number(), rg_whatsapp_link()

/** Longest link we will store. Anything longer is not a link a human pasted. */
const RG_URL_MAX_LENGTH = 500;

/** The fields that hold an outgoing URL, with the hosts they normally point at. */
function rg_link_fields(): array
{
    return [
        'instagram' => [
            'label'  => 'Instagram',
            'hosts'  => ['instagram.com', 'instagr.am'],
            'expect' => 'https://instagram.com/yourpage',
        ],
        'facebook' => [
            'label'  => 'Facebook',
            'hosts'  => ['facebook.com', 'fb.com', 'fb.me'],
            'expect' => 'https://facebook.com/yourpage',
        ],
        'website' => [
            'label'  => 'Website',
            'hosts'  => [],          // any host: it is the business's own site
            'expect' => 'https://yourwebsite.com',
        ],
        'google_review_url' => [
            'label'  => 'Google review',
            'hosts'  => ['google.com', 'search.google.com', 'maps.google.com', 'g.page', 'maps.app.goo.gl', 'goo.gl'],
            'expect' => 'https://search.google.com/local/writereview?placeid=...',
        ],
    ];
}

/**
 * Returns the URL if it is a safe absolute http(s) link, otherwise ''.
 *
 * Deliberately strict. Every rejection below is a way a link can be made to go
 * somewhere other than where it appears to go:
 *   - javascript:, data:, vbscript:, file:, blob:  (script execution)
 *   - //evil.com                                   (inherits our scheme)
 *   - https:/\evil.com and \\evil.com              (browsers read \ as /)
 *   - https://user:pass@evil.com                   (looks like another host)
 *   - embedded whitespace or control characters     (parsers disagree)
 *   - a host with no dot                            (not a reachable business)
 */
function rg_safe_url(string $url, string $field = ''): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (strlen($url) > RG_URL_MAX_LENGTH) return '';

    /* Control characters, including newlines, and any space: none of these have
       a legitimate use in a link a shopkeeper pastes. */
    if (preg_match('/[\x00-\x20\x7F]/', $url)) return '';

    /* Browsers treat a backslash as a slash in the authority position, so
       "https:/\\evil.com" is read as a different host than it appears to be.
       Refuse it rather than rewriting it into something that looks valid. */
    if (strpos($url, '\\') !== false) return '';

    if (strpos($url, '//') === 0) return '';                     // protocol-relative

    if (!preg_match('#^([A-Za-z][A-Za-z0-9+.-]*):#', $url, $m)) return '';
    $scheme = strtolower($m[1]);
    if ($scheme !== 'http' && $scheme !== 'https') return '';

    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    $host = (string)($parts['host'] ?? '');
    if ($host === '') return '';

    /* Credentials in the URL: the visible host is not the one being visited. */
    if (isset($parts['user']) || isset($parts['pass'])) return '';

    if (preg_match('/[^a-z0-9.\-]/i', $host)) return '';
    if (strpos($host, '.') === false) return '';

    /* Store the scheme lowercase so two spellings compare equal. */
    $url = preg_replace('#^[A-Za-z][A-Za-z0-9+.-]*:#', $scheme . ':', $url, 1);

    return (string)$url;
}

/** Lowercased host of an accepted URL, without a leading "www.". */
function rg_url_host(string $url): string
{
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (strpos($host, 'www.') === 0) $host = substr($host, 4);
    return $host;
}

/** True when the host equals, or is a subdomain of, one of the listed hosts. */
function rg_host_matches(string $host, array $allowed): bool
{
    foreach ($allowed as $candidate) {
        if ($host === $candidate) return true;
        if (substr($host, -strlen('.' . $candidate)) === '.' . $candidate) return true;
    }
    return false;
}

/**
 * A soft note for the settings form: "this does not look like an Instagram
 * link". Advisory only — a shop may genuinely host its menu on a subdomain of
 * its own site, so this never blocks a save.
 */
function rg_url_platform_hint(string $field, string $url): string
{
    $fields = rg_link_fields();
    if (!isset($fields[$field])) return '';
    $allowed = $fields[$field]['hosts'];
    if (!$allowed || $url === '') return '';

    $host = rg_url_host($url);
    if ($host === '' || rg_host_matches($host, $allowed)) return '';

    return 'This does not look like a ' . $fields[$field]['label'] . ' link (it points at ' . $host . '). Saved anyway — check it if that was not intended.';
}

/**
 * Any human way of writing a phone number → E.164 digits, or ''.
 *
 * Accepts "+91 98765 43210", "0091 98765 43210", "098765 43210",
 * "919876543210", and a pasted https://wa.me/919876543210. The heavy lifting is
 * rg_whatsapp_number() from payments.php, so there is one definition of what a
 * usable number is; this only adds the "a whole wa.me link was pasted" case.
 */
function rg_wa_normalise(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    if (preg_match('#^https?://#i', $raw)) {
        $url = rg_safe_url($raw, 'whatsapp');
        if ($url === '') return '';
        $host = rg_url_host($url);
        if (!rg_host_matches($host, ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com', 'whatsapp.com'])) {
            return '';
        }
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        if (!preg_match('#(\d{8,15})#', str_replace('/', ' ', $path), $m)) return '';
        return rg_whatsapp_number($m[1]);
    }

    return rg_whatsapp_number($raw);
}

/** E.164 digits → wa.me link with an optional pre-filled message. */
function rg_wa_url(string $raw, string $message = ''): string
{
    $digits = rg_wa_normalise($raw);
    if ($digits === '') return '';
    if (trim($message) === '') return 'https://wa.me/' . $digits;   // no dangling "?text="
    return rg_whatsapp_link($digits, $message);
}

/**
 * True only for a same-site path such as "card_home.php?slug=x".
 *
 * Moved here from login.php so there is a single definition and it can be
 * tested directly. login.php keeps its is_safe_local_redirect() wrapper, so no
 * call site changed behaviour.
 */
function rg_safe_local_redirect(string $target): bool
{
    if ($target === '') return false;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) return false;      // any scheme
    if (strpos($target, '//') === 0) return false;                        // protocol-relative
    if (strpos($target, '/\\') === 0) return false;
    if (strpos($target, '\\') !== false) return false;
    if (strpbrk($target, "\r\n") !== false) return false;
    return true;
}

/**
 * The five destinations for one business, already safe to render.
 *
 * Every entry is either a usable URL or ''. Nothing is defaulted, so a business
 * that has not configured a link simply does not get a button — the alternative
 * (a generic Google search, the old behaviour) sends customers somewhere that
 * is not the business at all.
 */
function rg_business_links(array $details): array
{
    $links = [];
    foreach (array_keys(rg_link_fields()) as $field) {
        $links[$field] = rg_safe_url((string)($details[$field] ?? ''), $field);
    }
    $links['whatsapp'] = rg_wa_url((string)($details['whatsapp'] ?? ''));
    return $links;
}

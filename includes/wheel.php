<?php
/**
 * wheel.php — the Spin the Wheel campaign: prizes, odds, eligibility, spins.
 *
 * Rules this file exists to keep:
 *
 *   1. The winner is chosen on the SERVER, with random_int(), from the weights
 *      saved for that card. The browser never picks and never sends a result.
 *   2. One spin per customer per campaign. The reward is unlocked only after the
 *      server has recorded a completed feedback round (private feedback, or the
 *      customer's own review being saved) — never by opening the page. A UNIQUE
 *      key on (campaign_id, session_key, spin_no) makes a second row impossible,
 *      and every spin is claimed with a conditional UPDATE before anything is
 *      written, so two tabs and two clicks cannot both win.
 *   3. The odds are integers (1 / 100,000,000 = 0.000001%), so the total is
 *      exact. No floating point in the draw.
 *   4. A prize is snapshotted onto the spin and the coupon, so editing the
 *      campaign later cannot rewrite history, and no coupon is ever invented.
 *
 * The pure parts (defaults, validation, the draw, the odds text) have no database
 * dependency, which is what tests/php/test_wheel.php exercises.
 */

const RG_WHEEL_UNIT = 100000000;          // 100% in integers
const RG_WHEEL_MAX_SPINS = 1;             // one customer, one spin, per campaign
const RG_WHEEL_MAX_SPIN_AGE_HOURS = 72;   // an unspun token stops working after this

/* ---------------------------------------------------------------------------
   Prizes
   --------------------------------------------------------------------------- */

/**
 * The seven segments and their default odds, exactly as specified.
 *
 * weights are in 1/100,000,000 of a percent-point; 100000000 = 100%.
 */
function rg_wheel_defaults(): array
{
    return [
        ['slug' => 'd1',      'label' => '1% Discount',                  'kind' => 'discount',   'discount_percent' => 1.00,  'weight' => 10000000],
        ['slug' => 'd2',      'label' => '2% Discount',                  'kind' => 'discount',   'discount_percent' => 2.00,  'weight' => 1000000],
        ['slug' => 'd5',      'label' => '5% Discount',                  'kind' => 'discount',   'discount_percent' => 5.00,  'weight' => 500000],
        ['slug' => 'd20',     'label' => '20% Discount',                 'kind' => 'discount',   'discount_percent' => 20.00, 'weight' => 10000],
        ['slug' => 'd50',     'label' => '50% Discount',                 'kind' => 'discount',   'discount_percent' => 50.00, 'weight' => 1000],
        ['slug' => 'better',  'label' => 'Better Luck Next Time!',       'kind' => 'better_luck','discount_percent' => null,  'weight' => 68489000],
        ['slug' => 'thanks',  'label' => 'Thank You for Your Feedback!', 'kind' => 'thanks',     'discount_percent' => null,  'weight' => 20000000],
    ];
}

/** Total of the active segments, in RG_WHEEL_UNIT units. */
function rg_wheel_total(array $segments): int
{
    $total = 0;
    foreach ($segments as $segment) {
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        $total += (int)($segment['weight'] ?? 0);
    }
    return $total;
}

/**
 * Is this a campaign that can run? Returns ['ok' => bool, 'total' => int,
 * 'errors' => []].
 *
 * A business may run a campaign that does not total 100% — the draw would still
 * be safe, it would just quietly change the real odds. So saving and enabling
 * both refuse it, and the dashboard says which segment to fix.
 */
function rg_wheel_validate(array $segments): array
{
    $errors = [];
    $active = 0;
    $total  = 0;

    foreach ($segments as $i => $segment) {
        $label = (string)($segment['label'] ?? ('Segment ' . ($i + 1)));
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        $active++;

        $weight = $segment['weight'] ?? null;
        if ($weight === null || !is_numeric($weight)) {
            $errors[] = $label . ': probability is not a number.';
            continue;
        }
        if ((int)$weight < 0) {
            $errors[] = $label . ': probability cannot be negative.';
            continue;
        }
        if ((float)$weight > RG_WHEEL_UNIT) {
            $errors[] = $label . ': probability cannot exceed 100%.';
            continue;
        }
        $total += (int)$weight;
    }

    if ($active < 2) {
        $errors[] = 'At least two prizes must be switched on.';
    }
    if ($total !== RG_WHEEL_UNIT) {
        $errors[] = sprintf(
            'The probabilities add up to %s%%, not 100%%.',
            rtrim(rtrim(number_format($total / RG_WHEEL_UNIT * 100, 6, '.', ''), '0'), '.')
        );
    }

    return ['ok' => $errors === [], 'total' => $total, 'errors' => $errors];
}

/** 0.001% .. 68.489% as a human string, trailing zeros removed. */
function rg_wheel_percent_text(int $weight): string
{
    $percent = $weight / RG_WHEEL_UNIT * 100;
    $text = rtrim(rtrim(number_format($percent, 6, '.', ''), '0'), '.');
    return ($text === '' ? '0' : $text) . '%';
}

/**
 * The draw. Integer only, one random number, no seed the browser can see.
 *
 * $rand is injectable so the tests can walk every boundary: it must behave like
 * random_int(0, $max - 1).
 */
function rg_wheel_pick(array $segments, ?callable $rand = null): ?array
{
    $live = [];
    foreach ($segments as $segment) {
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        if ((int)($segment['weight'] ?? 0) <= 0) continue;
        $live[] = $segment;
    }
    $total = 0;
    foreach ($live as $segment) $total += (int)$segment['weight'];
    if ($total <= 0) return null;

    $roll = $rand ? (int)$rand($total) : random_int(0, $total - 1);
    if ($roll < 0) $roll = 0;
    if ($roll >= $total) $roll = $total - 1;

    foreach ($live as $segment) {
        $roll -= (int)$segment['weight'];
        if ($roll < 0) return $segment;
    }
    return $live[count($live) - 1];
}

/** The wheel as the page draws it: equal wedges, real odds written beside them. */
function rg_wheel_public_view(array $segments): array
{
    $out = [];
    $total = rg_wheel_total($segments);
    foreach ($segments as $segment) {
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        $weight = (int)($segment['weight'] ?? 0);
        $out[] = [
            'label'   => (string)$segment['label'],
            'kind'    => (string)$segment['kind'],
            'odds'    => rg_wheel_percent_text($weight),
            'percent' => $total > 0 ? $weight / $total * 100 : 0.0,
        ];
    }
    return $out;
}

/**
 * Where a drawn prize sits on the wheel the page drew.
 *
 * The animation must stop on the prize the server already chose, even if two
 * prizes share a label. The index is counted over the same active segments (in
 * the same order) that rg_wheel_public_view() sends to the browser.
 */
function rg_wheel_segment_index(array $segments, $segmentId): ?int
{
    $index = 0;
    foreach ($segments as $segment) {
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        if ((int)($segment['id'] ?? 0) === (int)$segmentId) return $index;
        $index++;
    }
    return null;
}

/** "1% Discount 10% · 2% Discount 1% · …" for the campaign terms. */
function rg_wheel_odds_line(array $segments): string
{
    $parts = [];
    foreach (rg_wheel_public_view($segments) as $segment) {
        $parts[] = $segment['label'] . ' ' . $segment['odds'];
    }
    return implode(' · ', $parts);
}

/* ---------------------------------------------------------------------------
   Coupons
   --------------------------------------------------------------------------- */

/** Unambiguous alphabet: no O/0, no I/1, so a code can be read off a phone. */
function rg_wheel_random_code(string $prefix = 'RGW'): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $prefix . '-' . $code;
}

function rg_wheel_code_looks_valid(string $code): bool
{
    /* Only codes this site issues are accepted, so a made-up string cannot be
       redeemed on the dashboard and an O/0 look-alike cannot be typed in. */
    return (bool)preg_match('/^RGW-[A-HJ-NP-Z2-9]{8}$/', $code);
}

/* ---------------------------------------------------------------------------
   Campaign window
   --------------------------------------------------------------------------- */

/**
 * Is the wheel open right now? 'enabled' is the owner's switch; the dates are
 * the campaign window; both must agree.
 */
function rg_wheel_is_open(array $campaign, ?int $now = null): bool
{
    if (empty($campaign['enabled'])) return false;
    $now = $now ?? time();

    if (!empty($campaign['starts_at'])) {
        $start = strtotime((string)$campaign['starts_at']);
        if ($start !== false && $now < $start) return false;
    }
    if (!empty($campaign['ends_at'])) {
        /* ends_at is a date: the campaign includes that whole day. */
        $end = strtotime((string)$campaign['ends_at'] . ' 23:59:59');
        if ($end !== false && $now > $end) return false;
    }
    return true;
}

/* ---------------------------------------------------------------------------
   Database side
   --------------------------------------------------------------------------- */

/**
 * The card's campaign plus its segments, created from the defaults the first
 * time the owner opens the screen. Returns null when the card has no row yet and
 * $create is false.
 */
function rg_wheel_campaign($conn, int $cardId, bool $create = false): ?array
{
    $stmt = $conn->prepare('SELECT * FROM wheel_campaigns WHERE card_id = ? LIMIT 1');
    $stmt->execute([$cardId]);
    $campaign = $stmt->fetch() ?: null;

    if (!$campaign && $create) {
        $stmt = $conn->prepare('INSERT INTO wheel_campaigns (card_id, enabled, coupon_expiry_days, inventory_limit) VALUES (?, 0, 30, 0)');
        $stmt->execute([$cardId]);
        $campaignId = (int)$conn->lastInsertId();

        $insert = $conn->prepare(
            'INSERT INTO wheel_segments (campaign_id, slug, label, kind, discount_percent, weight, inventory_limit, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1)'
        );
        foreach (rg_wheel_defaults() as $order => $segment) {
            $insert->execute([
                $campaignId,
                $segment['slug'],
                $segment['label'],
                $segment['kind'],
                $segment['discount_percent'],
                $segment['weight'],
                $order,
            ]);
        }

        $stmt = $conn->prepare('SELECT * FROM wheel_campaigns WHERE id = ? LIMIT 1');
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch() ?: null;
    }

    if (!$campaign) return null;

    $campaign['segments'] = rg_wheel_segments($conn, (int)$campaign['id']);
    return $campaign;
}

function rg_wheel_segments($conn, int $campaignId): array
{
    $stmt = $conn->prepare('SELECT * FROM wheel_segments WHERE campaign_id = ? ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$campaignId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * The spin this visitor owns, keyed by spin number (1 = guaranteed, 2 = real).
 *
 * Read straight from the database, so a refresh, a second tab or a new browser
 * process all see the same state — the browser never holds the truth.
 *
 * @return array{1: ?array, 2: ?array}
 */
function rg_wheel_visitor_spins($conn, int $cardId): array
{
    $out = [1 => null, 2 => null];
    try {
        $stmt = $conn->prepare(
            'SELECT * FROM wheel_spins WHERE card_id = ? AND session_key = ? ORDER BY spin_no ASC, id ASC'
        );
        $stmt->execute([$cardId, rg_wheel_visitor_key($cardId)]);
        foreach (($stmt->fetchAll() ?: []) as $row) {
            $n = (int)($row['spin_no'] ?? 1);
            /* array_key_exists, not isset: isset() is false for a null value, which
               would quietly drop every spin. */
            if (array_key_exists($n, $out) && $out[$n] === null) $out[$n] = $row;
        }
    } catch (Throwable $e) {
        /* Old database without spin_no: treat every row as spin 1. */
        try {
            $stmt = $conn->prepare('SELECT * FROM wheel_spins WHERE card_id = ? AND session_key = ? ORDER BY id ASC LIMIT 1');
            $stmt->execute([$cardId, rg_wheel_visitor_key($cardId)]);
            $row = $stmt->fetch() ?: null;
            if ($row) $out[1] = $row;
        } catch (Throwable $ignored) {
        }
    }
    return $out;
}

/**
 * The participation record for this customer and card: eligibility, whether the
 * spin was taken, and the prize. Created on first sight, never duplicated.
 */
function rg_wheel_participation($conn, int $cardId): ?array
{
    $key = rg_wheel_visitor_key($cardId);

    try {
        $read = $conn->prepare('SELECT * FROM wheel_participations WHERE card_id = ? AND participant_key = ? LIMIT 1');
        $read->execute([$cardId, $key]);
        $row = $read->fetch() ?: null;
        if ($row) return $row;
    } catch (Throwable $e) {
        /* No participation table yet (migration not run): there is nothing the
           wheel can safely unlock, so behave as "not set up" rather than fatal. */
        return null;
    }

    $campaign = rg_wheel_campaign($conn, $cardId, false);
    if (!$campaign) return null;

    try {
        $insert = $conn->prepare(
            'INSERT INTO wheel_participations (card_id, campaign_id, participant_key, eligible, created_at)
             VALUES (?, ?, ?, 0, NOW())'
        );
        $insert->execute([$cardId, (int)$campaign['id'], $key]);
    } catch (PDOException $e) {
        /* 23000 = another request created it at the same moment, which is fine. */
        if (($e->getCode() !== '23000') && stripos($e->getMessage(), 'Duplicate') === false) {
            return null;
        }
    }

    $read->execute([$cardId, $key]);
    return $read->fetch() ?: null;
}

/**
 * Mark this customer eligible. Called by the server only, after a draft was
 * written or the private feedback was saved — never by the browser.
 */
function rg_wheel_mark_eligible($conn, int $cardId, string $source, ?int $feedbackId = null): bool
{
    $participation = rg_wheel_participation($conn, $cardId);
    if (!$participation) return false;

    try {
        $stmt = $conn->prepare(
            'UPDATE wheel_participations
                SET eligible = 1,
                    eligible_at = COALESCE(eligible_at, NOW()),
                    eligible_source = COALESCE(eligible_source, ?),
                    feedback_id = COALESCE(feedback_id, ?),
                    updated_at = NOW()
              WHERE id = ?'
        );
        $stmt->execute([$source, $feedbackId, (int)$participation['id']]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Record the completed spin on the participation row. */
function rg_wheel_close_participation($conn, int $participationId, int $spinId, array $segment, ?int $couponId = null): void
{
    try {
        $stmt = $conn->prepare(
            'UPDATE wheel_participations
                SET spin_id = ?, spun_at = NOW(), prize_label = ?, discount_percent = ?, coupon_id = ?, updated_at = NOW()
              WHERE id = ? AND spun_at IS NULL'
        );
        $stmt->execute([
            $spinId,
            (string)$segment['label'],
            $segment['discount_percent'] === null ? null : (float)$segment['discount_percent'],
            $couponId,
            $participationId,
        ]);
    } catch (Throwable $e) {
        /* The spin itself is already recorded; this is only the summary row. */
    }
}

/** The segment the first spin always lands on. */
function rg_wheel_forced_segment(array $segments): ?array
{
    $active = static function (array $segment): bool {
        return !isset($segment['is_active']) || (int)$segment['is_active'] !== 0;
    };
    foreach ($segments as $segment) {
        if ($active($segment) && ($segment['kind'] ?? '') === 'better_luck') return $segment;
    }
    foreach ($segments as $segment) {
        if ($active($segment) && stripos((string)($segment['label'] ?? ''), 'better luck') !== false) return $segment;
    }
    return null;
}

/**
 * A pending token older than RG_WHEEL_MAX_SPIN_AGE_HOURS is rotated in place.
 *
 * The UNIQUE key is (campaign_id, session_key, spin_no), so a stale row cannot be
 * replaced by a new one; rotating its token gives the customer a working spin
 * without weakening the one-spin rule.
 */
function rg_wheel_fresh_token($conn, array $spin): string
{
    $token = (string)($spin['token'] ?? '');
    $issued = strtotime((string)($spin['created_at'] ?? ''));
    $stale = $issued !== false && (time() - $issued) > RG_WHEEL_MAX_SPIN_AGE_HOURS * 3600;

    if ($token !== '' && !$stale) return $token;
    if (($spin['status'] ?? '') !== 'pending') return $token;

    $newToken = bin2hex(random_bytes(32));
    try {
        $stmt = $conn->prepare(
            "UPDATE wheel_spins SET token = ?, created_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$newToken, (int)$spin['id']]);
        if ($stmt->rowCount() > 0) return $newToken;
    } catch (Throwable $e) {
        /* Fall through to the old token: the spin endpoint will report expiry. */
    }
    return $token;
}

/**
 * Hand out spin number $spinNo for this visitor.
 *
 * Idempotent: calling it twice returns the same token, and the UNIQUE key
 * (campaign_id, session_key, spin_no) means a second row can never be written —
 * not by a refresh, not by a second tab, not by two clicks at once.
 *
 * @return array{ok: bool, token?: string, spin_no?: int, created?: bool, reason?: string}
 */
function rg_wheel_issue_for($conn, int $cardId, int $spinNo, ?int $feedbackId = null, ?int $now = null): array
{
    if ($spinNo < 1 || $spinNo > RG_WHEEL_MAX_SPINS) {
        return ['ok' => false, 'reason' => 'no_more_spins'];
    }

    $campaign = rg_wheel_campaign($conn, $cardId, false);
    if (!$campaign) return ['ok' => false, 'reason' => 'not_configured'];
    if (!rg_wheel_is_open($campaign, $now ?? time())) return ['ok' => false, 'reason' => 'closed'];

    $check = rg_wheel_validate($campaign['segments']);
    if (!$check['ok']) return ['ok' => false, 'reason' => 'bad_odds'];

    /* Nobody gets a spin token without the server having marked them eligible
       first (a written draft or a saved private feedback). */
    $participation = rg_wheel_participation($conn, $cardId);
    if (!$participation || empty($participation['eligible'])) {
        return ['ok' => false, 'reason' => 'not_eligible'];
    }

    $existing = rg_wheel_visitor_spins($conn, $cardId);

    /* One spin per customer per campaign. There is no second spin to grant. */
    if (!empty($existing[$spinNo])) {
        return [
            'ok'      => true,
            'token'   => rg_wheel_fresh_token($conn, $existing[$spinNo]),
            'spin_no' => $spinNo,
            'created' => false,
        ];
    }

    $token = bin2hex(random_bytes(32));

    try {
        $stmt = $conn->prepare(
            "INSERT INTO wheel_spins
                (card_id, campaign_id, feedback_id, session_key, spin_no, forced, token, status, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
        );
        $stmt->execute([
            $cardId,
            (int)$campaign['id'],
            $feedbackId,
            rg_wheel_visitor_key($cardId),
            $spinNo,
            0,
            $token,
            rg_wheel_ip_hash(),
        ]);
    } catch (PDOException $e) {
        /* 23000 = integrity constraint: this spin already exists. Hand back the
           one that won the race instead of failing: same token, same future. */
        if (($e->getCode() === '23000') || stripos($e->getMessage(), 'Duplicate') !== false) {
            $again = rg_wheel_visitor_spins($conn, $cardId);
            if (!empty($again[$spinNo])) {
                return [
                    'ok'      => true,
                    'token'   => (string)$again[$spinNo]['token'],
                    'spin_no' => $spinNo,
                    'created' => false,
                    'reason'  => 'already_issued',
                ];
            }
            return ['ok' => false, 'reason' => 'already_spun'];
        }
        /* A database from before the two-spin upgrade needs the migration; say so
           plainly instead of quietly doing nothing. */
        if (stripos($e->getMessage(), 'Unknown column') !== false) {
            return ['ok' => false, 'reason' => 'schema_outdated'];
        }
        return ['ok' => false, 'reason' => 'db_error'];
    }

    return ['ok' => true, 'token' => $token, 'spin_no' => $spinNo, 'created' => true, 'campaign_id' => (int)$campaign['id']];
}

/**
 * Backwards-compatible name used elsewhere: hands out the first spin.
 *
 * @return array{ok: bool, token?: string, reason?: string}
 */
function rg_wheel_issue_spin($conn, array $card, ?int $feedbackId = null, ?int $now = null): array
{
    return rg_wheel_issue_for($conn, (int)$card['id'], 1, $feedbackId, $now);
}

/**
 * The wheel as this customer should see it right now.
 *
 * Reads everything from the database and never changes eligibility. Used by the
 * page render and by the draft endpoint (which must NOT unlock the wheel: a
 * generated draft is not a submitted feedback round).
 *
 * @return array{available: bool, stage: string, token: ?string, result: ?array}
 */
function rg_wheel_state($conn, int $cardId, ?int $now = null): array
{
    $locked = ['available' => false, 'stage' => 'locked', 'token' => null, 'result' => null];

    try {
        $campaign = rg_wheel_campaign($conn, $cardId, false);
        if (!$campaign || !rg_wheel_is_open($campaign, $now ?? time())) return $locked;
        if (!rg_wheel_validate($campaign['segments'])['ok']) return $locked;

        $spins = rg_wheel_visitor_spins($conn, $cardId);
        $spin  = $spins[1] ?? null;

        /* A recorded result always wins, even if the campaign has since ended:
           the customer must be able to see the prize they were awarded. */
        if ($spin && $spin['status'] === 'spun') {
            return [
                'available' => false,
                'stage'     => 'done',
                'token'     => null,
                'result'    => rg_wheel_spin_result($conn, (int)$spin['id']),
            ];
        }

        $participation = rg_wheel_participation($conn, $cardId);
        if (!$participation || empty($participation['eligible'])) return $locked;
        if (!empty($participation['spun_at']) || !empty($participation['spin_id'])) {
            return [
                'available' => false,
                'stage'     => 'done',
                'token'     => null,
                'result'    => !empty($participation['spin_id'])
                    ? rg_wheel_spin_result($conn, (int)$participation['spin_id'])
                    : null,
            ];
        }

        $token = $spin ? rg_wheel_fresh_token($conn, $spin) : '';
        if ($token === '') {
            $grant = rg_wheel_issue_for($conn, $cardId, 1);
            if (!empty($grant['ok'])) $token = (string)$grant['token'];
        }
        if ($token === '') return $locked;

        return ['available' => true, 'stage' => 'ready', 'token' => $token, 'result' => null];
    } catch (Throwable $e) {
        return $locked;
    }
}

/**
 * Record a completed feedback round and return the wheel state.
 *
 * This is the ONLY way a customer becomes eligible. $source is stored for the
 * owner's records ('feedback' or 'review'); it is never read from the browser.
 */
function rg_wheel_unlock_state($conn, int $cardId, string $source, ?int $feedbackId = null, ?int $now = null): array
{
    try {
        $campaign = rg_wheel_campaign($conn, $cardId, false);
        if (!$campaign || !rg_wheel_is_open($campaign, $now ?? time())) {
            return ['available' => false, 'stage' => 'locked', 'token' => null, 'result' => null];
        }
        if (!rg_wheel_validate($campaign['segments'])['ok']) {
            return ['available' => false, 'stage' => 'locked', 'token' => null, 'result' => null];
        }

        rg_wheel_mark_eligible($conn, $cardId, $source, $feedbackId);
        return rg_wheel_state($conn, $cardId, $now);
    } catch (Throwable $e) {
        return ['available' => false, 'stage' => 'locked', 'token' => null, 'result' => null];
    }
}

/** One-way hash of the visitor's address, for abuse review. Never the raw IP. */
function rg_wheel_ip_hash(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? '' : hash('sha256', $ip . '|reviewgrow-wheel');
}

/** What the customer is told to do with a coupon. Editable in the dashboard. */
function rg_wheel_redemption_note($conn, int $cardId): string
{
    $stmt = $conn->prepare('SELECT redemption_note FROM wheel_campaigns WHERE card_id = ? LIMIT 1');
    $stmt->execute([$cardId]);
    $note = trim((string)($stmt->fetchColumn() ?: ''));
    return $note !== '' ? $note : 'Show this code at the counter before it expires.';
}

/** Has this card's visitor already spun in this session? Best-effort extra guard. */
/**
 * The participation id for this customer and this card.
 *
 * A long-lived, HttpOnly, SameSite=Lax cookie — NOT the PHP session, because a
 * session dies when the browser closes and the customer would get a fresh chance.
 * With a cookie the same phone returns to the same participation after a refresh,
 * a browser restart, or a re-scan of the QR code.
 *
 * What it cannot do: identify a person across different devices. That needs a
 * login or a phone OTP; a cookie is per browser, and this code does not pretend
 * otherwise.
 */
function rg_wheel_participant_key(int $cardId): string
{
    $name  = 'rgp_' . $cardId;
    $value = (string)($_COOKIE[$name] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $value)) return $value;

    $value = bin2hex(random_bytes(32));

    if (!headers_sent()) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        setcookie($name, $value, [
            'expires'  => time() + 365 * 86400,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[$name] = $value;

    return $value;
}

/**
 * The key stored on the spin rows: a hash of the participation id and the card,
 * so the raw cookie value is never written to the database.
 */
function rg_wheel_visitor_key(int $cardId): string
{
    return hash('sha256', rg_wheel_participant_key($cardId) . '|' . $cardId);
}

function rg_wheel_session_key(int $cardId): string
{
    return 'rg_wheel_spin_' . $cardId;
}

/**
 * The session flag that says "this visitor's draft was generated on the server".
 * The wheel is closed until it is set, and the browser cannot set it.
 */
function rg_wheel_draft_flag(int $cardId): string
{
    return 'rg_wheel_draft_ok_' . $cardId;
}

/**
 * Run the spin for a token.
 *
 * Order matters and is what stops double wins:
 *
 *   1. claim the row with "UPDATE … WHERE token = ? AND status = 'pending'" —
 *      only one request can win that race, and the row count proves it;
 *   2. draw the prize;
 *   3. write the coupon (if any) and the result onto the claimed row.
 *
 * If step 3 fails the row is put back to 'pending' by the transaction rollback,
 * so the customer can try again rather than being charged a spin for an error.
 */
function rg_wheel_spin($conn, int $cardId, string $token, ?callable $rand = null, ?int $now = null): array
{
    $now = $now ?? time();
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return ['ok' => false, 'error' => 'bad_token'];
    }

    $stmt = $conn->prepare('SELECT * FROM wheel_spins WHERE token = ? AND card_id = ? LIMIT 1');
    $stmt->execute([$token, $cardId]);
    $spin = $stmt->fetch() ?: null;
    if (!$spin) return ['ok' => false, 'error' => 'unknown_spin'];

    if ($spin['status'] === 'spun') {
        return ['ok' => false, 'error' => 'already_spun', 'result' => rg_wheel_spin_result($conn, (int)$spin['id'])];
    }

    /* Defence in depth: the participation must still be eligible and unused, even
       if someone kept an old token from before this change. */
    $participation = rg_wheel_participation($conn, $cardId);
    if (!$participation || empty($participation['eligible'])) {
        return ['ok' => false, 'error' => 'not_eligible'];
    }
    if (!empty($participation['spun_at']) || !empty($participation['spin_id'])) {
        return ['ok' => false, 'error' => 'already_spun'];
    }
    $spin['participation_id'] = (int)$participation['id'];
    if ($spin['status'] !== 'pending') {
        return ['ok' => false, 'error' => 'not_available'];
    }

    $issued = strtotime((string)$spin['created_at']);
    if ($issued !== false && $now - $issued > RG_WHEEL_MAX_SPIN_AGE_HOURS * 3600) {
        return ['ok' => false, 'error' => 'expired'];
    }

    $campaign = rg_wheel_campaign($conn, $cardId, false);
    if (!$campaign) return ['ok' => false, 'error' => 'not_configured'];

    $segments = $campaign['segments'];
    $check = rg_wheel_validate($segments);
    if (!$check['ok']) return ['ok' => false, 'error' => 'bad_odds'];

    $spinNo = (int)($spin['spin_no'] ?? 1);

    $conn->beginTransaction();
    try {
        $claim = $conn->prepare(
            "UPDATE wheel_spins SET status = 'spinning', spun_at = NOW() WHERE id = ? AND status = 'pending'"
        );
        $claim->execute([(int)$spin['id']]);
        if ($claim->rowCount() !== 1) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'already_spun'];
        }

        /* The single spin uses the business's own odds. The draw happens here, on
           the server, before the browser animates anything. */
        $won = rg_wheel_pick($segments, $rand);
        if (!$won) {
            $conn->rollBack();
            return ['ok' => false, 'error' => 'no_prizes'];
        }

        /* Campaign-wide coupon cap: once it is used up the spin is a Better Luck
           Next Time instead of a coupon that cannot be honoured. */
        $limit = (int)($campaign['inventory_limit'] ?? 0);
        if ($limit > 0) {
            $stmt = $conn->prepare('SELECT COUNT(*) FROM wheel_coupons WHERE card_id = ?');
            $stmt->execute([$cardId]);
            if ((int)$stmt->fetchColumn() >= $limit) {
                $won = rg_wheel_forced_segment($segments) ?? $won;
            }
        }

        /* A prize whose own stock ran out falls back to "Better Luck Next Time",
           recorded as such — never a coupon for nothing. */
        $won = rg_wheel_apply_stock($conn, $campaign, $won);

        $couponId = null;
        $expiresAt = null;
        if ($won['kind'] === 'discount') {
            $days = max(1, (int)($campaign['coupon_expiry_days'] ?? 30));
            $expiresAt = date('Y-m-d', strtotime('+' . $days . ' days', $now));
            $couponId = rg_wheel_insert_coupon($conn, (int)$cardId, (int)$spin['id'], (float)$won['discount_percent'], $expiresAt);
        }

        $done = $conn->prepare(
            "UPDATE wheel_spins
                SET status = 'spun', segment_id = ?, segment_label = ?, segment_kind = ?,
                    discount_percent = ?, coupon_id = ?, spun_at = NOW()
              WHERE id = ? AND status = 'spinning'"
        );
        $done->execute([
            (int)$won['id'],
            (string)$won['label'],
            (string)$won['kind'],
            $won['discount_percent'] === null ? null : (float)$won['discount_percent'],
            $couponId,
            (int)$spin['id'],
        ]);

        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        return ['ok' => false, 'error' => 'db_error'];
    }

    /* Close the participation record with the prize the customer actually got. */
    if (!empty($spin['participation_id'])) {
        rg_wheel_close_participation($conn, (int)$spin['participation_id'], (int)$spin['id'], $won, $couponId);
    }

    return [
        'ok'       => true,
        'spin_no'  => $spinNo,
        'forced'   => false,
        'segment'  => [
            'index'   => rg_wheel_segment_index($segments, $won['id'] ?? 0),
            'label'   => (string)$won['label'],
            'kind'    => (string)$won['kind'],
            'percent' => $won['discount_percent'] === null ? null : (float)$won['discount_percent'],
        ],
        'coupon'   => $couponId ? rg_wheel_coupon($conn, (int)$couponId) : null,
        'next_token' => null,
        'spins_left' => 0,
    ];
}

/** Does the prize still have stock? Re-checks the per-segment cap. */
function rg_wheel_apply_stock($conn, array $campaign, array $won): array
{
    $cap = (int)($won['inventory_limit'] ?? 0);
    if ($won['kind'] !== 'discount' || $cap <= 0) return $won;

    $stmt = $conn->prepare('SELECT COUNT(*) FROM wheel_spins WHERE segment_id = ? AND status = ?');
    $stmt->execute([(int)$won['id'], 'spun']);
    if ((int)$stmt->fetchColumn() < $cap) return $won;

    foreach ($campaign['segments'] as $segment) {
        if (isset($segment['is_active']) && (int)$segment['is_active'] === 0) continue;
        if ($segment['kind'] === 'better_luck') return $segment;
    }
    return $won;
}

function rg_wheel_insert_coupon($conn, int $cardId, int $spinId, float $percent, string $expiresAt): int
{
    $insert = $conn->prepare(
        'INSERT INTO wheel_coupons (spin_id, card_id, code, discount_percent, expires_at) VALUES (?, ?, ?, ?, ?)'
    );

    for ($attempt = 0; $attempt < 6; $attempt++) {
        $code = rg_wheel_random_code();
        try {
            $insert->execute([$spinId, $cardId, $code, $percent, $expiresAt]);
            return (int)$conn->lastInsertId();
        } catch (PDOException $e) {
            if ($attempt === 5) throw $e;   // unique code collision: try another
        }
    }
    throw new RuntimeException('Could not allocate a coupon code.');
}

function rg_wheel_coupon($conn, int $couponId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM wheel_coupons WHERE id = ? LIMIT 1');
    $stmt->execute([$couponId]);
    $row = $stmt->fetch();
    if (!$row) return null;

    return [
        'code'      => (string)$row['code'],
        'percent'   => (float)$row['discount_percent'],
        'expires'   => (string)$row['expires_at'],
        'redeemed'  => !empty($row['redeemed_at']),
    ];
}

/** What was won, read back from the spin row (never recomputed). */
function rg_wheel_spin_result($conn, int $spinId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM wheel_spins WHERE id = ? LIMIT 1');
    $stmt->execute([$spinId]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'spun') return null;

    $index = null;
    try {
        $campaign = rg_wheel_campaign($conn, (int)$row['card_id'], false);
        if ($campaign) $index = rg_wheel_segment_index($campaign['segments'], (int)$row['segment_id']);
    } catch (Throwable $e) {
        /* The recorded label still shows; only the animation hint is missing. */
    }

    return [
        'segment' => [
            'index'   => $index,
            'label'   => (string)$row['segment_label'],
            'kind'    => (string)$row['segment_kind'],
            'percent' => $row['discount_percent'] === null ? null : (float)$row['discount_percent'],
        ],
        'coupon'  => $row['coupon_id'] ? rg_wheel_coupon($conn, (int)$row['coupon_id']) : null,
    ];
}

/** Totals for the dashboard: spins, distribution, coupons issued and redeemed. */
function rg_wheel_stats($conn, int $cardId): array
{
    $stats = [
        'spins'      => 0,
        'coupons'    => 0,
        'redeemed'   => 0,
        'pending'    => 0,
        'by_segment' => [],
        'recent'     => [],
    ];

    $stmt = $conn->prepare("SELECT COUNT(*) FROM wheel_spins WHERE card_id = ? AND status = 'spun'");
    $stmt->execute([$cardId]);
    $stats['spins'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare("SELECT COUNT(*) FROM wheel_spins WHERE card_id = ? AND status = 'pending'");
    $stmt->execute([$cardId]);
    $stats['pending'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare(
        'SELECT segment_label, segment_kind, COUNT(*) AS n FROM wheel_spins
          WHERE card_id = ? AND status = ? GROUP BY segment_label, segment_kind ORDER BY n DESC'
    );
    $stmt->execute([$cardId, 'spun']);
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $stats['by_segment'][] = [
            'label' => (string)$row['segment_label'],
            'kind'  => (string)$row['segment_kind'],
            'count' => (int)$row['n'],
        ];
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM wheel_coupons WHERE card_id = ?');
    $stmt->execute([$cardId]);
    $stats['coupons'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare('SELECT COUNT(*) FROM wheel_coupons WHERE card_id = ? AND redeemed_at IS NOT NULL');
    $stmt->execute([$cardId]);
    $stats['redeemed'] = (int)$stmt->fetchColumn();

    $stmt = $conn->prepare(
        'SELECT * FROM wheel_coupons WHERE card_id = ? ORDER BY id DESC LIMIT 50'
    );
    $stmt->execute([$cardId]);
    $stats['recent'] = $stmt->fetchAll() ?: [];

    return $stats;
}

/* ---------------------------------------------------------------------------
   The drawing
   --------------------------------------------------------------------------- */

/** Split a prize label into short lines so seven of them stay readable. */
function rg_wheel_label_lines(string $label, int $maxChars = 11): array
{
    $words = preg_split('/\s+/', trim($label)) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (function_exists('mb_strlen')) {
            $len = mb_strlen($candidate, 'UTF-8');
        } else {
            $len = strlen($candidate);
        }
        if ($len > $maxChars && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') $lines[] = $line;
    return $lines ?: [$label];
}

/**
 * The wheel itself, as SVG.
 *
 * Equal wedges — the visual size deliberately does not encode the odds, which is
 * why the odds are printed under the wheel instead. Pure markup: the rotation is
 * a CSS transform the page applies, so no third-party wheel library is loaded.
 */
function rg_wheel_svg(array $segments, int $size = 320, string $centreText = 'ReviewAI'): string
{
    $count = count($segments);
    if ($count < 2) return '';

    $cx = $size / 2;
    $cy = $size / 2;
    $radius = $size / 2 - 10;
    $step = 360 / $count;

    /* Seven fills that never repeat side by side, and wrap cleanly from the last
       wedge back to the first. Gold is kept for the rim, so the prizes read as
       violet / cream alternation like the design. */
    $fills = ['#4B20DC', '#FBF8F2', '#7B4BF5', '#F1EBFE', '#3A16A8', '#FFFDFB', '#6E45E9'];
    $light = ['#FBF8F2', '#F1EBFE', '#FFFDFB'];

    $svg  = '<svg class="rg-wheel-svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" '
          . 'aria-label="Prize wheel with ' . $count . ' segments. ' . e(rg_wheel_odds_line($segments)) . '">';
    /* Gold rim, as in the reference: a warm band with a thin darker edge. */
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($radius + 7) . '" fill="#E9B44C"/>';
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($radius + 3) . '" fill="none" stroke="#C9962E" stroke-width="1.6"/>';
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . ($radius - 1) . '" fill="none" stroke="rgba(255,255,255,.55)" stroke-width="1"/>';

    foreach (array_values($segments) as $i => $segment) {
        $start = -90 + $i * $step;
        $end = $start + $step;
        $fill = $fills[$i % count($fills)];

        $x1 = $cx + $radius * cos(deg2rad($start));
        $y1 = $cy + $radius * sin(deg2rad($start));
        $x2 = $cx + $radius * cos(deg2rad($end));
        $y2 = $cy + $radius * sin(deg2rad($end));
        $large = $step > 180 ? 1 : 0;

        $svg .= '<path d="M' . round($cx, 2) . ' ' . round($cy, 2)
              . ' L' . round($x1, 2) . ' ' . round($y1, 2)
              . ' A' . $radius . ' ' . $radius . ' 0 ' . $large . ' 1 ' . round($x2, 2) . ' ' . round($y2, 2)
              . ' Z" fill="' . $fill . '" stroke="#FFFFFF" stroke-width="1.5"/>';

        /* Label along the middle radius of the wedge. */
        $mid = $start + $step / 2;
        $lines = rg_wheel_label_lines((string)$segment['label']);
        $ink = in_array($fill, $light, true) ? '#151832' : '#FFFFFF';
        $lineHeight = 11;
        $blockTop = -(count($lines) - 1) * $lineHeight / 2;

        /* Labels run ACROSS each wedge (tangential), which is how a real prize
           wheel reads, and any label that would land upside down is turned 180°
           about its own anchor so all seven stay readable. */
        $theta = $mid + 90;
        if ($theta > 90 && $theta < 270) $theta -= 180;

        $labelRadius = $radius * 0.66;
        $labelX = $cx + $labelRadius * cos(deg2rad($mid));
        $labelY = $cy + $labelRadius * sin(deg2rad($mid));

        $lineHeight = 11.5;
        $blockTop = -(count($lines) - 1) * $lineHeight / 2;

        $svg .= '<g transform="rotate(' . round($theta, 2) . ' ' . round($labelX, 2) . ' ' . round($labelY, 2) . ')">';
        foreach ($lines as $n => $text) {
            $svg .= '<text x="' . round($labelX, 2) . '" y="' . round($labelY + $blockTop + $n * $lineHeight, 2) . '" '
                  . 'text-anchor="middle" dominant-baseline="middle" '
                  . 'font-family="DM Sans, Arial, sans-serif" font-size="10.5" font-weight="700" '
                  . 'fill="' . $ink . '">' . e($text) . '</text>';
        }
        $svg .= '</g>';
    }

    /* Centre disc with the ReviewAI mark. */
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="36" fill="#FFFFFF"/>';
    $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="32" fill="url(#rgWheelCentre)"/>';
    $svg .= '<defs><radialGradient id="rgWheelCentre" cx="0.3" cy="0.2" r="0.9">'
          . '<stop offset="0%" stop-color="#7B4BF5"/><stop offset="100%" stop-color="#3A16A8"/>'
          . '</radialGradient></defs>';
    $svg .= '<text x="' . $cx . '" y="' . ($cy + 1) . '" text-anchor="middle" dominant-baseline="middle" '
          . 'font-family="DM Sans, Arial, sans-serif" font-size="13" font-weight="800" fill="#FFFFFF">✦</text>';
    $svg .= '<text x="' . $cx . '" y="' . ($cy + 15) . '" text-anchor="middle" dominant-baseline="middle" '
          . 'font-family="DM Sans, Arial, sans-serif" font-size="7.5" font-weight="700" fill="#F2E8FF">'
          . e($centreText) . '</text>';
    $svg .= '</svg>';

    return $svg;
}

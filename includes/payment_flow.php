<?php
/**
 * payment_flow.php — the database side of a payment, shared by the browser
 * callback (pay_verify.php) and the webhook (payment_webhook.php).
 *
 * Both paths must be able to activate a card, and both must do it at most once.
 * The rule here: only the transition created → paid may activate anything. The
 * UPDATE is the lock, so two simultaneous callbacks cannot both win.
 *
 * Every function takes a PDO connection as its first argument. The parameter is
 * documented rather than type-hinted so the test suite can inject a stand-in for
 * MySQL (which this sandbox does not have) and exercise the duplicate logic for
 * real. On the live site it is always the real $conn.
 */

/* payments.php may already be loaded (the endpoints require it explicitly, and the
   test harness inlines it), so only pull it in when its functions are missing. */
if (!function_exists('rg_is_duplicate_error')) {
    require_once __DIR__ . '/payments.php';
}

/** Find the plan row by id. Returns null when it does not exist. */
function rg_find_plan($conn, int $planId): ?array
{
    if ($planId <= 0) return null;
    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    return $plan ?: null;
}

/**
 * Record a newly created order. One row per order (unique key on the order id),
 * so a double-click cannot create two rows for one order.
 */
function rg_store_order($conn, int $userId, int $cardId, int $planId, string $orderId, int $amountPaise, string $environment): bool
{
    $stmt = $conn->prepare("
        INSERT INTO payments
            (user_id, card_id, plan_id, razorpay_order_id, amount_paise, currency, status, environment, channel)
        VALUES (?, ?, ?, ?, ?, ?, 'created', ?, 'razorpay')
        ON DUPLICATE KEY UPDATE razorpay_order_id = razorpay_order_id
    ");
    $stmt->execute([$userId, $cardId, $planId, $orderId, $amountPaise, RG_CURRENCY, $environment]);
    return $stmt->rowCount() > 0;
}

/** The payment row for an order, scoped to the user who owns it. */
function rg_find_order_row($conn, string $orderId, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM payments WHERE razorpay_order_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Any payment row by our own id (webhook path). */
function rg_find_payment_by_order($conn, string $orderId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM payments WHERE razorpay_order_id = ? LIMIT 1");
    $stmt->execute([$orderId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Mark an order paid and activate the card — at most once.
 *
 * $plan may be null for the webhook path (then it is loaded from the row).
 * Returns ['ok'=>bool,'reason'=>?string,'already'=>bool,'activated'=>bool].
 */
function rg_activate_order($conn, int $paymentRowId, string $paymentId, string $signature, ?array $plan = null): array
{
    /* Claim the row: only a row that is not paid can move to paid. This single
       UPDATE is what makes duplicate callbacks and replayed webhooks harmless. */
    $claim = $conn->prepare("
        UPDATE payments
        SET status = 'paid', razorpay_payment_id = ?, razorpay_signature = ?, paid_at = NOW()
        WHERE id = ? AND status <> 'paid'
    ");
    try {
        $claim->execute([$paymentId, $signature, $paymentRowId]);
    } catch (Throwable $e) {
        if (rg_is_duplicate_error($e)) {
            return ['ok' => true, 'reason' => 'duplicate_payment', 'already' => true, 'activated' => false];
        }
        throw $e;
    }

    if ($claim->rowCount() !== 1) {
        return ['ok' => true, 'reason' => null, 'already' => true, 'activated' => false];
    }

    /* Re-read the row so the activation uses the stored plan/card, not a request value. */
    $stmt = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
    $stmt->execute([$paymentRowId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'reason' => 'payment_row_missing', 'already' => false, 'activated' => false];
    }

    if ($plan === null) $plan = rg_find_plan($conn, (int)$row['plan_id']);
    if (!$plan) {
        return ['ok' => false, 'reason' => 'plan_missing', 'already' => false, 'activated' => false];
    }

    $duration = (int)($plan['duration'] ?? 30);
    $stmt = $conn->prepare("SELECT id, expiry_date FROM review_cards WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([(int)$row['card_id'], (int)$row['user_id']]);
    $card = $stmt->fetch();
    if (!$card) {
        return ['ok' => false, 'reason' => 'card_missing', 'already' => false, 'activated' => false];
    }

    $newExpiry = rg_next_expiry($card['expiry_date'] ?? null, $duration);
    $membership = strtolower(trim((string)$plan['name']));

    $upd = $conn->prepare("UPDATE review_cards SET membership = ?, expiry_date = ? WHERE id = ?");
    $upd->execute([$membership, $newExpiry, (int)$card['id']]);

    return ['ok' => true, 'reason' => null, 'already' => false, 'activated' => true];
}

/** Mark a payment as failed (webhook payment.failed). Never touches the card. */
function rg_mark_failed($conn, string $orderId, string $paymentId): bool
{
    $stmt = $conn->prepare("
        UPDATE payments
        SET status = 'failed', razorpay_payment_id = COALESCE(razorpay_payment_id, ?)
        WHERE razorpay_order_id = ? AND status = 'created'
    ");
    $stmt->execute([$paymentId, $orderId]);
    return $stmt->rowCount() > 0;
}

/**
 * Record a webhook event. The unique key on the event id is what makes a
 * replayed delivery a no-op instead of a second activation.
 * Returns true when this event is new.
 */
function rg_record_webhook_event($conn, string $eventId, string $eventType, bool $signatureOk, string $environment, string $rawPayload): bool
{
    $stmt = $conn->prepare("
        INSERT INTO webhook_events (event_id, event_type, signature_ok, environment, payload)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE event_id = event_id
    ");
    $stmt->execute([$eventId, $eventType, $signatureOk ? 1 : 0, $environment, $rawPayload]);
    return $stmt->rowCount() > 0;
}

/* -------------------------------------------------------------------------
   One 3-day trial per business account
   ------------------------------------------------------------------------- */

const RG_TRIAL_DAYS = 3;

/**
 * Has this account already used its trial?
 * Two sources: the persistent marker, and a card that is on trial right now
 * (covers accounts created before this column existed).
 */
function rg_trial_used($conn, int $userId): bool
{
    $stmt = $conn->prepare("SELECT trial_started_at FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $started = $stmt->fetchColumn();
    if ($started !== false && $started !== null && $started !== '') return true;

    $stmt = $conn->prepare("SELECT COUNT(*) FROM review_cards WHERE user_id = ? AND membership = 'trial'");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Mark the trial as used, if it is not marked already. Idempotent. */
function rg_mark_trial_started($conn, int $userId): void
{
    $stmt = $conn->prepare("UPDATE users SET trial_started_at = COALESCE(trial_started_at, NOW()) WHERE id = ?");
    $stmt->execute([$userId]);
}

/** True when the card is paid right now (used to protect paid access from trials). */
function rg_card_is_paid(array $card, ?int $now = null): bool
{
    $now = $now ?? time();
    $membership = strtolower(trim((string)($card['membership'] ?? '')));
    if ($membership === '' || $membership === 'trial') return false;
    if (empty($card['expiry_date'])) return false;
    $ts = strtotime((string)$card['expiry_date']);
    return $ts !== false && $ts > $now;
}

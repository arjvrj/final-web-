<?php
/**
 * payments.php — Razorpay subscriptions + WhatsApp fallback.
 *
 * Design rules, in priority order:
 *
 *  1. Money is integers. Everything internal is paise (1 rupee = 100 paise).
 *     Floats are used only to read the DECIMAL price column, then rounded once.
 *  2. Secrets never leave this file. They are read from settings, used, and
 *     dropped. No function here returns or logs a secret.
 *  3. Fail closed. If the mode, the key prefix or the amount cannot be proven
 *     correct, the answer is "no", with a reason code — never a guess.
 *  4. No payment is ever trusted from the browser. The signature, the provider's
 *     own record, and the amount are all re-checked server-side.
 *  5. WhatsApp is a *second* channel, not a fallback that fakes success. A
 *     WhatsApp payment is always "pending until the owner confirms" — it never
 *     grants access by itself.
 *
 * The HTTP transport is injectable so every branch can be tested without a
 * network call and without a real key.
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------------
   Module constants
   --------------------------------------------------------------------------- */

const RG_MODES = ['disabled', 'test', 'live'];
const RG_LIVE_CONFIRM = 'I_AUTHORIZE_REAL_CHARGES';
const RG_CURRENCY = 'INR';

/** Razorpay keys look like rzp_test_XXXX / rzp_live_XXXX. */
const RG_KEY_PATTERN = '/^rzp_(test|live)_[A-Za-z0-9]{6,64}$/';
const RG_PAYMENT_ID_PATTERN = '/^pay_[A-Za-z0-9]+$/';
const RG_SUBSCRIPTION_ID_PATTERN = '/^sub_[A-Za-z0-9]+$/';
const RG_SIGNATURE_PATTERN = '/^[a-fA-F0-9]{64}$/';

/* ---------------------------------------------------------------------------
   Config + mode gating
   --------------------------------------------------------------------------- */

/**
 * Reads payment config from the settings table (values are strings).
 * Never echoes, never returns secrets to the caller in a public context —
 * callers must not render this array.
 */
function rg_payment_config(array $settings): array
{
    return [
        'mode'            => strtolower(trim((string)($settings['payments_mode'] ?? 'disabled'))),
        'key_id'          => trim((string)($settings['razorpay_key_id'] ?? '')),
        'key_secret'      => trim((string)($settings['razorpay_key_secret'] ?? '')),
        'webhook_secret'  => trim((string)($settings['razorpay_webhook_secret'] ?? '')),
        'live_confirm'    => trim((string)($settings['razorpay_live_confirm'] ?? '')),
        'whatsapp'        => trim((string)($settings['whatsapp_number'] ?? '')),
        'timeout'         => max(2, min(30, (int)($settings['razorpay_timeout'] ?? 15))),
    ];
}

/**
 * Is checkout allowed right now? Returns ['enabled'=>bool, 'mode'=>string, 'reason'=>?string].
 *
 * Reasons are stable codes the UI can map to friendly text:
 *   payments_disabled | mode_invalid | key_missing | key_mode_mismatch |
 *   secret_missing
 */
function rg_payment_status(array $cfg): array
{
    $mode = $cfg['mode'];
    if (!in_array($mode, RG_MODES, true)) {
        return ['enabled' => false, 'mode' => $mode, 'reason' => 'mode_invalid'];
    }
    if ($mode === 'disabled') {
        return ['enabled' => false, 'mode' => 'disabled', 'reason' => 'payments_disabled'];
    }
    if ($cfg['key_id'] === '') {
        return ['enabled' => false, 'mode' => $mode, 'reason' => 'key_missing'];
    }
    if (!preg_match(RG_KEY_PATTERN, $cfg['key_id'], $m)) {
        return ['enabled' => false, 'mode' => $mode, 'reason' => 'key_missing'];
    }
    // A test key in live mode (or the reverse) is the most common mistake.
    if ($m[1] !== $mode) {
        return ['enabled' => false, 'mode' => $mode, 'reason' => 'key_mode_mismatch'];
    }
    if ($cfg['key_secret'] === '') {
        return ['enabled' => false, 'mode' => $mode, 'reason' => 'secret_missing'];
    }

    /* Live mode is authorised when it is saved: owner_settings.php refuses to
       store `live` unless the operator types the confirmation phrase. The
       phrase itself is deliberately never stored, so it must not be required
       again here — doing that made every live install look unconfirmed and
       hid the Pay button. */
    return ['enabled' => true, 'mode' => $mode, 'reason' => null];
}

/**
 * The ONLY config shape that may be rendered into a page or returned as JSON.
 * Anything not listed here (secrets, the webhook secret, the confirm phrase)
 * stays server-side by construction, not by remembering to unset it.
 */
function rg_public_config(array $cfg): array
{
    $status = rg_payment_status($cfg);
    return [
        'enabled'   => $status['enabled'],
        'mode'      => $status['mode'],
        'reason'    => $status['reason'],
        'key_id'    => $status['enabled'] ? $cfg['key_id'] : null, // public by design
        'currency'  => RG_CURRENCY,
        'whatsapp_available' => rg_whatsapp_number($cfg['whatsapp']) !== '',
    ];
}

/* ---------------------------------------------------------------------------
   Money
   --------------------------------------------------------------------------- */

/**
 * Converts a DECIMAL rupees string from the plans table into integer paise.
 * Sale price wins when it is present and positive.
 */
function rg_plan_amount_paise(array $plan): int
{
    $price = (float)($plan['price'] ?? 0);
    $sale  = isset($plan['sale_price']) && $plan['sale_price'] !== null ? (float)$plan['sale_price'] : 0.0;
    $chosen = ($sale > 0 && $sale < $price) ? $sale : $price;
    if ($chosen <= 0) {
        return 0;
    }
    return (int)round($chosen * 100);
}

/** Human text for a paise amount, e.g. 69900 -> "₹699.00". */
function rg_money(int $paise): string
{
    return '₹' . number_format($paise / 100, 2);
}

/* ---------------------------------------------------------------------------
   Signature verification
   --------------------------------------------------------------------------- */

/**
 * Razorpay checkout signature = HMAC_SHA256(payment_id + "|" + subscription_id, key_secret).
 * Compared with hash_equals so it cannot be timing-attacked.
 */
function rg_verify_checkout_signature(string $paymentId, string $subscriptionId, string $signature, string $secret): bool
{
    if ($secret === '') {
        return false;
    }
    if (!preg_match(RG_PAYMENT_ID_PATTERN, $paymentId)) {
        return false;
    }
    if (!preg_match(RG_SUBSCRIPTION_ID_PATTERN, $subscriptionId)) {
        return false;
    }
    if (!preg_match(RG_SIGNATURE_PATTERN, $signature)) {
        return false;
    }
    $expected = hash_hmac('sha256', $paymentId . '|' . $subscriptionId, $secret);
    return hash_equals($expected, strtolower($signature));
}

/**
 * Webhook signature = HMAC_SHA256(raw request body, webhook_secret).
 * The RAW bytes must be used, never a re-encoded JSON array.
 */
function rg_verify_webhook_signature(string $rawBody, string $signature, string $secret): bool
{
    if ($secret === '' || $rawBody === '') {
        return false;
    }
    if (!preg_match(RG_SIGNATURE_PATTERN, $signature)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
}

/* ---------------------------------------------------------------------------
   Razorpay API
   --------------------------------------------------------------------------- */

/**
 * Calls the Razorpay API. $transport, when given, receives
 * (method, url, headers, bodyOrNull) and must return ['status'=>int,'body'=>string].
 * This is the only place the key secret is used, and it is never returned.
 */
function rg_razorpay_request(array $cfg, string $method, string $path, ?array $body, ?callable $transport = null): array
{
    $url = 'https://api.razorpay.com/v1' . $path;
    $payload = $body === null ? null : json_encode($body);
    $headers = [
        'Authorization: Basic ' . base64_encode($cfg['key_id'] . ':' . $cfg['key_secret']),
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if ($transport !== null) {
        $raw = $transport($method, $url, $headers, $payload);
        $status = (int)($raw['status'] ?? 0);
        $text = (string)($raw['body'] ?? '');
    } elseif (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => (int)$cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
        curl_setopt_array($ch, $options);
        $text = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($text === '' || curl_errno($ch) !== 0) {
            $status = 0;
        }
        curl_close($ch);
    } else {
        // No curl extension on this host: fail closed rather than silently.
        $status = 0;
        $text = '';
    }

    $decoded = json_decode($text, true);
    return [
        'ok'     => $status >= 200 && $status < 300 && is_array($decoded),
        'status' => $status,
        'data'   => is_array($decoded) ? $decoded : [],
    ];
}

/* ---------------------------------------------------------------------------
   Subscription creation
   --------------------------------------------------------------------------- */

/**
 * Builds the request Razorpay needs. Kept separate from the network call so the
 * payload can be asserted in tests.
 */
function rg_subscription_payload(string $razorpayPlanId, int $totalCount, array $notes): array
{
    return [
        'plan_id'    => $razorpayPlanId,
        'total_count' => max(1, min(120, $totalCount)),
        'quantity'   => 1,
        'customer_notify' => 1,
        'notes'      => $notes,
    ];
}

/**
 * Creates a subscription.
 * Returns ['ok'=>bool,'subscription_id'=>?string,'reason'=>?string,'mode'=>string,'amount_paise'=>int].
 */
function rg_create_subscription(array $cfg, array $plan, string $period, int $userId, int $cardId, ?callable $transport = null): array
{
    $status = rg_payment_status($cfg);
    if (!$status['enabled']) {
        return ['ok' => false, 'subscription_id' => null, 'reason' => $status['reason'], 'mode' => $status['mode'], 'amount_paise' => 0];
    }

    $amountPaise = rg_plan_amount_paise($plan);
    if ($amountPaise <= 0) {
        return ['ok' => false, 'subscription_id' => null, 'reason' => 'plan_price_invalid', 'mode' => $status['mode'], 'amount_paise' => 0];
    }

    $razorpayPlanId = trim((string)($plan['razorpay_plan_' . $period] ?? ''));
    if (!preg_match('/^plan_[A-Za-z0-9]+$/', $razorpayPlanId)) {
        return ['ok' => false, 'subscription_id' => null, 'reason' => 'razorpay_plan_missing', 'mode' => $status['mode'], 'amount_paise' => $amountPaise];
    }

    $totalCount = $period === 'yearly' ? 5 : 12;
    $payload = rg_subscription_payload($razorpayPlanId, $totalCount, [
        'user_id' => (string)$userId,
        'card_id' => (string)$cardId,
        'plan_id' => (string)($plan['id'] ?? ''),
        'period'  => $period,
    ]);

    $response = rg_razorpay_request($cfg, 'POST', '/subscriptions', $payload, $transport);
    if (!$response['ok']) {
        return ['ok' => false, 'subscription_id' => null, 'reason' => 'provider_error', 'mode' => $status['mode'], 'amount_paise' => $amountPaise];
    }
    $id = (string)($response['data']['id'] ?? '');
    if (!preg_match(RG_SUBSCRIPTION_ID_PATTERN, $id)) {
        return ['ok' => false, 'subscription_id' => null, 'reason' => 'provider_error', 'mode' => $status['mode'], 'amount_paise' => $amountPaise];
    }

    return [
        'ok' => true,
        'subscription_id' => $id,
        'reason' => null,
        'mode' => $status['mode'],
        'amount_paise' => $amountPaise,
        'key_id' => $cfg['key_id'], // public value, safe for the browser
        'currency' => RG_CURRENCY,
    ];
}

/* ---------------------------------------------------------------------------
   Provider-side confirmation
   --------------------------------------------------------------------------- */

/**
 * Confirms a payment with Razorpay itself. A signature only proves the pair was
 * created by us — it does not prove money moved. All four checks must pass.
 */
function rg_confirm_payment_with_provider(array $cfg, string $paymentId, string $subscriptionId, int $expectedPaise, ?callable $transport = null): array
{
    $status = rg_payment_status($cfg);
    if (!$status['enabled']) {
        return ['ok' => false, 'reason' => $status['reason']];
    }
    if (!preg_match(RG_PAYMENT_ID_PATTERN, $paymentId)) {
        return ['ok' => false, 'reason' => 'invalid_request'];
    }

    $response = rg_razorpay_request($cfg, 'GET', '/payments/' . rawurlencode($paymentId), null, $transport);
    if (!$response['ok']) {
        return ['ok' => false, 'reason' => 'provider_error'];
    }
    $p = $response['data'];
    if (($p['entity'] ?? '') !== 'payment') {
        return ['ok' => false, 'reason' => 'provider_error'];
    }
    if (($p['status'] ?? '') !== 'captured') {
        return ['ok' => false, 'reason' => 'payment_not_confirmed'];
    }
    if (($p['currency'] ?? '') !== RG_CURRENCY) {
        return ['ok' => false, 'reason' => 'currency_mismatch'];
    }
    if ((int)($p['amount'] ?? -1) !== $expectedPaise) {
        return ['ok' => false, 'reason' => 'amount_mismatch'];
    }
    $providerSub = (string)($p['subscription_id'] ?? '');
    if ($providerSub !== '' && $providerSub !== $subscriptionId) {
        return ['ok' => false, 'reason' => 'subscription_mismatch'];
    }
    return ['ok' => true, 'reason' => null, 'paid_at' => (string)($p['created_at'] ?? '')];
}

/* ---------------------------------------------------------------------------
   Recording a payment (DB write isolated)
   --------------------------------------------------------------------------- */

/**
 * Builds the row to insert. Pure, so it can be asserted.
 * Environment is derived from the mode and cannot be overridden by a request.
 */
function rg_payment_row(array $args): array
{
    $mode = $args['mode'] === 'live' ? 'live' : 'test';
    return [
        'user_id'          => (int)$args['user_id'],
        'card_id'          => (int)$args['card_id'],
        'plan_id'          => (int)$args['plan_id'],
        'razorpay_payment_id' => (string)$args['payment_id'],
        'razorpay_subscription_id' => (string)$args['subscription_id'],
        'amount_paise'     => (int)$args['amount_paise'],
        'currency'         => RG_CURRENCY,
        'status'           => 'captured',
        'environment'      => $mode,
        'channel'          => 'razorpay',
        'paid_at'          => $args['paid_at'] !== '' ? $args['paid_at'] : date('Y-m-d H:i:s'),
    ];
}

/**
 * True when a PDO unique-violation means "we already recorded this payment".
 * Razorpay retries webhooks; a retry must never extend the subscription twice.
 */
function rg_is_duplicate_error(Throwable $e): bool
{
    $code = (string)$e->getCode();
    $message = $e->getMessage();
    if ($code === '23000') {
        return true;
    }
    return stripos($message, 'Duplicate entry') !== false
        || stripos($message, 'Integrity constraint violation') !== false;
}

/* ---------------------------------------------------------------------------
   WhatsApp channel
   --------------------------------------------------------------------------- */

/**
 * WhatsApp numbers must be digits only with country code: "919876543210".
 * Accepts "+91 98765 43210", "0091-98765-43210", "919876543210".
 */

/* =========================================================================
   ORDERS — one-time payment for one plan period (Stage 7)

   Razorpay Orders are used instead of Subscriptions: the amount comes from the
   plan row, the customer pays once, and the card's expiry is pushed forward by
   the plan's duration. Nothing auto-debits and nothing auto-refunds.
   ========================================================================= */

/** Razorpay order id shape. */
const RG_ORDER_ID_PATTERN = '/^order_[A-Za-z0-9]+$/';

/**
 * Build the /orders payload. Amount is always derived here, never accepted from
 * a caller that got it from a browser.
 */
function rg_order_payload(array $plan, int $userId, int $cardId, string $receipt): array
{
    $amountPaise = rg_plan_amount_paise($plan);

    return [
        'amount'          => $amountPaise,
        'currency'        => RG_CURRENCY,
        'receipt'         => $receipt,
        'payment_capture' => 1,
        'notes'           => [
            'user_id' => (string)$userId,
            'card_id' => (string)$cardId,
            'plan_id' => (string)($plan['id'] ?? ''),
            'plan'    => (string)($plan['name'] ?? ''),
        ],
    ];
}

/**
 * Create an order server-side.
 * Returns ['ok'=>bool,'order_id'=>?string,'amount_paise'=>int,'reason'=>?string,'mode'=>string]
 */
function rg_create_order(array $cfg, array $plan, int $userId, int $cardId, string $receipt, ?callable $transport = null): array
{
    $status = rg_payment_status($cfg);
    if (!$status['enabled']) {
        return ['ok' => false, 'order_id' => null, 'amount_paise' => 0, 'reason' => $status['reason'], 'mode' => $status['mode']];
    }

    $payload     = rg_order_payload($plan, $userId, $cardId, $receipt);
    $amountPaise = (int)$payload['amount'];
    if ($amountPaise <= 0) {
        return ['ok' => false, 'order_id' => null, 'amount_paise' => 0, 'reason' => 'plan_price_invalid', 'mode' => $status['mode']];
    }

    $response = rg_razorpay_request($cfg, 'POST', '/orders', $payload, $transport);
    if (!$response['ok']) {
        return ['ok' => false, 'order_id' => null, 'amount_paise' => $amountPaise, 'reason' => 'provider_error', 'mode' => $status['mode']];
    }

    $orderId = (string)($response['data']['id'] ?? '');
    if (!preg_match(RG_ORDER_ID_PATTERN, $orderId)) {
        return ['ok' => false, 'order_id' => null, 'amount_paise' => $amountPaise, 'reason' => 'provider_error', 'mode' => $status['mode']];
    }

    /* The provider's amount must be the amount we asked for. */
    if ((int)($response['data']['amount'] ?? -1) !== $amountPaise) {
        return ['ok' => false, 'order_id' => null, 'amount_paise' => $amountPaise, 'reason' => 'amount_mismatch', 'mode' => $status['mode']];
    }

    return ['ok' => true, 'order_id' => $orderId, 'amount_paise' => $amountPaise, 'reason' => null, 'mode' => $status['mode']];
}

/**
 * Checkout signature for an order: HMAC_SHA256(order_id + "|" + payment_id, key_secret).
 */
function rg_verify_order_signature(string $orderId, string $paymentId, string $signature, string $secret): bool
{
    if ($secret === '' || !preg_match(RG_ORDER_ID_PATTERN, $orderId) || !preg_match(RG_PAYMENT_ID_PATTERN, $paymentId)) {
        return false;
    }
    if (!preg_match(RG_SIGNATURE_PATTERN, $signature)) {
        return false;
    }
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    return hash_equals($expected, strtolower($signature));
}

/**
 * Ask Razorpay directly whether this order was really paid, for the right
 * amount, in the right currency. A valid signature alone is not enough.
 *
 * Returns ['ok'=>bool,'reason'=>?string,'paid_at'=>string,'payment_id'=>string]
 */
function rg_confirm_order_payment(array $cfg, string $orderId, string $paymentId, int $expectedPaise, ?callable $transport = null): array
{
    $status = rg_payment_status($cfg);
    if (!$status['enabled']) {
        return ['ok' => false, 'reason' => $status['reason'], 'paid_at' => '', 'payment_id' => ''];
    }
    if (!preg_match(RG_ORDER_ID_PATTERN, $orderId) || !preg_match(RG_PAYMENT_ID_PATTERN, $paymentId)) {
        return ['ok' => false, 'reason' => 'invalid_request', 'paid_at' => '', 'payment_id' => ''];
    }

    /* 1. the order must be recorded as paid, for our amount and currency */
    $order = rg_razorpay_request($cfg, 'GET', '/orders/' . rawurlencode($orderId), null, $transport);
    if (!$order['ok']) {
        return ['ok' => false, 'reason' => 'provider_error', 'paid_at' => '', 'payment_id' => ''];
    }
    $o = $order['data'];
    if (($o['entity'] ?? '') !== 'order') {
        return ['ok' => false, 'reason' => 'provider_error', 'paid_at' => '', 'payment_id' => ''];
    }
    if (($o['status'] ?? '') !== 'paid') {
        return ['ok' => false, 'reason' => 'payment_not_confirmed', 'paid_at' => '', 'payment_id' => ''];
    }
    if ((int)($o['amount'] ?? -1) !== $expectedPaise) {
        return ['ok' => false, 'reason' => 'amount_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }
    if ((string)($o['amount_paid'] ?? -1) !== (string)$expectedPaise) {
        return ['ok' => false, 'reason' => 'amount_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }
    if (($o['currency'] ?? '') !== RG_CURRENCY) {
        return ['ok' => false, 'reason' => 'currency_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }

    /* 2. the payment must be captured and belong to this order */
    $payment = rg_razorpay_request($cfg, 'GET', '/payments/' . rawurlencode($paymentId), null, $transport);
    if (!$payment['ok']) {
        return ['ok' => false, 'reason' => 'provider_error', 'paid_at' => '', 'payment_id' => ''];
    }
    $p = $payment['data'];
    if (($p['entity'] ?? '') !== 'payment') {
        return ['ok' => false, 'reason' => 'provider_error', 'paid_at' => '', 'payment_id' => ''];
    }
    if (($p['status'] ?? '') !== 'captured') {
        return ['ok' => false, 'reason' => 'payment_not_confirmed', 'paid_at' => '', 'payment_id' => ''];
    }
    if ((int)($p['amount'] ?? -1) !== $expectedPaise) {
        return ['ok' => false, 'reason' => 'amount_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }
    if (($p['currency'] ?? '') !== RG_CURRENCY) {
        return ['ok' => false, 'reason' => 'currency_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }
    if ((string)($p['order_id'] ?? '') !== $orderId) {
        return ['ok' => false, 'reason' => 'order_mismatch', 'paid_at' => '', 'payment_id' => ''];
    }

    return ['ok' => true, 'reason' => null, 'paid_at' => (string)($p['created_at'] ?? ''), 'payment_id' => $paymentId];
}

/**
 * Where a paid period starts and ends.
 * Renewals stack: a card that is still active is extended from its current
 * expiry, never shortened and never restarted from today.
 */
function rg_next_expiry(?string $currentExpiry, int $durationDays, ?int $now = null): string
{
    $now     = $now ?? time();
    $days    = max(1, $durationDays);
    $startTs = $now;

    if ($currentExpiry !== null && $currentExpiry !== '' && $currentExpiry !== '0000-00-00 00:00:00') {
        $ts = strtotime($currentExpiry);
        if ($ts !== false && $ts > $now) $startTs = $ts;
    }

    return date('Y-m-d H:i:s', $startTs + ($days * 86400));
}

function rg_whatsapp_number(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';

    // 00-prefixed international form: 0091... -> 91...
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    // 0-prefixed national trunk form: 09876543210 -> 9876543210
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    // Bare 10-digit number: assume India, the default for this product.
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    // An E.164 number is 11-15 digits. Anything else is not a usable number.
    if (strlen($digits) < 11 || strlen($digits) > 15) {
        return '';
    }
    return $digits;
}

/**
 * Builds a wa.me deep link with a pre-filled message.
 * wa.me is the documented way to start a chat without the API.
 */
function rg_whatsapp_link(string $number, string $message): string
{
    $digits = rg_whatsapp_number($number);
    if ($digits === '') {
        return '';
    }
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

/**
 * The message a customer sends when they choose "pay on WhatsApp".
 * Deliberately contains no card data and no secrets.
 */
function rg_whatsapp_payment_message(string $customerEmail, string $cardName, string $planName, int $amountPaise, string $period): string
{
    return implode("\n", [
        'Hi! I would like to activate a plan.',
        'Business: ' . $cardName,
        'Plan: ' . $planName . ' (' . $period . ')',
        'Amount: ' . rg_money($amountPaise),
        'My account: ' . $customerEmail,
        'Please send me the payment link or UPI details.',
    ]);
}

/**
 * Owner-side notification when a customer claims a WhatsApp payment.
 */
function rg_whatsapp_owner_message(string $customerEmail, string $cardName, string $planName): string
{
    return implode("\n", [
        'New plan request (WhatsApp channel)',
        'Business: ' . $cardName,
        'Plan: ' . $planName,
        'Account: ' . $customerEmail,
        'Confirm the payment, then activate it in the owner panel.',
    ]);
}

/**
 * WhatsApp requests are always PENDING. They never grant access.
 * This function exists so no caller can accidentally treat one as paid.
 */
function rg_whatsapp_request_row(array $args): array
{
    return [
        'user_id'     => (int)$args['user_id'],
        'card_id'     => (int)$args['card_id'],
        'plan_id'     => (int)$args['plan_id'],
        'amount_paise' => (int)$args['amount_paise'],
        'currency'    => RG_CURRENCY,
        'status'      => 'pending',
        'channel'     => 'whatsapp',
        'environment' => 'manual',
        'razorpay_payment_id' => null,
        'created_at'  => date('Y-m-d H:i:s'),
    ];
}

/* ---------------------------------------------------------------------------
   Customer-facing messages
   --------------------------------------------------------------------------- */

/** One honest sentence per outcome. Never claims access that was not granted. */
function rg_payment_message(string $reason): string
{
    switch ($reason) {
        case 'applied':
            return 'Payment confirmed. Your plan is active.';
        case 'already_recorded':
            return 'That payment was already recorded, so nothing was charged or extended twice.';
        case 'payments_disabled':
            return 'Card payments are not available right now. You can still request activation on WhatsApp.';
        case 'whatsapp_pending':
            return 'Your request was sent. Your plan activates as soon as the payment is confirmed.';
        case 'payment_not_confirmed':
            return 'The payment is not confirmed yet. Nothing has been activated.';
        case 'amount_mismatch':
            return 'The amount paid does not match the plan price. Nothing has been activated — contact support.';
        case 'invalid_signature':
            return 'We could not verify that payment. Nothing has been activated.';
        default:
            return 'We could not complete that. Your plan has not been changed.';
    }
}

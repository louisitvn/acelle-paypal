<?php

namespace Acelle\Paypal\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;

/**
 * Thin wrapper around PayPal REST API (Orders v2 + Subscriptions v1 + OAuth).
 *
 * Auth model: client-credentials OAuth. We hit POST /v1/oauth2/token with
 * Basic auth (client_id:client_secret), receive a Bearer access_token valid
 * ~9h. Cached per instance lifetime — one token reused across multiple calls
 * in the same request. No cross-request cache because each Init / Capture
 * happens in its own PHP request; round-tripping a token to the DB would add
 * complexity (lock contention, expiration races) without a real win.
 *
 * Error envelope: PayPal returns `{name, message, details:[{issue,description}],
 * debug_id}`. We pull `details[0].description` → `message` → `name` and
 * surface it on the exception so the customer-facing "Last payment failed:
 * <reason>" banner (subscription/payment.blade.php) shows something useful
 * — never just "Bad Request" or HTTP code.
 */
class PayPalApi
{
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const LIVE_BASE    = 'https://api-m.paypal.com';

    private Client $http;
    private string $clientId;
    private string $clientSecret;
    private ?string $cachedToken = null;

    public function __construct(string $clientId, string $clientSecret, string $environment = 'sandbox')
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $base = $environment === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
        $this->http = new Client([
            'base_uri'        => $base,
            'timeout'         => 20,
            'connect_timeout' => 5,
            'http_errors'     => true,
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string,string> $headers Extra request headers merged over the
     *        Authorization/Accept/Content-Type defaults. Used for `PayPal-Request-Id`
     *        (idempotency key) on charge calls so a cron retry never double-charges.
     */
    public function post(string $path, array $body, array $headers = []): array
    {
        $options = ['json' => self::jsonBody($body)];
        if ($headers !== []) {
            $options['headers'] = $headers;
        }
        return $this->request('POST', $path, $options);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, ['json' => self::jsonBody($body)]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Off-session merchant-initiated charge against a saved PayPal vault id.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Merchant-Initiated Transaction (MIT) against a saved PayPal wallet vault id —
     * the unattended off-session renewal charge (no buyer present).
     *
     * Single synchronous call: POST /v2/checkout/orders with intent=CAPTURE and
     * `payment_source.paypal.vault_id` — PayPal both creates AND captures the order in
     * one round-trip (no separate buyer approve → capture step). `stored_credential`
     * is REQUIRED to mark this as merchant-initiated recurring, otherwise PayPal
     * rejects it as needing cardholder presence.
     *
     * Idempotency: `PayPal-Request-Id` header is set to a caller-supplied stable key so
     * a cron retry (same key) returns the already-created order instead of charging twice.
     *
     * Returns the full Orders v2 create-with-capture response — caller reads top-level
     * `id` (order id) + `status` (COMPLETED / DECLINED / PAYER_ACTION_REQUIRED / …).
     *
     * @link https://developer.paypal.com/docs/checkout/save-payment-methods/purchase-later/paypal/#link-chargethevaultedpaymentmethod
     */
    public function createVaultCharge(
        string $vaultId,
        string $referenceId,
        float $amountMajor,
        string $currency,
        string $description,
        string $idempotencyKey,
    ): array {
        $iso   = self::assertSupportedCurrency($currency);
        $value = self::formatAmount($amountMajor, $iso);

        return $this->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $referenceId,
                'description'  => substr($description, 0, 127),
                'amount'       => [
                    'currency_code' => $iso,
                    'value'         => $value,
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'vault_id' => $vaultId,
                    // REQUIRED for merchant-initiated recurring — without this
                    // block PayPal treats the charge as needing cardholder presence
                    // and returns PAYER_ACTION_REQUIRED / rejects it.
                    'stored_credential' => [
                        'payment_initiator' => 'MERCHANT',
                        'usage'             => 'SUBSEQUENT',
                        'usage_pattern'     => 'RECURRING_PREPAID',
                    ],
                ],
            ],
        ], [
            // Same key on a cron retry → PayPal returns the already-created order
            // rather than charging the saved wallet a second time.
            'PayPal-Request-Id' => $idempotencyKey,
        ]);
    }

    /**
     * PayPal endpoints reject "[]" as malformed JSON when the body is meaningfully
     * empty (e.g. capture order, activate plan — both accept empty bodies but
     * expect "{}" not "[]"). PHP arrays with no keys serialize to "[]" — wrap
     * in stdClass so json_encode emits "{}". Keyed arrays pass through unchanged.
     */
    private static function jsonBody(array $body): array|\stdClass
    {
        return empty($body) ? new \stdClass() : $body;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  internals
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Single Bearer token cached for the lifetime of this PayPalApi instance.
     * Subsequent calls reuse — no extra `/oauth2/token` round-trip per API
     * call within the same request.
     */
    private function authToken(): string
    {
        if ($this->cachedToken !== null) {
            return $this->cachedToken;
        }
        try {
            $resp = $this->http->post('/v1/oauth2/token', [
                'auth'        => [$this->clientId, $this->clientSecret],
                'form_params' => ['grant_type' => 'client_credentials'],
                'headers'     => ['Accept' => 'application/json'],
            ]);
        } catch (BadResponseException $e) {
            throw new \RuntimeException(
                'PayPal OAuth failed: ' . self::extractErrorMessage($e),
                0,
                $e
            );
        }

        $data = json_decode((string) $resp->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('PayPal OAuth: malformed token response');
        }
        return $this->cachedToken = (string) $data['access_token'];
    }

    private function request(string $method, string $path, array $options): array
    {
        $opts = array_merge_recursive($options, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->authToken(),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
        ]);

        try {
            $resp = $this->http->request($method, $path, $opts);
        } catch (BadResponseException $e) {
            throw new \RuntimeException(
                "PayPal {$method} {$path} failed: " . self::extractErrorMessage($e),
                0,
                $e
            );
        }

        $body = (string) $resp->getBody();
        // 204 No Content for cancel / revise — PayPal returns empty body
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("PayPal {$method} {$path}: malformed JSON response");
        }
        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Static vendor helpers (currency map + amount format + HATEOAS link).
    //  Used by PayPalGateway (one-off + off-session vault auto-charge); the
    //  vendor-specific rules live here so callers don't duplicate them.
    // ─────────────────────────────────────────────────────────────────────

    public const SUPPORTED_CURRENCIES = [
        'USD','EUR','GBP','CAD','AUD','JPY','CHF','NZD','SGD','HKD','SEK',
        'DKK','PLN','CZK','HUF','BRL','MXN','TWD','THB','PHP','MYR','ILS','NOK',
    ];

    public const ZERO_DECIMAL_CURRENCIES = ['JPY'];

    public static function assertSupportedCurrency(string $currency): string
    {
        $iso = strtoupper($currency);
        if (!in_array($iso, self::SUPPORTED_CURRENCIES, true)) {
            throw new \InvalidArgumentException(
                "PayPal: unsupported currency '{$currency}'. Supported: "
                . implode(', ', self::SUPPORTED_CURRENCIES) . '.'
            );
        }
        return $iso;
    }

    /**
     * PayPal Orders v2 wants `amount.value` as a decimal string. Zero-decimal
     * currencies (JPY in our list) must NOT have decimals — `"49.00"` is
     * rejected by PayPal as `INVALID_PARAMETER_VALUE` for JPY.
     */
    public static function formatAmount(float $amountMajor, string $iso): string
    {
        if (in_array($iso, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (string) (int) round($amountMajor);
        }
        return number_format($amountMajor, 2, '.', '');
    }

    /** Pull a link by rel from PayPal's HATEOAS links[] block. */
    public static function linkRel(array $links, string $rel): ?string
    {
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === $rel) {
                return $link['href'] ?? null;
            }
        }
        return null;
    }

    /**
     * Pull the most-informative human reason from a PayPal error envelope.
     * Priority: details[0].description → details[0].issue → message → name →
     * HTTP code. Returned string is what the customer banner / admin log will
     * display; making this useful matters more than purity.
     */
    public static function extractErrorMessage(BadResponseException $e): string
    {
        $code = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'unknown';
        $raw  = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        $body = json_decode($raw, true);
        if (is_array($body)) {
            if (!empty($body['details'][0]['description'])) {
                return "[{$code}] " . $body['details'][0]['description'];
            }
            if (!empty($body['details'][0]['issue'])) {
                return "[{$code}] " . $body['details'][0]['issue'];
            }
            if (!empty($body['message'])) {
                return "[{$code}] " . $body['message'];
            }
            if (!empty($body['name'])) {
                return "[{$code}] " . $body['name'];
            }
        }
        return "[{$code}] " . ($raw !== '' ? substr($raw, 0, 200) : 'no response body');
    }
}

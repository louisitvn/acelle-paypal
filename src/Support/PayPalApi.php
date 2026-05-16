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

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, ['json' => $body]);
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

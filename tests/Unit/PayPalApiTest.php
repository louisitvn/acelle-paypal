<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Support\PayPalApi;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Unit tests for PayPalApi::extractErrorMessage — the **customer-facing reason
 * surface**. Bug here = banner shows empty / HTTP code instead of the actual
 * reason ("Currency mismatch with terminal", "Card declined: insufficient
 * funds", etc.). Worth covering exhaustively since it's what admin and
 * customers see when things go wrong.
 *
 * Priority chain we MUST preserve:
 *   details[0].description  →  details[0].issue  →  message  →  name  →  HTTP code
 */
class PayPalApiTest extends TestCase
{
    public function test_extracts_details_description_first(): void
    {
        $body = [
            'name'    => 'INVALID_REQUEST',
            'message' => 'Request is not well formed',
            'details' => [[
                'issue'       => 'CURRENCY_NOT_SUPPORTED_FOR_COUNTRY',
                'description' => 'The currency code is not supported for the merchant country.',
            ]],
        ];
        $exception = $this->makeException(422, $body);
        $msg = PayPalApi::extractErrorMessage($exception);

        $this->assertStringContainsString('422', $msg);
        $this->assertStringContainsString('not supported for the merchant country', $msg);
    }

    public function test_falls_back_to_details_issue_when_no_description(): void
    {
        $body = [
            'name'    => 'INVALID_REQUEST',
            'message' => 'Generic',
            'details' => [['issue' => 'INVALID_PARAMETER_VALUE']],
        ];
        $msg = PayPalApi::extractErrorMessage($this->makeException(400, $body));

        $this->assertStringContainsString('400', $msg);
        $this->assertStringContainsString('INVALID_PARAMETER_VALUE', $msg);
        $this->assertStringNotContainsString('Generic', $msg,
            'details.issue should take priority over message');
    }

    public function test_falls_back_to_message_when_no_details(): void
    {
        $body = [
            'name'    => 'UNPROCESSABLE_ENTITY',
            'message' => 'PayPal could not process this request.',
        ];
        $msg = PayPalApi::extractErrorMessage($this->makeException(422, $body));

        $this->assertStringContainsString('422', $msg);
        $this->assertStringContainsString('PayPal could not process', $msg);
    }

    public function test_falls_back_to_name_when_no_message(): void
    {
        $body = ['name' => 'INTERNAL_SERVER_ERROR'];
        $msg  = PayPalApi::extractErrorMessage($this->makeException(500, $body));

        $this->assertStringContainsString('500', $msg);
        $this->assertStringContainsString('INTERNAL_SERVER_ERROR', $msg);
    }

    public function test_returns_http_code_when_body_is_garbage(): void
    {
        $exception = $this->makeRawException(503, '<html>503 Service Unavailable</html>');
        $msg = PayPalApi::extractErrorMessage($exception);

        $this->assertStringContainsString('503', $msg);
    }

    public function test_returns_http_code_when_body_is_empty(): void
    {
        $exception = $this->makeRawException(502, '');
        $msg = PayPalApi::extractErrorMessage($exception);

        $this->assertStringContainsString('502', $msg);
        $this->assertStringContainsString('no response body', $msg);
    }

    public function test_truncates_long_garbage_body_to_200_chars(): void
    {
        $longGarbage = str_repeat('y', 5000);
        $exception = $this->makeRawException(500, $longGarbage);
        $msg = PayPalApi::extractErrorMessage($exception);

        // expected: "[500] " + 200 chars of 'y' = "[500] yyy...yyy" — overall < 220
        $this->assertLessThan(250, strlen($msg),
            'Garbage body must be truncated to keep banner UI sane');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeException(int $status, array $jsonBody): BadResponseException
    {
        return $this->makeRawException($status, json_encode($jsonBody));
    }

    private function makeRawException(int $status, string $rawBody): BadResponseException
    {
        $req  = new Request('POST', '/v2/checkout/orders');
        $resp = new Response($status, [], $rawBody);
        return new BadResponseException("HTTP {$status}", $req, $resp);
    }
}

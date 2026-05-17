<?php

namespace Acelle\Paypal\Tests\Unit;

use Acelle\Paypal\Support\PayPalApi;

/**
 * Test double for PayPalApi shared by gateway test files.
 *
 * Three modes:
 *   1. Static canned response — single response returned every call (ctor arg)
 *   2. Queue mode — `queueResponses(...)` to return different responses per call
 *   3. Throw mode — `throwOnNextCall(...)` queues a single \Throwable
 *
 * Captures every call into `$callHistory` so tests can introspect call order.
 * `lastMethod / lastPath / lastBody / lastQuery` mirror the latest call.
 */
class FakePayPalApi extends PayPalApi
{
    public ?string $lastMethod = null;
    public ?string $lastPath   = null;
    public array $lastBody     = [];
    public array $lastQuery    = [];
    public array $callHistory  = [];
    private array $responseQueue = [];
    private ?\Throwable $nextThrow = null;
    private bool $useQueue = false;
    private array $staticResponse = [];

    public function __construct(array $response = [])
    {
        $this->staticResponse = $response;
    }

    public function queueResponses(array ...$responses): void
    {
        $this->responseQueue = $responses;
        $this->useQueue      = true;
    }

    public function throwOnNextCall(\Throwable $e): void
    {
        $this->nextThrow = $e;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->capture('GET', $path, [], $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->capture('POST', $path, $body, []);
    }

    public function patch(string $path, array $body): array
    {
        return $this->capture('PATCH', $path, $body, []);
    }

    private function capture(string $method, string $path, array $body, array $query): array
    {
        $this->lastMethod = $method;
        $this->lastPath   = $path;
        $this->lastBody   = $body;
        $this->lastQuery  = $query;
        $this->callHistory[] = compact('method', 'path', 'body', 'query');

        if ($this->nextThrow !== null) {
            $t = $this->nextThrow;
            $this->nextThrow = null;
            throw $t;
        }
        if ($this->useQueue) {
            if (empty($this->responseQueue)) {
                throw new \LogicException('FakePayPalApi: queue exhausted at ' . $method . ' ' . $path);
            }
            return array_shift($this->responseQueue);
        }
        return $this->staticResponse;
    }
}

<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Minimal HTTP GET that never follows redirects, so a redirect to Adobe's
 * login page is seen as a redirect rather than silently followed.
 *
 * Rate limits, server errors and failed connections are retried a few times. If they
 * persist, a TransientHttpException is thrown rather than a status returned, so no
 * caller mistakes "try later" for "this does not exist".
 */
class Http
{
    private const USER_AGENT = 'deployecommerce/magento-community-patches';

    private const ATTEMPTS = 3;

    /** Longest wait between attempts, whatever Retry-After asks for. */
    private const MAX_WAIT = 60;

    /**
     * @return array{0: int, 1: string} status code and body
     */
    public function get(string $url): array
    {
        for ($attempt = 1;; $attempt++) {
            [$status, $body, $headers] = $this->request($url);
            if ($status !== 0 && $status !== 429 && $status < 500) {
                return [$status, $body];
            }
            if ($attempt === self::ATTEMPTS) {
                throw new TransientHttpException(sprintf('%s returned HTTP %d after %d attempts.', $url, $status, $attempt));
            }
            $this->wait(min(self::MAX_WAIT, self::retryAfter($headers) ?? 5 * 2 ** ($attempt - 1)));
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string[]} status code (0 when no response), body and response headers
     */
    protected function request(string $url): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 60,
            'header' => 'User-Agent: ' . self::USER_AGENT,
        ]]);
        $body = @file_get_contents($url, false, $context);
        // $http_response_header is set by file_get_contents in this scope.
        $headers = $http_response_header ?? [];
        $status = isset($headers[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $headers[0], $m)
            ? (int) $m[1]
            : 0;

        return [$status, $body === false ? '' : $body, $headers];
    }

    protected function wait(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * @param string[] $headers
     * @return int|null the delay-seconds form of Retry-After; the HTTP-date form is ignored
     */
    private static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^Retry-After:\s*(\d+)\s*$#i', $header, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }
}

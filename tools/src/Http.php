<?php

declare(strict_types=1);

namespace DeployEcommerce\CommunityPatches\Tools;

/**
 * Minimal HTTP GET that never follows redirects, so a redirect to Adobe's
 * login page is seen as a redirect rather than silently followed.
 */
class Http
{
    private const USER_AGENT = 'deployecommerce/magento-community-patches';

    /**
     * @return array{0: int, 1: string} status code and body
     */
    public function get(string $url): array
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
        $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m)
            ? (int) $m[1]
            : 0;

        return [$status, $body === false ? '' : $body];
    }
}

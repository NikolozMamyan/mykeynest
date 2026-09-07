<?php

namespace App\Service;

use App\Entity\Credential;

final class CredentialUrlPolicy
{
    public function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '' || preg_match('/[\s\\\\]/', $value)) {
            return null;
        }

        $url = str_contains($value, '://') ? $value : 'https://' . $value;
        $parts = parse_url($url);
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || !$this->usesSecureOrLocalScheme($parts)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    public function allows(Credential $credential, string $url): bool
    {
        $url = $this->normalize($url);
        if ($url === null) {
            return false;
        }

        $host = $this->hostname($url);
        $loginUrl = $this->normalize($credential->getLoginUrl());
        if ($loginUrl !== null) {
            return $host === $this->hostname($loginUrl)
                && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === strtolower((string) parse_url($loginUrl, PHP_URL_SCHEME))
                && $this->effectivePort($url) === $this->effectivePort($loginUrl);
        }

        $domain = $this->hostname($credential->getDomain());
        if ($domain === '' || ($host !== $domain && !str_ends_with($host, '.' . $domain))) {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return $port === null || $port === 443 || $this->isLoopbackHost($host);
    }

    public function hostname(?string $value): string
    {
        $value = trim($value ?? '');
        if ($value === '' || preg_match('/[\s\\\\]/', $value)) {
            return '';
        }

        $url = str_contains($value, '://') ? $value : 'https://' . $value;
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower(rtrim($host, '.'))) : '';
    }

    private function usesSecureOrLocalScheme(array $parts): bool
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && $this->isLoopbackHost((string) ($parts['host'] ?? ''));
    }

    private function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function effectivePort(string $url): ?int
    {
        $port = parse_url($url, PHP_URL_PORT);
        if (is_int($port)) {
            return $port;
        }

        return match (strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
    }
}

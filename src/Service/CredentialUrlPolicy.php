<?php

namespace App\Service;

use App\Entity\Credential;

final class CredentialUrlPolicy
{
    /**
     * @return array{domain: string, loginUrl: ?string}|null
     */
    public function parseSite(?string $value): ?array
    {
        $raw = trim($value ?? '');
        $url = $this->normalize($raw);
        if ($url === null) {
            return null;
        }

        $host = $this->hostname($url);
        if ($host === '') {
            return null;
        }

        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $hasDedicatedPage = ($path !== '' && $path !== '/')
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $this->effectivePort($url) !== 443);

        return [
            'domain' => $host,
            'loginUrl' => $hasDedicatedPage ? $url : null,
        ];
    }

    public function loginPage(?string $value): ?string
    {
        $url = $this->normalize($value);
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path === '' ? '/' : $path);
    }

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

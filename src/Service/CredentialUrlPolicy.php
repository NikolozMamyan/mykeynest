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
            || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
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
        $domain = $this->hostname($credential->getDomain());
        if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
            return true;
        }

        $loginUrl = $this->normalize($credential->getLoginUrl());

        return $loginUrl !== null
            && $host === $this->hostname($loginUrl)
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === strtolower((string) parse_url($loginUrl, PHP_URL_SCHEME))
            && parse_url($url, PHP_URL_PORT) === parse_url($loginUrl, PHP_URL_PORT);
    }

    public function hostname(?string $value): string
    {
        $url = $this->normalize($value);

        return $url === null ? '' : preg_replace('/^www\./', '', strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.')));
    }
}

<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class DeviceIdentifier
{
    public const COOKIE_NAME = 'DEVICE_ID';
    private const DEVICE_ID_PATTERN = '/^[a-f0-9]{64}$/';

    public function __construct(
        private RequestStack $requestStack
    ) {
    }

    public function getCurrentDeviceId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        $deviceId = $request?->cookies->get(self::COOKIE_NAME);

        return is_string($deviceId) && self::isValid($deviceId)
            ? strtolower(trim($deviceId))
            : null;
    }

    public function getOrCreateCurrentDeviceId(): string
    {
        $deviceId = $this->getCurrentDeviceId();

        if ($deviceId) {
            return $deviceId;
        }

        return self::generate();
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isValid(string $deviceId): bool
    {
        return preg_match(self::DEVICE_ID_PATTERN, strtolower(trim($deviceId))) === 1;
    }

    public function attachDeviceCookie(Response $response, string $deviceId): void
    {
        $cookie = Cookie::create(
            self::COOKIE_NAME,
            $deviceId,
            new \DateTimeImmutable('+5 years'),
            '/',
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX
        );

        $response->headers->setCookie($cookie);
    }

    public function clearDeviceCookie(Response $response): void
    {
        $response->headers->clearCookie(self::COOKIE_NAME, '/');
    }
}

<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

class DeviceIdentifier
{
    public const COOKIE_NAME = 'DEVICE_ID';

    public function __construct(
        private RequestStack $requestStack
    ) {
    }

    public function getCurrentDeviceId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request?->cookies->get(self::COOKIE_NAME);
    }

    public function getOrCreateCurrentDeviceId(): string
    {
        $deviceId = $this->getCurrentDeviceId();

        if ($deviceId) {
            return $deviceId;
        }

        return bin2hex(random_bytes(32));
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
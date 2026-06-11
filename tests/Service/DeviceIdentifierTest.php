<?php

namespace App\Tests\Service;

use App\Service\DeviceIdentifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class DeviceIdentifierTest extends TestCase
{
    public function testReturnsNormalizedValidDeviceCookie(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(cookies: [
            DeviceIdentifier::COOKIE_NAME => ' ' . str_repeat('A', 64) . ' ',
        ]));

        $identifier = new DeviceIdentifier($requestStack);

        self::assertSame(str_repeat('a', 64), $identifier->getCurrentDeviceId());
    }

    public function testRejectsMalformedCookieAndGeneratesFreshIdentifier(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(cookies: [
            DeviceIdentifier::COOKIE_NAME => '../../invalid',
        ]));

        $identifier = new DeviceIdentifier($requestStack);

        self::assertNull($identifier->getCurrentDeviceId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $identifier->getOrCreateCurrentDeviceId());
    }
}

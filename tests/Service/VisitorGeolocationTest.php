<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VisitorGeolocation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class VisitorGeolocationTest extends TestCase
{
    public function testCachesCountriesAndDoesNotSendPrivateIps(): void
    {
        $client = new MockHttpClient([new MockResponse('{"ip":"1.1.1.1","country":"LU"}')]);
        $geolocation = $this->geolocation($client);
        self::assertNull($geolocation->country('127.0.0.1'));
        self::assertNull($geolocation->country('10.0.0.1'));
        self::assertNull($geolocation->country('invalid'));
        self::assertSame('LU', $geolocation->country('1.1.1.1'));
        self::assertSame('LU', $geolocation->country('1.1.1.1'));
        self::assertSame(1, $client->getRequestsCount());
    }

    /** @dataProvider failedLookups */
    public function testRejectsUnreliableLocationsAndCachesFailures(string $body, int $status): void
    {
        $client = new MockHttpClient([new MockResponse($body, ['http_code' => $status])]);
        $geolocation = $this->geolocation($client);
        self::assertNull($geolocation->country('1.1.1.1'));
        self::assertNull($geolocation->country('1.1.1.1'));
        self::assertSame(1, $client->getRequestsCount());
    }

    public static function failedLookups(): array
    {
        return [
            ['{"ip":"8.8.8.8","country":"LU"}', 200],
            ['{"ip":"1.1.1.1","country":"XX"}', 200],
            ['{"ip":"invalid","country":"LU"}', 200],
            ['unavailable', 503],
            ['{}', 429],
        ];
    }

    private function geolocation(MockHttpClient $client): VisitorGeolocation
    {
        return new VisitorGeolocation($client, new ArrayAdapter(), new RateLimiterFactory(
            ['id' => 'geo-test', 'policy' => 'fixed_window', 'limit' => 8, 'interval' => '1 second'], new InMemoryStorage(),
        ));
    }
}

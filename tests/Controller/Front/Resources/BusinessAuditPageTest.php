<?php

namespace App\Tests\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BusinessAuditPageTest extends WebTestCase
{
    public function testAuditPageExposesStableSeoMetadata(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/audit-cybersecurite-pme');

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/audit-cybersecurite-pme',
            parse_url((string) $crawler->filter('link[rel="canonical"]')->attr('href'), PHP_URL_PATH)
        );
        self::assertSame(
            'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            $crawler->filter('meta[name="robots"]')->attr('content')
        );
        self::assertSame(
            'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            $crawler->filter('meta[name="googlebot"]')->attr('content')
        );

        $schema = $crawler
            ->filter('script[type="application/ld+json"]')
            ->reduce(static fn ($node) => str_contains($node->text(), 'FAQPage'))
            ->first()
            ->text();

        $decodedSchema = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);
        $graphTypes = array_column($decodedSchema['@graph'] ?? [], '@type');

        self::assertContains('WebPage', $graphTypes);
        self::assertContains('BreadcrumbList', $graphTypes);
        self::assertContains('Service', $graphTypes);
        self::assertContains('FAQPage', $graphTypes);
    }
}

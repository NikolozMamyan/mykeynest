<?php

namespace App\Tests\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrivacyPageTest extends WebTestCase
{
    public function testPrivacyPolicyIsPublicAndDirectlyAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSame('/privacy', parse_url((string) $crawler->filter('link[rel="canonical"]')->attr('href'), PHP_URL_PATH));
        self::assertSelectorTextContains('h1', 'Politique de confidentialité');
        self::assertSelectorTextContains('body', 'llckfoodkfccmibgmpfiodjkpincnfid');
        self::assertSelectorTextContains('body', '20/08/2026');
        self::assertSelectorTextContains('body', 'Limited Use');
        self::assertSelectorNotExists('script[src*="googletagmanager.com"]');
    }

    public function testPrivacyPolicyHasAnEnglishVersion(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/privacy?lang=en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Privacy Policy');
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }
}

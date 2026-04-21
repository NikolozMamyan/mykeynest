<?php

namespace App\Tests\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BusinessInternalLinksTest extends WebTestCase
{
    /**
     * @dataProvider pageProvider
     */
    public function testBusinessPagesExposeStrategicInternalLinks(string $path, array $expectedHrefs): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#seo-links-title');

        foreach ($expectedHrefs as $href) {
            self::assertGreaterThan(
                0,
                $crawler->filter(sprintf('a[href="%s"]', $href))->count(),
                sprintf('Missing expected internal link "%s" on page "%s".', $href, $path)
            );
        }
    }

    public static function pageProvider(): iterable
    {
        yield 'password manager page' => [
            '/gestionnaire-mot-de-passe-entreprise',
            [
                '/solution-cybersecurite-pme',
                '/comparatif-password-manager-entreprise',
                '/audit-cybersecurite-pme',
                '/help/center',
            ],
        ];

        yield 'solution page' => [
            '/solution-cybersecurite-pme',
            [
                '/gestionnaire-mot-de-passe-entreprise',
                '/comparatif-password-manager-entreprise',
                '/audit-cybersecurite-pme',
                '/help/center',
            ],
        ];

        yield 'comparison page' => [
            '/comparatif-password-manager-entreprise',
            [
                '/gestionnaire-mot-de-passe-entreprise',
                '/solution-cybersecurite-pme',
                '/audit-cybersecurite-pme',
                '/fr/blog',
            ],
        ];

        yield 'audit page' => [
            '/audit-cybersecurite-pme',
            [
                '/gestionnaire-mot-de-passe-entreprise',
                '/solution-cybersecurite-pme',
                '/help/center',
                '/fr/blog',
            ],
        ];
    }
}

<?php

namespace App\Controller\Front\Resources;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    private function getHelpCenterData(): array
    {
        return [
            [
                'categorySlug' => 'demarrer',
                'articles' => [
                    'creer-son-compte',
                    'importer-identifiants',
                    'synchronisation-appareils',
                    'application-mobile',
                ],
            ],
            [
                'categorySlug' => 'securite',
                'articles' => [
                    'zero-knowledge-explique',
                    'aes-256-explique',
                    'mot-de-passe-maitre',
                    'activer-2fa',
                    'audit-securite',
                ],
            ],
            [
                'categorySlug' => 'generateur',
                'articles' => [
                    'utiliser-le-generateur',
                    'longueur-ideale',
                    'securite-generateur',
                ],
            ],
            [
                'categorySlug' => 'partage',
                'articles' => [
                    'partager-identifiant',
                    'limite-partages-gratuits',
                    'revoquer-partage',
                ],
            ],
            [
                'categorySlug' => 'extension',
                'articles' => [
                    'installer-extension-chrome',
                    'installer-extension-firefox',
                    'autofill-ne-fonctionne-pas',
                ],
            ],
            [
                'categorySlug' => 'abonnement',
                'articles' => [
                    'difference-free-pro',
                    'passer-au-pro',
                    'annuler-abonnement',
                ],
            ],
        ];
    }

    private function localizedPublicEntries(
        UrlGeneratorInterface $urlGenerator,
        string $routeName,
        array $params,
        \DateTimeInterface $lastmod,
        string $changefreq,
        string $priority
    ): array {
        return [
            $this->entry(
                $urlGenerator->generate($routeName, $params, UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod,
                $changefreq,
                $priority
            ),
            $this->entry(
                $urlGenerator->generate($routeName, array_merge($params, ['lang' => 'en']), UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod,
                $changefreq,
                $priority
            ),
        ];
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(
        UrlGeneratorInterface $urlGenerator,
        ArticleRepository $articleRepository
    ): Response {
        $urls = [];
        $now = new \DateTimeImmutable('now');

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_landing',
            [],
            $now,
            'weekly',
            '1.0'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_public_generator',
            [],
            $now,
            'monthly',
            '1.0'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_help_center',
            [],
            $now,
            'weekly',
            '0.9'
        ));

        foreach ($this->getHelpCenterData() as $category) {
            $urls = array_merge($urls, $this->localizedPublicEntries(
                $urlGenerator,
                'app_help_category',
                ['slug' => $category['categorySlug']],
                $now,
                'weekly',
                '0.8'
            ));

            foreach ($category['articles'] as $articleSlug) {
                $urls = array_merge($urls, $this->localizedPublicEntries(
                    $urlGenerator,
                    'app_help_article',
                    [
                        'categorySlug' => $category['categorySlug'],
                        'articleSlug' => $articleSlug,
                    ],
                    $now,
                    'monthly',
                    '0.7'
                ));
            }
        }

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_solution',
            [],
            $now,
            'weekly',
            '0.9'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_comparatif',
            [],
            $now,
            'monthly',
            '0.8'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_audit',
            [],
            $now,
            'weekly',
            '0.8'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_password_manager',
            [],
            $now,
            'weekly',
            '0.9'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'legal_cgu',
            [],
            $now,
            'yearly',
            '0.3'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'legal_cgv',
            [],
            $now,
            'yearly',
            '0.3'
        ));

        foreach (['fr', 'en'] as $locale) {
            $urls[] = $this->entry(
                $urlGenerator->generate('blog_index', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                $now,
                'weekly',
                '0.9'
            );
        }

        foreach ($articleRepository->findAllForSitemap() as $article) {
            $lastmod = $article->getUpdatedAt() ?? $article->getPublishedAt();

            $urls[] = $this->entry(
                $urlGenerator->generate('blog_article_show', [
                    '_locale' => 'fr',
                    'slug' => $article->getSlugFr(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod,
                'monthly',
                '0.7'
            );

            $urls[] = $this->entry(
                $urlGenerator->generate('blog_article_show', [
                    '_locale' => 'en',
                    'slug' => $article->getSlugEn(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod,
                'monthly',
                '0.7'
            );
        }

        return new Response(
            $this->renderView('sitemap/sitemap.xml.twig', ['urls' => $urls]),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }

    private function entry(string $loc, \DateTimeInterface $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }
}

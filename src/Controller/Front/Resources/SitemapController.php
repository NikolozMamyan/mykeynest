<?php

namespace App\Controller\Front\Resources;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    #[Route('/.well-known/{name}.txt', name: 'app_llms_alias', requirements: ['name' => 'llms|llms-full'], methods: ['GET'])]
    public function llms(string $name): Response
    {
        $response = new Response(
            file_get_contents($this->getParameter('kernel.project_dir').'/public/'.$name.'.txt'),
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }

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
        ?\DateTimeInterface $lastmod,
        string $changefreq,
        string $priority
    ): array {
        $entries = [
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
        return in_array($routeName, ['legal_cgu', 'legal_cgv', 'legal_privacy'], true) ? [$entries[0]] : $entries;
    }

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(
        UrlGeneratorInterface $urlGenerator,
        ArticleRepository $articleRepository
    ): Response {
        $urls = [];

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_landing',
            [],
            null,
            'weekly',
            '1.0'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_public_generator',
            [],
            null,
            'monthly',
            '1.0'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'app_help_center',
            [],
            null,
            'weekly',
            '0.9'
        ));

        foreach ($this->getHelpCenterData() as $category) {
            $urls = array_merge($urls, $this->localizedPublicEntries(
                $urlGenerator,
                'app_help_category',
                ['slug' => $category['categorySlug']],
                null,
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
                    null,
                    'monthly',
                    '0.7'
                ));
            }
        }

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_solution',
            [],
            null,
            'weekly',
            '0.9'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_comparatif',
            [],
            null,
            'monthly',
            '0.8'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_audit',
            [],
            null,
            'weekly',
            '0.8'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'business_password_manager',
            [],
            null,
            'weekly',
            '0.9'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'legal_cgu',
            [],
            null,
            'yearly',
            '0.3'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'legal_cgv',
            [],
            null,
            'yearly',
            '0.3'
        ));

        $urls = array_merge($urls, $this->localizedPublicEntries(
            $urlGenerator,
            'legal_privacy',
            [],
            null,
            'yearly',
            '0.3'
        ));

        foreach (['legal_mentions', 'app_security'] as $route) {
            $urls = array_merge($urls, $this->localizedPublicEntries(
                $urlGenerator, $route, [], null, 'monthly', '0.6'
            ));
        }

        foreach (['fr', 'en'] as $locale) {
            $urls[] = $this->entry(
                $urlGenerator->generate('blog_index', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                null,
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

    private function entry(string $loc, ?\DateTimeInterface $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }
}

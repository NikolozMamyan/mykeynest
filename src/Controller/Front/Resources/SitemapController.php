<?php

namespace App\Controller\Front\Resources;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    // Même structure que HelpCenterController — les deux doivent rester synchros
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

    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(
        UrlGeneratorInterface $urlGenerator,
        ArticleRepository $articleRepository
    ): Response {
        $urls = [];
        $now  = new \DateTimeImmutable('now');

        // ── Pages publiques ────────────────────────────────────────────────
        $urls[] = $this->entry(
            $urlGenerator->generate('app_landing', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'weekly', '1.0'
        );

        $urls[] = $this->entry(
            $urlGenerator->generate('app_public_generator', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'monthly', '1.0'
        );

        // ── Help Center — index ───────────────────────────────────────────
        $urls[] = $this->entry(
            $urlGenerator->generate('app_help_center', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'weekly', '0.9'
        );

        // ── Help Center — pages catégories ───────────────────────────────
        foreach ($this->getHelpCenterData() as $category) {
            $urls[] = $this->entry(
                $urlGenerator->generate('app_help_category', [
                    'slug' => $category['categorySlug'],
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $now, 'weekly', '0.8'
            );

            // ── Help Center — pages articles ─────────────────────────────
            foreach ($category['articles'] as $articleSlug) {
                $urls[] = $this->entry(
                    $urlGenerator->generate('app_help_article', [
                        'categorySlug' => $category['categorySlug'],
                        'articleSlug'  => $articleSlug,
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    $now, 'monthly', '0.7'
                );
            }
        }

        // ── Pages business / entreprise ──────────────────────────────────
        $urls[] = $this->entry(
            $urlGenerator->generate('business_solution', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'weekly', '0.9'
        );

        $urls[] = $this->entry(
            $urlGenerator->generate('business_comparatif', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'monthly', '0.8'
        );

        $urls[] = $this->entry(
            $urlGenerator->generate('business_audit', [], UrlGeneratorInterface::ABSOLUTE_URL),
            $now, 'weekly', '0.8'
        );

        // ── Blog — index par locale ───────────────────────────────────────
        foreach (['fr', 'en'] as $locale) {
            $urls[] = $this->entry(
                $urlGenerator->generate('blog_index', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                $now, 'weekly', '0.9'
            );
        }

        // ── Blog — articles ───────────────────────────────────────────────
        foreach ($articleRepository->findAllForSitemap() as $article) {
            $lastmod = $article->getUpdatedAt() ?? $article->getPublishedAt();

            $urls[] = $this->entry(
                $urlGenerator->generate('blog_article_show', [
                    '_locale' => 'fr',
                    'slug'    => $article->getSlugFr(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod, 'monthly', '0.7'
            );

            $urls[] = $this->entry(
                $urlGenerator->generate('blog_article_show', [
                    '_locale' => 'en',
                    'slug'    => $article->getSlugEn(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod, 'monthly', '0.7'
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
            'loc'        => $loc,
            'lastmod'    => $lastmod,
            'changefreq' => $changefreq,
            'priority'   => $priority,
        ];
    }
}
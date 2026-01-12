<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function sitemap(
        UrlGeneratorInterface $urlGenerator,
        ArticleRepository $articleRepository
    ): Response {
        $urls = [];

        // ✅ Pages publiques (SEO)
        $urls[] = $this->entry(
            $urlGenerator->generate('app_landing', [], UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateTimeImmutable('now'),
            'weekly',
            '1.0'
        );

        // ✅ Blog index par locale
        foreach (['fr', 'en'] as $locale) {
            $urls[] = $this->entry(
                $urlGenerator->generate('blog_index', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                new \DateTimeImmutable('now'),
                'weekly',
                '0.9'
            );
        }

        $articles = $articleRepository->findAllForSitemap();

        foreach ($articles as $article) {
            $lastmod = $article->getUpdatedAt() ?? $article->getPublishedAt();

            // FR
            $urls[] = $this->entry(
                $urlGenerator->generate('blog_article_show', [
                    '_locale' => 'fr',
                    'slug' => $article->getSlugFr(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                $lastmod,
                'monthly',
                '0.7'
            );

            // EN
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

        $xml = $this->renderView('sitemap/sitemap.xml.twig', [
            'urls' => $urls,
        ]);

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
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

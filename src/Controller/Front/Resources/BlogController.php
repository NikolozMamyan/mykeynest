<?php

namespace App\Controller\Front\Resources;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class BlogController extends AbstractController
{

public function index(
    Request $request,
    ArticleRepository $repo
): Response {
    $locale = $request->getLocale(); // fr|en

    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = 10;

    $pagination = $repo->findPaginated($page, $perPage);

    // Canonical / alternates pour la page index blog
    $urlFr = $this->generateUrl('blog_index', ['_locale' => 'fr'], UrlGeneratorInterface::ABSOLUTE_URL);
    $urlEn = $this->generateUrl('blog_index', ['_locale' => 'en'], UrlGeneratorInterface::ABSOLUTE_URL);

    // Canonical doit inclure le param page si > 1
    $canonicalBase = $locale === 'fr' ? $urlFr : $urlEn;
    $canonical = $page > 1 ? ($canonicalBase . '?page=' . $page) : $canonicalBase;

    // SEO texte index
    $seoTitle = $locale === 'fr'
        ? 'Blog cybersécurité & mots de passe | MyKeyNest'
        : 'Password security & cybersecurity blog | MyKeyNest';

    $metaDesc = $locale === 'fr'
        ? 'Conseils pratiques pour sécuriser vos mots de passe : 2FA, gestionnaire de mots de passe, phishing, bonnes pratiques et guides.'
        : 'Practical advice to secure your passwords: 2FA, password managers, phishing protection, best practices and guides.';

    return $this->render('blog/index.html.twig', [
        'vm' => [
            'seoTitle' => $seoTitle,
            'metaDesc' => $metaDesc,
            'url_fr' => $page > 1 ? ($urlFr . '?page=' . $page) : $urlFr,
            'url_en' => $page > 1 ? ($urlEn . '?page=' . $page) : $urlEn,
            'canonical' => $canonical,
            'ogLocale' => $locale === 'fr' ? 'fr_FR' : 'en_US',
            'page' => $pagination['page'],
            'pages' => $pagination['pages'],
            'total' => $pagination['total'],
            'perPage' => $pagination['perPage'],
        ],
        'articles' => $pagination['items'],
    ]);
}

    public function show(
        Request $request,
        ArticleRepository $repo,
        string $slug
    ): Response {
        $locale = $request->getLocale(); // fr|en

        $article = $repo->findOneBySlugAndLocale($slug, $locale);
        if (!$article) {
            throw $this->createNotFoundException();
        }

        // Alternate URLs (ABSOLUTE)
        $urlFr = $this->generateUrl('blog_article_show', [
            '_locale' => 'fr',
            'slug' => $article->getSlugFr(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $urlEn = $this->generateUrl('blog_article_show', [
            '_locale' => 'en',
            'slug' => $article->getSlugEn(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $canonical = $locale === 'fr' ? $urlFr : $urlEn;

        $vm = [
            'url_fr' => $urlFr,
            'url_en' => $urlEn,
            'canonical' => $canonical,
            'seoTitle' => $locale === 'fr' ? $article->getSeoTitleFr() : $article->getSeoTitleEn(),
            'metaDesc' => $locale === 'fr' ? $article->getMetaDescFr() : $article->getMetaDescEn(),
            'h1' => $locale === 'fr' ? $article->getH1Fr() : $article->getH1En(),
            'content' => $locale === 'fr' ? $article->getContentFr() : $article->getContentEn(),
            'publishedAt' => $article->getPublishedAt(),
            'updatedAt' => $article->getUpdatedAt(),

            // OG locale mapping
            'ogLocale' => $locale === 'fr' ? 'fr_FR' : 'en_US',
        ];

        return $this->render('blog/article_show.html.twig', [
            'article' => $article,
            'vm' => $vm,
        ]);
    }
}

<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Form\Admin\ArticleType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    private const MAX_FILE_SIZE = 5242880; // 5MB
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        #[Autowire('%article_covers_dir%')]
        private readonly string $coversDir,
        #[Autowire('%article_content_images_dir%')]
        private readonly string $contentImagesDir,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $q = $this->sanitizeSearchQuery($request->query->get('q', ''));

        $repo = $em->getRepository(Article::class);
        /** @var QueryBuilder $qb */
        $qb = $repo->createQueryBuilder('a')
            ->orderBy('a.publishedAt', 'DESC');

        if ($q !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'a.slugFr LIKE :q',
                    'a.slugEn LIKE :q',
                    'a.h1Fr LIKE :q',
                    'a.h1En LIKE :q',
                    'a.seoTitleFr LIKE :q',
                    'a.seoTitleEn LIKE :q'
                )
            )->setParameter('q', '%' . $q . '%');
        }

        $articles = $qb->getQuery()->getResult();

        return $this->render('admin/index.html.twig', [
            'articles' => $articles,
            'q' => $q,
        ]);
    }

    #[Route('/articles/new', name: 'admin_article_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $article = new Article();

        $form = $this->createForm(ArticleType::class, $article, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $coverFile = $form->get('coverFile')->getData();
                if ($coverFile instanceof UploadedFile) {
                    $filename = $this->handleFileUpload($coverFile, $slugger, $this->coversDir);
                    $article->setCoverImage($filename);
                }

                $em->persist($article);
                $em->flush();

                $this->addFlash('success', 'Article créé avec succès ✅');
                return $this->redirectToRoute('app_admin');
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la création de l\'article', [
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors de la création de l\'article.');
            }
        }

        return $this->render('admin/article_form.html.twig', [
            'form' => $form,
            'article' => $article,
            'mode' => 'new',
        ]);
    }

    #[Route('/articles/{id}/edit', name: 'admin_article_edit', methods: ['GET', 'POST'])]
    public function edit(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $oldCover = $article->getCoverImage();

        $form = $this->createForm(ArticleType::class, $article, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $coverFile = $form->get('coverFile')->getData();
                if ($coverFile instanceof UploadedFile) {
                    $filename = $this->handleFileUpload($coverFile, $slugger, $this->coversDir);
                    $article->setCoverImage($filename);

                    // Supprime l'ancienne image
                    if ($oldCover) {
                        $this->deleteFile($this->coversDir, $oldCover);
                    }
                }

                $em->flush();

                $this->addFlash('success', 'Article mis à jour avec succès ✅');
                return $this->redirectToRoute('app_admin');
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la modification de l\'article', [
                    'article_id' => $article->getId(),
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors de la modification de l\'article.');
            }
        }

        return $this->render('admin/article_form.html.twig', [
            'form' => $form,
            'article' => $article,
            'mode' => 'edit',
        ]);
    }

    #[Route('/articles/{id}/delete', name: 'admin_article_delete', methods: ['POST'])]
    public function delete(
        Article $article,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $token = (string) $request->request->get('_token');
        
        if (!$this->isCsrfTokenValid('delete_article_' . $article->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_admin');
        }

        try {
            // Supprime le fichier cover
            $cover = $article->getCoverImage();
            if ($cover) {
                $this->deleteFile($this->coversDir, $cover);
            }

            $em->remove($article);
            $em->flush();

            $this->addFlash('success', 'Article supprimé avec succès ✅');
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression de l\'article', [
                'article_id' => $article->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Une erreur est survenue lors de la suppression de l\'article.');
        }

        return $this->redirectToRoute('app_admin');
    }

    #[Route('/articles/upload-image', name: 'admin_article_upload_image', methods: ['POST'])]
    public function uploadArticleImage(
        Request $request,
        ValidatorInterface $validator
    ): Response {
        $file = $request->files->get('upload');

        if (!$file instanceof UploadedFile) {
            return $this->json([
                'error' => ['message' => 'Aucun fichier reçu.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validation du fichier
        $violations = $validator->validate($file, [
            new Assert\NotNull(),
            new Assert\File([
                'maxSize' => self::MAX_FILE_SIZE,
                'mimeTypes' => self::ALLOWED_IMAGE_TYPES,
                'mimeTypesMessage' => 'Le fichier doit être une image valide (JPEG, PNG, WebP ou GIF).',
            ]),
        ]);

        if (count($violations) > 0) {
            return $this->json([
                'error' => ['message' => (string) $violations[0]->getMessage()]
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validation supplémentaire de l'extension
        $extension = $file->guessExtension();
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json([
                'error' => ['message' => 'Extension de fichier non autorisée.']
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Génère un nom de fichier sécurisé
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $file->move($this->contentImagesDir, $filename);

            return $this->json([
                'url' => '/uploads/articles/content/' . $filename
            ]);
        } catch (FileException $e) {
            $this->logger->error('Erreur lors de l\'upload d\'image', [
                'error' => $e->getMessage(),
            ]);
            
            return $this->json([
                'error' => ['message' => 'Erreur lors de l\'upload du fichier.']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gère l'upload d'un fichier de manière sécurisée
     */
    private function handleFileUpload(
        UploadedFile $file,
        SluggerInterface $slugger,
        string $targetDirectory
    ): string {
        // Validation du type MIME
        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé.');
        }

        // Validation de la taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Fichier trop volumineux (max 5MB).');
        }

        // Validation de l'extension
        $extension = $file->guessExtension();
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Extension de fichier non autorisée.');
        }

        // Génère un nom de fichier sécurisé
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename)->lower();
        $filename = $safeFilename . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        try {
            $file->move($targetDirectory, $filename);
        } catch (FileException $e) {
            $this->logger->error('Erreur lors du déplacement du fichier', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Impossible d\'uploader le fichier.');
        }

        return $filename;
    }

    /**
     * Supprime un fichier de manière sécurisée
     */
    private function deleteFile(string $directory, string $filename): void
    {
        // Protection contre les path traversal
        $filename = basename($filename);
        $filepath = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($filepath)) {
            return;
        }

        // Vérifie que le fichier est bien dans le répertoire attendu
        $realPath = realpath($filepath);
        $realDir = realpath($directory);

        if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir)) {
            $this->logger->warning('Tentative de suppression de fichier en dehors du répertoire autorisé', [
                'filepath' => $filepath,
            ]);
            return;
        }

        try {
            unlink($filepath);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression du fichier', [
                'filepath' => $filepath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nettoie et sécurise une requête de recherche
     */
    private function sanitizeSearchQuery(mixed $query): string
    {
        if (!is_string($query)) {
            return '';
        }

        // Supprime les espaces multiples et trim
        $query = trim(preg_replace('/\s+/', ' ', $query));

        // Limite la longueur
        return mb_substr($query, 0, 255);
    }
}
<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\ExtensionClient;
use App\Entity\ExtensionInstallationChallenge;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Form\Admin\ArticleType;
use App\Repository\ExtensionClientRepository;
use App\Repository\ExtensionInstallationChallengeRepository;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use App\Repository\UserSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use App\Service\ExtensionClientManager;
use App\Service\ExtensionInstallationChallengeManager;
use App\Service\SessionManager;
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
    public function index(
        EntityManagerInterface $em,
        Request $request,
        UserRepository $userRepository
    ): Response
    {
        return $this->render('admin/index.html.twig', [
            'recentArticles' => array_slice($this->getArticles($em), 0, 5),
            'recentUsers' => array_slice($this->getUsers($userRepository), 0, 5),
            'articlesCount' => $this->countArticles($em),
            'usersCount' => $this->countUsers($userRepository),
            'activeSubscriptionsCount' => $this->countActiveSubscriptions($userRepository),
        ]);
    }

    #[Route('/blog', name: 'admin_blog', methods: ['GET'])]
    public function blog(
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $q = $this->sanitizeSearchQuery($request->query->get('q', ''));

        return $this->render('admin/blog.html.twig', [
            'articles' => $this->getArticles($em, $q),
            'q' => $q,
        ]);
    }

    #[Route('/subscriptions', name: 'admin_subscriptions', methods: ['GET'])]
    public function subscriptions(UserRepository $userRepository): Response
    {
        return $this->render('admin/subscriptions.html.twig', [
            'users' => $this->getUsers($userRepository),
        ]);
    }

    #[Route('/sessions', name: 'admin_sessions', methods: ['GET'])]
    public function sessions(UserSessionRepository $userSessionRepository): Response
    {
        $sessions = $userSessionRepository->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->orderBy('s.lastActivityAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $this->render('admin/sessions.html.twig', [
            'sessions' => $sessions,
        ]);
    }

    #[Route('/extensions', name: 'admin_extensions', methods: ['GET'])]
    public function extensions(
        ExtensionClientRepository $extensionClientRepository,
        ExtensionInstallationChallengeRepository $challengeRepository
    ): Response {
        $clients = $extensionClientRepository->createQueryBuilder('ec')
            ->leftJoin('ec.user', 'u')
            ->addSelect('u')
            ->orderBy('ec.lastSeenAt', 'DESC')
            ->addOrderBy('ec.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        $challenges = $challengeRepository->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $this->render('admin/extensions.html.twig', [
            'clients' => $clients,
            'challenges' => $challenges,
        ]);
    }

    #[Route('/users/{id}/subscription/assign-pro', name: 'admin_user_subscription_assign_pro', methods: ['POST'])]
    public function assignProToUser(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserSubscriptionRepository $subscriptionRepository
    ): Response {
        if (!$this->isCsrfTokenValid('assign_pro_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_subscriptions');
        }

        $subscription = $this->getOrCreateSubscription($user, $subscriptionRepository, $em);
        $subscription->setPlanCode('pro');
        $subscription->setStatus('admin_active');
        $subscription->setIsActive(true);
        $subscription->touch();
        $em->flush();

        $this->addFlash('success', 'Abonnement Pro attribue a ' . $user->getEmail() . '.');

        return $this->redirectToRoute('admin_subscriptions');
    }

    #[Route('/users/{id}/subscription/deactivate', name: 'admin_user_subscription_deactivate', methods: ['POST'])]
    public function deactivateUserSubscription(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserSubscriptionRepository $subscriptionRepository
    ): Response {
        if (!$this->isCsrfTokenValid('deactivate_subscription_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_subscriptions');
        }

        $subscription = $this->getOrCreateSubscription($user, $subscriptionRepository, $em);
        $subscription->setIsActive(false);
        $subscription->setStatus('admin_disabled');
        $subscription->touch();
        $em->flush();

        $this->addFlash('warning', 'Abonnement desactive pour ' . $user->getEmail() . '.');

        return $this->redirectToRoute('admin_subscriptions');
    }

    #[Route('/sessions/{id}/revoke', name: 'admin_session_revoke', methods: ['POST'])]
    public function revokeSession(
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager,
        int $id
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_revoke_session_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        if (!$session->isRevoked()) {
            $sessionManager->revoke($session, 'admin_revoked');
            $this->addFlash('success', 'Session revoquee pour ' . $session->getUser()?->getEmail() . '.');
        }

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/sessions/{id}/block-device', name: 'admin_session_block_device', methods: ['POST'])]
    public function blockSessionDevice(
        int $id,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_block_device_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        $deviceId = $session->getDeviceId();
        if (!$deviceId) {
            $this->addFlash('warning', 'Aucun device id pour cette session.');

            return $this->redirectToRoute('admin_sessions');
        }

        $count = $sessionManager->blockDevice($session->getUser(), $deviceId, 'admin_blocked');
        $this->addFlash('warning', $count . ' session(s) bloquees pour cet appareil.');

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/sessions/{id}/unblock-device', name: 'admin_session_unblock_device', methods: ['POST'])]
    public function unblockSessionDevice(
        int $id,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_unblock_device_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        $deviceId = $session->getDeviceId();
        if (!$deviceId) {
            $this->addFlash('warning', 'Aucun device id pour cette session.');

            return $this->redirectToRoute('admin_sessions');
        }

        $count = $sessionManager->unblockDevice($session->getUser(), $deviceId);
        $this->addFlash('success', $count . ' session(s) debloquees pour cet appareil.');

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/extensions/clients/{id}/block', name: 'admin_extension_client_block', methods: ['POST'])]
    public function blockExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_block_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->block($client, 'admin_blocked');
        $this->addFlash('warning', 'Extension bloquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/clients/{id}/unblock', name: 'admin_extension_client_unblock', methods: ['POST'])]
    public function unblockExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_unblock_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->unblock($client);
        $this->addFlash('success', 'Extension debloquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/clients/{id}/revoke', name: 'admin_extension_client_revoke', methods: ['POST'])]
    public function revokeExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_revoke_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->revoke($client, 'admin_revoked');
        $this->addFlash('warning', 'Extension revoquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/challenges/{id}/approve', name: 'admin_extension_challenge_approve', methods: ['POST'])]
    public function approveExtensionChallenge(
        ExtensionInstallationChallenge $challenge,
        Request $request,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_approve_extension_challenge_' . $challenge->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $challengeManager->approve($challenge);
        $this->addFlash('success', 'Demande d installation approuvee.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/challenges/{id}/reject', name: 'admin_extension_challenge_reject', methods: ['POST'])]
    public function rejectExtensionChallenge(
        ExtensionInstallationChallenge $challenge,
        Request $request,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_reject_extension_challenge_' . $challenge->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $challengeManager->reject($challenge);
        $this->addFlash('warning', 'Demande d installation rejetee.');

        return $this->redirectToRoute('admin_extensions');
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
                return $this->redirectToRoute('admin_blog');
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
                return $this->redirectToRoute('admin_blog');
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
            return $this->redirectToRoute('admin_blog');
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

        return $this->redirectToRoute('admin_blog');
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
            if (!is_dir($this->contentImagesDir) && !mkdir($concurrentDirectory = $this->contentImagesDir, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Impossible de créer le dossier d\'upload "%s".', $this->contentImagesDir));
            }

            // Génère un nom de fichier sécurisé
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $file->move($this->contentImagesDir, $filename);

            return $this->json([
                'url' => '/uploads/blog/content/' . $filename
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'upload d\'image', [
                'error' => $e->getMessage(),
                'target_directory' => $this->contentImagesDir,
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

    private function getOrCreateSubscription(
        User $user,
        UserSubscriptionRepository $subscriptionRepository,
        EntityManagerInterface $em
    ): UserSubscription {
        $subscription = $user->getUserSubscription() ?? $subscriptionRepository->findOneBy(['user' => $user]);

        if (!$subscription) {
            $subscription = new UserSubscription();
            $subscription->setUser($user);
            $user->setUserSubscription($subscription);
            $em->persist($subscription);
        }

        return $subscription;
    }

    /**
     * @return list<Article>
     */
    private function getArticles(EntityManagerInterface $em, string $q = ''): array
    {
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

        /** @var list<Article> $articles */
        $articles = $qb->getQuery()->getResult();

        return $articles;
    }

    /**
     * @return list<User>
     */
    private function getUsers(UserRepository $userRepository): array
    {
        /** @var list<User> $users */
        $users = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.userSubscription', 's')
            ->addSelect('s')
            ->orderBy('u.id', 'DESC')
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $users;
    }

    private function countArticles(EntityManagerInterface $em): int
    {
        return (int) $em->getRepository(Article::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countUsers(UserRepository $userRepository): int
    {
        return (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countActiveSubscriptions(UserRepository $userRepository): int
    {
        return (int) $userRepository->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->innerJoin('u.userSubscription', 's')
            ->andWhere('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

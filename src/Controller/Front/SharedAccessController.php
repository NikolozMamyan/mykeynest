<?php

namespace App\Controller\Front;

use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\CredentialRepository;
use App\Repository\SharedAccessRepository;
use App\Repository\UserRepository;
use App\Service\EncryptionService;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class SharedAccessController extends AbstractController
{
    public function __construct(
        private RateLimiterFactory $shareInvitationLimiter
    ) {
    }

    private function sendGuestInvitationEmail(
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        User $guest,
        \DateTimeImmutable $expiresAt
    ): void {
        $guestRegisterUrl = $urlGenerator->generate(
            'app_guest_register',
            ['token' => $guest->getApiToken(), 'email' => $guest->getEmail()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $guestPreviewUrl = $urlGenerator->generate(
            'app_guest_shared_preview',
            ['token' => $guest->getApiToken(), 'email' => $guest->getEmail()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $mailer->send(
            $guest->getEmail(),
            'Vous avez ete invite sur MYKEYNEST',
            'emails/register_guest.html.twig',
            [
                'user' => $guest,
                'guest_register' => $guestRegisterUrl,
                'guest_preview' => $guestPreviewUrl,
                'expiresAt' => $expiresAt,
            ]
        );
    }

    #[Route('/app/shared-access', name: 'shared_access_index', methods: ['GET'])]
    public function index(SharedAccessRepository $sharedAccessRepository): Response
    {
        $user = $this->getUser();

        $sharedByMe = $sharedAccessRepository->findBy(['owner' => $user]);
        $sharedWithMe = $sharedAccessRepository->findBy(['guest' => $user]);

        return $this->render('shared_access/index.html.twig', [
            'sharedByMe' => $sharedByMe,
            'sharedWithMe' => $sharedWithMe,
            'heading' => 'Partages',
        ]);
    }

    #[Route('/app/shared-access/new', name: 'shared_access_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        MailerService $mailer,
        LoggerInterface $logger,
        UrlGeneratorInterface $urlGenerator,
        CredentialRepository $credentialRepository
    ): Response {
        /** @var User $owner */
        $owner = $this->getUser();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$owner) {
            throw $this->createAccessDeniedException();
        }

        $hasSubscription = $owner->hasActiveSubscription();
        $limit = 3;

        if ($request->isMethod('POST')) {
            return $this->handleShareSubmission(
                $request,
                $owner,
                $entityManager,
                $userRepository,
                $mailer,
                $logger,
                $urlGenerator,
                $credentialRepository,
                $hasSubscription,
                $limit,
                'shared_access_index'
            );
        }

        $credentials = $credentialRepository->findBy(['user' => $owner]);

        return $this->render('shared_access/new.html.twig', [
            'credentials' => $credentials,
            'heading' => 'Partages',
        ]);
    }

    #[Route('/app/shared-access/quick', name: 'shared_access_quick', methods: ['POST'])]
    public function quickShare(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        MailerService $mailer,
        LoggerInterface $logger,
        UrlGeneratorInterface $urlGenerator,
        CredentialRepository $credentialRepository
    ): Response {
        /** @var User $owner */
        $owner = $this->getUser();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$owner) {
            throw $this->createAccessDeniedException();
        }

        $credentialId = $request->request->getInt('credential_id');
        $request->request->set('credentials', $credentialId > 0 ? [$credentialId] : []);

        return $this->handleShareSubmission(
            $request,
            $owner,
            $entityManager,
            $userRepository,
            $mailer,
            $logger,
            $urlGenerator,
            $credentialRepository,
            $owner->hasActiveSubscription(),
            3,
            'app_credential'
        );
    }

    #[Route('/app/shared-access/{id}/revoke', name: 'shared_access_revoke', methods: ['POST'])]
    public function revoke(
        Request $request,
        SharedAccess $sharedAccess,
        EntityManagerInterface $entityManager
    ): Response {
        if ($sharedAccess->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'etes pas autorise a revoquer cet acces partage.');
        }

        if ($this->isCsrfTokenValid('revoke' . $sharedAccess->getId(), $request->request->get('_token'))) {
            $entityManager->remove($sharedAccess);
            $entityManager->flush();
            $this->addFlash('success', 'Acces partage revoque avec succes.');
        }

        return $this->redirectToRoute('shared_access_index');
    }

    #[Route('/app/shared-access/view/{id}', name: 'shared_access_view_credential', methods: ['GET'])]
    public function viewSharedCredential(
        SharedAccess $sharedAccess,
        EncryptionService $encryptionService
    ): Response {
        $user = $this->getUser();

        if ($sharedAccess->getGuest() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'etes pas autorise a voir cet identifiant.');
        }

        $credential = $sharedAccess->getCredential();
        $owner = $sharedAccess->getOwner();

        $decryptedPassword = '';
        if ($owner) {
            $primaryKey = $owner->getCredentialEncryptionKey();
            if (is_string($primaryKey) && $primaryKey !== '') {
                $encryptionService->setKeyFromUserSecret($primaryKey);
                $decryptedPassword = $encryptionService->decrypt((string) $credential?->getPassword());
            }

            $legacyKey = $owner->getApiExtensionToken();
            if ($decryptedPassword === '' && is_string($legacyKey) && $legacyKey !== '' && $legacyKey !== $primaryKey) {
                $encryptionService->setKeyFromUserSecret($legacyKey);
                $decryptedPassword = $encryptionService->decrypt((string) $credential?->getPassword());
            }
        }

        return $this->render('shared_access/view_credential.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'owner' => $owner,
            'heading' => 'Partages',
        ]);
    }

    private function handleShareSubmission(
        Request $request,
        User $owner,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        MailerService $mailer,
        LoggerInterface $logger,
        UrlGeneratorInterface $urlGenerator,
        CredentialRepository $credentialRepository,
        bool $hasSubscription,
        int $limit,
        string $redirectRoute
    ): RedirectResponse {
        $email = strtolower(trim((string) $request->request->get('email')));
        $credentialIds = $request->request->all('credentials') ?? [];
        $limitKey = sprintf('%s|%s', $owner->getId() ?? 0, $email);
        $limit = $this->shareInvitationLimiter->create($limitKey)->consume(1);

        if (!$limit->isAccepted()) {
            $this->addFlash('warning', 'Trop de tentatives de partage. Reessayez plus tard.');

            return $this->redirectToRoute($redirectRoute);
        }

        if ($email === '') {
            $this->addFlash('error', 'Veuillez renseigner une adresse email valide.');

            return $this->redirectToRoute($redirectRoute);
        }

        if ($credentialIds === []) {
            $this->addFlash('error', 'Aucun identifiant n\'a ete selectionne pour le partage.');

            return $this->redirectToRoute($redirectRoute);
        }

        $guest = $userRepository->findOneBy(['email' => $email]);

        if (!$guest) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = new \DateTimeImmutable('+6 hours');

            $guest = new User();
            $guest->setEmail($email);
            $guest->setCompany('');
            $guest->setPassword('');
            $guest->setRoles(['ROLE_GUEST']);
            $guest->setApiToken($token);
            $guest->setTokenExpiresAt($expiresAt);
            $guest->regenerateApiExtensionToken();

            $entityManager->persist($guest);
            $entityManager->flush();

            $this->sendGuestInvitationEmail($mailer, $urlGenerator, $guest, $expiresAt);
            $this->addFlash('success', 'Une invitation a ete envoyee a ' . $email . '.');
        } elseif (in_array('ROLE_GUEST', $guest->getRoles(), true) && $guest->getApiToken() !== null) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = new \DateTimeImmutable('+24 hours');

            $guest->setApiToken($token);
            $guest->setTokenExpiresAt($expiresAt);
            $entityManager->flush();

            $this->sendGuestInvitationEmail($mailer, $urlGenerator, $guest, $expiresAt);
            $this->addFlash('success', 'Une nouvelle invitation a ete envoyee a ' . $email . '.');
        }

        if ($guest === $owner) {
            $this->addFlash('error', 'Vous ne pouvez pas partager des identifiants avec vous-meme.');

            return $this->redirectToRoute($redirectRoute);
        }

        if (!$hasSubscription) {
            $existingCount = $entityManager->getRepository(SharedAccess::class)->count(['owner' => $owner]);
            $newToCreate = 0;

            foreach ($credentialIds as $credentialId) {
                $credential = $credentialRepository->find($credentialId);

                if (!$credential || $credential->getUser() !== $owner) {
                    continue;
                }

                $exists = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                    'owner' => $owner,
                    'guest' => $guest,
                    'credential' => $credential,
                ]);

                if (!$exists) {
                    $newToCreate++;
                }
            }

            if (($existingCount + $newToCreate) > $limit) {
                $remaining = max(0, $limit - $existingCount);
                $this->addFlash(
                    'warning',
                    sprintf(
                        'Limite atteinte : %d partages maximum sans abonnement. Il vous reste %d partage(s) possible(s).',
                        $limit,
                        $remaining
                    )
                );

                return $this->redirectToRoute($redirectRoute);
            }
        }

        $createdCount = 0;
        $sharedCredentials = [];

        foreach ($credentialIds as $credentialId) {
            $credential = $credentialRepository->find($credentialId);

            if (!$credential || $credential->getUser() !== $owner) {
                continue;
            }

            $existingAccess = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                'owner' => $owner,
                'guest' => $guest,
                'credential' => $credential,
            ]);

            if ($existingAccess) {
                continue;
            }

            $sharedAccess = new SharedAccess();
            $sharedAccess->setOwner($owner);
            $sharedAccess->setGuest($guest);
            $sharedAccess->setCredential($credential);
            $sharedAccess->setCreatedAt(new \DateTimeImmutable());

            $entityManager->persist($sharedAccess);
            $sharedCredentials[] = $credential;
            $createdCount++;
        }

        if ($createdCount === 0) {
            $this->addFlash('info', 'Aucun nouvel identifiant n\'a ete partage.');

            return $this->redirectToRoute($redirectRoute);
        }

        $entityManager->flush();
        $this->addFlash('success', $createdCount . ' identifiant(s) partage(s) avec succes.');

        $isActiveUser = in_array('ROLE_USER', $guest->getRoles(), true)
            && !in_array('ROLE_GUEST', $guest->getRoles(), true);

        if ($isActiveUser) {
            try {
                $mailer->send(
                    $guest->getEmail(),
                    $owner->getEmail() . ' a partage des identifiants avec vous',
                    'emails/share_notification.html.twig',
                    [
                        'owner' => $owner,
                        'guest' => $guest,
                        'credentials' => $sharedCredentials,
                        'app_url' => $urlGenerator->generate(
                            'shared_access_index',
                            [],
                            UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                    ]
                );
            } catch (\Throwable $exception) {
                $logger->warning('Unable to send share notification email.', [
                    'guest_email' => $guest->getEmail(),
                    'owner_id' => $owner->getId(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return $this->redirectToRoute($redirectRoute);
    }
}

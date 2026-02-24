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
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class SharedAccessController extends AbstractController
{
    #[Route('/app/shared-access', name: 'shared_access_index', methods: ['GET'])]
    public function index(SharedAccessRepository $sharedAccessRepository): Response
    {
        $user = $this->getUser();

        $sharedByMe   = $sharedAccessRepository->findBy(['owner' => $user]);
        $sharedWithMe = $sharedAccessRepository->findBy(['guest' => $user]);

        return $this->render('shared_access/index.html.twig', [
            'sharedByMe'   => $sharedByMe,
            'sharedWithMe' => $sharedWithMe,
            'heading'      => 'Partages',
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

        $hasSubscription = (bool) $owner->isSubscribed();
        $limit = 3;

        if ($request->isMethod('POST')) {
            $email         = strtolower(trim((string) $request->request->get('email')));
            $credentialIds = $request->request->all('credentials') ?? [];

            $guest = $userRepository->findOneBy(['email' => $email]);

            // ─── Cas 1 : utilisateur inexistant → créer un compte guest + envoyer l'invitation ───
            if (!$guest) {
                $token     = bin2hex(random_bytes(32));
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
                $entityManager->flush(); // flush pour avoir l'ID avant de créer les SharedAccess

                $guestRegisterUrl = $urlGenerator->generate(
                    'app_guest_register',
                    ['token' => $token, 'email' => $email],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $mailer->send(
                    $guest->getEmail(),
                    'Vous avez été invité sur MYKEYNEST',
                    'emails/register_guest.html.twig',
                    [
                        'user'           => $guest,
                        'guest_register' => $guestRegisterUrl,
                        'expiresAt'      => $expiresAt,
                    ]
                );

                $this->addFlash('success', 'Une invitation a été envoyée à ' . $email . '.');
            }

            // ─── Cas 2 : compte guest existant mais non activé → regénérer le token et renvoyer l'invitation ───
            elseif (in_array('ROLE_GUEST', $guest->getRoles(), true) && $guest->getApiToken() !== null) {
                $token     = bin2hex(random_bytes(32));
                $expiresAt = new \DateTimeImmutable('+6 hours');

                $guest->setApiToken($token);
                $guest->setTokenExpiresAt($expiresAt);
                $entityManager->flush();

                $guestRegisterUrl = $urlGenerator->generate(
                    'app_guest_register',
                    ['token' => $token, 'email' => $email],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $mailer->send(
                    $guest->getEmail(),
                    'Vous avez été invité sur MYKEYNEST',
                    'emails/register_guest.html.twig',
                    [
                        'user'           => $guest,
                        'guest_register' => $guestRegisterUrl,
                        'expiresAt'      => $expiresAt,
                    ]
                );

                $this->addFlash('success', 'Une nouvelle invitation a été envoyée à ' . $email . '.');
            }

            // ─── Cas 3 : compte actif (ROLE_USER) → on notifie après création des partages ───
            // (la notification est envoyée plus bas, après $entityManager->flush())

            // ─── Garde : on ne peut pas partager avec soi-même ───
            if ($guest === $owner) {
                $this->addFlash('error', 'Vous ne pouvez pas partager des identifiants avec vous-même.');
                return $this->redirectToRoute('shared_access_new');
            }

            // ─── Vérification de la limite d'abonnement ───
            if (!$hasSubscription) {
                $existingCount = $entityManager->getRepository(SharedAccess::class)->count(['owner' => $owner]);
                $newToCreate   = 0;

                foreach ($credentialIds as $credentialId) {
                    $credential = $credentialRepository->find($credentialId);

                    if (!$credential || $credential->getUser() !== $owner) {
                        continue;
                    }

                    $exists = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                        'owner'      => $owner,
                        'guest'      => $guest,
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
                    return $this->redirectToRoute('shared_access_new');
                }
            }

            // ─── Création des SharedAccess ───
            $createdCount = 0;

            foreach ($credentialIds as $credentialId) {
                $credential = $credentialRepository->find($credentialId);

                if (!$credential || $credential->getUser() !== $owner) {
                    continue;
                }

                $existingAccess = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                    'owner'      => $owner,
                    'guest'      => $guest,
                    'credential' => $credential,
                ]);

                if (!$existingAccess) {
                    $sharedAccess = new SharedAccess();
                    $sharedAccess->setOwner($owner);
                    $sharedAccess->setGuest($guest);
                    $sharedAccess->setCredential($credential);
                    $sharedAccess->setCreatedAt(new \DateTimeImmutable());

                    $entityManager->persist($sharedAccess);
                    $createdCount++;
                }
            }

            if ($createdCount > 0) {
                $entityManager->flush();
                $this->addFlash('success', $createdCount . ' identifiant(s) partagé(s) avec succès.');

                // ─── Notification mail pour un compte actif (ROLE_USER) ───
                $isActiveUser = in_array('ROLE_USER', $guest->getRoles(), true)
                    && !in_array('ROLE_GUEST', $guest->getRoles(), true);

                if ($isActiveUser) {
                    // Récupérer uniquement les credentials nouvellement partagés pour le mail
                    $sharedCredentials = [];
                    foreach ($credentialIds as $credentialId) {
                        $credential = $credentialRepository->find($credentialId);
                        if ($credential && $credential->getUser() === $owner) {
                            $sharedCredentials[] = $credential;
                        }
                    }

                    $mailer->send(
                        $guest->getEmail(),
                        $owner->getEmail() . ' a partagé des identifiants avec vous',
                        'emails/share_notification.html.twig',
                        [
                            'owner'       => $owner,
                            'guest'       => $guest,
                            'credentials' => $sharedCredentials,
                            'app_url'     => $urlGenerator->generate(
                                'shared_access_index',
                                [],
                                UrlGeneratorInterface::ABSOLUTE_URL
                            ),
                        ]
                    );
                }
            } else {
                $this->addFlash('info', 'Aucun nouvel identifiant n\'a été partagé.');
            }

            return $this->redirectToRoute('shared_access_index');
        }

        // GET
        $credentials = $credentialRepository->findBy(['user' => $owner]);

        return $this->render('shared_access/new.html.twig', [
            'credentials' => $credentials,
            'heading'     => 'Partages',
        ]);
    }

    #[Route('/app/shared-access/{id}/revoke', name: 'shared_access_revoke', methods: ['POST'])]
    public function revoke(
        Request $request,
        SharedAccess $sharedAccess,
        EntityManagerInterface $entityManager
    ): Response {
        if ($sharedAccess->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à révoquer cet accès partagé.');
        }

        if ($this->isCsrfTokenValid('revoke' . $sharedAccess->getId(), $request->request->get('_token'))) {
            $entityManager->remove($sharedAccess);
            $entityManager->flush();
            $this->addFlash('success', 'Accès partagé révoqué avec succès.');
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
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à voir cet identifiant.');
        }

        $credential        = $sharedAccess->getCredential();
        $decryptedPassword = $encryptionService->decrypt($credential->getPassword());

        return $this->render('shared_access/view_credential.html.twig', [
            'credential'        => $credential,
            'decryptedPassword' => $decryptedPassword,
            'owner'             => $sharedAccess->getOwner(),
            'heading'           => 'Partages',
        ]);
    }
}
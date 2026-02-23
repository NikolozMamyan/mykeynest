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
        
        // Récupérer les accès que l'utilisateur a partagés
        $sharedByMe = $sharedAccessRepository->findBy(['owner' => $user]);
        
        // Récupérer les accès partagés avec l'utilisateur
        $sharedWithMe = $sharedAccessRepository->findBy(['guest' => $user]);
        
        return $this->render('shared_access/index.html.twig', [
            'sharedByMe' => $sharedByMe,
            'sharedWithMe' => $sharedWithMe,
            'heading' => 'Partages'
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
    $owner = $this->getUser();
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    if (!$owner) {
        throw $this->createAccessDeniedException();
    }

    // ✅ règle abonnement
    $hasSubscription = (bool) $owner->isSubscribed(); // adapte à ton projet
    $limit = 3;

    if ($request->isMethod('POST')) {
        $email = $request->request->get('email');
        $credentialIds = $request->request->all('credentials') ?? []; // plus safe que $request->get()

        // Vérifier si l'utilisateur existe
        $guest = $userRepository->findOneBy(['email' => $email]);
     if (!$guest) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = (new \DateTimeImmutable('+1 hour'));

    $guest = new User(); // ✅ on crée directement $guest
    $guest->setEmail($email);
    $guest->setCompany('');
    $guest->setPassword(''); // ⚠️ voir note sécurité plus bas
    $guest->setRoles(['ROLE_GUEST']);
    $guest->setApiToken($token);
    $guest->setTokenExpiresAt($expiresAt);
    $guest->regenerateApiExtensionToken();

    $entityManager->persist($guest);
    $entityManager->flush();

    $guestRegisterUrl = $urlGenerator->generate(
        'app_guest_register',
        ['token' => $token, 'email' => $email],
        UrlGeneratorInterface::ABSOLUTE_URL
    );

    try {
        $mailer->send(
            $guest->getEmail(),
            'Welcome to MYKEYNEST',
            'emails/register_guest.html.twig',
            [
                'user' => $guest,
                'guest_register' => $guestRegisterUrl,
                'expiresAt' => $expiresAt,
            ]
        );
    } catch (\Exception $e) {
        $logger->error('Failed to send registration email', [
            'email' => $email,
            'error' => $e->getMessage(),
            'exception' => $e,
        ]);

        // ✅ pour voir direct en dev (optionnel)
        $this->addFlash('error', 'Erreur envoi email: ' . $e->getMessage());
    }

    $this->addFlash('success', 'Une invitation a été envoyée à cet utilisateur.');
    return $this->redirectToRoute('shared_access_new');
}
        // Vérifier que l'utilisateur ne partage pas avec lui-même
        if ($guest === $owner) {
            $this->addFlash('error', 'Vous ne pouvez pas partager des identifiants avec vous-même.');
            return $this->redirectToRoute('shared_access_new');
        }

        // ✅ 1) Si pas d'abonnement : vérifier la limite AVANT de persister
        if (!$hasSubscription) {
            // Compte des partages existants (ici: tous les partages créés par owner)
            $existingCount = $entityManager->getRepository(SharedAccess::class)->count([
                'owner' => $owner,
            ]);

            // Compter combien de "nouveaux" partages on s’apprête à créer
            $newToCreate = 0;

            foreach ($credentialIds as $credentialId) {
                $credential = $credentialRepository->find($credentialId);

                // appartient bien à owner
                if (!$credential || $credential->getUser() !== $owner) {
                    continue;
                }

                // existe déjà ?
                $existingAccess = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                    'owner' => $owner,
                    'guest' => $guest,
                    'credential' => $credential,
                ]);

                if (!$existingAccess) {
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

        // ✅ 2) Ensuite seulement : persister
        $createdCount = 0;

        foreach ($credentialIds as $credentialId) {
            $credential = $credentialRepository->find($credentialId);

            if (!$credential || $credential->getUser() !== $owner) {
                continue;
            }

            $existingAccess = $entityManager->getRepository(SharedAccess::class)->findOneBy([
                'owner' => $owner,
                'guest' => $guest,
                'credential' => $credential
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
        } else {
            $this->addFlash('info', 'Aucun nouvel identifiant n\'a été partagé.');
        }

        return $this->redirectToRoute('shared_access_index');
    }

    // GET
    $credentials = $credentialRepository->findBy(['user' => $owner]);

    return $this->render('shared_access/new.html.twig', [
        'credentials' => $credentials,
        'heading' => 'Partages'
    ]);
}

    
    #[Route('/app/shared-access/{id}/revoke', name: 'shared_access_revoke', methods: ['POST'])]
    public function revoke(Request $request, SharedAccess $sharedAccess, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que l'accès partagé appartient à l'utilisateur connecté
        if ($sharedAccess->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à révoquer cet accès partagé.');
        }
        
        if ($this->isCsrfTokenValid('revoke'.$sharedAccess->getId(), $request->request->get('_token'))) {
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
        
        // Vérifier que l'utilisateur est l'invité à qui cet accès a été partagé
        if ($sharedAccess->getGuest() !== $user) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à voir cet identifiant.');
        }
        
        $credential = $sharedAccess->getCredential();
        $decryptedPassword = $encryptionService->decrypt($credential->getPassword());
        
        return $this->render('shared_access/view_credential.html.twig', [
            'credential' => $credential,
            'decryptedPassword' => $decryptedPassword,
            'owner' => $sharedAccess->getOwner(),
            'heading' => 'Partages'
        ]);
    }
}
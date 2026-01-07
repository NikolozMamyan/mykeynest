<?php

namespace App\Controller\Front;

use App\Repository\CredentialRepository;
use App\Service\SecurityCheckerService;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityCheckerController extends AbstractController
{
    public function __construct(
        private Security $security,
        private SecurityCheckerService $checker,
        private CredentialRepository $credentialRepository,
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
    ) {}

    #[Route('/app/security/checker', name: 'app_security_checker', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $report = $this->checker->buildReport($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);

        // Optionnel: créer une notification si score très bas (simple exemple)
        if (($report['overallScore'] ?? 0) < 40) {
            $this->notificationService->createNotification(
                $user,
                'Sécurité : mots de passe à améliorer',
                'Certains mots de passe sont faibles/anciens. Consulte le vérificateur de sécurité.',
                type: \App\Entity\Notification::TYPE_WARNING,
                actionUrl: $this->generateUrl('app_security_checker'),
                icon: 'shield-exclamation',
                priority: \App\Entity\Notification::PRIORITY_HIGH
            );
        }

        return $this->render('security_checker/index.html.twig', [
            'report' => $report,
            'rotationDays' => SecurityCheckerService::ROTATION_DAYS_DEFAULT,
        ]);
    }

    #[Route('/app/security/checker/credential/{id}/rotated', name: 'app_security_checker_credential_rotated', methods: ['POST'])]
    public function markRotated(int $id, Request $request): Response
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('rotate'.$id, $token)) {
            throw $this->createAccessDeniedException('CSRF token invalide.');
        }

        $cred = $this->credentialRepository->find($id);
        if (!$cred) {
            throw $this->createNotFoundException();
        }

        // Autorisation: owner uniquement (simple). Si tu veux autoriser guest, adapte avec SharedAccess.
        if ($cred->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $cred->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', 'Mot de passe marqué comme changé.');

        return $this->redirectToRoute('app_security_checker');
    }
}

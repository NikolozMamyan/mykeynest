<?php

namespace App\Controller\Front;

use App\Entity\Notification;
use App\Service\NotificationService;
use App\Service\SecurityCheckerService;
use App\Repository\CredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\NotificationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class SecurityCheckerController extends AbstractController
{
    public function __construct(
        private Security $security,
        private SecurityCheckerService $checker,
        private CredentialRepository $credentialRepository,
        private EntityManagerInterface $em,
        private NotificationService $notificationService,
        private NotificationRepository $notificationRepository,
    ) {}

    #[Route('/app/security/checker', name: 'app_security_checker', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->security->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $report = $this->checker->buildReport($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);

        $score = $report['overallScore'] ?? 0;

if ($score < 40) {


       $this->checker->buildReportAndNotify($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);
    
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

<?php

namespace App\Controller\Front;

use App\Entity\SharedAccess;
use App\Service\SharedAccessValidityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SharedAccessValidityController extends AbstractController
{
    #[Route('/app/shared-access/{id}/validity', name: 'shared_access_validity', methods: ['GET', 'POST'])]
    public function manage(SharedAccess $share, Request $request, SharedAccessValidityManager $validity, EntityManagerInterface $em): Response
    {
        if ($share->getOwner() !== $this->getUser()) throw $this->createAccessDeniedException();
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('share_validity_'.$share->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $validity->apply($share, $validity->parseExpiry((string) $request->request->get('expires_at', '')));
                $em->flush();
                $this->addFlash('success', 'share_validity.saved');

                return $this->redirectToRoute('shared_access_validity', ['id' => $share->getId()]);
            } catch (\DomainException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('shared_access/validity.html.twig', ['share' => $share, 'error' => $error]);
    }

    #[Route('/guest/shared-access/{id}/extension', name: 'guest_share_extension', methods: ['GET', 'POST'])]
    public function requestExtension(SharedAccess $share, Request $request, SharedAccessValidityManager $validity): Response
    {
        $signature = (string) $request->query->get('signature', '');
        if (!$validity->acceptsSignature($share, $signature)) throw $this->createAccessDeniedException();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('share_extension_'.$share->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            try {
                $validity->requestExtension($share, $signature);
            } catch (\DomainException) {
                throw $this->createAccessDeniedException();
            }
        }

        return $this->render('guest/extension_request.html.twig', ['share' => $share]);
    }
}

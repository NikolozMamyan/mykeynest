<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\VisitorSession;
use App\Service\VisitorReportProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/visiteurs')]
#[IsGranted('ROLE_ADMIN')]
final class AdminVisitorController extends AbstractController
{
    #[Route('', name: 'admin_visitors', methods: ['GET'])]
    public function index(Request $request, VisitorReportProvider $reports): Response
    {
        return $this->render(
            $request->isXmlHttpRequest() ? 'admin/visitors/_overview.html.twig' : 'admin/visitors/index.html.twig',
            $reports->overview($request),
            new Response(headers: ['Cache-Control' => 'private, no-store']),
        );
    }

    #[Route('/{id}', name: 'admin_visitor_session', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function session(int $id, Request $request, EntityManagerInterface $entityManager, VisitorReportProvider $reports): Response
    {
        $session = $entityManager->find(VisitorSession::class, $id);
        if (null === $session) {
            throw $this->createNotFoundException();
        }

        return $this->render(
            $request->isXmlHttpRequest() ? 'admin/visitors/_history.html.twig' : 'admin/visitors/session.html.twig',
            $reports->history($session, $request),
            new Response(headers: ['Cache-Control' => 'private, no-store']),
        );
    }
}

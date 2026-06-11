<?php

namespace App\Controller\Front;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthSessionManagerController extends AbstractController
{
    #[Route('/app/sessions', name: 'app_sessions_manager')]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('auth_session_manager/index.html.twig', [
            'hasTeamPlan' => $user instanceof User && $user->hasActivePlan('team'),
        ]);
    }
}

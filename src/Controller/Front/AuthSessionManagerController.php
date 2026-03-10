<?php

namespace App\Controller\Front;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthSessionManagerController extends AbstractController
{
    #[Route('/app/sessions', name: 'app_sessions_manager')]
    public function index(): Response
    {
        return $this->render('auth_session_manager/index.html.twig');
    }
}
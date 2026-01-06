<?php

namespace App\Controller\Front;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityCheckerController extends AbstractController
{
    #[Route('/security/checker', name: 'app_security_checker')]
    public function index(): Response
    {
        return $this->render('security_checker/index.html.twig', [
            'controller_name' => 'SecurityCheckerController',
        ]);
    }
}

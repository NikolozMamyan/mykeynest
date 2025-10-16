<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class CredentialPageController extends AbstractController
{
    #[Route('/app/credential', name: 'app_credential')]
    public function index(): Response
    {

        return $this->render('credential/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }
}
<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class DashboardPageController extends AbstractController
{
    #[Route('/app/dashboard', name: 'app_dashboard')]
    public function index(): Response
    {

        return $this->render('dashboard/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }
}
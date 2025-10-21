<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class SubscriptionPageController extends AbstractController
{
    #[Route('/app/subscription', name: 'app_subscription')]
    public function index(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }


        #[Route('/app/subscription/pro', name: 'app_subscription_pro')]
    public function pro(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }

            #[Route('/app/subscription/corpo', name: 'app_subscription_corpo')]
    public function corpo(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }



    #[Route('/app/contact', name: 'app_contact')]
    public function contact(): Response
    {

        return $this->render('subscription/index.html.twig', [
            'controller_name' => 'TestController',
        ]);
    }
}
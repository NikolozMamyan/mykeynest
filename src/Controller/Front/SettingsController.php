<?php

namespace App\Controller\Front;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SettingsController extends AbstractController
{
    #[Route('/app/settings', name: 'app_settings')]
    public function index(): Response
    {
        $user = $this->getUser();

        if(!$user) {
             return $this->redirectToRoute('show_login');
        }


        return $this->render('settings/index.html.twig', [
            'controller_name' => 'SettingsController',
        ]);
    }
}

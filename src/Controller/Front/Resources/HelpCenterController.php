<?php

namespace App\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HelpCenterController extends AbstractController
{
    #[Route('/help/center', name: 'app_help_center')]
    public function helpCenter(): Response
    {

        return $this->render('help_center/index.html.twig', [
            'controller_name' => 'HelpCenterController',
        ]);
    }

        #[Route('/generator', name: 'app_public_generator')]
    public function publicGenerator(): Response
    {

        return $this->render('help_center/public_generator.html.twig', [
            'controller_name' => 'HelpCenterController',
        ]);
    }
}

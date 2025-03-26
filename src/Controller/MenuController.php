<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class MenuController extends AbstractController
{
    #[Route('/app/menu', name: 'app_menu')]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
 
        return $this->render('menu/index.html.twig', [
            'user' => $user,
        ]);
    }
}

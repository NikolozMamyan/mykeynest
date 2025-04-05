<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ModSelectorController extends AbstractController
{
    #[Route('/app/mod/selector', name: 'app_mod_selector')]
    public function index(): Response
    {
        $user = $this->getUser();
        $userChar = $user->getCharacters();
        $charArr = [];

        foreach ($userChar as $char) {
            $charArr[] = [
                'id' => $char->getId(),
                'name' => $char->getName(),
                'image' => $char->getHero()->getImage(),
                'heroClass' => $char->getHero()->getClassName(),
            ];
        }

        return $this->render('mod_selector/index.html.twig', [
            'characters' => $charArr,
        ]);
    }
}

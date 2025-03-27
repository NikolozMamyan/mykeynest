<?php
// src/Controller/BattleFaceOffController.php

namespace App\Controller;

use App\Entity\Character;
use App\Service\BotService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BattleFaceOffController extends AbstractController
{
    #[Route('/app/battle/faceoff/{id}', name: 'battle_faceoff')]
    public function faceoff(
        int $id,
        EntityManagerInterface $em,
        BotService $botService,
        RequestStack $requestStack
    ): Response {
        $char1 = $em->getRepository(Character::class)->find($id);

        if (!$char1 || $char1->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException("You do not own this character.");
        }

        // Génère un bot adverse
        $char2 = $botService->generateBotFor($char1);

        // Stocke en session (optionnel pour transition vers `/battle/init`)
        $session = $requestStack->getSession();
        $session->set('pending_battle', [
            'char1_id' => $char1->getId(),
            'char2_id' => $char2->getId(),
        ]);

        return $this->render('battle/faceoff.html.twig', [
            'char1' => $char1,
            'char2' => $char2,
        ]);
    }
}

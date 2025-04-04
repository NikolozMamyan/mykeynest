<?php

namespace App\Controller;

use App\Entity\Character;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class BattleFaceOffController extends AbstractController
{
    #[Route('/app/faceoff/select/{id}', name: 'battle_faceoff_select')]
    public function selectOpponent(int $id, EntityManagerInterface $em): Response
    {
        $currentUser = $this->getUser();

        $userCharacter = $em->getRepository(Character::class)->find($id);

        if (!$userCharacter || $userCharacter->getOwner() !== $currentUser) {
            throw $this->createAccessDeniedException("Ce personnage ne vous appartient pas.");
        }

        // On suppose qu'il y a une relation User->friends (ManyToMany)
        $friends = $currentUser->getFriends(); // méthode personnalisée dans l'entité User

        $opponents = [];

        foreach ($friends as $friend) {
            foreach ($friend->getCharacters() as $char) {
                $opponents[] = [
                    'id' => $char->getId(),
                    'name' => $char->getName(),
                    'owner' => $friend->getUsername(),
                    'heroClass' => $char->getHero()->getClassName(),
                ];
            }
        }

        return $this->render('battle_faceoff/select.html.twig', [
            'myChar' => $userCharacter,
            'opponents' => $opponents,
        ]);
    }

    #[Route('/app/battle/faceoff/{id1}/{id2}', name: 'battle_faceoff_start')]
    public function startFaceOff(int $id1, int $id2, EntityManagerInterface $em): Response
    {
        $char1 = $em->getRepository(Character::class)->find($id1);
        $char2 = $em->getRepository(Character::class)->find($id2);

        if (!$char1 || !$char2) {
            throw $this->createNotFoundException("Un des personnages est introuvable.");
        }

        // TODO : Lancer logique de combat ici ou rediriger vers une vue de combat personnalisée
        return $this->redirectToRoute('battle_init', [
            'id' => $char1->getId(),
            'id2' => $char2->getId()
        ]);
    }
}

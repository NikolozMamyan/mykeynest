<?php

namespace App\Controller;

use App\Entity\Perk;
use App\Entity\Character;
use App\Entity\Inventory;
use App\Service\RoundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class InventoryController extends AbstractController
{


    private RoundService $roundService;

    public function __construct(RoundService $roundService)
    {
        $this->roundService = $roundService;
    }
    #[Route('/app/inventory/{id}', name: 'inventory_show')]
    public function manageInventory(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $character = $em->getRepository(Character::class)->find($id);
        if (!$character || $character->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException("Access denied.");
        }
    
        $inventory = $em->getRepository(Inventory::class)->findOneBy(['character' => $character]);
    
        if ($request->isMethod('POST')) {
            $selectedPerks = $request->request->all('perks'); // array of perk IDs
            $character->getEquippedPerks()->clear();
    
            foreach ($selectedPerks as $perkId) {
                $perk = $em->getRepository(Perk::class)->find($perkId);
                if ($perk) {
                    $character->addEquippedPerk($perk);
                }
            }
    
            $em->flush();
            $this->addFlash('success', 'Inventory updated!');
            $modifiedStats = $this->roundService->getCharacterStatsWithPerks(['name' => $character->getName()]);
            return $this->redirectToRoute('app_menu');
        }
    
        return $this->render('inventory/index.html.twig', [
            'character' => $character,
            'inventory' => $inventory,
        ]);
    }
    
}

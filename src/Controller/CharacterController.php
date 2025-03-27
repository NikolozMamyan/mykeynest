<?php
namespace App\Controller;

use App\Entity\Hero;
use App\Entity\Character;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class CharacterController extends AbstractController
{
    #[Route('/app/character/create', name: 'character_create')]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
    if (!$user) {
        return $this->redirectToRoute('show_login');
    }

    if ($request->isMethod('POST')) {
        $data = $request->request;
        $heroId = $data->get('hero_id');
        $hero = $em->getRepository(Hero::class)->find($heroId);

        $character = new Character();
        $character->setName($data->get('name'));
        $character->setStrength((int) $data->get('strength'));
        $character->setDefense((int) $data->get('defense'));
        $character->setSpeed((int) $data->get('speed'));
        $character->setAgility((int) $data->get('agility'));
        $character->setHp(100);
        $character->setStamina(100);
        $character->setHero($hero);
        $character->setOwner($user);

        $em->persist($character);
        $em->flush();

        $this->addFlash('success', 'Character Created!');
        return $this->redirectToRoute('app_menu');
    }

    // Envoie les héros au template
    $heroes = $em->getRepository(Hero::class)->findAll();

    return $this->render('character/create.html.twig', [
        'heroes' => $heroes,
    ]);
    
    }

    #[Route('/app/character/{id}', name: 'character_show', methods: ['GET'])]
public function show(int $id, EntityManagerInterface $em): Response
{
    $character = $em->getRepository(Character::class)->find($id);

    if (!$character) {
        throw $this->createNotFoundException('Character not found');
    }

    // Optionnel : empêcher d'accéder aux persos des autres
    if ($character->getOwner() !== $this->getUser()) {
        throw $this->createAccessDeniedException("Access denied.");
    }

    return $this->render('character/show.html.twig', [
        'character' => $character,
    ]);
}

#[Route('/app/character/{id}/delete', name: 'character_delete', methods: ['POST'])]
public function delete(int $id, EntityManagerInterface $em): Response
{
    $character = $em->getRepository(Character::class)->find($id);

    if (!$character) {
        throw $this->createNotFoundException('Character not found');
    }

    if ($character->getOwner() !== $this->getUser()) {
        throw $this->createAccessDeniedException("You cannot delete this character.");
    }

    $em->remove($character);
    $em->flush();

    $this->addFlash('success', 'Character deleted!');
    return $this->redirectToRoute('app_menu');
}


}

<?php

namespace App\Controller\Front;

use App\Form\UserAvatarType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserProfilController extends AbstractController
{
    #[Route('/app/user/profil', name: 'app_user_profil')]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(UserAvatarType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Avatar mis à jour ✅');

            return $this->redirectToRoute('app_user_profil');
        }

        return $this->render('user_profil/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}

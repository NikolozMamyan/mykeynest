<?php

namespace App\Controller\Front;

use App\Form\AvatarType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Filesystem\Filesystem;

final class SettingsController extends AbstractController
{
    #[Route('/app/settings', name: 'app_settings')]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        Filesystem $filesystem
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // 🔹 on garde l’ancien avatar AVANT traitement
        $oldAvatar = $user->getAvatar();

        $form = $this->createForm(AvatarType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $avatarFile = $form->get('avatar')->getData();

            if ($avatarFile) {
                $newFilename = uniqid('avatar_', true).'.'.$avatarFile->guessExtension();

                try {
                    $avatarFile->move(
                        $this->getParameter('uploads_avatar'),
                        $newFilename
                    );
                } catch (FileException $e) {
                    $this->addFlash('error', 'Erreur lors de l’upload de l’image.');
                    return $this->redirectToRoute('app_settings');
                }

                // 🔹 suppression de l’ancien fichier si existant
                if ($oldAvatar) {
                    $oldAvatarPath = $this->getParameter('uploads_avatar').'/'.$oldAvatar;

                    if ($filesystem->exists($oldAvatarPath)) {
                        $filesystem->remove($oldAvatarPath);
                    }
                }

                // 🔹 update BDD
                $user->setAvatar($newFilename);
            }

            $em->flush();

            $this->addFlash('success', 'Avatar mis à jour avec succès ✅');
            return $this->redirectToRoute('app_settings');
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}

<?php

namespace App\Controller\Front;

use App\Form\UserType;
use App\Form\AvatarType;
use App\Form\PreferencesType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

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
    #[Route('/app/settings/profile', name: 'app_user_profile')]
    public function profile(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Profile updated');

            return $this->redirectToRoute('app_user_profile');
        }

        return $this->render('settings/profile.html.twig', [
            'form' => $form->createView(),
        ]);
    }

#[Route('/app/settings/preferences', name: 'app_user_preferences')]
public function preferences(Request $request, EntityManagerInterface $em): Response
{
    $user = $this->getUser();

    if (!$user) {
        throw $this->createAccessDeniedException();
    }

    $form = $this->createForm(PreferencesType::class, $user);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
        $em->flush();
        

        $request->getSession()->set('_locale', $user->getLocale());
        
        $this->addFlash('success', 'Préférences mises à jour avec succès');

        return $this->redirectToRoute('app_user_preferences');
    }

    return $this->render('settings/preferences.html.twig', [
        'form' => $form->createView(),
    ]);
}

#[Route('/app/settings/subscription/manage', name: 'app_user_subscription_manage')]
    public function subscriptionManage(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

    if (!$user) {
        throw $this->createAccessDeniedException();
    }

    if ($user->hasActiveSubscription()) {
        return $this->redirectToRoute('app_subscription_portal');
    }

    return $this->redirectToRoute('app_subscription');
    }
}

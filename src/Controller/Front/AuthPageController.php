<?php

namespace App\Controller\Front;

use App\Repository\UserRepository;
use App\Service\TokenCleaner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class AuthPageController extends AbstractController
{
    #[Route('/login', name: 'show_login', methods: ['GET'])]
    public function login(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();
    
        // 💣 Nettoie le token même si pas authentifié dans "main"
        $cleaner->clearTokenFromRequest($request, $response);

          $user = $this->getUser(); 
        if($user) {
              $security->logout(false);
        }
    
        $response->setContent(
            $this->renderView('auth/login.html.twig')
        );
    
        return $response;
    }
    
    #[Route('/register', name: 'show_register', methods: ['GET'])]
    public function showRegister(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();
    
        // 💣 Nettoie le token même si pas authentifié dans "main"
        $cleaner->clearTokenFromRequest($request, $response);

          $user = $this->getUser(); 
        if($user) {
              $security->logout(false);
        }
    
        $response->setContent(
            $this->renderView('auth/register.html.twig')
        );
    
        return $response;
    }
    
        #[Route('/', name: 'app_landing', methods: ['GET'])]
    public function landing(Request $request, TokenCleaner $cleaner, Security $security): Response
    {
        $response = new Response();
    
        // 💣 Nettoie le token même si pas authentifié dans "main"
        $cleaner->clearTokenFromRequest($request, $response);

          $user = $this->getUser(); 
        if($user) {
              $security->logout(false);
        }
    
        $response->setContent(
            $this->renderView('auth/landing.html.twig')
        );
    
        return $response;
    }

    #[Route('/guest/register', name: 'app_guest_register', methods: ['GET', 'POST'])]
public function guestRegister(
    Request $request,
    UserRepository $userRepository,
    EntityManagerInterface $em,
    UserPasswordHasherInterface $hasher
): Response {
    $token = $request->query->get('token');
    $email = $request->query->get('email');
    

    if (!$token) {
        throw $this->createNotFoundException('Token manquant');
    }

    $user = $userRepository->findOneBy(['apiToken' => $token]);

    if (!$user) {
        throw $this->createNotFoundException('Token invalide');
    }
    if (!in_array('ROLE_GUEST', $user->getRoles(), true)) {
            $this->addFlash('error', 'Invitation déjà utilisée.');
        return $this->redirectToRoute('show_login');
}

    // Expiration
    if ($user->getTokenExpiresAt() && $user->getTokenExpiresAt() < new \DateTimeImmutable()) {
        $this->addFlash('error', 'Lien expiré. Demandez une nouvelle invitation.');
        return $this->redirectToRoute('show_login'); // ou une page dédiée
    }

    // Optionnel: vérifier que l'email du lien correspond
    if ($email && strtolower($user->getEmail()) !== strtolower($email)) {
        throw $this->createAccessDeniedException('Email mismatch');
    }

    if ($request->isMethod('POST')) {
        $plainPassword = (string) $request->request->get('password');
        $company =  (string) $request->request->get('company');

        // TODO: validations (longueur, confirmation, etc.)
        $user->setPassword($hasher->hashPassword($user, $plainPassword));
        $user->setCompany($company);

        // ✅ invalider le token après usage
        $user->setApiToken(null);
        $user->setTokenExpiresAt(null);

        // ✅ activer le rôle final si besoin
        $user->setRoles(['ROLE_USER']); // ou ROLE_GUEST + ROLE_USER selon ton système

        $em->flush();

        $this->addFlash('success', 'Compte créé. Vous pouvez vous connecter.');
        return $this->redirectToRoute('show_login');
    }

    return $this->render('guest/register.html.twig', [
        'user' => $user,
        'token' => $token,
    ]);
}
    
}

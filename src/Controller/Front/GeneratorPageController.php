<?php

namespace App\Controller\Front;

use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Service\CredentialManager;
use App\Service\DraftPasswordManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

final class GeneratorPageController extends AbstractController
{


    #[Route('/app/generator', name: 'app_generator')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $drafts = $em->getRepository(DraftPassword::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            10
        );

        return $this->render('generateur/index.html.twig', [
            'drafts' => $drafts,
        ]);
    }
}

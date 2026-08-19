<?php

namespace App\Controller\Front;

use App\Entity\DraftPassword;
use App\Entity\User;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GeneratorPageController extends AbstractController
{
    public function __construct(private readonly SubscriptionPlanService $subscriptionPlans)
    {
    }

    #[Route('/app/generator', name: 'app_generator')]
    public function index(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->subscriptionPlans->hasFeature($user, SubscriptionPlanService::FEATURE_PASSWORD_GENERATOR)) {
            throw $this->createAccessDeniedException('Le générateur n’est pas disponible avec votre plan.');
        }

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

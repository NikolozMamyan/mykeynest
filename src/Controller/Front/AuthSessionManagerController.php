<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Service\SubscriptionPlanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthSessionManagerController extends AbstractController
{
    #[Route('/app/sessions', name: 'app_sessions_manager')]
    public function index(SubscriptionPlanService $subscriptionPlans): Response
    {
        $user = $this->getUser();

        return $this->render('auth_session_manager/index.html.twig', [
            'hasTeamPlan' => $user instanceof User
                && $subscriptionPlans->getPlanForUser($user)['code'] === SubscriptionPlanService::PLAN_TEAM,
        ]);
    }
}

<?php

namespace App\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BusinessController extends AbstractController
{
    #[Route('/solution-cybersecurite-pme', name: 'business_solution')]
    public function solution(): Response
    {
        return $this->render('business/solution.html.twig');
    }

    #[Route('/comparatif-password-manager-entreprise', name: 'business_comparatif')]
    public function comparatif(): Response
    {
        return $this->render('business/comparatif.html.twig');
    }

    #[Route('/audit-cybersecurite-pme', name: 'business_audit')]
    public function audit(): Response
    {
        return $this->render('business/audit.html.twig');
    }
}
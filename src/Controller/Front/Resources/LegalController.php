<?php
namespace App\Controller\Front\Resources;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LegalController extends AbstractController
{
private function commonVars(): array
{
    return [
        'brand_name' => 'MyKeyNest',
        'publisher_name' => 'OptiWebSolutions',
        'brand_note' => 'MyKeyNest est une marque (sous-brand) de OptiWebSolutions.',
        'founder_name' => 'Nikoloz Mamyan',
        'contact_email' => 'nikoloz.mamyan@gmail.com',
        'hosting_provider' => 'o2switch',
        'updated_at' => new \DateTimeImmutable('2026-02-04'),
    ];
}


    #[Route('/cgu', name: 'legal_cgu', methods: ['GET'])]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig', $this->commonVars() + [
            'page_title' => 'Conditions Générales d’Utilisation (CGU)',
        ]);
    }

    #[Route('/cgv', name: 'legal_cgv', methods: ['GET'])]
    public function cgv(): Response
    {
        return $this->render('legal/cgv.html.twig', $this->commonVars() + [
            'page_title' => 'Conditions Générales de Vente (CGV)',
        ]);
    }
}


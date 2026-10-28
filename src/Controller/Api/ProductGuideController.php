<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\ProductGuide;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ProductGuideController extends AbstractController
{
    #[Route('/api/product-guide', name: 'api_product_guide_update', methods: ['POST'])]
    public function update(Request $request, ProductGuide $guide, EntityManagerInterface $entityManager): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$this->isCsrfTokenValid('product_guide', $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException();
        }

        $data = $request->toArray();
        if (!is_string($data['action'] ?? null) || (isset($data['step']) && !is_int($data['step']))) {
            return $this->json(['error' => 'Invalid guide request.'], 422);
        }
        try {
            $state = $guide->update($user, $data['action'], $data['step'] ?? null);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        $entityManager->flush();
        $response = $this->json(['state' => $state]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}

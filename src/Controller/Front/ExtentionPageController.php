<?php

namespace App\Controller\Front;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;


 final class ExtentionPageController extends AbstractController
{
    #[Route('/app/extention', name: 'app_extention')]
    public function index(): Response
    {
        $user = $this->getUser();

        $extentiotoken = $user->getApiExtensionToken();
        
        return $this->render('extention/index.html.twig', [
            'extentiotoken' => $extentiotoken,
        ]);
    }
}
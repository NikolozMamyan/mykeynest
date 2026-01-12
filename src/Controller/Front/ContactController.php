<?php

namespace App\Controller\Front;

use App\Service\MailerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ContactController extends AbstractController
{
#[Route('/contact/send', name: 'contact_send', methods: ['POST'])]
public function send(Request $request, MailerService $mailer): Response
{
    $name = trim((string) $request->request->get('name', ''));
    $email = trim((string) $request->request->get('email', ''));
    $reason = trim((string) $request->request->get('reason', ''));
    $message = trim((string) $request->request->get('message', ''));

    // 🔁 Page précédente (fallback si absent)
    $referer = $request->headers->get('referer');
    $redirectUrl = $referer ?: $this->generateUrl('app_landing');

    // validations
    if ($name === '' || $email === '' || $reason === '' || $message === '') {
        $this->addFlash('error', 'Tous les champs sont obligatoires.');
        return $this->redirect($redirectUrl . '#contact');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->addFlash('error', 'Adresse e-mail invalide.');
        return $this->redirect($redirectUrl . '#contact');
    }

    $reasonMap = [
        'support' => 'Support',
        'billing' => 'Facturation',
        'bug' => 'Bug',
        'partnership' => 'Partenariat',
        'other' => 'Autre',
    ];

    $reasonLabel = $reasonMap[$reason] ?? 'Autre';

    $mailer->send(
        'contact@key-nest.com',
        'Contact - ' . $reasonLabel,
        'emails/contact.html.twig',
        [
            'name' => $name,
            'userEmail' => $email,
            'reason' => $reason,
            'reasonLabel' => $reasonLabel,
            'message' => $message,
        ]
    );

    $this->addFlash('success', 'Thanks! Your message has been sent.');

    return $this->redirect($redirectUrl . '#contact');
}

}

<?php

namespace App\Controller\Front;

use App\Service\SupportChatService;
use App\Service\AdminNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class SupportChatController extends AbstractController
{
    public function __construct(
        private readonly SupportChatService $supportChatService,
        private readonly RateLimiterFactory $supportChatLimiter,
        private readonly AdminNotificationService $adminNotificationService,
    ) {
    }

    #[Route('/support/chat/widget', name: 'app_support_chat_widget', methods: ['GET'])]
    public function widget(Request $request): Response
    {
        $conversation = $this->supportChatService->findConversationForToken(
            $request->cookies->get(SupportChatService::COOKIE_NAME)
        );

        if ($conversation !== null) {
            $this->supportChatService->markConversationSeenByVisitor($conversation);
        }

        return $this->render('support_chat/_widget.html.twig', [
            'conversation' => $conversation,
            'conversationPayload' => $conversation ? $this->supportChatService->buildWidgetPayload($conversation) : null,
        ]);
    }

    #[Route('/support/chat/state', name: 'app_support_chat_state', methods: ['GET'])]
    public function state(Request $request): JsonResponse
    {
        $conversation = $this->supportChatService->findConversationForToken(
            $request->cookies->get(SupportChatService::COOKIE_NAME)
        );

        if ($conversation === null) {
            return $this->json(['conversation' => null]);
        }

        $this->supportChatService->markConversationSeenByVisitor($conversation);

        return $this->json([
            'conversation' => $this->supportChatService->buildWidgetPayload($conversation),
        ]);
    }

    #[Route('/support/chat/messages', name: 'app_support_chat_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('support_chat_send', $token)) {
            return $this->json(['error' => 'Session invalide. Recharge la page et reessaie.'], Response::HTTP_FORBIDDEN);
        }

        $email = trim((string) $request->request->get('email', ''));
        $message = trim((string) $request->request->get('message', ''));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Adresse email invalide.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($message === '') {
            return $this->json(['error' => 'Le message est obligatoire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $ip = (string) ($request->getClientIp() ?? 'unknown');
        $key = $ip . '|' . mb_strtolower($email);
        $limit = $this->supportChatLimiter->create($key)->consume(1);

        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'Trop de messages envoyes. Reessaie un peu plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        try {
            $conversation = $this->supportChatService->createOrAppendVisitorMessage(
                $request->cookies->get(SupportChatService::COOKIE_NAME),
                $email,
                $message
            );
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        $response = $this->json([
            'conversation' => $this->supportChatService->buildWidgetPayload($conversation),
        ]);

        $response->headers->setCookie($this->buildConversationCookie($request, $conversation->getPublicToken()));
        $this->adminNotificationService->notifySupportChatMessage($conversation, $message);

        return $response;
    }

    private function buildConversationCookie(Request $request, string $token): Cookie
    {
        return Cookie::create(SupportChatService::COOKIE_NAME)
            ->withValue($token)
            ->withHttpOnly(true)
            ->withSecure($request->isSecure())
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withPath('/')
            ->withExpires(new \DateTimeImmutable('+6 months'));
    }
}

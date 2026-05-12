<?php

namespace App\Controller\Admin;

use App\Entity\SupportConversation;
use App\Repository\SupportConversationRepository;
use App\Service\SupportChatService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/support-chat')]
#[IsGranted('ROLE_ADMIN')]
final class SupportChatAdminController extends AbstractController
{
    #[Route('', name: 'admin_chat', methods: ['GET'])]
    public function index(
        Request $request,
        SupportConversationRepository $conversationRepository,
        SupportChatService $supportChatService,
    ): Response {
        $conversations = $conversationRepository->findInboxConversations();
        $selectedId = $request->query->getInt('conversation');
        $selectedConversation = null;

        if ($selectedId > 0) {
            $selectedConversation = $conversationRepository->find($selectedId);
        }

        if ($selectedConversation === null && $conversations !== []) {
            $selectedConversation = $conversations[0];
        }

        if ($selectedConversation instanceof SupportConversation) {
            $supportChatService->markConversationSeenByAdmin($selectedConversation);
        }

        return $this->render('admin/chat.html.twig', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
        ]);
    }

    #[Route('/{id}/reply', name: 'admin_chat_reply', methods: ['POST'])]
    public function reply(
        SupportConversation $conversation,
        Request $request,
        SupportChatService $supportChatService,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_chat_reply_' . $conversation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
        }

        $message = trim((string) $request->request->get('message', ''));
        if ($message === '') {
            $this->addFlash('error', 'Le message de reponse est obligatoire.');

            return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
        }

        $adminEmail = $this->getUser()?->getUserIdentifier() ?? 'admin@mykeynest.local';
        try {
            $supportChatService->appendAdminMessage($conversation, $adminEmail, $message);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
        }

        $this->addFlash('success', 'Reponse envoyee dans la conversation.');

        return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
    }

    #[Route('/{id}/close', name: 'admin_chat_close', methods: ['POST'])]
    public function close(
        SupportConversation $conversation,
        Request $request,
        SupportChatService $supportChatService,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_chat_close_' . $conversation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
        }

        $supportChatService->closeConversation($conversation);
        $this->addFlash('success', 'Conversation fermee. Le visiteur ne peut plus envoyer de message sur cette session.');

        return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_chat_delete', methods: ['POST'])]
    public function delete(
        SupportConversation $conversation,
        Request $request,
        SupportChatService $supportChatService,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_chat_delete_' . $conversation->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_chat', ['conversation' => $conversation->getId()]);
        }

        $supportChatService->deleteConversation($conversation);
        $this->addFlash('success', 'Conversation supprimee.');

        return $this->redirectToRoute('admin_chat');
    }
}

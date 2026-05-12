<?php

namespace App\Twig;

use App\Repository\SupportConversationRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SupportChatExtension extends AbstractExtension
{
    public function __construct(
        private readonly SupportConversationRepository $conversationRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('support_chat_unread_count', [$this, 'getUnreadCount']),
        ];
    }

    public function getUnreadCount(): int
    {
        return $this->conversationRepository->countUnreadForAdmin();
    }
}

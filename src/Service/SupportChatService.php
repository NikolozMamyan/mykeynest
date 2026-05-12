<?php

namespace App\Service;

use App\Entity\SupportConversation;
use App\Entity\SupportMessage;
use App\Repository\SupportConversationRepository;
use Doctrine\ORM\EntityManagerInterface;

class SupportChatService
{
    public const COOKIE_NAME = 'SUPPORT_CHAT_TOKEN';

    public function __construct(
        private readonly SupportConversationRepository $conversationRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function findConversationForToken(?string $token): ?SupportConversation
    {
        $normalizedToken = trim((string) $token);
        if ($normalizedToken === '') {
            return null;
        }

        return $this->conversationRepository->findOneByPublicToken($normalizedToken);
    }

    public function createOrAppendVisitorMessage(?string $token, string $email, string $body): SupportConversation
    {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedBody = $this->normalizeBody($body);

        $conversation = $this->findConversationForToken($token);
        if ($conversation === null) {
            $conversation = (new SupportConversation())
                ->setPublicToken(bin2hex(random_bytes(32)))
                ->setEmail($normalizedEmail);
            $this->em->persist($conversation);
        } else {
            $conversation->setEmail($normalizedEmail);
        }

        $message = (new SupportMessage())
            ->setAuthorType(SupportMessage::AUTHOR_VISITOR)
            ->setAuthorEmail($normalizedEmail)
            ->setBody($normalizedBody);

        if ($conversation->getStatus() === SupportConversation::STATUS_CLOSED) {
            throw new \RuntimeException('Cette conversation est fermee.');
        }

        $conversation
            ->addMessage($message)
            ->setStatus(SupportConversation::STATUS_OPEN)
            ->setUnreadForAdmin(true)
            ->setUnreadForVisitor(false)
            ->setLastMessageAt($message->getCreatedAt());

        $this->em->persist($message);
        $this->em->flush();

        return $conversation;
    }

    public function appendAdminMessage(SupportConversation $conversation, string $adminEmail, string $body): SupportConversation
    {
        if ($conversation->getStatus() === SupportConversation::STATUS_CLOSED) {
            throw new \RuntimeException('Cette conversation est fermee.');
        }

        $normalizedBody = $this->normalizeBody($body);
        $normalizedAdminEmail = $this->normalizeEmail($adminEmail);

        $message = (new SupportMessage())
            ->setAuthorType(SupportMessage::AUTHOR_ADMIN)
            ->setAuthorEmail($normalizedAdminEmail)
            ->setBody($normalizedBody);

        $conversation
            ->addMessage($message)
            ->setStatus(SupportConversation::STATUS_OPEN)
            ->setUnreadForAdmin(false)
            ->setUnreadForVisitor(true)
            ->setLastMessageAt($message->getCreatedAt());

        $this->em->persist($message);
        $this->em->flush();

        return $conversation;
    }

    public function closeConversation(SupportConversation $conversation): void
    {
        $conversation
            ->setStatus(SupportConversation::STATUS_CLOSED)
            ->setUnreadForAdmin(false)
            ->setUnreadForVisitor(false);

        $this->em->flush();
    }

    public function deleteConversation(SupportConversation $conversation): void
    {
        $this->em->remove($conversation);
        $this->em->flush();
    }

    public function markConversationSeenByAdmin(SupportConversation $conversation): void
    {
        if (!$conversation->isUnreadForAdmin()) {
            return;
        }

        $conversation->setUnreadForAdmin(false);
        $this->em->flush();
    }

    public function markConversationSeenByVisitor(SupportConversation $conversation): void
    {
        if (!$conversation->isUnreadForVisitor()) {
            return;
        }

        $conversation->setUnreadForVisitor(false);
        $this->em->flush();
    }

    /**
     * @return array{
     *   id:int|null,
     *   email:string,
     *   status:string,
     *   unreadForVisitor:bool,
     *   messages:list<array{authorType:string,body:string,createdAt:string}>
     * }
     */
    public function buildWidgetPayload(SupportConversation $conversation): array
    {
        $messages = [];

        foreach ($conversation->getMessages() as $message) {
            $messages[] = [
                'authorType' => $message->getAuthorType(),
                'body' => $message->getBody(),
                'createdAt' => $message->getCreatedAt()->format(\DATE_ATOM),
            ];
        }

        return [
            'id' => $conversation->getId(),
            'email' => $conversation->getEmail(),
            'status' => $conversation->getStatus(),
            'unreadForVisitor' => $conversation->isUnreadForVisitor(),
            'messages' => $messages,
        ];
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizeBody(string $body): string
    {
        $normalized = trim(preg_replace('/\R{3,}/u', "\n\n", $body) ?? $body);

        return $normalized;
    }
}

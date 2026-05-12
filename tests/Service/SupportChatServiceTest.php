<?php

namespace App\Tests\Service;

use App\Entity\SupportConversation;
use App\Repository\SupportConversationRepository;
use App\Service\SupportChatService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SupportChatServiceTest extends TestCase
{
    public function testCreateOrAppendVisitorMessageCreatesConversationWhenNoneExists(): void
    {
        $repository = $this->createMock(SupportConversationRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $em->expects(self::exactly(2))
            ->method('persist');
        $em->expects(self::once())
            ->method('flush');

        $service = new SupportChatService($repository, $em);
        $conversation = $service->createOrAppendVisitorMessage(null, 'Lead@Example.com', "Bonjour\n\nProjet CRM");

        self::assertSame('lead@example.com', $conversation->getEmail());
        self::assertTrue($conversation->isUnreadForAdmin());
        self::assertFalse($conversation->isUnreadForVisitor());
        self::assertCount(1, $conversation->getMessages());
        self::assertNotSame('', $conversation->getPublicToken());
    }

    public function testAppendAdminMessageMarksConversationUnreadForVisitor(): void
    {
        $repository = $this->createMock(SupportConversationRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $conversation = (new SupportConversation())
            ->setPublicToken('token')
            ->setEmail('lead@example.com');

        $em->expects(self::once())
            ->method('persist');
        $em->expects(self::once())
            ->method('flush');

        $service = new SupportChatService($repository, $em);
        $service->appendAdminMessage($conversation, 'admin@example.com', 'Bonjour, on peut vous aider.');

        self::assertFalse($conversation->isUnreadForAdmin());
        self::assertTrue($conversation->isUnreadForVisitor());
        self::assertCount(1, $conversation->getMessages());

        $message = $conversation->getMessages()->first();
        self::assertSame('admin', $message->getAuthorType());
        self::assertSame('admin@example.com', $message->getAuthorEmail());
    }

    public function testClosedConversationRejectsVisitorMessages(): void
    {
        $repository = $this->createMock(SupportConversationRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $conversation = (new SupportConversation())
            ->setPublicToken('token')
            ->setEmail('lead@example.com')
            ->setStatus(SupportConversation::STATUS_CLOSED);

        $repository->expects(self::once())
            ->method('findOneByPublicToken')
            ->with('token')
            ->willReturn($conversation);

        $em->expects(self::never())
            ->method('persist');
        $em->expects(self::never())
            ->method('flush');

        $service = new SupportChatService($repository, $em);

        $this->expectException(\RuntimeException::class);
        $service->createOrAppendVisitorMessage('token', 'lead@example.com', 'Encore un message');
    }
}

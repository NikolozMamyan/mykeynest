<?php

namespace App\EventSubscriber;

use App\Entity\Credential;
use App\Service\CredentialCountProvider;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Events;

final class CredentialCountCacheSubscriber implements EventSubscriber
{
    public function __construct(private readonly CredentialCountProvider $credentialCounts)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postPersist, Events::postRemove];
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $this->invalidate($event->getObject());
    }

    public function postRemove(PostRemoveEventArgs $event): void
    {
        $this->invalidate($event->getObject());
    }

    private function invalidate(object $entity): void
    {
        if ($entity instanceof Credential && $entity->getUser() !== null) {
            $this->credentialCounts->invalidateForUser($entity->getUser());
        }
    }
}

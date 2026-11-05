<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\SharedAccess;
use App\Entity\User;
use App\Repository\SharedAccessRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessValidityManager
{
    public function __construct(
        private SharedAccessRepository $shares,
        private EntityManagerInterface $em,
        private NotificationService $notifications,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {}

    public function parseExpiry(string $value): ?\DateTimeImmutable
    {
        if (trim($value) === '') return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new \DateTimeZone('Europe/Paris'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $date <= new \DateTimeImmutable()) {
            throw new \DomainException('share_validity.invalid_date');
        }

        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    public function apply(SharedAccess $share, ?\DateTimeImmutable $expiresAt): void
    {
        if ($expiresAt !== null && $expiresAt <= new \DateTimeImmutable()) throw new \DomainException('share_validity.invalid_date');
        $isGuest = in_array('ROLE_GUEST', $share->getGuest()->getRoles(), true);
        $share->setValidity($expiresAt ?? ($isGuest ? new \DateTimeImmutable('+7 days') : null), $isGuest && $expiresAt === null);
        if ($isGuest) $this->keepInvitationValid($share->getGuest(), $share->getExpiresAt());
    }

    public function keepInvitationValid(User $guest, ?\DateTimeImmutable $expiresAt): void
    {
        if ($guest->getApiToken() === null) $guest->setApiToken(bin2hex(random_bytes(32)));
        if ($expiresAt !== null && ($guest->getTokenExpiresAt() === null || $guest->getTokenExpiresAt() < $expiresAt)) {
            $guest->setTokenExpiresAt($expiresAt);
        }
    }

    public function activate(User $guest): void
    {
        foreach ($this->shares->findBy(['guest' => $guest]) as $share) {
            if ($share->isUnlimitedOnActivation() && !$share->isExpired()) $share->setValidity(null);
        }
    }

    public function extensionRequestUrl(SharedAccess $share): string
    {
        return $this->urls->generate('guest_share_extension', [
            'id' => $share->getId(), 'signature' => $this->signature($share),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function acceptsSignature(SharedAccess $share, string $signature): bool
    {
        return $share->getExpiresAt() !== null && hash_equals($this->signature($share), $signature);
    }

    public function requestExtension(SharedAccess $share, string $signature): bool
    {
        return $this->em->getConnection()->transactional(function () use ($share, $signature): bool {
            $this->em->refresh($share, LockMode::PESSIMISTIC_WRITE);
            if (!$this->acceptsSignature($share, $signature)) throw new \DomainException('share_validity.invalid_link');
            if ($share->getExpiresAt() === null || $share->getExtensionRequestedAt() !== null) return false;
            $share->setExtensionRequestedAt(new \DateTimeImmutable());
            $owner = $share->getOwner();
            $this->notifications->createNotification(
                user: $owner,
                title: $this->translator->trans('share_validity.request_title', [], null, $owner->getLocale()),
                message: $this->translator->trans('share_validity.request_message', ['%email%' => $share->getGuest()->getEmail(), '%name%' => $share->getCredential()->getName()], null, $owner->getLocale()),
                type: Notification::TYPE_INFO,
                actionUrl: $this->urls->generate('shared_access_validity', ['id' => $share->getId()]),
                icon: 'fa-solid fa-clock',
                uniqueKey: 'share-extension-'.$share->getId().'-'.$share->getExpiresAt()->getTimestamp(),
            );
            $this->em->flush();

            return true;
        });
    }

    private function signature(SharedAccess $share): string
    {
        return hash_hmac('sha256', $share->getId().'|'.$share->getGuest()->getId().'|'.$share->getExpiresAt()?->getTimestamp(), $this->secret);
    }
}

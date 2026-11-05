<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\SharedAccess;
use App\Repository\NotificationRepository;
use App\Repository\SharedAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SharedAccessExpiryNotifier
{
    public function __construct(
        private SharedAccessRepository $shares,
        private NotificationRepository $notificationRepository,
        private NotificationService $notifications,
        private SharedAccessValidityManager $validity,
        private MailerService $mailer,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function sendDue(): int
    {
        $now = new \DateTimeImmutable();
        $sent = 0;
        foreach ($this->shares->findDueForExpiryReminder($now) as $share) {
            $this->em->refresh($share);
            if ($share->isExpired($now) || $share->getExpiresAt() === null || $share->getExpiresAt() > $now->modify('+1 day')) continue;
            foreach (['owner', 'guest'] as $recipient) {
                try {
                    $sent += $this->sendReminder($share, $recipient, $now) ? 1 : 0;
                } catch (\Throwable $exception) {
                    $this->logger->error('Shared access expiry reminder failed.', ['share_id' => $share->getId(), 'recipient' => $recipient, 'exception' => $exception]);
                }
            }
        }

        return $sent;
    }

    private function sendReminder(SharedAccess $share, string $recipient, \DateTimeImmutable $now): bool
    {
        $isOwner = $recipient === 'owner';
        if (($isOwner ? $share->getOwnerReminderSentAt() : $share->getGuestReminderSentAt()) !== null) return false;
        $user = $isOwner ? $share->getOwner() : $share->getGuest();
        $locale = $user->getLocale();
        $manageUrl = $this->urls->generate('shared_access_validity', ['id' => $share->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        if ($isOwner) {
            $key = 'share-expiry-'.$share->getId().'-'.$share->getExpiresAt()->getTimestamp();
            if (!$this->notificationRepository->findOneBy(['uniqueKey' => $key])) {
                $this->notifications->createNotification(
                    user: $user,
                    title: $this->translator->trans('share_validity.reminder_title', [], null, $locale),
                    message: $this->translator->trans('share_validity.reminder_message', ['%name%' => $share->getCredential()->getName(), '%email%' => $share->getGuest()->getEmail()], null, $locale),
                    type: Notification::TYPE_WARNING,
                    actionUrl: $manageUrl,
                    icon: 'fa-solid fa-clock',
                    uniqueKey: $key,
                );
            }
        }
        $isGuest = in_array('ROLE_GUEST', $share->getGuest()->getRoles(), true);
        $registrationUrl = $isGuest ? $this->urls->generate('app_guest_register', [
            'token' => $share->getGuest()->getApiToken(), 'email' => $share->getGuest()->getEmail(),
        ], UrlGeneratorInterface::ABSOLUTE_URL) : $this->urls->generate('shared_access_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->mailer->send(
            $user->getEmail(),
            $this->translator->trans('share_validity.reminder_title', [], null, $locale),
            'emails/share_expiry.html.twig',
            ['share' => $share, 'locale' => $locale, 'isOwner' => $isOwner, 'isGuest' => $isGuest,
                'manageUrl' => $manageUrl, 'registrationUrl' => $registrationUrl, 'requestUrl' => $this->validity->extensionRequestUrl($share)],
        );
        if ($isOwner) $share->setOwnerReminderSentAt($now);
        else $share->setGuestReminderSentAt($now);
        $this->em->flush();

        return true;
    }
}

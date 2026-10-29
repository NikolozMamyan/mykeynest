<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class UserRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ExtensionOnboardingPolicy $onboarding,
        private NotificationService $notifications,
        private MailerService $mailer,
        private AdminNotificationService $adminNotifications,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function companyFor(array $data): ?string
    {
        $company = $data['company'] ?? '';
        if (!is_string($company)) {
            throw new \InvalidArgumentException('auth.register.errors.invalid_company');
        }
        $company = trim($company);
        $type = $data['accountType'] ?? ('' === $company ? 'personal' : 'professional');
        if (!in_array($type, ['personal', 'professional'], true)) {
            throw new \InvalidArgumentException('auth.register.errors.invalid_type');
        }
        if ('personal' === $type) {
            return null;
        }
        if ('' === $company || mb_strlen($company) > 255) {
            throw new \InvalidArgumentException('auth.register.errors.company_required');
        }

        return $company;
    }

    public function register(Request $request, string $email, #[\SensitiveParameter] ?string $password, ?string $company, ?string $googleSubject = null): User
    {
        $user = (new User())->setEmail($email)->setCompany($company)->setRoles(['ROLE_USER'])
            ->setLocale(in_array($request->getLocale(), ['fr', 'en'], true) ? $request->getLocale() : 'en')
            ->setGoogleSubject($googleSubject);
        if (null !== $password) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        }
        $this->onboarding->initializeNewRegistration($user);
        $user->regenerateApiExtensionToken();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        try {
            $this->notifications->createEntityNotification($user, 'Welcome', $user,
                'Complete your profile to fully enjoy the application.', Notification::TYPE_SUCCESS,
                '/app/settings/profile', 'fa-hands-clapping', Notification::PRIORITY_LOW);
        } catch (\Exception $exception) {
            $this->logger->error('Failed to create registration notification', ['userId' => $user->getId(), 'error' => $exception->getMessage()]);
        }
        try {
            $this->mailer->send($user->getEmail(), 'Bienvenue sur MYKEYNEST', 'emails/welcome.html.twig', [
                'user' => $user, 'settingsUrl' => $this->urls->generate('app_user_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        } catch (\Exception $exception) {
            $this->logger->error('Failed to send registration emails', ['userId' => $user->getId(), 'error' => $exception->getMessage()]);
        }
        try {
            $this->adminNotifications->notifyNewRegistration($user, $request->getClientIp(), $request->headers->get('User-Agent'));
        } catch (\Exception $exception) {
            $this->logger->error('Failed to send admin registration notification', ['userId' => $user->getId(), 'error' => $exception->getMessage()]);
        }

        return $user;
    }
}

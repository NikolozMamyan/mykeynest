<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class GoogleAccountService
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private UserRegistrationService $registrations,
    ) {
    }

    public function resolve(array $identity, Request $request, ?string $company): array
    {
        $user = $this->users->findOneBy(['googleSubject' => $identity['sub']]);
        if (null !== $user) {
            return [$user, false];
        }
        $email = mb_strtolower(trim($identity['email']));
        if (null !== $this->users->findOneBy(['email' => $email])) {
            throw new \RuntimeException('auth.google.link_required');
        }

        return [$this->registrations->register($request, $email, null, $company, $identity['sub']), true];
    }

    public function link(User $user, array $identity): void
    {
        if (mb_strtolower((string) $user->getEmail()) !== mb_strtolower($identity['email'])) {
            throw new \RuntimeException('auth.google.email_mismatch');
        }
        $existing = $this->users->findOneBy(['googleSubject' => $identity['sub']]);
        if ((null !== $existing && $existing->getId() !== $user->getId())
            || (null !== $user->getGoogleSubject() && $user->getGoogleSubject() !== $identity['sub'])) {
            throw new \RuntimeException('auth.google.link_conflict');
        }
        $user->setGoogleSubject($identity['sub']);
        $this->entityManager->flush();
    }
}

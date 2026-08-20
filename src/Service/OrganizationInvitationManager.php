<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class OrganizationInvitationManager
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OrganizationSeatManager $organizationSeats,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerService $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{membership: OrganizationMember, pending: bool, mailSent: bool}
     */
    public function inviteEmployee(
        Organization $organization,
        string $email,
        OrganizationRole $role,
        User $actor,
    ): array {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse e-mail invalide.');
        }
        if (!in_array($role, [OrganizationRole::MEMBER, OrganizationRole::ADMIN], true)) {
            throw new \InvalidArgumentException('Ce rôle ne peut pas être attribué.');
        }
        $owner = $organization->getOwner();
        $actorIsOwner = $owner === $actor
            || ($owner?->getId() !== null && $owner->getId() === $actor->getId());
        if ($role === OrganizationRole::ADMIN && !$actorIsOwner) {
            throw new \DomainException('Seul le propriétaire peut inviter un administrateur.');
        }

        $memberUser = $this->users->findOneBy(['email' => $email]);
        $existing = $memberUser instanceof User
            ? $this->organizationSeats->getMembership($organization, $memberUser)
            : null;
        if (
            $existing instanceof OrganizationMember
            && !$existing->isInvitationExpired()
            && $existing->getRole() !== OrganizationRole::GUEST
        ) {
            throw new \DomainException('Cet utilisateur appartient déjà à l’entreprise.');
        }

        $expiresAt = null;
        $pending = false;
        if (!$memberUser instanceof User) {
            $expiresAt = new \DateTimeImmutable('+24 hours');
            $pending = true;
            $memberUser = (new User())
                ->setEmail($email)
                ->setCompany('')
                ->setPassword('')
                ->setRoles(['ROLE_GUEST'])
                ->setApiToken(bin2hex(random_bytes(32)))
                ->setTokenExpiresAt($expiresAt)
                ->regenerateApiExtensionToken();
            $this->entityManager->persist($memberUser);
        } elseif (in_array('ROLE_GUEST', $memberUser->getRoles(), true)) {
            $expiresAt = new \DateTimeImmutable('+24 hours');
            $pending = true;
            $memberUser
                ->setApiToken(bin2hex(random_bytes(32)))
                ->setTokenExpiresAt($expiresAt);
        }

        $membership = $this->organizationSeats->inviteCompanyMember(
            $organization,
            $memberUser,
            $actor,
            $role,
            $pending,
            $expiresAt,
        );
        $this->entityManager->flush();

        $actionUrl = $pending
            ? $this->urlGenerator->generate('app_guest_register', [
                'token' => $memberUser->getApiToken(),
                'email' => $memberUser->getEmail(),
            ], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->urlGenerator->generate('app_team_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $mailSent = true;
        try {
            $this->mailer->send(
                (string) $memberUser->getEmail(),
                $this->translator->trans(
                    'teams.organization.invite.email_subject',
                    ['%company%' => $organization->getName()],
                    null,
                    $memberUser->getLocale(),
                ),
                'emails/organization_invitation.html.twig',
                [
                    'organization' => $organization,
                    'membership' => $membership,
                    'action_url' => $actionUrl,
                    'requires_registration' => $pending,
                    'expiresAt' => $expiresAt,
                    'locale' => $memberUser->getLocale(),
                ],
            );
        } catch (\Throwable $exception) {
            $mailSent = false;
            $this->logger->warning('Unable to send organization invitation email.', [
                'organization_id' => $organization->getId(),
                'email' => $email,
                'exception' => $exception->getMessage(),
            ]);
        }

        return [
            'membership' => $membership,
            'pending' => $pending,
            'mailSent' => $mailSent,
        ];
    }
}

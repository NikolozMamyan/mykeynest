<?php

namespace App\Service;

use App\Entity\Organization;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Enum\TeamRole;
use Doctrine\ORM\EntityManagerInterface;

final class TeamCreationManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TeamMemberAssignmentManager $memberAssignments,
        private readonly TeamCredentialPermissionManager $permissions,
        private readonly OrganizationSeatManager $organizationSeats,
    ) {
    }

    /**
     * @param array<array{email:string, role:TeamRole}> $members
     * @return list<array{member:TeamMember, user:User, invitationExpiresAt:?\DateTimeImmutable}>
     */
    public function create(Team $team, User $actor, ?Organization $organization, array $members, bool $canRevealPassword): array
    {
        if ($organization !== null && (!$organization->isActive() || !$this->organizationSeats->canManage($organization, $actor))) {
            throw new \DomainException('teams.wizard.organization_unavailable');
        }

        $owner = $organization?->getOwner() ?? $actor;
        $emails = [mb_strtolower((string) $owner->getEmail()), mb_strtolower((string) $actor->getEmail())];
        foreach ($members as $member) {
            $email = mb_strtolower(trim($member['email']));
            if (in_array($email, $emails, true)) {
                throw new \DomainException('teams.wizard.duplicate_email');
            }
            $emails[] = $email;
        }

        return $this->entityManager->getConnection()->transactional(function () use ($team, $actor, $owner, $organization, $members, $canRevealPassword): array {
            $team->setOwner($owner)->setOrganization($organization);
            $this->entityManager->persist($team);
            $ownerMember = (new TeamMember())->setUser($owner)->setRole(TeamRole::OWNER);
            $team->addMember($ownerMember);
            $this->entityManager->persist($ownerMember);

            if ($owner !== $actor) {
                $creatorMember = (new TeamMember())->setUser($actor)->setRole(TeamRole::ADMIN);
                $team->addMember($creatorMember);
                $this->entityManager->persist($creatorMember);
            }

            foreach ($team->getCredentials() as $credential) {
                $this->permissions->setPasswordReveal($team, $credential, $canRevealPassword);
            }
            $this->entityManager->flush();

            $assignments = [];
            foreach ($members as $member) {
                $assignment = $this->memberAssignments->assign(
                    $team,
                    $member['email'],
                    $member['role'],
                    $actor,
                );
                $team->addMember($assignment['member']);
                $assignments[] = $assignment;
            }

            return $assignments;
        });
    }
}

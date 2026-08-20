<?php

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\User;
use App\Enum\OrganizationMemberStatus;
use App\Enum\OrganizationRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<OrganizationMember> */
class OrganizationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMember::class);
    }

    public function findMembership(Organization $organization, User $user): ?OrganizationMember
    {
        return $this->findOneBy(['organization' => $organization, 'user' => $user]);
    }

    /** @return list<OrganizationMember> */
    public function findPendingForUser(User $user): array
    {
        return $this->findBy([
            'user' => $user,
            'status' => OrganizationMemberStatus::PENDING,
        ]);
    }

    public function findActiveTeamMembership(User $user): ?OrganizationMember
    {
        return $this->createQueryBuilder('organizationMember')
            ->innerJoin('organizationMember.organization', 'organization')
            ->addSelect('organization')
            ->innerJoin('organization.subscription', 'subscription')
            ->addSelect('subscription')
            ->andWhere('organizationMember.user = :user')
            ->andWhere('organizationMember.status = :status')
            ->andWhere('organizationMember.role != :guestRole')
            ->andWhere('organization.status = :organizationStatus')
            ->andWhere('subscription.isActive = true')
            ->andWhere('LOWER(subscription.planCode) = :plan')
            ->setParameter('user', $user)
            ->setParameter('status', OrganizationMemberStatus::ACTIVE->value)
            ->setParameter('guestRole', OrganizationRole::GUEST->value)
            ->setParameter('organizationStatus', \App\Enum\OrganizationStatus::ACTIVE->value)
            ->setParameter('plan', 'team')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

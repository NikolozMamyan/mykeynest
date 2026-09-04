<?php

namespace App\Service;

use App\Entity\Credential;
use App\Entity\ExtensionClient;
use App\Entity\ExtensionInstallationChallenge;
use App\Entity\OrganizationMember;
use App\Entity\SupportConversation;
use App\Entity\User;
use App\Entity\UserDevice;
use App\Entity\UserSession;
use App\Entity\UserSubscription;
use Doctrine\ORM\EntityManagerInterface;

final class AdminInsightsService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDashboard(int $periodDays): array
    {
        $periodDays = in_array($periodDays, [1, 7, 30, 90], true) ? $periodDays : 30;
        $now = new \DateTimeImmutable();
        $since = $now->modify(sprintf('-%d days', $periodDays));

        $customerUsers = $this->countCustomerUsers();
        $activeSubscriptions = $this->countActiveSubscriptions();
        $activeSessions = $this->countActiveSessions($now);
        $activeExtensionUsers = $this->countActiveExtensionUsers($since);
        $onboardedUsers = $this->scalarCount(
            User::class,
            'user',
            'user.extensionOnboardingStatus = :status AND user.roles NOT LIKE :adminRole',
            ['status' => User::EXTENSION_ONBOARDING_COMPLETED, 'adminRole' => '%ROLE_ADMIN%']
        );
        $credentialUsers = $this->countCredentialUsers();

        return [
            'periodDays' => $periodDays,
            'since' => $since,
            'metrics' => [
                'users' => $customerUsers,
                'activeSubscriptions' => $activeSubscriptions,
                'activeSessions' => $activeSessions,
                'activeExtensionUsers' => $activeExtensionUsers,
                'extensionActivationRate' => $customerUsers > 0 ? (int) round(($activeExtensionUsers / $customerUsers) * 100) : 0,
                'pendingChallenges' => $this->scalarCount(
                    ExtensionInstallationChallenge::class,
                    'challenge',
                    'challenge.status = :status AND challenge.expiresAt > :now',
                    ['status' => ExtensionInstallationChallenge::STATUS_PENDING, 'now' => $now]
                ),
                'attentionItems' => $this->countAttentionItems($now),
            ],
            'funnel' => [
                ['label' => 'Comptes clients', 'value' => $customerUsers],
                ['label' => 'Onboarding terminé', 'value' => $onboardedUsers],
                ['label' => 'Extension active', 'value' => $activeExtensionUsers],
                ['label' => 'Premier identifiant', 'value' => $credentialUsers],
                ['label' => 'Abonnement actif', 'value' => $activeSubscriptions],
            ],
            'plans' => $this->getPlanDistribution($customerUsers),
            'versions' => $this->getExtensionVersions($since),
            'attention' => [
                'pendingChallenges' => $this->entityManager->getRepository(ExtensionInstallationChallenge::class)
                    ->createQueryBuilder('challenge')
                    ->leftJoin('challenge.user', 'user')->addSelect('user')
                    ->andWhere('challenge.status = :status')
                    ->andWhere('challenge.expiresAt > :now')
                    ->setParameter('status', ExtensionInstallationChallenge::STATUS_PENDING)
                    ->setParameter('now', $now)
                    ->orderBy('challenge.createdAt', 'DESC')
                    ->setMaxResults(4)
                    ->getQuery()->getResult(),
                'blockedExtensions' => $this->entityManager->getRepository(ExtensionClient::class)
                    ->createQueryBuilder('client')
                    ->leftJoin('client.user', 'user')->addSelect('user')
                    ->andWhere('client.isBlocked = true')
                    ->orderBy('client.blockedAt', 'DESC')
                    ->setMaxResults(4)
                    ->getQuery()->getResult(),
                'scheduledCancellations' => $this->entityManager->getRepository(UserSubscription::class)
                    ->createQueryBuilder('subscription')
                    ->leftJoin('subscription.user', 'user')->addSelect('user')
                    ->andWhere('subscription.isActive = true')
                    ->andWhere('subscription.cancelAtPeriodEnd = true')
                    ->orderBy('subscription.currentPeriodEnd', 'ASC')
                    ->setMaxResults(4)
                    ->getQuery()->getResult(),
                'unreadConversations' => $this->entityManager->getRepository(SupportConversation::class)
                    ->createQueryBuilder('conversation')
                    ->andWhere('conversation.unreadForAdmin = true')
                    ->orderBy('conversation.lastMessageAt', 'DESC')
                    ->setMaxResults(4)
                    ->getQuery()->getResult(),
            ],
        ];
    }

    /**
     * @param list<User> $users
     * @return list<array<string, mixed>>
     */
    public function buildUserRows(array $users): array
    {
        $userIds = array_values(array_filter(array_map(static fn (User $user): ?int => $user->getId(), $users)));
        if ($userIds === []) {
            return [];
        }

        $now = new \DateTimeImmutable();
        $sessionStats = $this->indexRows($this->entityManager->getRepository(UserSession::class)
            ->createQueryBuilder('session')
            ->select(
                'IDENTITY(session.user) AS userId',
                'COUNT(session.id) AS total',
                'SUM(CASE WHEN session.isBlocked = false AND session.isRevoked = false AND session.expiresAt > :now THEN 1 ELSE 0 END) AS active',
                'SUM(CASE WHEN session.isBlocked = true THEN 1 ELSE 0 END) AS blocked',
                'MAX(session.lastActivityAt) AS lastActivity'
            )
            ->andWhere('session.user IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->setParameter('now', $now)
            ->groupBy('session.user')
            ->getQuery()->getArrayResult());

        $deviceStats = $this->indexRows($this->entityManager->getRepository(UserDevice::class)
            ->createQueryBuilder('device')
            ->select('IDENTITY(device.user) AS userId', 'COUNT(device.id) AS total')
            ->andWhere('device.user IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->groupBy('device.user')
            ->getQuery()->getArrayResult());

        $extensionStats = $this->indexRows($this->entityManager->getRepository(ExtensionClient::class)
            ->createQueryBuilder('client')
            ->select(
                'IDENTITY(client.user) AS userId',
                'COUNT(client.id) AS total',
                'SUM(CASE WHEN client.isBlocked = false AND client.isRevoked = false THEN 1 ELSE 0 END) AS active',
                'SUM(CASE WHEN client.isBlocked = true THEN 1 ELSE 0 END) AS blocked',
                'MAX(client.lastSeenAt) AS lastActivity'
            )
            ->andWhere('client.user IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->groupBy('client.user')
            ->getQuery()->getArrayResult());

        $challengeStats = $this->indexRows($this->entityManager->getRepository(ExtensionInstallationChallenge::class)
            ->createQueryBuilder('challenge')
            ->select('IDENTITY(challenge.user) AS userId', 'COUNT(challenge.id) AS total')
            ->andWhere('challenge.user IN (:userIds)')
            ->andWhere('challenge.status = :status')
            ->andWhere('challenge.expiresAt > :now')
            ->setParameter('userIds', $userIds)
            ->setParameter('status', ExtensionInstallationChallenge::STATUS_PENDING)
            ->setParameter('now', $now)
            ->groupBy('challenge.user')
            ->getQuery()->getArrayResult());

        $credentialStats = $this->indexRows($this->entityManager->getRepository(Credential::class)
            ->createQueryBuilder('credential')
            ->select('IDENTITY(credential.user) AS userId', 'COUNT(credential.id) AS total')
            ->andWhere('credential.user IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->groupBy('credential.user')
            ->getQuery()->getArrayResult());

        $memberships = [];
        $membershipRows = $this->entityManager->getRepository(OrganizationMember::class)
            ->createQueryBuilder('membership')
            ->leftJoin('membership.organization', 'organization')->addSelect('organization')
            ->leftJoin('organization.subscription', 'organizationSubscription')->addSelect('organizationSubscription')
            ->andWhere('membership.user IN (:userIds)')
            ->setParameter('userIds', $userIds)
            ->orderBy('membership.joinedAt', 'DESC')
            ->getQuery()->getResult();
        foreach ($membershipRows as $membership) {
            $memberUserId = $membership->getUser()?->getId();
            if ($memberUserId !== null && !isset($memberships[$memberUserId])) {
                $memberships[$memberUserId] = $membership;
            }
        }

        $rows = [];
        foreach ($users as $user) {
            $id = (int) $user->getId();
            $membership = $memberships[$id] ?? null;
            $subscription = $user->getUserSubscription();
            $sessions = $sessionStats[$id] ?? [];
            $extensions = $extensionStats[$id] ?? [];
            $pendingChallenges = (int) ($challengeStats[$id]['total'] ?? 0);
            $activeExtensions = (int) ($extensions['active'] ?? 0);
            $blockedExtensions = (int) ($extensions['blocked'] ?? 0);
            $lastActivity = $this->mostRecent($sessions['lastActivity'] ?? null, $extensions['lastActivity'] ?? null);

            if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $extensionState = ['label' => 'Non applicable', 'tone' => 'muted'];
            } elseif ($blockedExtensions > 0) {
                $extensionState = ['label' => 'Action requise', 'tone' => 'danger'];
            } elseif ($pendingChallenges > 0) {
                $extensionState = ['label' => 'Installation en cours', 'tone' => 'warning'];
            } elseif ($activeExtensions > 0) {
                $extensionState = ['label' => 'Active', 'tone' => 'success'];
            } elseif ($user->requiresExtensionOnboarding()) {
                $extensionState = ['label' => 'À installer', 'tone' => 'warning'];
            } else {
                $extensionState = ['label' => 'Absente', 'tone' => 'muted'];
            }

            if ($membership !== null
                && $membership->getStatus()->value === 'ACTIVE'
                && $membership->getRole()->value !== 'GUEST'
                && $membership->getOrganization()?->isActive()
            ) {
                $effectivePlan = 'team';
                $planSource = 'Siège entreprise';
            } elseif ($subscription?->isActive()) {
                $effectivePlan = mb_strtolower((string) ($subscription->getPlanCode() ?: 'pro'));
                $planSource = 'Abonnement individuel';
            } else {
                $effectivePlan = 'free';
                $planSource = 'Compte gratuit';
            }

            $rows[] = [
                'user' => $user,
                'subscription' => $subscription,
                'membership' => $membership,
                'effectivePlan' => $effectivePlan,
                'planSource' => $planSource,
                'credentials' => (int) ($credentialStats[$id]['total'] ?? 0),
                'sessions' => [
                    'total' => (int) ($sessions['total'] ?? 0),
                    'active' => (int) ($sessions['active'] ?? 0),
                    'blocked' => (int) ($sessions['blocked'] ?? 0),
                ],
                'devices' => (int) ($deviceStats[$id]['total'] ?? 0),
                'extensions' => [
                    'total' => (int) ($extensions['total'] ?? 0),
                    'active' => $activeExtensions,
                    'blocked' => $blockedExtensions,
                    'pending' => $pendingChallenges,
                    'state' => $extensionState,
                ],
                'lastActivity' => $lastActivity,
            ];
        }

        return $rows;
    }

    /**
     * @param list<UserSession> $sessions
     * @return array{groups:list<array<string, mixed>>, stats:array<string, int>}
     */
    public function groupSessionsByUser(array $sessions): array
    {
        $now = new \DateTimeImmutable();
        $groups = [];

        foreach ($sessions as $session) {
            $user = $session->getUser();
            $userId = $user?->getId();
            if ($user === null || $userId === null) {
                continue;
            }

            $groups[$userId] ??= [
                'user' => $user,
                'devices' => [],
                'sessionCount' => 0,
                'activeCount' => 0,
                'blockedCount' => 0,
                'lastActivity' => null,
            ];

            $deviceKey = $session->getDeviceId() ?: 'session-' . $session->getId();
            $groups[$userId]['devices'][$deviceKey] ??= [
                'device' => null,
                'deviceId' => $session->getDeviceId(),
                'deviceName' => $session->getDeviceName() ?: 'Appareil non identifié',
                'ipAddress' => $session->getIpAddress(),
                'lastSeenAt' => $session->getLastActivityAt(),
                'sessions' => [],
            ];
            $groups[$userId]['devices'][$deviceKey]['sessions'][] = $session;
            if ($session->getLastActivityAt() > $groups[$userId]['devices'][$deviceKey]['lastSeenAt']) {
                $groups[$userId]['devices'][$deviceKey]['lastSeenAt'] = $session->getLastActivityAt();
            }

            ++$groups[$userId]['sessionCount'];
            if (!$session->isBlocked() && !$session->isRevoked() && $session->getExpiresAt() > $now) {
                ++$groups[$userId]['activeCount'];
            }
            if ($session->isBlocked()) {
                ++$groups[$userId]['blockedCount'];
            }
            if ($groups[$userId]['lastActivity'] === null || $session->getLastActivityAt() > $groups[$userId]['lastActivity']) {
                $groups[$userId]['lastActivity'] = $session->getLastActivityAt();
            }
        }

        if ($groups !== []) {
            $devices = $this->entityManager->getRepository(UserDevice::class)
                ->createQueryBuilder('device')
                ->andWhere('device.user IN (:users)')
                ->setParameter('users', array_keys($groups))
                ->orderBy('device.lastSeenAt', 'DESC')
                ->getQuery()->getResult();

            foreach ($devices as $device) {
                $userId = $device->getUser()?->getId();
                if ($userId === null || !isset($groups[$userId])) {
                    continue;
                }

                $deviceKey = $device->getDeviceId() ?: 'device-' . $device->getId();
                $groups[$userId]['devices'][$deviceKey] ??= [
                    'device' => null,
                    'deviceId' => $device->getDeviceId(),
                    'deviceName' => $device->getDeviceName() ?: 'Appareil non identifié',
                    'ipAddress' => $device->getIpAddress(),
                    'lastSeenAt' => $device->getLastSeenAt(),
                    'sessions' => [],
                ];
                $groups[$userId]['devices'][$deviceKey]['device'] = $device;
                $groups[$userId]['devices'][$deviceKey]['deviceName'] = $device->getDeviceName() ?: $groups[$userId]['devices'][$deviceKey]['deviceName'];
                $groups[$userId]['devices'][$deviceKey]['ipAddress'] = $device->getIpAddress() ?: $groups[$userId]['devices'][$deviceKey]['ipAddress'];
                $groups[$userId]['devices'][$deviceKey]['lastSeenAt'] = $this->mostRecentDate(
                    $groups[$userId]['devices'][$deviceKey]['lastSeenAt'],
                    $device->getLastSeenAt()
                );
            }
        }

        $result = array_values(array_map(static function (array $group): array {
            $group['devices'] = array_values($group['devices']);

            return $group;
        }, $groups));

        return [
            'groups' => $result,
            'stats' => [
                'users' => count($result),
                'sessions' => count($sessions),
                'active' => array_sum(array_column($result, 'activeCount')),
                'blocked' => array_sum(array_column($result, 'blockedCount')),
            ],
        ];
    }

    /**
     * @param list<ExtensionClient> $clients
     * @param list<ExtensionInstallationChallenge> $challenges
     * @return array{groups:list<array<string, mixed>>, stats:array<string, int>, latestVersion:?string}
     */
    public function groupExtensionsByUser(array $clients, array $challenges, string $query = '', string $status = 'all'): array
    {
        $groups = [];
        $now = new \DateTimeImmutable();
        $latestVersion = null;

        foreach ($clients as $client) {
            $user = $client->getUser();
            $userId = $user?->getId();
            if ($user === null || $userId === null) {
                continue;
            }

            $groups[$userId] ??= $this->emptyExtensionGroup($user);
            $groups[$userId]['clients'][] = $client;
            $groups[$userId]['lastActivity'] = $this->mostRecentDate($groups[$userId]['lastActivity'], $client->getLastSeenAt());

            if ($client->isBlocked()) {
                ++$groups[$userId]['blocked'];
            } elseif ($client->isRevoked()) {
                ++$groups[$userId]['revoked'];
            } else {
                ++$groups[$userId]['active'];
            }

            $version = trim((string) $client->getExtensionVersion());
            if ($version !== '' && ($latestVersion === null || version_compare($version, $latestVersion, '>'))) {
                $latestVersion = $version;
            }
        }

        foreach ($challenges as $challenge) {
            $user = $challenge->getUser();
            $userId = $user?->getId();
            if ($user === null || $userId === null) {
                continue;
            }

            $groups[$userId] ??= $this->emptyExtensionGroup($user);
            $groups[$userId]['challenges'][] = $challenge;
            if ($challenge->isPending() && !$challenge->isExpired()) {
                ++$groups[$userId]['pending'];
            }
        }

        $usersAwaitingInstallation = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('user')
            ->andWhere('user.extensionOnboardingStatus = :status')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->andWhere('user.roles NOT LIKE :guestRole')
            ->setParameter('status', User::EXTENSION_ONBOARDING_PENDING)
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->setParameter('guestRole', '%ROLE_GUEST%')
            ->orderBy('user.id', 'DESC')
            ->setMaxResults(500)
            ->getQuery()->getResult();
        foreach ($usersAwaitingInstallation as $user) {
            $userId = $user->getId();
            if ($userId !== null) {
                $groups[$userId] ??= $this->emptyExtensionGroup($user);
            }
        }

        foreach ($groups as &$group) {
            if ($group['blocked'] > 0) {
                $group['state'] = ['key' => 'blocked', 'label' => 'Action requise', 'tone' => 'danger'];
            } elseif ($group['pending'] > 0) {
                $group['state'] = ['key' => 'pending', 'label' => 'Installation en cours', 'tone' => 'warning'];
            } elseif ($group['active'] > 0) {
                $group['state'] = ['key' => 'active', 'label' => 'Active', 'tone' => 'success'];
            } elseif ($group['revoked'] > 0) {
                $group['state'] = ['key' => 'revoked', 'label' => 'Révoquée', 'tone' => 'muted'];
            } else {
                $group['state'] = ['key' => 'missing', 'label' => 'Non installée', 'tone' => 'muted'];
            }
        }
        unset($group);

        $normalizedQuery = mb_strtolower(trim($query));
        $filtered = array_filter($groups, static function (array $group) use ($normalizedQuery, $status): bool {
            if ($status !== 'all' && $group['state']['key'] !== $status) {
                return false;
            }

            if ($normalizedQuery === '') {
                return true;
            }

            $haystack = mb_strtolower(implode(' ', array_filter([
                $group['user']->getEmail(),
                $group['user']->getCompany(),
                ...array_map(static fn (ExtensionClient $client): string => implode(' ', array_filter([
                    $client->getDeviceLabel(),
                    $client->getBrowserName(),
                    $client->getOsName(),
                    $client->getExtensionVersion(),
                ])), $group['clients']),
            ])));

            return str_contains($haystack, $normalizedQuery);
        });

        return [
            'groups' => array_values($filtered),
            'stats' => [
                'users' => count($groups),
                'active' => count(array_filter($groups, static fn (array $group): bool => $group['state']['key'] === 'active')),
                'pending' => count(array_filter($groups, static fn (array $group): bool => $group['state']['key'] === 'pending')),
                'blocked' => count(array_filter($groups, static fn (array $group): bool => $group['state']['key'] === 'blocked')),
                'missing' => count(array_filter($groups, static fn (array $group): bool => $group['state']['key'] === 'missing')),
            ],
            'latestVersion' => $latestVersion,
        ];
    }

    /** @return list<OrganizationMember> */
    public function getUserMemberships(User $user): array
    {
        return $this->entityManager->getRepository(OrganizationMember::class)
            ->createQueryBuilder('membership')
            ->leftJoin('membership.organization', 'organization')->addSelect('organization')
            ->leftJoin('organization.subscription', 'subscription')->addSelect('subscription')
            ->andWhere('membership.user = :user')
            ->setParameter('user', $user)
            ->orderBy('membership.joinedAt', 'DESC')
            ->addOrderBy('membership.invitedAt', 'DESC')
            ->getQuery()->getResult();
    }

    /** @return list<UserDevice> */
    public function getUserDevices(User $user): array
    {
        return $this->entityManager->getRepository(UserDevice::class)->findBy(
            ['user' => $user],
            ['lastSeenAt' => 'DESC']
        );
    }

    private function countCustomerUsers(): int
    {
        return $this->scalarCount(
            User::class,
            'user',
            'user.roles NOT LIKE :adminRole',
            ['adminRole' => '%ROLE_ADMIN%']
        );
    }

    private function countActiveSubscriptions(): int
    {
        return (int) $this->entityManager->getRepository(UserSubscription::class)
            ->createQueryBuilder('subscription')
            ->select('COUNT(subscription.id)')
            ->innerJoin('subscription.user', 'user')
            ->andWhere('subscription.isActive = true')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getSingleScalarResult();
    }

    private function countActiveExtensionUsers(\DateTimeImmutable $since): int
    {
        return (int) $this->entityManager->getRepository(ExtensionClient::class)
            ->createQueryBuilder('client')
            ->select('COUNT(DISTINCT IDENTITY(client.user))')
            ->innerJoin('client.user', 'user')
            ->andWhere('client.isBlocked = false')
            ->andWhere('client.isRevoked = false')
            ->andWhere('client.lastSeenAt >= :since')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('since', $since)
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getSingleScalarResult();
    }

    private function countActiveSessions(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->getRepository(UserSession::class)
            ->createQueryBuilder('session')
            ->select('COUNT(session.id)')
            ->innerJoin('session.user', 'user')
            ->andWhere('session.isBlocked = false')
            ->andWhere('session.isRevoked = false')
            ->andWhere('session.expiresAt > :now')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('now', $now)
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getSingleScalarResult();
    }

    private function countCredentialUsers(): int
    {
        return (int) $this->entityManager->getRepository(Credential::class)
            ->createQueryBuilder('credential')
            ->select('COUNT(DISTINCT IDENTITY(credential.user))')
            ->innerJoin('credential.user', 'user')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getSingleScalarResult();
    }

    private function countAttentionItems(\DateTimeImmutable $now): int
    {
        return $this->scalarCount(
            ExtensionInstallationChallenge::class,
            'challenge',
            'challenge.status = :status AND challenge.expiresAt > :now',
            ['status' => ExtensionInstallationChallenge::STATUS_PENDING, 'now' => $now]
        ) + $this->scalarCount(ExtensionClient::class, 'client', 'client.isBlocked = true')
            + $this->scalarCount(UserSubscription::class, 'subscription', 'subscription.isActive = true AND subscription.cancelAtPeriodEnd = true')
            + $this->scalarCount(SupportConversation::class, 'conversation', 'conversation.unreadForAdmin = true');
    }

    /** @return list<array{key:string,label:string,value:int,percentage:int}> */
    private function getPlanDistribution(int $customerUsers): array
    {
        $proRows = $this->entityManager->getRepository(UserSubscription::class)
            ->createQueryBuilder('subscription')
            ->select('IDENTITY(subscription.user) AS userId')
            ->innerJoin('subscription.user', 'user')
            ->andWhere('subscription.isActive = true')
            ->andWhere('LOWER(subscription.planCode) = :plan')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('plan', 'pro')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getArrayResult();

        $teamRows = $this->entityManager->getRepository(OrganizationMember::class)
            ->createQueryBuilder('membership')
            ->select('IDENTITY(membership.user) AS userId')
            ->innerJoin('membership.user', 'user')
            ->innerJoin('membership.organization', 'organization')
            ->innerJoin('organization.subscription', 'subscription')
            ->andWhere('membership.status = :memberStatus')
            ->andWhere('membership.role != :guestRole')
            ->andWhere('organization.status = :organizationStatus')
            ->andWhere('subscription.isActive = true')
            ->andWhere('LOWER(subscription.planCode) = :teamPlan')
            ->andWhere('user.roles NOT LIKE :adminRole')
            ->setParameter('memberStatus', 'ACTIVE')
            ->setParameter('guestRole', 'GUEST')
            ->setParameter('organizationStatus', 'ACTIVE')
            ->setParameter('teamPlan', 'team')
            ->setParameter('adminRole', '%ROLE_ADMIN%')
            ->getQuery()->getArrayResult();

        $teamUserIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['userId'], $teamRows)));
        $proUserIds = array_values(array_diff(
            array_unique(array_map(static fn (array $row): int => (int) $row['userId'], $proRows)),
            $teamUserIds
        ));
        $plans = [
            'free' => max(0, $customerUsers - count($proUserIds) - count($teamUserIds)),
            'pro' => count($proUserIds),
            'team' => count($teamUserIds),
        ];

        return array_map(static fn (string $key, int $value): array => [
            'key' => $key,
            'label' => match ($key) {
                'pro' => 'Pro',
                'team' => 'Team',
                default => 'Free',
            },
            'value' => $value,
            'percentage' => $customerUsers > 0 ? (int) round(($value / $customerUsers) * 100) : 0,
        ], array_keys($plans), array_values($plans));
    }

    /** @return list<array{version:string,total:int}> */
    private function getExtensionVersions(\DateTimeImmutable $since): array
    {
        $rows = $this->entityManager->getRepository(ExtensionClient::class)
            ->createQueryBuilder('client')
            ->select('client.extensionVersion AS version', 'COUNT(client.id) AS total')
            ->andWhere('client.isRevoked = false')
            ->andWhere('client.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->groupBy('client.extensionVersion')
            ->orderBy('total', 'DESC')
            ->setMaxResults(6)
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $row): array => [
            'version' => trim((string) ($row['version'] ?? '')) ?: 'Inconnue',
            'total' => (int) $row['total'],
        ], $rows);
    }

    /** @param array<string, mixed> $parameters */
    private function scalarCount(
        string $entityClass,
        string $alias,
        ?string $where = null,
        array $parameters = [],
        ?string $expression = null
    ): int {
        $qb = $this->entityManager->getRepository($entityClass)->createQueryBuilder($alias)
            ->select(sprintf('COUNT(%s)', $expression ?? $alias . '.id'));

        if ($where !== null) {
            $qb->andWhere($where);
        }
        foreach ($parameters as $name => $value) {
            $qb->setParameter($name, $value);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function indexRows(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['userId']] = $row;
        }

        return $indexed;
    }

    private function mostRecent(mixed $first, mixed $second): mixed
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        return strtotime((string) $first) >= strtotime((string) $second) ? $first : $second;
    }

    private function mostRecentDate(?\DateTimeImmutable $first, ?\DateTimeImmutable $second): ?\DateTimeImmutable
    {
        if ($first === null) {
            return $second;
        }
        if ($second === null) {
            return $first;
        }

        return $first >= $second ? $first : $second;
    }

    /** @return array<string, mixed> */
    private function emptyExtensionGroup(User $user): array
    {
        return [
            'user' => $user,
            'clients' => [],
            'challenges' => [],
            'active' => 0,
            'blocked' => 0,
            'revoked' => 0,
            'pending' => 0,
            'lastActivity' => null,
            'state' => ['key' => 'missing', 'label' => 'Non installée', 'tone' => 'muted'],
        ];
    }
}

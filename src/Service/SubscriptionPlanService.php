<?php

namespace App\Service;

use App\Entity\SubscriptionPlanConfiguration;
use App\Entity\User;
use App\Repository\SubscriptionPlanConfigurationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class SubscriptionPlanService
{
    public const PLAN_FREE = 'free';
    public const PLAN_PRO = 'pro';
    public const PLAN_TEAM = 'team';

    public const LIMIT_CREDENTIALS = 'credentials';
    public const LIMIT_SHARES = 'shares';
    public const LIMIT_TEAMS = 'teams';
    public const LIMIT_EXTENSION_INSTALLATIONS = 'extension_installations';

    public const FEATURE_PASSWORD_GENERATOR = 'password_generator';
    public const FEATURE_SECURE_NOTES = 'secure_notes';
    public const FEATURE_SECURITY_CHECKER = 'security_checker';
    public const FEATURE_CREDENTIAL_IMPORT = 'credential_import';

    /**
     * Null limits mean unlimited.
     *
     * @var array<string, array{label:string, limits:array<string, int|null>, features:array<string, bool>, editable:bool}>
     */
    private const DEFAULT_PLANS = [
        self::PLAN_FREE => [
            'label' => 'Free',
            'limits' => [
                self::LIMIT_CREDENTIALS => 5,
                self::LIMIT_SHARES => 3,
                self::LIMIT_TEAMS => 1,
                self::LIMIT_EXTENSION_INSTALLATIONS => 1,
            ],
            'features' => [
                self::FEATURE_PASSWORD_GENERATOR => true,
                self::FEATURE_SECURE_NOTES => false,
                self::FEATURE_SECURITY_CHECKER => false,
                self::FEATURE_CREDENTIAL_IMPORT => false,
            ],
            'editable' => true,
        ],
        self::PLAN_PRO => [
            'label' => 'Pro',
            'limits' => [
                self::LIMIT_CREDENTIALS => null,
                self::LIMIT_SHARES => null,
                self::LIMIT_TEAMS => null,
                self::LIMIT_EXTENSION_INSTALLATIONS => 1,
            ],
            'features' => [
                self::FEATURE_PASSWORD_GENERATOR => true,
                self::FEATURE_SECURE_NOTES => true,
                self::FEATURE_SECURITY_CHECKER => true,
                self::FEATURE_CREDENTIAL_IMPORT => true,
            ],
            'editable' => false,
        ],
        self::PLAN_TEAM => [
            'label' => 'Team',
            'limits' => [
                self::LIMIT_CREDENTIALS => null,
                self::LIMIT_SHARES => null,
                self::LIMIT_TEAMS => null,
                self::LIMIT_EXTENSION_INSTALLATIONS => null,
            ],
            'features' => [
                self::FEATURE_PASSWORD_GENERATOR => true,
                self::FEATURE_SECURE_NOTES => true,
                self::FEATURE_SECURITY_CHECKER => true,
                self::FEATURE_CREDENTIAL_IMPORT => true,
            ],
            'editable' => false,
        ],
    ];

    /** @var array<string, array{code:string, label:string, limits:array<string, int|null>, features:array<string, bool>, editable:bool, updatedAt:?\DateTimeImmutable}> */
    private array $resolvedPlans = [];

    public function __construct(
        private readonly SubscriptionPlanConfigurationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{code:string, label:string, limits:array<string, int|null>, features:array<string, bool>, editable:bool, updatedAt:?\DateTimeImmutable}
     */
    public function getPlan(string $planCode): array
    {
        $planCode = $this->normalizeKnownPlanCode($planCode);
        if (isset($this->resolvedPlans[$planCode])) {
            return $this->resolvedPlans[$planCode];
        }

        $defaults = self::DEFAULT_PLANS[$planCode];
        $configuration = $this->repository->findByPlanCode($planCode);

        return $this->resolvedPlans[$planCode] = [
            'code' => $planCode,
            'label' => $defaults['label'],
            'limits' => array_replace($defaults['limits'], $configuration?->getLimits() ?? []),
            'features' => array_replace($defaults['features'], $configuration?->getFeatures() ?? []),
            'editable' => $defaults['editable'],
            'updatedAt' => $configuration?->getUpdatedAt(),
        ];
    }

    /**
     * @return list<array{code:string, label:string, limits:array<string, int|null>, features:array<string, bool>, editable:bool, updatedAt:?\DateTimeImmutable}>
     */
    public function getPlans(): array
    {
        return array_map(fn (string $code): array => $this->getPlan($code), [
            self::PLAN_FREE,
            self::PLAN_PRO,
            self::PLAN_TEAM,
        ]);
    }

    /**
     * @return array{code:string, label:string, limits:array<string, int|null>, features:array<string, bool>, editable:bool, updatedAt:?\DateTimeImmutable}
     */
    public function getPlanForUser(User $user): array
    {
        return $this->getPlan($this->resolveUserPlanCode($user));
    }

    public function getLimit(User $user, string $limit): ?int
    {
        $value = $this->getPlanForUser($user)['limits'][$limit] ?? null;

        return is_int($value) ? max(0, $value) : null;
    }

    public function hasFeature(User $user, string $feature): bool
    {
        return ($this->getPlanForUser($user)['features'][$feature] ?? false) === true;
    }

    public function canCreate(User $user, string $limit, int $currentUsage): bool
    {
        $maximum = $this->getLimit($user, $limit);

        return $maximum === null || $currentUsage < $maximum;
    }

    /**
     * @return array<string, int|bool|null>
     */
    public function getFreePlanFormData(): array
    {
        $plan = $this->getPlan(self::PLAN_FREE);

        return [
            'credentialLimit' => $plan['limits'][self::LIMIT_CREDENTIALS],
            'credentialsUnlimited' => $plan['limits'][self::LIMIT_CREDENTIALS] === null,
            'shareLimit' => $plan['limits'][self::LIMIT_SHARES],
            'sharesUnlimited' => $plan['limits'][self::LIMIT_SHARES] === null,
            'teamLimit' => $plan['limits'][self::LIMIT_TEAMS],
            'teamsUnlimited' => $plan['limits'][self::LIMIT_TEAMS] === null,
            'extensionInstallationLimit' => $plan['limits'][self::LIMIT_EXTENSION_INSTALLATIONS],
            'extensionInstallationsUnlimited' => $plan['limits'][self::LIMIT_EXTENSION_INSTALLATIONS] === null,
            'passwordGenerator' => $plan['features'][self::FEATURE_PASSWORD_GENERATOR],
            'secureNotes' => $plan['features'][self::FEATURE_SECURE_NOTES],
            'securityChecker' => $plan['features'][self::FEATURE_SECURITY_CHECKER],
            'credentialImport' => $plan['features'][self::FEATURE_CREDENTIAL_IMPORT],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFreePlan(array $data): SubscriptionPlanConfiguration
    {
        $configuration = $this->repository->findByPlanCode(self::PLAN_FREE)
            ?? (new SubscriptionPlanConfiguration())->setPlanCode(self::PLAN_FREE);

        $configuration
            ->setLimits([
                self::LIMIT_CREDENTIALS => $this->resolveSubmittedLimit($data, 'credentialLimit', 'credentialsUnlimited'),
                self::LIMIT_SHARES => $this->resolveSubmittedLimit($data, 'shareLimit', 'sharesUnlimited'),
                self::LIMIT_TEAMS => $this->resolveSubmittedLimit($data, 'teamLimit', 'teamsUnlimited'),
                self::LIMIT_EXTENSION_INSTALLATIONS => $this->resolveSubmittedLimit($data, 'extensionInstallationLimit', 'extensionInstallationsUnlimited'),
            ])
            ->setFeatures([
                self::FEATURE_PASSWORD_GENERATOR => (bool) ($data['passwordGenerator'] ?? false),
                self::FEATURE_SECURE_NOTES => (bool) ($data['secureNotes'] ?? false),
                self::FEATURE_SECURITY_CHECKER => (bool) ($data['securityChecker'] ?? false),
                self::FEATURE_CREDENTIAL_IMPORT => (bool) ($data['credentialImport'] ?? false),
            ])
            ->touch();

        $this->entityManager->persist($configuration);
        $this->entityManager->flush();
        unset($this->resolvedPlans[self::PLAN_FREE]);

        return $configuration;
    }

    private function resolveUserPlanCode(User $user): string
    {
        if (!$user->hasActiveSubscription()) {
            return self::PLAN_FREE;
        }

        return $user->hasActivePlan(self::PLAN_TEAM) ? self::PLAN_TEAM : self::PLAN_PRO;
    }

    private function normalizeKnownPlanCode(string $planCode): string
    {
        $planCode = mb_strtolower(trim($planCode));

        return isset(self::DEFAULT_PLANS[$planCode]) ? $planCode : self::PLAN_PRO;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveSubmittedLimit(array $data, string $valueKey, string $unlimitedKey): ?int
    {
        if (($data[$unlimitedKey] ?? false) === true) {
            return null;
        }

        return max(0, (int) ($data[$valueKey] ?? 0));
    }
}

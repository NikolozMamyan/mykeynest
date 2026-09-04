<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\EmailCampaign;
use App\Entity\ExtensionClient;
use App\Entity\ExtensionInstallationChallenge;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\OrganizationRole;
use App\Enum\TeamRole;
use App\Form\Admin\ArticleType;
use App\Form\Admin\SubscriptionPlanType;
use App\Repository\ExtensionClientRepository;
use App\Repository\ExtensionInstallationChallengeRepository;
use App\Repository\EmailCampaignRepository;
use App\Repository\OrganizationRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserDeviceRepository;
use App\Repository\UserRepository;
use App\Repository\UserSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerInterface;
use App\Service\ExtensionClientManager;
use App\Service\ExtensionInstallationChallengeManager;
use App\Service\AdminInsightsService;
use App\Service\MailerService;
use App\Service\ManualSubscriptionManager;
use App\Service\OrganizationSeatManager;
use App\Service\SessionManager;
use App\Service\StripeEnvironmentManager;
use App\Service\StripePlanCatalog;
use App\Service\SubscriptionPlanService;
use App\Service\TeamMemberAssignmentManager;
use App\Service\TeamNotifier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AdminController extends AbstractController
{
    private const MAX_FILE_SIZE = 5242880; // 5MB
    private const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public function __construct(
        #[Autowire('%article_covers_dir%')]
        private readonly string $coversDir,
        #[Autowire('%article_content_images_dir%')]
        private readonly string $contentImagesDir,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('', name: 'app_admin', methods: ['GET'])]
    public function index(
        Request $request,
        AdminInsightsService $adminInsights
    ): Response
    {
        $period = (int) $request->query->get('period', 30);

        return $this->render('admin/index.html.twig', [
            'dashboard' => $adminInsights->buildDashboard($period),
        ]);
    }

    #[Route('/blog', name: 'admin_blog', methods: ['GET'])]
    public function blog(
        EntityManagerInterface $em,
        Request $request
    ): Response {
        $q = $this->sanitizeSearchQuery($request->query->get('q', ''));

        return $this->render('admin/blog.html.twig', [
            'articles' => $this->getArticles($em, $q),
            'q' => $q,
        ]);
    }

    #[Route('/users', name: 'admin_users', methods: ['GET'])]
    public function users(
        Request $request,
        UserRepository $userRepository,
        AdminInsightsService $adminInsights
    ): Response {
        $filters = $this->buildUserFilters($request);
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 30;
        $qb = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.userSubscription', 's')
            ->addSelect('s');

        $this->applyUserFilters($qb, $filters);
        $pagination = $this->paginateQuery($qb, $page, $perPage);

        return $this->render('admin/users.html.twig', [
            'userRows' => $adminInsights->buildUserRows($pagination['items']),
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    #[Route('/subscriptions', name: 'admin_subscriptions', methods: ['GET'])]
    public function subscriptions(
        Request $request,
        UserRepository $userRepository,
        StripePlanCatalog $stripePlans,
    ): Response {
        $filters = $this->buildUserFilters($request);
        $qb = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.userSubscription', 's')
            ->addSelect('s');

        $this->applyUserFilters($qb, $filters);

        return $this->render('admin/subscriptions.html.twig', [
            'users' => $qb->setMaxResults(200)->getQuery()->getResult(),
            'filters' => $filters,
            'teamMinimumSeats' => $stripePlans->getTeamMinimumSeats(),
            'teamMaximumSeats' => $stripePlans->getTeamMaximumSeats(),
        ]);
    }

    #[Route('/sessions', name: 'admin_sessions', methods: ['GET'])]
    public function sessions(
        Request $request,
        UserSessionRepository $userSessionRepository,
        AdminInsightsService $adminInsights
    ): Response
    {
        $filters = $this->buildSessionFilters($request);
        $qb = $userSessionRepository->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->orderBy('s.lastActivityAt', 'DESC')
            ->addOrderBy('s.id', 'DESC');

        $this->applySessionFilters($qb, $filters);

        $sessions = $qb
            ->setMaxResults(250)
            ->getQuery()
            ->getResult();
        $accessOverview = $adminInsights->groupSessionsByUser($sessions);

        return $this->render('admin/sessions.html.twig', [
            'accessGroups' => $accessOverview['groups'],
            'accessStats' => $accessOverview['stats'],
            'filters' => $filters,
        ]);
    }

    #[Route('/users/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function showUser(
        User $user,
        UserSessionRepository $userSessionRepository,
        UserDeviceRepository $userDeviceRepository,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionInstallationChallengeRepository $challengeRepository,
        AdminInsightsService $adminInsights,
        StripePlanCatalog $stripePlans,
    ): Response {
        $sessions = $userSessionRepository->createQueryBuilder('s')
            ->andWhere('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.lastActivityAt', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        $extensionClients = $extensionClientRepository->createQueryBuilder('ec')
            ->andWhere('ec.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ec.lastSeenAt', 'DESC')
            ->setMaxResults(15)
            ->getQuery()
            ->getResult();

        $extensionChallenges = $challengeRepository->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $this->render('admin/user_show.html.twig', [
            'user' => $user,
            'sessions' => $sessions,
            'extensionClients' => $extensionClients,
            'extensionChallenges' => $extensionChallenges,
            'devices' => $userDeviceRepository->findBy(['user' => $user], ['lastSeenAt' => 'DESC']),
            'memberships' => $adminInsights->getUserMemberships($user),
            'userOverview' => $adminInsights->buildUserRows([$user])[0],
            'teamMinimumSeats' => $stripePlans->getTeamMinimumSeats(),
            'teamMaximumSeats' => $stripePlans->getTeamMaximumSeats(),
        ]);
    }

    #[Route('/subscriptions/plans', name: 'admin_subscription_plans', methods: ['GET', 'POST'])]
    public function subscriptionPlans(Request $request, SubscriptionPlanService $plans): Response
    {
        $form = $this->createForm(SubscriptionPlanType::class, $plans->getFreePlanFormData(), [
            'method' => 'POST',
            'action' => $this->generateUrl('admin_subscription_plans'),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $plans->updateFreePlan($data);
            $this->addFlash('success', 'Le plan Free a été mis à jour. Les nouvelles règles sont actives immédiatement.');

            return $this->redirectToRoute('admin_subscription_plans');
        }

        return $this->render('admin/subscription_plans.html.twig', [
            'plans' => $plans->getPlans(),
            'form' => $form,
        ]);
    }

    #[Route('/subscriptions/payment', name: 'admin_subscription_payment', methods: ['GET', 'POST'])]
    public function subscriptionPayment(
        Request $request,
        StripeEnvironmentManager $stripeEnvironments,
        TranslatorInterface $translator,
    ): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('stripe_payment_mode', $request->request->getString('_token'))) {
                $this->addFlash('error', $translator->trans('admin_payment.flash.invalid_csrf'));

                return $this->redirectToRoute('admin_subscription_payment');
            }

            try {
                $mode = $request->request->getString('mode');
                $administrator = $this->getUser();
                $updatedBy = $administrator instanceof User ? $administrator->getEmail() : null;
                $stripeEnvironments->activateMode($mode, $updatedBy);
                $this->addFlash(
                    'success',
                    $mode === StripeEnvironmentManager::MODE_PRODUCTION
                        ? $translator->trans('admin_payment.flash.production_active')
                        : $translator->trans('admin_payment.flash.sandbox_active'),
                );
            } catch (\InvalidArgumentException|\LogicException) {
                $this->addFlash('error', $translator->trans('admin_payment.flash.incomplete'));
            }

            return $this->redirectToRoute('admin_subscription_payment');
        }

        return $this->render('admin/subscription_payment.html.twig', [
            'stripeModes' => $stripeEnvironments->getAdminStatus(),
            'webhookUrl' => $this->generateUrl('stripe_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/users/{id}/extension-onboarding', name: 'admin_user_extension_onboarding_update', methods: ['POST'])]
    public function updateUserExtensionOnboarding(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $redirectToShow = $request->request->getString('_redirect') === 'show';
        $redirect = fn(): Response => $this->redirectToRoute(
            $redirectToShow ? 'admin_user_show' : 'admin_users',
            $redirectToShow ? ['id' => $user->getId()] : []
        );

        if (!$this->isCsrfTokenValid('admin_extension_onboarding_' . $user->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $redirect();
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $this->addFlash('warning', 'L onboarding extension ne s applique pas aux administrateurs.');

            return $redirect();
        }

        $status = $request->request->getString('status');
        if ($status === User::EXTENSION_ONBOARDING_COMPLETED) {
            $user->completeExtensionOnboarding();
            $message = 'Installation marquee comme terminee pour ' . $user->getEmail() . '.';
        } elseif ($status === User::EXTENSION_ONBOARDING_PENDING) {
            $user->requireExtensionOnboarding();
            if (!$user->hasActivePlan('team')) {
                $user->regenerateApiExtensionToken();
            }
            $message = 'Installation annulee : ' . $user->getEmail() . ' devra recommencer.';
        } else {
            $this->addFlash('error', 'Action d onboarding invalide.');

            return $redirect();
        }

        $entityManager->flush();
        $this->addFlash('success', $message);

        return $redirect();
    }

    #[Route('/extensions', name: 'admin_extensions', methods: ['GET'])]
    public function extensions(
        Request $request,
        ExtensionClientRepository $extensionClientRepository,
        ExtensionInstallationChallengeRepository $challengeRepository,
        AdminInsightsService $adminInsights
    ): Response {
        $clients = $extensionClientRepository->createQueryBuilder('ec')
            ->leftJoin('ec.user', 'u')
            ->addSelect('u')
            ->orderBy('ec.lastSeenAt', 'DESC')
            ->addOrderBy('ec.id', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        $challenges = $challengeRepository->createQueryBuilder('c')
            ->leftJoin('c.user', 'u')
            ->addSelect('u')
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();

        $query = $this->sanitizeSearchQuery($request->query->get('q', ''));
        $status = (string) $request->query->get('status', 'all');
        if (!in_array($status, ['all', 'active', 'pending', 'blocked', 'revoked', 'missing'], true)) {
            $status = 'all';
        }
        $extensionOverview = $adminInsights->groupExtensionsByUser($clients, $challenges, $query, $status);

        return $this->render('admin/extensions.html.twig', [
            'extensionGroups' => $extensionOverview['groups'],
            'extensionStats' => $extensionOverview['stats'],
            'latestVersion' => $extensionOverview['latestVersion'],
            'filters' => ['q' => $query, 'status' => $status],
        ]);
    }

    #[Route('/emailing', name: 'admin_emailing', methods: ['GET', 'POST'])]
    public function emailing(
        Request $request,
        UserRepository $userRepository,
        MailerService $mailerService,
        EmailCampaignRepository $emailCampaignRepository,
        EntityManagerInterface $em
    ): Response {
        $selectedUserIds = array_values(array_unique(array_map(
            static fn(mixed $value): int => (int) $value,
            array_filter(
                $request->request->all('selected_users'),
                static fn(mixed $value): bool => is_scalar($value) && ctype_digit((string) $value)
            )
        )));

        $formData = [
            'subject' => trim((string) $request->request->get('subject', '')),
            'recipientEmails' => trim((string) $request->request->get('recipient_emails', '')),
            'htmlContent' => (string) $request->request->get('html_content', ''),
            'selectedUserIds' => $selectedUserIds,
        ];

        $users = $userRepository->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $campaigns = $emailCampaignRepository->findBy([], ['sentAt' => 'DESC'], 12);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_emailing', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');

                return $this->render('admin/emailing.html.twig', [
                    'users' => $users,
                    'formData' => $formData,
                    'campaigns' => $campaigns,
                ]);
            }

            $subject = $this->sanitizeEmailSubject($formData['subject']);
            $htmlContent = trim($formData['htmlContent']);
            $selectedUsers = $selectedUserIds === []
                ? []
                : $userRepository->createQueryBuilder('u')
                    ->andWhere('u.id IN (:ids)')
                    ->setParameter('ids', $selectedUserIds)
                    ->orderBy('u.email', 'ASC')
                    ->getQuery()
                    ->getResult();

            $manualEmails = $this->extractEmailAddresses($formData['recipientEmails']);
            $invalidEmails = array_values(array_filter(
                $manualEmails,
                static fn(string $email): bool => !filter_var($email, FILTER_VALIDATE_EMAIL)
            ));

            if ($subject === '') {
                $this->addFlash('error', 'L objet de l email est obligatoire.');
            }

            if ($htmlContent === '') {
                $this->addFlash('error', 'Le contenu HTML est obligatoire.');
            }

            if ($invalidEmails !== []) {
                $this->addFlash('error', 'Emails invalides detectes: ' . implode(', ', $invalidEmails));
            }

            $recipients = $this->buildRecipientList($selectedUsers, $manualEmails);

            if ($recipients === []) {
                $this->addFlash('error', 'Ajoute au moins un destinataire.');
            }

            if ($subject !== '' && $htmlContent !== '' && $invalidEmails === [] && $recipients !== []) {
                $sentCount = 0;
                $failedRecipients = [];

                foreach ($recipients as $recipient) {
                    try {
                        $mailerService->sendHtml($recipient, $subject, $htmlContent);
                        ++$sentCount;
                    } catch (\Throwable) {
                        $failedRecipients[] = $recipient;
                    }
                }

                if ($sentCount > 0) {
                    $this->addFlash('success', sprintf('%d email(s) envoye(s) individuellement.', $sentCount));
                }

                if ($failedRecipients !== []) {
                    $this->addFlash('warning', 'Echec pour: ' . implode(', ', $failedRecipients));
                }

                if ($sentCount > 0) {
                    $campaign = (new EmailCampaign())
                        ->setSubject($subject)
                        ->setHtmlContent($htmlContent)
                        ->setRecipients($recipients)
                        ->setFailedRecipients($failedRecipients)
                        ->setSentByEmail($this->getUser() instanceof User ? $this->getUser()->getEmail() : null)
                        ->setSentAt(new DateTimeImmutable());

                    $em->persist($campaign);
                    $em->flush();
                    $campaigns = $emailCampaignRepository->findBy([], ['sentAt' => 'DESC'], 12);
                }

                if ($sentCount > 0 && $failedRecipients === []) {
                    return $this->redirectToRoute('admin_emailing');
                }
            }
        }

        return $this->render('admin/emailing.html.twig', [
            'users' => $users,
            'formData' => $formData,
            'campaigns' => $campaigns,
        ]);
    }

    #[Route('/organizations', name: 'admin_organizations', methods: ['GET'])]
    public function organizations(
        Request $request,
        OrganizationRepository $organizationRepository,
        OrganizationSeatManager $organizationSeats,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        $q = $this->sanitizeSearchQuery($request->query->get('q', ''));
        $qb = $organizationRepository->createQueryBuilder('organization')
            ->leftJoin('organization.owner', 'owner')->addSelect('owner')
            ->leftJoin('organization.subscription', 'subscription')->addSelect('subscription')
            ->leftJoin('organization.members', 'membership')->addSelect('membership')
            ->leftJoin('membership.user', 'memberUser')->addSelect('memberUser')
            ->orderBy('organization.updatedAt', 'DESC')
            ->addOrderBy('organization.id', 'DESC');

        if ($q !== '') {
            $qb->andWhere('organization.name LIKE :q OR owner.email LIKE :q OR memberUser.email LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $organizationRows = array_map(
            static fn (Organization $organization): array => [
                'organization' => $organization,
                'seats' => $organizationSeats->getSeatSummary($organization),
                'stripeManaged' => $organization->getSubscription() !== null
                    && $manualSubscriptions->isStripeManaged($organization->getSubscription()),
            ],
            $qb->setMaxResults(200)->getQuery()->getResult(),
        );

        return $this->render('admin/organizations.html.twig', [
            'organizationRows' => $organizationRows,
            'q' => $q,
        ]);
    }

    #[Route('/organizations/{id}', name: 'admin_organization_show', methods: ['GET'])]
    public function showOrganization(
        Organization $organization,
        OrganizationSeatManager $organizationSeats,
        ManualSubscriptionManager $manualSubscriptions,
        StripePlanCatalog $stripePlans,
    ): Response {
        $subscription = $organization->getSubscription();

        return $this->render('admin/organization_show.html.twig', [
            'organization' => $organization,
            'seatSummary' => $organizationSeats->getSeatSummary($organization),
            'stripeManaged' => $subscription !== null && $manualSubscriptions->isStripeManaged($subscription),
            'teamMinimumSeats' => $stripePlans->getTeamMinimumSeats(),
            'teamMaximumSeats' => $stripePlans->getTeamMaximumSeats(),
        ]);
    }

    #[Route('/organizations/{id}/manual/update', name: 'admin_organization_manual_update', methods: ['POST'])]
    public function updateManualOrganization(
        Organization $organization,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_organization_update_' . $organization->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
        }

        try {
            $manualSubscriptions->updateOrganization(
                $organization,
                $request->request->getString('name'),
                $request->request->getInt('quantity'),
            );
            $this->addFlash('success', 'L’entreprise et ses licences ont été mises à jour.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
    }

    #[Route('/organizations/{id}/manual/toggle', name: 'admin_organization_manual_toggle', methods: ['POST'])]
    public function toggleManualOrganization(
        Organization $organization,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_organization_toggle_' . $organization->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
        }

        try {
            $activate = $request->request->getString('action') === 'activate';
            $manualSubscriptions->setOrganizationActive($organization, $activate);
            $this->addFlash($activate ? 'success' : 'warning', $activate
                ? 'L’entreprise et son abonnement Team sont actifs.'
                : 'L’entreprise et son abonnement Team sont suspendus.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
    }

    #[Route('/organizations/{id}/manual/transfer', name: 'admin_organization_manual_transfer', methods: ['POST'])]
    public function transferManualOrganization(
        Organization $organization,
        Request $request,
        UserRepository $users,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_organization_transfer_' . $organization->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
        }

        $email = mb_strtolower(trim($request->request->getString('email')));
        $newOwner = filter_var($email, FILTER_VALIDATE_EMAIL) ? $users->findOneBy(['email' => $email]) : null;
        if (!$newOwner instanceof User) {
            $this->addFlash('error', 'Aucun utilisateur MYKEYNEST ne correspond à cette adresse email.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
        }

        try {
            $manualSubscriptions->transferOrganization($organization, $newOwner);
            $this->addFlash('success', sprintf('%s est maintenant propriétaire de l’entreprise.', $newOwner->getEmail()));
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization->getId()]);
    }

    #[Route('/organization-members/{id}/role', name: 'admin_organization_member_role', methods: ['POST'])]
    public function updateOrganizationMemberRole(
        OrganizationMember $membership,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        $organization = $membership->getOrganization();
        if (!$this->isCsrfTokenValid('admin_organization_member_role_' . $membership->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
        }

        try {
            $role = OrganizationRole::tryFrom($request->request->getString('role'));
            if (!$role instanceof OrganizationRole) {
                throw new \InvalidArgumentException('Rôle invalide.');
            }
            $manualSubscriptions->changeMemberRole($membership, $role);
            $this->addFlash('success', 'Le rôle du membre a été mis à jour.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
    }

    #[Route('/organization-members/{id}/toggle', name: 'admin_organization_member_toggle', methods: ['POST'])]
    public function toggleOrganizationMember(
        OrganizationMember $membership,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        $organization = $membership->getOrganization();
        if (!$this->isCsrfTokenValid('admin_organization_member_toggle_' . $membership->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
        }

        try {
            $wasActive = $membership->getStatus()->value === 'ACTIVE';
            $manualSubscriptions->toggleMembership($membership);
            $this->addFlash($wasActive ? 'warning' : 'success', $wasActive ? 'Le membre a été suspendu.' : 'Le membre a été réactivé.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
    }

    #[Route('/organization-members/{id}/remove', name: 'admin_organization_member_remove', methods: ['POST'])]
    public function removeOrganizationMember(
        OrganizationMember $membership,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        $organization = $membership->getOrganization();
        if (!$this->isCsrfTokenValid('admin_organization_member_remove_' . $membership->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
        }

        try {
            $manualSubscriptions->removeMembership($membership);
            $this->addFlash('warning', 'Le membre a été retiré de l’entreprise et de ses groupes.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_organization_show', ['id' => $organization?->getId()]);
    }

    #[Route('/teams', name: 'admin_teams', methods: ['GET'])]
    public function teams(Request $request, TeamRepository $teamRepository): Response
    {
        $q = $this->sanitizeSearchQuery($request->query->get('q', ''));

        $qb = $teamRepository->createQueryBuilder('t')
            ->leftJoin('t.owner', 'o')
            ->addSelect('o')
            ->leftJoin('t.members', 'm')
            ->addSelect('m')
            ->leftJoin('m.user', 'mu')
            ->addSelect('mu')
            ->leftJoin('t.credentials', 'c')
            ->addSelect('c')
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if ($q !== '') {
            $qb->andWhere('t.name LIKE :q OR o.email LIKE :q OR mu.email LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        /** @var list<Team> $teams */
        $teams = $qb->setMaxResults(250)->getQuery()->getResult();

        return $this->render('admin/teams.html.twig', [
            'teams' => $teams,
            'q' => $q,
        ]);
    }

    #[Route('/teams/{id}', name: 'admin_team_show', methods: ['GET'])]
    public function showTeam(Team $team): Response
    {
        return $this->render('admin/team_show.html.twig', [
            'team' => $team,
        ]);
    }

    #[Route('/teams/{id}/members/add', name: 'admin_team_member_add', methods: ['POST'])]
    public function addTeamMember(
        Team $team,
        Request $request,
        TeamMemberAssignmentManager $memberAssignments,
        TeamNotifier $teamNotifier,
        MailerService $mailerService,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        if (!$this->isCsrfTokenValid('admin_team_add_member_' . $team->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $roleRaw = (string) $request->request->get('role', TeamRole::MEMBER->value);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'Adresse email invalide.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $role = TeamRole::tryFrom($roleRaw);
        if (!$role || $role === TeamRole::OWNER) {
            $role = TeamRole::MEMBER;
        }

        $actor = $this->getUser();
        if (!$actor instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $assignment = $memberAssignments->assign(
                $team,
                $email,
                $role,
                $actor,
                $team->getOrganization() !== null && $request->request->getString('membership_type') === 'guest',
            );
        } catch (\DomainException|\InvalidArgumentException|\LogicException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $member = $assignment['member'];
        $memberUser = $assignment['user'];
        $guestInvitationExpiresAt = $assignment['invitationExpiresAt'];
        $teamNotifier->notifyMemberAdded($team, $memberUser, $actor, $member->getRole());

        if ($guestInvitationExpiresAt instanceof DateTimeImmutable) {
            try {
                $this->sendTeamGuestInvitationEmail($mailerService, $urlGenerator, $memberUser, $team, $guestInvitationExpiresAt);
                $this->addFlash('success', 'Invitation envoyee et membre ajoute a l equipe.');
            } catch (\Throwable $exception) {
                $this->logger->warning('Unable to send admin team invitation email.', [
                    'guest_email' => $memberUser->getEmail(),
                    'team_id' => $team->getId(),
                    'exception' => $exception->getMessage(),
                ]);
                $this->addFlash('warning', 'Membre ajoute, mais impossible d envoyer l invitation email.');
            }
        } else {
            $this->addFlash('success', 'Membre ajoute avec succes a l equipe.');
        }

        return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
    }

    #[Route('/teams/{id}/members/{memberId}/remove', name: 'admin_team_member_remove', methods: ['POST'])]
    public function removeTeamMember(
        Team $team,
        int $memberId,
        Request $request,
        TeamMemberRepository $teamMemberRepository,
        EntityManagerInterface $em,
        TeamMemberAssignmentManager $memberAssignments,
    ): Response {
        if (!$this->isCsrfTokenValid('admin_remove_team_member_' . $memberId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $member = $teamMemberRepository->find($memberId);
        if (!$member || $member->getTeam()?->getId() !== $team->getId()) {
            $this->addFlash('error', 'Membre introuvable dans cette equipe.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        if ($member->getRole() === TeamRole::OWNER) {
            $this->addFlash('warning', 'Le proprietaire ne peut pas etre supprime.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $memberAssignments->removeAssignment($member);
        $em->flush();

        $this->addFlash('success', 'Membre supprime de l equipe.');

        return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
    }

    #[Route('/teams/{id}/delete', name: 'admin_team_delete', methods: ['POST'])]
    public function deleteTeam(
        Team $team,
        Request $request,
        EntityManagerInterface $em,
        TeamMemberAssignmentManager $memberAssignments,
    ): Response
    {
        if (!$this->isCsrfTokenValid('admin_delete_team_' . $team->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_team_show', ['id' => $team->getId()]);
        }

        $teamName = $team->getName() ?? 'cette equipe';

        foreach ($team->getMembers() as $member) {
            $memberAssignments->removeAssignment($member);
        }

        $em->remove($team);
        $em->flush();

        $this->addFlash('success', sprintf('Equipe "%s" supprimee.', $teamName));

        return $this->redirectToRoute('admin_teams');
    }

    #[Route('/users/{id}/subscription/assign-pro', name: 'admin_user_subscription_assign_pro', methods: ['POST'])]
    public function assignProToUser(
        User $user,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('assign_pro_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectAfterSubscriptionAction($request, $user);
        }

        try {
            $manualSubscriptions->activate($user, SubscriptionPlanService::PLAN_PRO);
            $this->addFlash('success', 'Abonnement Pro manuel attribué à ' . $user->getEmail() . '.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectAfterSubscriptionAction($request, $user);
    }

    #[Route('/users/{id}/subscription/manual', name: 'admin_user_subscription_manual_update', methods: ['POST'])]
    public function updateManualUserSubscription(
        User $user,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('manual_subscription_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectAfterSubscriptionAction($request, $user);
        }

        $planCode = mb_strtolower(trim($request->request->getString('plan')));
        try {
            $manualSubscriptions->activate(
                $user,
                $planCode,
                $request->request->getInt('quantity', 1),
                $request->request->getString('organization_name'),
            );
            $this->addFlash('success', sprintf(
                'L’offre %s est maintenant gérée manuellement pour %s.',
                mb_strtoupper($planCode),
                $user->getEmail(),
            ));
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectAfterSubscriptionAction($request, $user);
    }

    #[Route('/users/{id}/subscription/deactivate', name: 'admin_user_subscription_deactivate', methods: ['POST'])]
    public function deactivateUserSubscription(
        User $user,
        Request $request,
        ManualSubscriptionManager $manualSubscriptions,
    ): Response {
        if (!$this->isCsrfTokenValid('deactivate_subscription_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectAfterSubscriptionAction($request, $user);
        }

        try {
            $subscription = $user->getUserSubscription();
            if ($subscription === null) {
                throw new \LogicException('Aucun abonnement ne peut être désactivé pour ce compte.');
            }
            $manualSubscriptions->deactivate($subscription);
            $this->addFlash('warning', 'Abonnement manuel désactivé pour ' . $user->getEmail() . '.');
        } catch (\LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectAfterSubscriptionAction($request, $user);
    }

    #[Route('/users/{id}/sessions/revoke-all', name: 'admin_user_sessions_revoke_all', methods: ['POST'])]
    public function revokeAllUserSessions(
        User $user,
        Request $request,
        SessionManager $sessionManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_revoke_all_sessions_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
        }

        $count = $sessionManager->revokeAllForUser($user);
        $this->addFlash('warning', sprintf('%d session(s) revoquee(s) pour %s.', $count, $user->getEmail()));

        return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
    }

    #[Route('/sessions/{id}/revoke', name: 'admin_session_revoke', methods: ['POST'])]
    public function revokeSession(
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager,
        int $id
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_revoke_session_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        if (!$session->isRevoked()) {
            $sessionManager->revoke($session, 'admin_revoked');
            $this->addFlash('success', 'Session revoquee pour ' . $session->getUser()?->getEmail() . '.');
        }

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/sessions/{id}/block-device', name: 'admin_session_block_device', methods: ['POST'])]
    public function blockSessionDevice(
        int $id,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_block_device_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        $deviceId = $session->getDeviceId();
        if (!$deviceId) {
            $this->addFlash('warning', 'Aucun device id pour cette session.');

            return $this->redirectToRoute('admin_sessions');
        }

        $count = $sessionManager->blockDevice($session->getUser(), $deviceId, 'admin_blocked');
        $this->addFlash('warning', $count . ' session(s) bloquees pour cet appareil.');

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/sessions/{id}/unblock-device', name: 'admin_session_unblock_device', methods: ['POST'])]
    public function unblockSessionDevice(
        int $id,
        Request $request,
        UserSessionRepository $userSessionRepository,
        SessionManager $sessionManager
    ): Response {
        $session = $userSessionRepository->find($id);
        if (!$session) {
            throw $this->createNotFoundException('Session introuvable.');
        }

        if (!$this->isCsrfTokenValid('admin_unblock_device_' . $session->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_sessions');
        }

        $deviceId = $session->getDeviceId();
        if (!$deviceId) {
            $this->addFlash('warning', 'Aucun device id pour cette session.');

            return $this->redirectToRoute('admin_sessions');
        }

        $count = $sessionManager->unblockDevice($session->getUser(), $deviceId);
        $this->addFlash('success', $count . ' session(s) debloquees pour cet appareil.');

        return $this->redirectToRoute('admin_sessions');
    }

    #[Route('/extensions/clients/{id}/block', name: 'admin_extension_client_block', methods: ['POST'])]
    public function blockExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_block_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->block($client, 'admin_blocked');
        $this->addFlash('warning', 'Extension bloquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/clients/{id}/unblock', name: 'admin_extension_client_unblock', methods: ['POST'])]
    public function unblockExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_unblock_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->unblock($client);
        $this->addFlash('success', 'Extension debloquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/clients/{id}/revoke', name: 'admin_extension_client_revoke', methods: ['POST'])]
    public function revokeExtensionClient(
        ExtensionClient $client,
        Request $request,
        ExtensionClientManager $extensionClientManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_revoke_extension_' . $client->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $extensionClientManager->revoke($client, 'admin_revoked');
        $this->addFlash('warning', 'Extension revoquee pour ' . $client->getUser()?->getEmail() . '.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/challenges/{id}/approve', name: 'admin_extension_challenge_approve', methods: ['POST'])]
    public function approveExtensionChallenge(
        ExtensionInstallationChallenge $challenge,
        Request $request,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_approve_extension_challenge_' . $challenge->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $challengeManager->approve($challenge);
        $this->addFlash('success', 'Demande d installation approuvee.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/extensions/challenges/{id}/reject', name: 'admin_extension_challenge_reject', methods: ['POST'])]
    public function rejectExtensionChallenge(
        ExtensionInstallationChallenge $challenge,
        Request $request,
        ExtensionInstallationChallengeManager $challengeManager
    ): Response {
        if (!$this->isCsrfTokenValid('admin_reject_extension_challenge_' . $challenge->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('admin_extensions');
        }

        $challengeManager->reject($challenge);
        $this->addFlash('warning', 'Demande d installation rejetee.');

        return $this->redirectToRoute('admin_extensions');
    }

    #[Route('/articles/new', name: 'admin_article_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $article = new Article();

        $form = $this->createForm(ArticleType::class, $article, [
            'is_edit' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $coverFile = $form->get('coverFile')->getData();
                if ($coverFile instanceof UploadedFile) {
                    $filename = $this->handleFileUpload($coverFile, $slugger, $this->coversDir);
                    $article->setCoverImage($filename);
                }

                $em->persist($article);
                $em->flush();

                $this->addFlash('success', 'Article créé avec succès ✅');
                return $this->redirectToRoute('admin_blog');
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la création de l\'article', [
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors de la création de l\'article.');
            }
        }

        return $this->render('admin/article_form.html.twig', [
            'form' => $form,
            'article' => $article,
            'mode' => 'new',
        ]);
    }

    #[Route('/articles/{id}/edit', name: 'admin_article_edit', methods: ['GET', 'POST'])]
    public function edit(
        Article $article,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        $oldCover = $article->getCoverImage();

        $form = $this->createForm(ArticleType::class, $article, [
            'is_edit' => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $coverFile = $form->get('coverFile')->getData();
                if ($coverFile instanceof UploadedFile) {
                    $filename = $this->handleFileUpload($coverFile, $slugger, $this->coversDir);
                    $article->setCoverImage($filename);

                    // Supprime l'ancienne image
                    if ($oldCover) {
                        $this->deleteFile($this->coversDir, $oldCover);
                    }
                }

                $em->flush();

                $this->addFlash('success', 'Article mis à jour avec succès ✅');
                return $this->redirectToRoute('admin_blog');
            } catch (\Exception $e) {
                $this->logger->error('Erreur lors de la modification de l\'article', [
                    'article_id' => $article->getId(),
                    'error' => $e->getMessage(),
                ]);
                $this->addFlash('error', 'Une erreur est survenue lors de la modification de l\'article.');
            }
        }

        return $this->render('admin/article_form.html.twig', [
            'form' => $form,
            'article' => $article,
            'mode' => 'edit',
        ]);
    }

    #[Route('/articles/{id}/delete', name: 'admin_article_delete', methods: ['POST'])]
    public function delete(
        Article $article,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $token = (string) $request->request->get('_token');
        
        if (!$this->isCsrfTokenValid('delete_article_' . $article->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_blog');
        }

        try {
            // Supprime le fichier cover
            $cover = $article->getCoverImage();
            if ($cover) {
                $this->deleteFile($this->coversDir, $cover);
            }

            $em->remove($article);
            $em->flush();

            $this->addFlash('success', 'Article supprimé avec succès ✅');
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression de l\'article', [
                'article_id' => $article->getId(),
                'error' => $e->getMessage(),
            ]);
            $this->addFlash('error', 'Une erreur est survenue lors de la suppression de l\'article.');
        }

        return $this->redirectToRoute('admin_blog');
    }

    #[Route('/articles/upload-image', name: 'admin_article_upload_image', methods: ['POST'])]
    public function uploadArticleImage(
        Request $request,
        ValidatorInterface $validator
    ): Response {
        $file = $request->files->get('upload');

        if (!$file instanceof UploadedFile) {
            return $this->json([
                'error' => ['message' => 'Aucun fichier reçu.']
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validation du fichier
        $violations = $validator->validate($file, [
            new Assert\NotNull(),
            new Assert\File([
                'maxSize' => self::MAX_FILE_SIZE,
                'mimeTypes' => self::ALLOWED_IMAGE_TYPES,
                'mimeTypesMessage' => 'Le fichier doit être une image valide (JPEG, PNG, WebP ou GIF).',
            ]),
        ]);

        if (count($violations) > 0) {
            return $this->json([
                'error' => ['message' => (string) $violations[0]->getMessage()]
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validation supplémentaire de l'extension
        $extension = $file->guessExtension();
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->json([
                'error' => ['message' => 'Extension de fichier non autorisée.']
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            if (!is_dir($this->contentImagesDir) && !mkdir($concurrentDirectory = $this->contentImagesDir, 0775, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Impossible de créer le dossier d\'upload "%s".', $this->contentImagesDir));
            }

            // Génère un nom de fichier sécurisé
            $filename = bin2hex(random_bytes(16)) . '.' . $extension;
            $file->move($this->contentImagesDir, $filename);

            return $this->json([
                'url' => '/uploads/blog/content/' . $filename
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur lors de l\'upload d\'image', [
                'error' => $e->getMessage(),
                'target_directory' => $this->contentImagesDir,
            ]);
            
            return $this->json([
                'error' => ['message' => 'Erreur lors de l\'upload du fichier.']
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Gère l'upload d'un fichier de manière sécurisée
     */
    private function handleFileUpload(
        UploadedFile $file,
        SluggerInterface $slugger,
        string $targetDirectory
    ): string {
        // Validation du type MIME
        if (!in_array($file->getMimeType(), self::ALLOWED_IMAGE_TYPES, true)) {
            throw new \InvalidArgumentException('Type de fichier non autorisé.');
        }

        // Validation de la taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Fichier trop volumineux (max 5MB).');
        }

        // Validation de l'extension
        $extension = $file->guessExtension();
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException('Extension de fichier non autorisée.');
        }

        // Génère un nom de fichier sécurisé
        $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = $slugger->slug($originalFilename)->lower();
        $filename = $safeFilename . '-' . bin2hex(random_bytes(8)) . '.' . $extension;

        try {
            $file->move($targetDirectory, $filename);
        } catch (FileException $e) {
            $this->logger->error('Erreur lors du déplacement du fichier', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Impossible d\'uploader le fichier.');
        }

        return $filename;
    }

    /**
     * Supprime un fichier de manière sécurisée
     */
    private function deleteFile(string $directory, string $filename): void
    {
        // Protection contre les path traversal
        $filename = basename($filename);
        $filepath = $directory . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($filepath)) {
            return;
        }

        // Vérifie que le fichier est bien dans le répertoire attendu
        $realPath = realpath($filepath);
        $realDir = realpath($directory);

        if ($realPath === false || $realDir === false || !str_starts_with($realPath, $realDir)) {
            $this->logger->warning('Tentative de suppression de fichier en dehors du répertoire autorisé', [
                'filepath' => $filepath,
            ]);
            return;
        }

        try {
            unlink($filepath);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la suppression du fichier', [
                'filepath' => $filepath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Nettoie et sécurise une requête de recherche
     */
    private function sanitizeSearchQuery(mixed $query): string
    {
        if (!is_string($query)) {
            return '';
        }

        // Supprime les espaces multiples et trim
        $query = trim(preg_replace('/\s+/', ' ', $query));

        // Limite la longueur
        return mb_substr($query, 0, 255);
    }

    private function sanitizeEmailSubject(string $subject): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');

        return mb_substr($subject, 0, 255);
    }

    private function sendTeamGuestInvitationEmail(
        MailerService $mailer,
        UrlGeneratorInterface $urlGenerator,
        User $guest,
        Team $team,
        DateTimeImmutable $expiresAt
    ): void {
        $guestRegisterUrl = $urlGenerator->generate(
            'app_guest_register',
            ['token' => $guest->getApiToken(), 'email' => $guest->getEmail()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $mailer->send(
            $guest->getEmail(),
            'Vous avez été invité dans une équipe MYKEYNEST',
            'emails/team_invitation.html.twig',
            [
                'user' => $guest,
                'team' => $team,
                'guest_register' => $guestRegisterUrl,
                'expiresAt' => $expiresAt,
            ]
        );
    }

    /**
     * @return list<string>
     */
    private function extractEmailAddresses(string $rawRecipients): array
    {
        $parts = preg_split('/[\s,;]+/', mb_strtolower($rawRecipients), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_unique(array_map('trim', $parts)));
    }

    /**
     * @param list<User> $selectedUsers
     * @param list<string> $manualEmails
     * @return list<string>
     */
    private function buildRecipientList(array $selectedUsers, array $manualEmails): array
    {
        $recipients = [];

        foreach ($selectedUsers as $user) {
            $email = mb_strtolower(trim((string) $user->getEmail()));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $email;
            }
        }

        foreach ($manualEmails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $email;
            }
        }

        return array_values(array_unique($recipients));
    }

    private function redirectAfterSubscriptionAction(Request $request, User $user): Response
    {
        if ($request->request->getString('_redirect') === 'user') {
            return $this->redirectToRoute('admin_user_show', ['id' => $user->getId()]);
        }

        return $this->redirectToRoute('admin_subscriptions');
    }

    /**
     * @return list<Article>
     */
    private function getArticles(EntityManagerInterface $em, string $q = ''): array
    {
        $repo = $em->getRepository(Article::class);
        /** @var QueryBuilder $qb */
        $qb = $repo->createQueryBuilder('a')
            ->orderBy('a.publishedAt', 'DESC');

        if ($q !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'a.slugFr LIKE :q',
                    'a.slugEn LIKE :q',
                    'a.h1Fr LIKE :q',
                    'a.h1En LIKE :q',
                    'a.seoTitleFr LIKE :q',
                    'a.seoTitleEn LIKE :q'
                )
            )->setParameter('q', '%' . $q . '%');
        }

        /** @var list<Article> $articles */
        $articles = $qb->getQuery()->getResult();

        return $articles;
    }

    /**
     * @return array{q:string,subscription:string,role:string,sort:string}
     */
    private function buildUserFilters(Request $request): array
    {
        $subscription = (string) $request->query->get('subscription', 'all');
        $role = (string) $request->query->get('role', 'all');
        $sort = (string) $request->query->get('sort', 'newest');

        return [
            'q' => $this->sanitizeSearchQuery($request->query->get('q', '')),
            'subscription' => in_array($subscription, ['all', 'active', 'inactive', 'free', 'pro', 'team'], true) ? $subscription : 'all',
            'role' => in_array($role, ['all', 'admin', 'user', 'guest'], true) ? $role : 'all',
            'sort' => in_array($sort, ['newest', 'oldest', 'email', 'company'], true) ? $sort : 'newest',
        ];
    }

    /**
     * @param array{q:string,subscription:string,role:string,sort:string} $filters
     */
    private function applyUserFilters(QueryBuilder $qb, array $filters): void
    {
        $activeTeamMembership = 'EXISTS (SELECT teamMembership.id FROM App\\Entity\\OrganizationMember teamMembership '
            . 'JOIN teamMembership.organization teamOrganization '
            . 'JOIN teamOrganization.subscription teamSubscription '
            . 'WHERE teamMembership.user = u '
            . 'AND teamMembership.status = :activeMemberStatus '
            . 'AND teamMembership.role != :guestMemberRole '
            . 'AND teamOrganization.status = :activeOrganizationStatus '
            . 'AND teamSubscription.isActive = true '
            . 'AND LOWER(teamSubscription.planCode) = :teamPlan)';

        if ($filters['q'] !== '') {
            if (ctype_digit($filters['q'])) {
                $qb->andWhere('u.id = :exactId OR u.email LIKE :query OR u.company LIKE :query')
                    ->setParameter('exactId', (int) $filters['q']);
            } else {
                $qb->andWhere('u.email LIKE :query OR u.company LIKE :query');
            }

            $qb->setParameter('query', '%' . $filters['q'] . '%');
        }

        match ($filters['subscription']) {
            'active' => $qb->andWhere('s.isActive = true'),
            'inactive' => $qb->andWhere('s.id IS NOT NULL')->andWhere('s.isActive = false'),
            'free' => $qb
                ->andWhere('(s.id IS NULL OR s.isActive = false)')
                ->andWhere('NOT ' . $activeTeamMembership)
                ->setParameter('activeMemberStatus', 'ACTIVE')
                ->setParameter('guestMemberRole', 'GUEST')
                ->setParameter('activeOrganizationStatus', 'ACTIVE')
                ->setParameter('teamPlan', 'team'),
            'pro' => $qb
                ->andWhere('s.isActive = true')
                ->andWhere('LOWER(s.planCode) = :proPlan')
                ->andWhere('NOT ' . $activeTeamMembership)
                ->setParameter('proPlan', 'pro')
                ->setParameter('activeMemberStatus', 'ACTIVE')
                ->setParameter('guestMemberRole', 'GUEST')
                ->setParameter('activeOrganizationStatus', 'ACTIVE')
                ->setParameter('teamPlan', 'team'),
            'team' => $qb
                ->andWhere('(s.isActive = true AND LOWER(s.planCode) = :teamPlan) OR ' . $activeTeamMembership)
                ->setParameter('activeMemberStatus', 'ACTIVE')
                ->setParameter('guestMemberRole', 'GUEST')
                ->setParameter('activeOrganizationStatus', 'ACTIVE')
                ->setParameter('teamPlan', 'team'),
            default => null,
        };

        match ($filters['role']) {
            'admin' => $qb->andWhere('u.roles LIKE :roleAdmin')->setParameter('roleAdmin', '%ROLE_ADMIN%'),
            'user' => $qb->andWhere('u.roles LIKE :roleUser')->setParameter('roleUser', '%ROLE_USER%'),
            'guest' => $qb->andWhere('u.roles LIKE :roleGuest')->setParameter('roleGuest', '%ROLE_GUEST%'),
            default => null,
        };

        match ($filters['sort']) {
            'oldest' => $qb->orderBy('u.id', 'ASC'),
            'email' => $qb->orderBy('u.email', 'ASC'),
            'company' => $qb->orderBy('u.company', 'ASC')->addOrderBy('u.email', 'ASC'),
            default => $qb->orderBy('u.id', 'DESC'),
        };
    }

    /**
     * @return array{q:string,status:string}
     */
    private function buildSessionFilters(Request $request): array
    {
        $status = (string) $request->query->get('status', 'all');

        return [
            'q' => $this->sanitizeSearchQuery($request->query->get('q', '')),
            'status' => in_array($status, ['all', 'active', 'blocked', 'revoked', 'expired'], true) ? $status : 'all',
        ];
    }

    /**
     * @param array{q:string,status:string} $filters
     */
    private function applySessionFilters(QueryBuilder $qb, array $filters): void
    {
        if ($filters['q'] !== '') {
            if (ctype_digit($filters['q'])) {
                $qb->andWhere('s.id = :sessionId OR u.id = :userId OR u.email LIKE :query OR u.company LIKE :query OR s.deviceName LIKE :query OR s.deviceId LIKE :query OR s.ipAddress LIKE :query')
                    ->setParameter('sessionId', (int) $filters['q'])
                    ->setParameter('userId', (int) $filters['q']);
            } else {
                $qb->andWhere('u.email LIKE :query OR u.company LIKE :query OR s.deviceName LIKE :query OR s.deviceId LIKE :query OR s.ipAddress LIKE :query');
            }

            $qb->setParameter('query', '%' . $filters['q'] . '%');
        }

        match ($filters['status']) {
            'active' => $qb->andWhere('s.isBlocked = false')->andWhere('s.isRevoked = false')->andWhere('s.expiresAt > :now'),
            'blocked' => $qb->andWhere('s.isBlocked = true'),
            'revoked' => $qb->andWhere('s.isRevoked = true'),
            'expired' => $qb->andWhere('s.expiresAt <= :now'),
            default => null,
        };

        if ($filters['status'] !== 'all') {
            $qb->setParameter('now', new DateTimeImmutable());
        }
    }

    /**
     * @return array{items:list<User>,page:int,pages:int,total:int,perPage:int}
     */
    private function paginateQuery(QueryBuilder $queryBuilder, int $page, int $perPage): array
    {
        $queryBuilder
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($queryBuilder, true);
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / $perPage));

        if ($page > $pages) {
            $page = $pages;
            $queryBuilder->setFirstResult(($page - 1) * $perPage);
            $paginator = new Paginator($queryBuilder, true);
        }

        /** @var list<User> $items */
        $items = iterator_to_array($paginator->getIterator(), false);

        return [
            'items' => $items,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'perPage' => $perPage,
        ];
    }
}

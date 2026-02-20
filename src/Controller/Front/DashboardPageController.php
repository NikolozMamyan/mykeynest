<?php

namespace App\Controller\Front;

use App\Entity\User;
use App\Entity\Team;
use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Entity\SharedAccess;
use App\Service\SecurityCheckerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class DashboardPageController extends AbstractController
{
    public function __construct(
        private readonly SecurityCheckerService $securityCheckerService,
        private readonly CacheInterface $cache
    ) {}

    #[Route('/app/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('show_login');
        }

        // -------------------------
        // Stats (comme tu as déjà)
        // -------------------------
        $credentialsCount = (int) $em->getRepository(Credential::class)->count(['user' => $user]);
        $draftsCount      = (int) $em->getRepository(DraftPassword::class)->count(['user' => $user]);

        $ownedTeams = $em->getRepository(Team::class)->findBy(['owner' => $user], ['createdAt' => 'DESC']);
        $teamsCount = \count($ownedTeams);

        $sharedCount = (int) $em->getRepository(SharedAccess::class)->count(['owner' => $user]);

        $teamsCards = [];
        foreach ($ownedTeams as $team) {
            $name = (string) $team->getName();
            $teamsCards[] = [
                'id' => $team->getId(),
                'name' => $name,
                'membersCount' => $team->getMembers()->count(),
                'credentialsCount' => $team->getCredentials()->count(),
                'roleLabel' => 'Propriétaire',
                'roleClass' => 'owner',
                'avatar' => mb_strtoupper(mb_substr(trim($name), 0, 2)),
            ];
        }

        $drafts = $em->getRepository(DraftPassword::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            5
        );

        // Activité récente (comme tu avais)
        $recentCredentials = $em->getRepository(Credential::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            3
        );

        $recentShares = $em->getRepository(SharedAccess::class)->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC'],
            3
        );

        $activity = [];
        foreach ($recentCredentials as $c) {
            $activity[] = [
                'type' => 'credential_created',
                'title_key' => 'dashboard.activity.credential_created.title',
                'desc' => $c->getName(),
                'at' => $c->getCreatedAt(),
            ];
        }
        foreach ($drafts as $d) {
            $activity[] = [
                'type' => 'draft_created',
                'title_key' => 'dashboard.activity.draft_created.title',
                'desc' => $d->getName() ?: null,
                'at' => $d->getCreatedAt(),
            ];
        }
        foreach ($recentShares as $s) {
            $activity[] = [
                'type' => 'share_created',
                'title_key' => 'dashboard.activity.share_created.title',
                'desc' => $s->getCredential()?->getName() ?: null,
                'at' => $s->getCreatedAt(),
            ];
        }
        usort($activity, fn($a, $b) => $b['at'] <=> $a['at']);
        $activity = array_slice($activity, 0, 6);

        // -------------------------
        // Security report via Service + Cache (PROD)
        // -------------------------
        $cacheKey = 'security_report_user_'.$user->getId();
        $report = $this->cache->get($cacheKey, function (ItemInterface $item) use ($user) {
            // cache court : tu déchiffres des mots de passe => coûteux
            $item->expiresAfter(300); // 5 minutes

            // IMPORTANT: dashboard ne doit PAS spammer des notifs
            return $this->securityCheckerService->buildReport($user, SecurityCheckerService::ROTATION_DAYS_DEFAULT);
        });

        // On derive les compteurs pour l’UI
        $counts = $report['counts'] ?? ['total'=>0,'weak'=>0,'reused'=>0,'expired'=>0,'undecipherable'=>0];
        $items  = $report['items'] ?? [];

        // strong = nb de credentials avec score >= 80 (plus fiable que "total-weak")
        $strongCount = 0;
        foreach ($items as $it) {
            $sc = (int) ($it['score'] ?? 0);
            if ($sc >= 80) {
                $strongCount++;
            }
        }

        $security = [
            'score' => (int) ($report['overallScore'] ?? 0),
            'strong' => $strongCount,
            'weak' => (int) ($counts['weak'] ?? 0),
            'reused' => (int) ($counts['reused'] ?? 0),
            'expired' => (int) ($counts['expired'] ?? 0),
            'undecipherable' => (int) ($counts['undecipherable'] ?? 0),
            'total' => (int) ($counts['total'] ?? 0),
        ];

        // -------------------------
        // Deltas (si tu les as déjà dans une autre version)
        // -> tu peux garder ton bloc "deltas" précédent.
        // Pour ne pas te noyer ici, je laisse neutre :
        // -------------------------
        $deltas = [
            'credentialsMonth' => 0,
            'teamsMonth' => 0,
            'sharesWeek' => 0,
            'draftsMonth' => 0,
        ];

        return $this->render('dashboard/index.html.twig', [
            'stats' => [
                'credentials' => $credentialsCount,
                'teams' => $teamsCount,
                'shares' => $sharedCount,
                'drafts' => $draftsCount,
            ],
            'deltas' => $deltas,
            'teams' => $teamsCards,
            'drafts' => $drafts,
            'activity' => $activity,
            'security' => $security,

            // optionnel: si tu veux afficher un “top issues”
            // 'securityReport' => $report,
        ]);
    }
}

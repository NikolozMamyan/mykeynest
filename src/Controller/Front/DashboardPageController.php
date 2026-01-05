<?php

namespace App\Controller\Front;

use App\Entity\Team;
use App\Entity\User;
use App\Entity\Credential;
use App\Entity\DraftPassword;
use App\Entity\SharedAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardPageController extends AbstractController
{
    #[Route('/app/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Counts simples
        $credentialsCount = (int) $em->getRepository(Credential::class)->count(['user' => $user]);
        $draftsCount      = (int) $em->getRepository(DraftPassword::class)->count(['user' => $user]);

        // Teams : owner
        $ownedTeams = $em->getRepository(Team::class)->findBy(['owner' => $user], ['createdAt' => 'DESC']);
        $teamsCount = count($ownedTeams);

        // Partages actifs (où user est owner)
        $sharedCount = (int) $em->getRepository(SharedAccess::class)->count(['owner' => $user]);

        // Teams cards : pour chaque team, compter membres + identifiants
        $teamsCards = [];
        foreach ($ownedTeams as $team) {
            $teamsCards[] = [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'membersCount' => $team->getMembers()->count(),
                'credentialsCount' => $team->getCredentials()->count(),
                'roleLabel' => 'Propriétaire', // tu peux adapter selon ton TeamMember si tu l’utilises
                'roleClass' => 'owner',
                'avatar' => mb_strtoupper(mb_substr($team->getName(), 0, 2)),
            ];
        }

        // Derniers brouillons (5)
        $drafts = $em->getRepository(DraftPassword::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            5
        );

        // Activité récente (simple, basé sur les derniers credentials + drafts + shares)
        // Pour un vrai "activity log", il faudra une table Activity.
        $recentCredentials = $em->getRepository(Credential::class)->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC'],
            3
        );
        $recentShares = $em->getRepository(SharedAccess::class)->findBy(
            ['owner' => $user],
            ['createdAt' => 'DESC'],
            2
        );

        $activity = [];

        foreach ($recentCredentials as $c) {
            $activity[] = [
                'type' => 'credential_created',
                'title' => 'Nouvel identifiant créé',
                'desc' => $c->getName(),
                'at' => $c->getCreatedAt(),
            ];
        }

        foreach ($drafts as $d) {
            $activity[] = [
                'type' => 'draft_created',
                'title' => 'Brouillon créé',
                'desc' => $d->getName() ?: 'Sans nom',
                'at' => $d->getCreatedAt(),
            ];
        }

        foreach ($recentShares as $s) {
            $activity[] = [
                'type' => 'share_created',
                'title' => 'Accès partagé',
                'desc' => $s->getCredential()?->getName() ?? 'Identifiant',
                'at' => $s->getCreatedAt(),
            ];
        }

        // Tri activité par date desc
        usort($activity, fn($a, $b) => $b['at'] <=> $a['at']);
        $activity = array_slice($activity, 0, 6);

        return $this->render('dashboard/index.html.twig', [
            'stats' => [
                'credentials' => $credentialsCount,
                'teams' => $teamsCount,
                'shares' => $sharedCount,
                'drafts' => $draftsCount,
            ],
            'teams' => $teamsCards,
            'drafts' => $drafts,
            'activity' => $activity,
        ]);
    }
}

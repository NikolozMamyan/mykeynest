<?php

namespace App\Tests\Controller\Front;

use App\Entity\Credential;
use App\Entity\Organization;
use App\Entity\OrganizationMember;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\UserSubscription;
use App\Enum\OrganizationRole;
use App\Enum\TeamRole;
use App\Form\OrganizationInviteMemberType;
use App\Form\TeamAddMemberType;
use App\Service\SubscriptionPlanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class TeamWorkspacePageTest extends KernelTestCase
{
    public function testCompanyOwnerKeepsInvitationLicenseAndRoleControls(): void
    {
        $data = $this->pageData(true);
        $html = self::getContainer()->get('twig')->render('team/index.html.twig', $data);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('#tw-invite-company form input[name="organization_invite_member[email]"]'));
        self::assertCount(1, $crawler->filter('#tw-licenses input[name="_token"]'));
        self::assertSame('6', $crawler->filter('#company-seat-quantity')->attr('min'));
        self::assertSame('250', $crawler->filter('#company-seat-quantity')->attr('max'));
        self::assertCount(1, $crawler->filter('.tw-role-form'));
        self::assertCount(1, $crawler->filter('a[href="/app/teams/10"]'));
        self::assertStringNotContainsString('teams.workspace.', $html);
    }

    public function testCompanyMemberCannotSeeManagementForms(): void
    {
        $data = $this->pageData(false);
        $crawler = new Crawler(self::getContainer()->get('twig')->render('team/index.html.twig', $data));

        self::assertCount(0, $crawler->filter('#tw-invite-company, #tw-licenses, .tw-role-form'));
        self::assertCount(0, $crawler->filter('form[action$="/remove"]'));
        self::assertCount(1, $crawler->filter('a[href="/app/teams/10"]'));
    }

    public function testGroupOwnerKeepsCredentialAndMemberFormsWithCsrfTokens(): void
    {
        $data = $this->pageData(true);
        $crawler = new Crawler(self::getContainer()->get('twig')->render('team/show.html.twig', $data));

        self::assertCount(1, $crawler->filter('#tw-add-member input[name="team_add_member[email]"]'));
        self::assertCount(1, $crawler->filter('#tw-add-member input[name="team_add_member[_token]"]'));
        self::assertCount(1, $crawler->filter('#credentialsModal input[type="checkbox"][value="1008"]'));
        self::assertCount(1, $crawler->filter('#credentialsModal input[name="team_add_credentials[_token]"]'));
        self::assertCount(1, $crawler->filter('form[action="/app/teams/10/credentials/1007/remove"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('form[action="/app/teams/10/members/2/remove"] input[name="_token"]'));
        self::assertCount(1, $crawler->filter('#deleteTeamForm input[name="_token"]'));
        self::assertCount(0, $crawler->filter('form[action="/app/teams/10/leave"]'));
    }

    public function testGroupMemberKeepsReadAccessWithoutManagementControls(): void
    {
        $data = $this->pageData(false);
        $crawler = new Crawler(self::getContainer()->get('twig')->render('team/show.html.twig', $data));

        self::assertCount(0, $crawler->filter('#tw-add-member, #credentialsModal, #deleteTeamForm'));
        self::assertCount(0, $crawler->filter('form[action$="/remove"]'));
        self::assertCount(1, $crawler->filter('a[href="/app/credential/1007"]'));
        self::assertCount(1, $crawler->filter('form[action="/app/teams/10/leave"] input[name="_token"]'));
    }

    private function pageData(bool $ownerView): array
    {
        self::bootKernel();
        $container = self::getContainer();
        $owner = $this->identified((new User())->setEmail('owner@example.com')->setCompany('Studio'), 1);
        $employee = $this->identified((new User())->setEmail('lea@example.com'), 2);
        $subscription = (new UserSubscription())->setUser($owner)->setPlanCode('team')->setIsActive(true)->setQuantity(6);
        $owner->setUserSubscription($subscription);
        $organization = $this->identified((new Organization())->setName('Studio')->setOwner($owner)->setSubscription($subscription), 20);
        $organization->addMember($this->identified((new OrganizationMember())->setUser($owner)->setRole(OrganizationRole::OWNER)->activate(), 1));
        $organization->addMember($this->identified((new OrganizationMember())->setUser($employee)->setRole(OrganizationRole::MEMBER)->activate(), 2));
        $team = $this->identified((new Team())->setName('Marketing')->setOwner($owner)->setOrganization($organization), 10);
        $team->addMember($this->identified((new TeamMember())->setUser($owner)->setRole(TeamRole::OWNER), 1));
        $team->addMember($this->identified((new TeamMember())->setUser($employee)->setRole(TeamRole::MEMBER), 2));
        $credential = $this->identified((new Credential())->setName('Figma')->setDomain('figma.com')->setUsername('studio@example.com')->setUser($owner), 1007);
        $team->addCredential($credential);
        $available = $this->identified((new Credential())->setName('Notion')->setDomain('notion.so')->setUsername('studio@example.com')->setUser($owner), 1008);
        $container->get(EntityManagerInterface::class)->getUnitOfWork()->registerManaged($available, ['id' => 1008], []);
        $user = $ownerView ? $owner : $employee;
        $request = Request::create('/app/teams/10');
        $request->setLocale('fr');
        $request->attributes->set('_route', 'app_team_show');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $container->get('request_stack')->push($request);
        $container->get('translator')->setLocale('fr');
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $plans = $container->get(SubscriptionPlanService::class);
        (new \ReflectionProperty(SubscriptionPlanService::class, 'organizationTeamAccess'))->setValue($plans, ['user_1' => true, 'user_2' => true]);
        (new \ReflectionProperty(SubscriptionPlanService::class, 'resolvedPlans'))->setValue($plans, ['team' => [
            'code' => 'team', 'label' => 'Team', 'limits' => [], 'features' => [], 'editable' => false, 'updatedAt' => null,
        ]]);
        $factory = $container->get(FormFactoryInterface::class);
        $credentialForm = $ownerView ? $factory->createNamedBuilder('team_add_credentials', FormType::class)
            ->add('credentials', EntityType::class, ['class' => Credential::class, 'choices' => [$available], 'choice_label' => 'name', 'multiple' => true, 'expanded' => true, 'required' => false])
            ->add('canRevealPassword', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'credential_access.reveal_label', 'help' => 'credential_access.reveal_help'])
            ->getForm()->createView() : null;

        return [
            'teams' => [$team], 'team' => $team, 'currentPlan' => $plans->getPlanForUser($user), 'ownedTeamsCount' => $ownerView ? 1 : 0,
            'teamLimit' => null, 'canCreateTeam' => true, 'organization' => $organization, 'canManageOrganization' => $ownerView,
            'organizationInviteForm' => $ownerView ? $factory->create(OrganizationInviteMemberType::class, null, ['action' => '/app/teams/company/members/invite'])->createView() : null,
            'seatSummary' => ['purchased' => 6, 'used' => 2, 'active' => 2, 'pending' => 0, 'available' => 4, 'guests' => 0, 'guestLimit' => 5, 'overCapacity' => false],
            'seatMinimum' => 6, 'seatMaximum' => 250,
            'add_member_form' => $ownerView ? $factory->create(TeamAddMemberType::class)->createView() : null,
            'add_credentials_form' => $credentialForm,
        ];
    }

    private function identified(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}

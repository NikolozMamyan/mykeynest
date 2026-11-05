<?php

namespace App\Tests\Form;

use App\Entity\Team;
use App\Entity\Credential;
use App\Enum\TeamRole;
use App\Form\TeamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormFactoryInterface;

final class TeamTypeTest extends KernelTestCase
{
    public function testCredentialCardsRenderWithNonConsecutiveDoctrineChoiceKeys(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $credentials = [];
        foreach ([1007 => 'Figma', 940 => 'Notion'] as $id => $name) {
            $credential = (new Credential())
                ->setName($name)
                ->setDomain(strtolower($name).'.com')
                ->setUsername(strtolower($name).'@example.com');
            (new \ReflectionProperty(Credential::class, 'id'))->setValue($credential, $id);
            $entityManager->getUnitOfWork()->registerManaged($credential, ['id' => $id], []);
            $credentials[] = $credential;
        }
        $form = $container->get(FormFactoryInterface::class)->create(TeamType::class, new Team(), ['csrf_protection' => false]);
        $form->add('credentials', EntityType::class, [
            'class' => Credential::class,
            'choices' => $credentials,
            'choice_label' => 'name',
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'by_reference' => false,
        ]);
        $form->add('canRevealPassword', CheckboxType::class, ['mapped' => false, 'required' => false]);
        $view = $form->createView();
        self::assertSame([1007, 940], array_keys($view['credentials']->vars['choices']));

        $html = $container->get('twig')->render('team/_form.html.twig', [
            'form' => $view,
            'companyTeam' => false,
            'teamOwnerEmail' => 'owner@example.com',
            'currentUserEmail' => 'owner@example.com',
            'automaticMembers' => ['owner@example.com'],
        ]);
        $cards = (new Crawler($html))->filter('.tc-credential');
        self::assertCount(2, $cards);
        foreach ($cards as $index => $card) {
            $card = new Crawler($card);
            self::assertSame($credentials[$index]->getName(), $card->attr('data-name'));
            self::assertSame((string) $credentials[$index]->getId(), $card->filter('input')->attr('value'));
            self::assertStringContainsString($credentials[$index]->getUsername(), $card->text());
        }
    }

    public function testSeveralMembersAreSubmittedTogetherWithoutMappingThemToTheTeam(): void
    {
        self::bootKernel();
        $team = new Team();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(TeamType::class, $team, ['csrf_protection' => false]);
        $form->submit([
            'name' => 'Marketing',
            'members' => [
                ['email' => 'employee@example.com', 'role' => 'ADMIN'],
                ['email' => 'guest@example.com', 'role' => 'MEMBER'],
            ],
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('Marketing', $team->getName());
        self::assertCount(0, $team->getMembers());
        self::assertCount(2, $form->get('members')->getData());
        self::assertSame(TeamRole::ADMIN, $form->get('members')->getData()[0]['role']);
        self::assertFalse($form->get('members')->get(1)->has('membershipType'));
    }

    /** @dataProvider invalidSubmissions */
    public function testInvalidNamesEmailsAndRolesAreRejected(array $data): void
    {
        self::bootKernel();
        $form = self::getContainer()->get(FormFactoryInterface::class)->create(TeamType::class, new Team(), ['csrf_protection' => false]);
        $form->submit($data);

        self::assertFalse($form->isValid());
    }

    public static function invalidSubmissions(): array
    {
        return [
            'empty name' => [['name' => '   ', 'members' => []]],
            'name too long' => [['name' => str_repeat('a', 181), 'members' => []]],
            'invalid email' => [['name' => 'Marketing', 'members' => [['email' => 'invalid', 'role' => 'MEMBER']]]],
            'empty email' => [['name' => 'Marketing', 'members' => [['email' => '', 'role' => 'MEMBER']]]],
            'owner role' => [['name' => 'Marketing', 'members' => [['email' => 'member@example.com', 'role' => 'OWNER']]]],
        ];
    }
}

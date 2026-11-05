<?php

namespace App\Tests\Repository;

use App\Entity\Organization;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\TeamRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class TeamRepositoryTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private TeamRepository $teams;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('The pdo_sqlite extension is required for repository tests.');
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE team (id INTEGER PRIMARY KEY, name VARCHAR(180) NOT NULL, owner_id INTEGER NOT NULL, organization_id INTEGER DEFAULT NULL)');
        $this->connection->insert('team', ['id' => 1, 'name' => 'Marketing', 'owner_id' => 1]);
        $this->connection->insert('team', ['id' => 2, 'name' => '  Support  ', 'owner_id' => 1]);
        $this->connection->insert('team', ['id' => 3, 'name' => 'Design', 'owner_id' => 1, 'organization_id' => 10]);
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2).'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $this->entityManager = new EntityManager($this->connection, $config);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Team::class)->willReturn($this->entityManager);
        $this->teams = new TeamRepository($registry);
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) $this->entityManager->close();
        if (isset($this->connection)) $this->connection->close();
    }

    /** @dataProvider namesAndWorkspaces */
    public function testNamesAreComparedWithoutCaseOrSurroundingSpacesWithinTheWorkspace(string $name, int $ownerId, ?int $organizationId, bool $exists): void
    {
        $owner = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($owner, $ownerId);
        $organization = null;
        if ($organizationId !== null) {
            $organization = new Organization();
            (new \ReflectionProperty(Organization::class, 'id'))->setValue($organization, $organizationId);
        }

        self::assertSame($exists, $this->teams->nameExistsInWorkspace($name, $owner, $organization));
    }

    public static function namesAndWorkspaces(): array
    {
        return [
            'identical personal name' => ['Marketing', 1, null, true],
            'uppercase personal name' => ['MARKETING', 1, null, true],
            'mixed case personal name' => ['mArKeTiNg', 1, null, true],
            'input surrounding spaces' => [' marketing ', 1, null, true],
            'existing surrounding spaces' => ['SUPPORT', 1, null, true],
            'different personal name' => ['Finance', 1, null, false],
            'another personal owner' => ['Marketing', 2, null, false],
            'company name' => ['DESIGN', 1, 10, true],
            'company scope ignores creator' => ['design', 2, 10, true],
            'another company' => ['Design', 1, 11, false],
            'personal does not block company' => ['Marketing', 1, 10, false],
            'company does not block personal' => ['Design', 1, null, false],
        ];
    }
}

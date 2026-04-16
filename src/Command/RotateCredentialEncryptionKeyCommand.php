<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\CredentialEncryptionRotationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:credential:rotate-encryption-key',
    description: 'Rotate credential encryption keys for one user or the full database.'
)]
final class RotateCredentialEncryptionKeyCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private CredentialEncryptionRotationService $rotationService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'Rotate a single user by id.')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Rotate a single user by email.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Rotate every user in the database.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate the rotation without writing to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $users = $this->resolveUsers($input);
        if ($users === null) {
            $io->error('Use exactly one targeting option: --user-id, --email or --all.');

            return Command::INVALID;
        }

        if ($users === []) {
            $io->warning('No matching user found.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $totalCredentials = 0;
        $totalDraftPasswords = 0;

        foreach ($users as $user) {
            $result = $this->rotationService->rotateUser($user, $dryRun);
            $totalCredentials += $result['credentials'];
            $totalDraftPasswords += $result['draftPasswords'];

            $io->writeln(sprintf(
                '%s user #%d <%s>: %d credentials, %d drafts',
                $dryRun ? '[dry-run] Would rotate' : 'Rotated',
                $user->getId() ?? 0,
                $user->getEmail() ?? 'unknown',
                $result['credentials'],
                $result['draftPasswords']
            ));
        }

        $io->success(sprintf(
            '%s %d user(s), %d credential(s), %d draft password(s).',
            $dryRun ? 'Dry run inspected' : 'Rotation completed for',
            count($users),
            $totalCredentials,
            $totalDraftPasswords
        ));

        return Command::SUCCESS;
    }

    /**
     * @return User[]|null
     */
    private function resolveUsers(InputInterface $input): ?array
    {
        $userId = $input->getOption('user-id');
        $email = $input->getOption('email');
        $all = (bool) $input->getOption('all');

        $selectedOptions = 0;
        $selectedOptions += $userId !== null ? 1 : 0;
        $selectedOptions += $email !== null ? 1 : 0;
        $selectedOptions += $all ? 1 : 0;

        if ($selectedOptions !== 1) {
            return null;
        }

        if ($userId !== null) {
            $user = $this->userRepository->find((int) $userId);

            return $user instanceof User ? [$user] : [];
        }

        if ($email !== null) {
            $user = $this->userRepository->findOneBy(['email' => $email]);

            return $user instanceof User ? [$user] : [];
        }

        return $this->userRepository->findAll();
    }
}

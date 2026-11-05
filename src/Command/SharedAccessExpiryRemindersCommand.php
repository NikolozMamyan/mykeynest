<?php

namespace App\Command;

use App\Service\SharedAccessExpiryNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(name: 'app:shared-access:expiry-reminders', description: 'Notify owners and recipients of shares expiring within 24 hours.')]
final class SharedAccessExpiryRemindersCommand extends Command
{
    public function __construct(private SharedAccessExpiryNotifier $notifier, private LockFactory $locks)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->locks->createLock('shared-access-expiry-reminders', 3600);
        if (!$lock->acquire()) return Command::SUCCESS;
        try {
            $output->writeln(sprintf('%d expiry reminder(s) sent.', $this->notifier->sendDue()));
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }
}

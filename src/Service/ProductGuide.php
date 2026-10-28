<?php

namespace App\Service;

use App\Entity\User;

final class ProductGuide
{
    public function update(User $user, string $action, ?int $step = null): array
    {
        if (!in_array($action, ['progress', 'skip', 'complete', 'restart'], true)) {
            throw new \InvalidArgumentException('Invalid guide action.');
        }
        if ($action === 'progress' && ($step === null || $step < 1 || $step > 4)) {
            throw new \InvalidArgumentException('Invalid guide step.');
        }

        $state = $user->getProductGuideState();
        if ($action !== 'restart' && ($state === null || in_array($state['status'], ['completed', 'skipped'], true))) {
            return $state ?? ['status' => 'skipped', 'step' => 1];
        }

        $state = match ($action) {
            'restart' => ['status' => 'active', 'step' => 1],
            'skip' => ['status' => 'skipped', 'step' => $state['step']],
            'complete' => ['status' => 'completed', 'step' => 4],
            'progress' => ['status' => 'active', 'step' => $step],
        };
        $user->setProductGuideState($state);

        return $state;
    }

    public function advanceAfter(User $user, int $step): bool
    {
        $state = $user->getProductGuideState();
        if ($state === null || !in_array($state['status'], ['pending', 'active'], true) || $state['step'] !== $step) {
            return false;
        }

        $user->setProductGuideState(['status' => $step === 4 ? 'completed' : 'active', 'step' => min(4, $step + 1)]);

        return true;
    }
}

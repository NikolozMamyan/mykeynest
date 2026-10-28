<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\ProductGuide;
use PHPUnit\Framework\TestCase;

final class ProductGuideTest extends TestCase
{
    public function testNewAccountsHaveAPendingGuideAndActionsOnlyAdvanceTheMatchingStep(): void
    {
        $user = new User();
        $guide = new ProductGuide();
        self::assertSame(['status' => 'pending', 'step' => 1], $user->getProductGuideState());
        self::assertFalse($guide->advanceAfter($user, 3));
        self::assertTrue($guide->advanceAfter($user, 1));
        self::assertSame(['status' => 'active', 'step' => 2], $user->getProductGuideState());
        self::assertFalse($guide->advanceAfter($user, 1));
        $guide->update($user, 'progress', 3);
        self::assertTrue($guide->advanceAfter($user, 3));
        self::assertSame(['status' => 'active', 'step' => 4], $user->getProductGuideState());
        self::assertTrue($guide->advanceAfter($user, 4));
        self::assertSame(['status' => 'completed', 'step' => 4], $user->getProductGuideState());
    }

    public function testExistingAccountsDoNotAutomaticallyGetAGuide(): void
    {
        $user = (new User())->setProductGuideState(null);
        $guide = new ProductGuide();
        self::assertFalse($guide->advanceAfter($user, 1));
        $guide->update($user, 'progress', 2);
        self::assertNull($user->getProductGuideState());
        self::assertSame(['status' => 'active', 'step' => 1], $guide->update($user, 'restart'));
    }

    public function testSkipAndCompletionCannotBeUndoneByLateProgressRequests(): void
    {
        $user = new User();
        $guide = new ProductGuide();
        $guide->update($user, 'progress', 3);
        $guide->update($user, 'skip');
        self::assertSame(['status' => 'skipped', 'step' => 3], $guide->update($user, 'progress', 2));
        self::assertFalse($guide->advanceAfter($user, 3));
        $guide->update($user, 'restart');
        $guide->update($user, 'complete');
        self::assertSame(['status' => 'completed', 'step' => 4], $guide->update($user, 'progress', 1));
    }

    /** @dataProvider invalidUpdates */
    public function testInvalidUpdatesLeaveTheAccountUnchanged(string $action, ?int $step): void
    {
        $user = new User();
        $this->expectException(\InvalidArgumentException::class);
        try {
            (new ProductGuide())->update($user, $action, $step);
        } finally {
            self::assertSame(['status' => 'pending', 'step' => 1], $user->getProductGuideState());
        }
    }

    public static function invalidUpdates(): array
    {
        return [['unknown', 1], ['progress', null], ['progress', 0], ['progress', 5]];
    }
}

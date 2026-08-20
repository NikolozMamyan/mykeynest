<?php

namespace App\Tests\Service;

use App\Service\StripePlanCatalog;
use PHPUnit\Framework\TestCase;
use Stripe\Subscription;

final class StripePlanCatalogTest extends TestCase
{
    public function testProCheckoutUsesOneMonthlyPrice(): void
    {
        $catalog = new StripePlanCatalog(6, 250);

        self::assertSame([
            'price' => 'price_pro',
            'quantity' => 1,
        ], $catalog->getCheckoutLineItem('PRO', 'price_pro'));
        self::assertSame('pro', $catalog->resolvePlanFromPrice('price_pro', 'price_pro', 'price_team'));
        self::assertSame(699, $catalog->getExpectedMonthlyAmount('pro'));
    }

    public function testTeamCheckoutStartsAtSixAndAllowsSeatAdjustment(): void
    {
        $catalog = new StripePlanCatalog(2, 100);

        self::assertSame([
            'price' => 'price_team',
            'quantity' => 6,
            'adjustable_quantity' => [
                'enabled' => true,
                'minimum' => 6,
                'maximum' => 100,
            ],
        ], $catalog->getCheckoutLineItem('team', 'price_team'));
        self::assertSame('team', $catalog->resolvePlanFromPrice('price_team', 'price_pro', 'price_team'));
        self::assertSame(549, $catalog->getExpectedMonthlyAmount('team'));
        self::assertNull($catalog->resolvePlanFromPrice('price_unrelated', 'price_pro', 'price_team'));
    }

    public function testCheckoutRefusesMissingOrUnsupportedConfiguration(): void
    {
        $catalog = new StripePlanCatalog(6, 250);

        $this->expectException(\LogicException::class);
        $catalog->getCheckoutLineItem('team', '');
    }

    public function testProToTeamUpgradeInvoicesTheProratedDifferenceImmediately(): void
    {
        $catalog = new StripePlanCatalog(6, 250);

        self::assertSame([
            'items' => [[
                'id' => 'si_pro',
                'price' => 'price_team',
                'quantity' => 8,
            ]],
            'cancel_at_period_end' => false,
            'payment_behavior' => Subscription::PAYMENT_BEHAVIOR_PENDING_IF_INCOMPLETE,
            'proration_behavior' => Subscription::PRORATION_BEHAVIOR_ALWAYS_INVOICE,
            'metadata' => [
                'plan' => 'team',
                'user_id' => '42',
                'stripe_mode' => 'production',
            ],
            'expand' => ['customer', 'items.data.price', 'latest_invoice'],
        ], $catalog->buildProToTeamUpdate('si_pro', 'price_team', 8, 42, 'production'));
    }
}

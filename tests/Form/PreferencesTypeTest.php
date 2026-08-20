<?php

namespace App\Tests\Form;

use App\Entity\User;
use App\Form\PreferencesType;
use Symfony\Component\Form\Test\TypeTestCase;

final class PreferencesTypeTest extends TypeTestCase
{
    public function testEmailTwoFactorFieldIsDisabledAndIgnoredWhenUnavailable(): void
    {
        $user = new User();
        $form = $this->factory->create(PreferencesType::class, $user, [
            'email_two_factor_available' => false,
        ]);

        self::assertTrue($form->get('emailTwoFactorEnabled')->isDisabled());
        self::assertFalse($form->get('emailTwoFactorEnabled')->getData());

        $form->submit([
            'locale' => 'fr',
            'emailTwoFactorEnabled' => '1',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertTrue($user->isEmailTwoFactorEnabled());
    }

    public function testAvailableUserCanDisableEmailTwoFactorAuthentication(): void
    {
        $user = new User();
        $form = $this->factory->create(PreferencesType::class, $user, [
            'email_two_factor_available' => true,
        ]);

        self::assertFalse($form->get('emailTwoFactorEnabled')->isDisabled());
        self::assertTrue($form->get('emailTwoFactorEnabled')->getData());

        $form->submit([
            'locale' => 'fr',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertFalse($user->isEmailTwoFactorEnabled());
    }
}

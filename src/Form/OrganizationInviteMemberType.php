<?php

namespace App\Form;

use App\Enum\OrganizationRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

final class OrganizationInviteMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $roles = [
            'teams.organization.invite.role_member' => OrganizationRole::MEMBER,
        ];
        if ($options['can_invite_admin']) {
            $roles['teams.organization.invite.role_admin'] = OrganizationRole::ADMIN;
        }

        $builder
            ->add('email', EmailType::class, [
                'label' => 'teams.organization.invite.email_label',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'teams.organization.invite.role_label',
                'choices' => $roles,
                'data' => OrganizationRole::MEMBER,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id' => 'organization_invite_member',
            'can_invite_admin' => false,
        ]);
        $resolver->setAllowedTypes('can_invite_admin', 'bool');
    }
}

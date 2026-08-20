<?php

namespace App\Form;

use App\Enum\TeamRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TeamAddMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'teams.show.members.add.email_label',
        ]);

        if ($options['company_team']) {
            $builder->add('membershipType', ChoiceType::class, [
                'label' => 'teams.show.members.add.type_label',
                'choices' => [
                    'teams.show.members.add.type_employee' => 'employee',
                    'teams.show.members.add.type_guest' => 'guest',
                ],
                'expanded' => false,
                'multiple' => false,
                'mapped' => false,
            ]);
        }

        $builder->add('role', ChoiceType::class, [
            'label' => 'teams.show.members.add.role_label',
            'choices' => [
                'teams.show.members.add.role_member' => TeamRole::MEMBER,
                'teams.show.members.add.role_admin' => TeamRole::ADMIN,
            ],
            'expanded' => false,
            'multiple' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // on utilise un simple array (pas d’entity liée directement)
        $resolver->setDefaults([
            'csrf_protection' => true,
            'company_team' => false,
        ]);
        $resolver->setAllowedTypes('company_team', 'bool');
    }
}

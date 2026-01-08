<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

class PreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', ChoiceType::class, [
                'label' => 'preferences.language.label',
                'choices' => [
                    'preferences.language.french' => 'fr',
                    'preferences.language.english' => 'en',
                ],
                'expanded' => true,
                'required' => true,
            ])

            ->add('allowFeedback', CheckboxType::class, [
                'label' => 'preferences.feedback.label',
                'required' => false,
            ])

            ->add('interestedInCyberSecurity', CheckboxType::class, [
                'label' => 'preferences.cybersecurity.label',
                'required' => false,
            ])

            ->add('receiveSecurityEmails', CheckboxType::class, [
                'label' => 'preferences.security_emails.label',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
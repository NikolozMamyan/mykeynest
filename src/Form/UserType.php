<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // === IDENTITÉ ===
            ->add('prenom', TextType::class, [
                'label' => 'user.field.firstname',
                'required' => true,
                'attr' => [
                    'placeholder' => 'John',
                    'autocomplete' => 'given-name',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'user.error.firstname_required',
                    ]),
                    new Assert\Length([
                        'min' => 2,
                        'max' => 50,
                        'minMessage' => 'user.error.firstname_too_short',
                        'maxMessage' => 'user.error.firstname_too_long',
                    ]),
                ],
            ])

            ->add('nom', TextType::class, [
                'label' => 'user.field.lastname',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Doe',
                    'autocomplete' => 'family-name',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'user.error.lastname_required',
                    ]),
                    new Assert\Length([
                        'min' => 2,
                        'max' => 50,
                        'minMessage' => 'user.error.lastname_too_short',
                        'maxMessage' => 'user.error.lastname_too_long',
                    ]),
                ],
            ])

            // === ENTREPRISE ===
            ->add('company', TextType::class, [
                'label' => 'user.field.company',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Acme Inc.',
                    'autocomplete' => 'organization',
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 100,
                        'maxMessage' => 'user.error.company_too_long',
                    ]),
                ],
            ])

            // ->add('fonction', TextType::class, [
            //     'label' => 'user.field.position',
            //     'required' => false,
            //     'attr' => [
            //         'placeholder' => 'Product Manager',
            //         'autocomplete' => 'organization-title',
            //     ],
            //     'constraints' => [
            //         new Assert\Length([
            //             'max' => 100,
            //             'maxMessage' => 'user.error.position_too_long',
            //         ]),
            //     ],
            // ])

            // === CONTACT ===
            ->add('email', EmailType::class, [
                'label' => 'user.field.email',
                'required' => true,
                'attr' => [
                    'placeholder' => 'john.doe@example.com',
                    'autocomplete' => 'email',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'user.error.email_required',
                    ]),
                    new Assert\Email([
                        'message' => 'user.error.email_invalid',
                    ]),
                    new Assert\Length([
                        'max' => 180,
                        'maxMessage' => 'user.error.email_too_long',
                    ]),
                ],
            ])

            ->add('phone', TelType::class, [
                'label' => 'user.field.phone',
                'required' => false,
                'attr' => [
                    'placeholder' => '+33 6 12 34 56 78',
                    'autocomplete' => 'tel',
                ],
                'constraints' => [
                    new Assert\Length([
                        'max' => 20,
                        'maxMessage' => 'user.error.phone_too_long',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^[\d\s\+\-\(\)]+$/',
                        'message' => 'user.error.phone_invalid',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'attr' => ['novalidate' => 'novalidate'], // Pour utiliser la validation HTML5 côté client
        ]);
    }
}
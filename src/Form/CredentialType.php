<?php

namespace App\Form;

use App\Entity\Credential;
use App\Service\CredentialUrlPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CredentialType extends AbstractType
{
    public function __construct(private readonly CredentialUrlPolicy $credentialUrls)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var \App\Entity\User|null $user */
        $user = $options['user'] ?? null;
        $isEdit = $options['is_edit'] ?? false;

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du credential',
                'attr' => ['placeholder' => 'Ex : Gmail, Facebook, etc.'],
            ])
            ->add('domain', TextType::class, [
                'label' => 'credential.form.site.label',
                'attr' => ['placeholder' => 'credential.form.site.placeholder'],
                'constraints' => [
                    new Callback(function (mixed $value, ExecutionContextInterface $context): void {
                        if (!is_string($value) || $this->credentialUrls->parseSite($value) === null) {
                            $context->buildViolation('credential.form.site.invalid')->addViolation();
                        }
                    }),
                ],
            ])
            ->add('loginUrl', HiddenType::class, [
                'label' => 'credential.index.launch.url_label',
                'required' => false,
            ])
            ->add('username', TextType::class, [
                'label' => "Nom d'utilisateur",
                'attr' => ['placeholder' => 'Votre identifiant'],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => !$isEdit,
                'mapped' => !$isEdit, // 👈 Non mappé en mode édition
                'attr' => [
                    'placeholder' => $isEdit ? 'Laissez vide pour conserver le mot de passe actuel' : '********'
                ],
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }

            $site = $this->credentialUrls->parseSite(is_string($data['domain'] ?? null) ? $data['domain'] : null);
            if ($site === null) {
                return;
            }

            $data['domain'] = $site['domain'];
            if ($site['loginUrl'] !== null) {
                $data['loginUrl'] = $site['loginUrl'];
            } else {
                $data['loginUrl'] = '';
            }
            $event->setData($data);
        });

        // if ($user) {
        //     $builder->add('teams', EntityType::class, [
        //         'class'        => Team::class,
        //         'choice_label' => 'name',
        //         'label'        => 'Équipes (optionnel)',
        //         'multiple'     => true,
        //         'expanded'     => true,
        //         'required'     => false,
        //         'by_reference' => false,
        //         'query_builder' => function (TeamRepository $repo) use ($user) {
        //             return $repo->createQueryBuilder('t')
        //                 ->join('t.members', 'm')
        //                 ->andWhere('m.user = :user')
        //                 ->setParameter('user', $user)
        //                 ->orderBy('t.name', 'ASC');
        //         },
        //     ]);
        // }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Credential::class,
            'user'       => null,
            'is_edit'    => false,
        ]);
    }
}

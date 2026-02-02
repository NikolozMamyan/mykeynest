<?php

namespace App\Form\Admin;

use App\Entity\Article;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

final class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = (bool) ($options['is_edit'] ?? false);

        $builder
            ->add('slugFr', TextType::class, ['label' => 'Slug FR'])
            ->add('slugEn', TextType::class, ['label' => 'Slug EN'])

            ->add('seoTitleFr', TextType::class, ['label' => 'SEO Title FR'])
            ->add('seoTitleEn', TextType::class, ['label' => 'SEO Title EN'])

            ->add('metaDescFr', TextType::class, ['label' => 'Meta desc FR'])
            ->add('metaDescEn', TextType::class, ['label' => 'Meta desc EN'])

            ->add('h1Fr', TextType::class, ['label' => 'H1 FR'])
            ->add('h1En', TextType::class, ['label' => 'H1 EN'])

->add('contentFr', TextareaType::class, [
    'label' => 'Contenu FR',
    'attr' => ['class' => 'wysiwyg'],
])
->add('contentEn', TextareaType::class, [
    'label' => 'Contenu EN',
    'attr' => ['class' => 'wysiwyg'],
])


            // champs alt
            ->add('coverAltFr', TextType::class, [
                'label' => 'Cover ALT FR',
                'required' => false,
                'mapped' => true,
            ])
            ->add('coverAltEn', TextType::class, [
                'label' => 'Cover ALT EN',
                'required' => false,
                'mapped' => true,
            ])

            // Upload (non mappé: on gère le filename dans le controller)
            ->add('coverFile', FileType::class, [
                'label' => 'Cover image',
                'mapped' => false,
                'required' => !$isEdit, // requis en création, optionnel en edit
                'constraints' => [
                    new File([
                        'maxSize' => '5M',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp'],
                        'mimeTypesMessage' => 'Image invalide (jpg/png/webp).',
                    ]),
                ],
            ])

            // Optionnel : permettre de modifier publishedAt
            ->add('publishedAt', DateTimeType::class, [
                'label' => 'Publié le',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;

        // updatedAt: géré par lifecycle callback, donc inutile dans le form
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
            'is_edit' => false,
        ]);
    }
}

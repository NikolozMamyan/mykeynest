<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BreadcrumbsExtension extends AbstractExtension
{
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(UrlGeneratorInterface $urlGenerator)
    {
        $this->urlGenerator = $urlGenerator;
    }

    /**
     * Déclare les fonctions Twig disponibles
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('breadcrumb_map', [$this, 'getBreadcrumbMap']),
        ];
    }

    /**
     * Retourne la map complète des breadcrumbs
     */
    public function getBreadcrumbMap(): array
    {
        return [
            'app_dashboard' => [
                [
                    'label'  => 'Tableau de bord',
                    'icon'   => 'fas fa-house',
                    'url'    => $this->urlGenerator->generate('app_dashboard'),
                    'active' => true,
                ],
            ],

            'app_credential' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label'  => 'Mots de passe',
                    'icon'   => 'fas fa-key',
                    'url'    => $this->urlGenerator->generate('app_credential'),
                    'active' => true,
                ],
            ],
            'credential_new' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label'  => 'Mots de passe',
                    'icon'   => 'fas fa-key',
                    'url'    => $this->urlGenerator->generate('app_credential'),
                    'active' => true,
                ],
                                [
                    'label'  => 'Nouveau',
                    'icon'   => 'fa-solid fa-circle-plus',
                    'url'    => $this->urlGenerator->generate('credential_new'),
                    'active' => true,
                ],
            ],

            'shared_access_index' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label'  => 'Partages sécurisées',
                    'icon'   => 'fas fa-share-alt',
                    'url'    => $this->urlGenerator->generate('shared_access_index'),
                    'active' => true,
                ],
            ],

            'app_extension' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label'  => 'Extension navigateur',
                    'icon'   => 'fas fa-plug',
                    'url'    => '#',
                    'active' => true,
                ],
            ],

            'app_generator' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label' => 'Outils',
                    'icon'  => 'fas fa-toolbox',
                ],
                [
                    'label'  => 'Générateur',
                    'icon'   => 'fas fa-wand-magic-sparkles',
                    'url'    => $this->urlGenerator->generate('app_generator'),
                    'active' => true,
                ],
            ],



            'app_notes' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label' => 'Outils',
                    'icon'  => 'fas fa-toolbox',
                ],
                [
                    'label'  => 'Notes sécurisées',
                    'icon'   => 'fas fa-note-sticky',
                    'url'    => '#',
                    'active' => true,
                ],
            ],

            'app_security_checker' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label' => 'Outils',
                    'icon'  => 'fas fa-toolbox',
                ],
                [
                    'label'  => 'Vérificateur de sécurité',
                    'icon'   => 'fas fa-shield-heart',
                    'url'    => '#',
                    'active' => true,
                ],
            ],

            'app_subscription' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label' => 'Paramètres',
                    'icon'  => 'fas fa-gear',
                ],
                [
                    'label'  => 'Abonnements',
                    'icon'   => 'fas fa-receipt',
                    'url'    => $this->urlGenerator->generate('app_subscription'),
                    'active' => true,
                ],
            ],

            'app_preferences' => [
                [
                    'label' => 'Tableau de bord',
                    'icon'  => 'fas fa-house',
                    'url'   => $this->urlGenerator->generate('app_dashboard'),
                ],
                [
                    'label' => 'Paramètres',
                    'icon'  => 'fas fa-gear',
                ],
                [
                    'label'  => 'Préférences',
                    'icon'   => 'fas fa-gear',
                    'url'    => '#',
                    'active' => true,
                ],
            ],

            // 'app_security' => [
            //     [
            //         'label' => 'Tableau de bord',
            //         'icon'  => 'fas fa-house',
            //         'url'   => $this->urlGenerator->generate('app_dashboard'),
            //     ],
            //     [
            //         'label' => 'Paramètres',
            //         'icon'  => 'fas fa-gear',
            //     ],
            //     [
            //         'label'  => 'Sécurité',
            //         'icon'   => 'fas fa-shield',
            //         'url'    => '#',
            //         'active' => true,
            //     ],
            // ],
        ];
    }
}

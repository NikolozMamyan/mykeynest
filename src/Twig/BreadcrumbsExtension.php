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

    public function getFunctions(): array
    {
        return [
            new TwigFunction('breadcrumb_map', [$this, 'getBreadcrumbMap']),
        ];
    }

    public function getBreadcrumbMap(): array
    {
        $dashboard = [
            'label' => 'breadcrumb.dashboard',
            'icon'  => 'fas fa-house',
            'url'   => $this->urlGenerator->generate('app_dashboard'),
        ];

        $tools = [
            'label' => 'breadcrumb.tools',
            'icon'  => 'fas fa-toolbox',
            // 'url' => $this->urlGenerator->generate('app_tools'),
        ];

        $settings = [
            'label' => 'breadcrumb.settings',
            'icon'  => 'fas fa-gear',
            // 'url' => $this->urlGenerator->generate('app_settings'),
        ];

        return [
            // --- Principal ---
            'app_dashboard' => [
                $dashboard + ['active' => true],
            ],

            'app_team_index' => [
                $dashboard,
                [
                    'label'  => 'breadcrumb.teams',
                    'icon'   => 'fas fa-users',
                    'url'    => $this->urlGenerator->generate('app_team_index'),
                    'active' => true,
                ],
            ],

            'app_credential' => [
                $dashboard,
                [
                    'label'  => 'breadcrumb.credentials',
                    'icon'   => 'fas fa-key',
                    'url'    => $this->urlGenerator->generate('app_credential'),
                    'active' => true,
                ],
            ],

            'credential_new' => [
                $dashboard,
                [
                    'label' => 'breadcrumb.credentials',
                    'icon'  => 'fas fa-key',
                    'url'   => $this->urlGenerator->generate('app_credential'),
                ],
                [
                    'label'  => 'breadcrumb.new',
                    'icon'   => 'fas fa-circle-plus',
                    'url'    => $this->urlGenerator->generate('credential_new'),
                    'active' => true,
                ],
            ],

            'shared_access_index' => [
                $dashboard,
                [
                    'label'  => 'breadcrumb.shared_access',
                    'icon'   => 'fas fa-share-alt',
                    'url'    => $this->urlGenerator->generate('shared_access_index'),
                    'active' => true,
                ],
            ],

            'app_extention' => [
                $dashboard,
                [
                    'label'  => 'breadcrumb.extension',
                    'icon'   => 'fas fa-plug',
                    'url'    => $this->urlGenerator->generate('app_extention'),
                    'active' => true,
                ],
            ],

            // --- Outils ---
            'app_generator' => [
                $dashboard,
                $tools,
                [
                    'label'  => 'breadcrumb.generator',
                    'icon'   => 'fas fa-wand-magic-sparkles',
                    'url'    => $this->urlGenerator->generate('app_generator'),
                    'active' => true,
                ],
            ],

            'app_note' => [
                $dashboard,
                $tools,
                [
                    'label'  => 'breadcrumb.notes',
                    'icon'   => 'fas fa-note-sticky',
                    'url'    => $this->urlGenerator->generate('app_note'),
                    'active' => true,
                ],
            ],

            'app_security_checker' => [
                $dashboard,
                $tools,
                [
                    'label'  => 'breadcrumb.security_checker',
                    'icon'   => 'fas fa-shield-heart',
                    'url'    => $this->urlGenerator->generate('app_security_checker'),
                    'active' => true,
                ],
            ],

            // --- Paramètres ---
            'app_subscription' => [
                $dashboard,
                $settings,
                [
                    'label'  => 'breadcrumb.subscription',
                    'icon'   => 'fas fa-receipt',
                    'url'    => $this->urlGenerator->generate('app_subscription'),
                    'active' => true,
                ],
            ],

            'app_settings' => [
                $dashboard,
                $settings,
                [
                    'label'  => 'breadcrumb.settings',
                    'icon'   => 'fas fa-gear',
                    'url'    => $this->urlGenerator->generate('app_settings'),
                    'active' => true,
                ],
            ],
        ];
    }
}

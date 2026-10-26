<?php

namespace App\Twig;

use App\Service\BlogMedia;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class BlogMediaExtension extends AbstractExtension
{
    public function __construct(private readonly BlogMedia $media)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('blog_image', [$this->media, 'image'])];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('blog_media', [$this->media, 'content'])];
    }
}

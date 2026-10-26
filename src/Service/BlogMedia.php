<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class BlogMedia
{
    private array $images = [];

    public function __construct(#[Autowire('%kernel.project_dir%/public')] private readonly string $publicDir)
    {
    }

    public function image(string $src, int $maxWidth = 1600): array
    {
        $key = $src.'|'.$maxWidth;
        return $this->images[$key] ??= $this->prepareImage($src, $maxWidth);
    }

    private function prepareImage(string $src, int $maxWidth): array
    {
        $result = ['src' => $src, 'width' => null, 'height' => null];
        $url = parse_url($src);
        if ($url === false || isset($url['host']) || isset($url['scheme'])) {
            return $result;
        }

        $path = rawurldecode($url['path'] ?? '');
        if (!str_starts_with(ltrim($path, '/'), 'uploads/blog/')) {
            return $result;
        }

        $root = realpath($this->publicDir.'/uploads/blog');
        $file = realpath($this->publicDir.'/'.ltrim($path, '/'));
        if ($root === false || $file === false || !str_starts_with($file, $root.DIRECTORY_SEPARATOR) || !is_file($file)) {
            return $result;
        }

        $size = @getimagesize($file);
        if ($size === false) {
            return $result;
        }

        $result['width'] = $size[0];
        $result['height'] = $size[1];
        if (!in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
            || !function_exists('imagewebp') || $size[0] * $size[1] > 25000000
            || ($size[0] <= $maxWidth && filesize($file) <= 150000)) {
            return $result;
        }

        $name = hash('sha256', $path.'|'.filemtime($file).'|'.filesize($file).'|'.$maxWidth).'.webp';
        $directory = $root.'/optimized';
        $target = $directory.'/'.$name;
        $width = min($maxWidth, $size[0]);
        $height = max(1, (int) round($size[1] * $width / $size[0]));

        if (!is_file($target)) {
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return $result;
            }
            if (!is_writable($directory)) {
                return $result;
            }

            $image = match ($size[2]) {
                IMAGETYPE_PNG => @imagecreatefrompng($file),
                IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
                IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            };
            if ($image === false) {
                return $result;
            }

            $resized = imagecreatetruecolor($width, $height);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            $temp = tempnam($directory, 'webp-');
            $written = $temp !== false && imagewebp($resized, $temp, 82);
            imagedestroy($image);
            imagedestroy($resized);
            if (!$written || !@rename($temp, $target)) {
                if ($temp !== false && is_file($temp)) {
                    unlink($temp);
                }
                return $result;
            }
            chmod($target, 0644);
        }

        if (filesize($target) >= filesize($file) && $size[0] <= $maxWidth) {
            return $result;
        }

        return ['src' => '/uploads/blog/optimized/'.$name, 'width' => $width, 'height' => $height];
    }

    public function content(string $html): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->getElementsByTagName('img') as $image) {
            $media = $this->image($image->getAttribute('src'));
            $image->setAttribute('src', $media['src']);
            if ($media['width'] !== null) {
                $image->setAttribute('width', (string) $media['width']);
                $image->setAttribute('height', (string) $media['height']);
            }
            $image->setAttribute('loading', 'lazy');
            $image->setAttribute('decoding', 'async');
            if (!$image->hasAttribute('alt')) {
                $image->setAttribute('alt', '');
            }
        }

        $result = '';
        foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }

        return $result;
    }
}

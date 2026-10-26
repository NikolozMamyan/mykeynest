<?php

namespace App\Tests\Service;

use App\Service\BlogMedia;
use PHPUnit\Framework\TestCase;

final class BlogMediaTest extends TestCase
{
    private string $directory;
    private BlogMedia $media;

    protected function setUp(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD with WebP support is required.');
        }
        $this->directory = sys_get_temp_dir().'/keynest-media-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/uploads/blog', 0775, true);
        $image = imagecreatetruecolor(600, 300);
        imagepng($image, $this->directory.'/uploads/blog/test.png');
        imagedestroy($image);
        file_put_contents($this->directory.'/outside.txt', 'not an image');
        $this->media = new BlogMedia($this->directory);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        foreach (glob($this->directory.'/uploads/blog/optimized/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory.'/uploads/blog/optimized')) {
            rmdir($this->directory.'/uploads/blog/optimized');
        }
        unlink($this->directory.'/uploads/blog/test.png');
        unlink($this->directory.'/outside.txt');
        rmdir($this->directory.'/uploads/blog');
        rmdir($this->directory.'/uploads');
        rmdir($this->directory);
    }

    public function testResizesAndCachesAnExistingImageWithoutChangingTheOriginal(): void
    {
        $result = $this->media->image('/uploads/blog/test.png', 160);

        self::assertSame(160, $result['width']);
        self::assertSame(80, $result['height']);
        self::assertStringEndsWith('.webp', $result['src']);
        self::assertSame(IMAGETYPE_WEBP, getimagesize($this->directory.$result['src'])[2]);
        self::assertSame(600, getimagesize($this->directory.'/uploads/blog/test.png')[0]);
        self::assertSame($result, $this->media->image('/uploads/blog/test.png', 160));
    }

    public function testNeverProcessesRemoteUrlsOrPathsOutsideBlogUploads(): void
    {
        foreach (['https://example.com/image.png', '//example.com/image.png', '/uploads/blog/../../outside.txt', '/uploads/blog/missing.png'] as $src) {
            self::assertSame(['src' => $src, 'width' => null, 'height' => null], $this->media->image($src));
        }
    }

    public function testContentKeepsUtf8AndAddsImageDimensionsAndLazyLoading(): void
    {
        $html = $this->media->content('<h2>Équipe et sécurité</h2><p><img src="/uploads/blog/test.png" alt="Schéma des accès"></p>');

        self::assertStringContainsString('Équipe et sécurité', $html);
        self::assertStringContainsString('alt="Schéma des accès"', $html);
        self::assertStringContainsString('width="600"', $html);
        self::assertStringContainsString('height="300"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringNotContainsString('<html', $html);
    }
}

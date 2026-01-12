<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 200, unique: true)]
    private string $slugFr;

    #[ORM\Column(length: 200, unique: true)]
    private string $slugEn;

    #[ORM\Column(length: 255)]
    private string $seoTitleFr;

    #[ORM\Column(length: 255)]
    private string $seoTitleEn;

    #[ORM\Column(length: 320)]
    private string $metaDescFr;

    #[ORM\Column(length: 320)]
    private string $metaDescEn;

    #[ORM\Column(length: 255)]
    private string $h1Fr;

    #[ORM\Column(length: 255)]
    private string $h1En;

    #[ORM\Column(type: 'text')]
    private string $contentFr;

    #[ORM\Column(type: 'text')]
    private string $contentEn;

    // ✅ cover image fields (store filename only: "secure-passwords.webp")
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverAltFr = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverAltEn = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $now = new \DateTimeImmutable('now');
        $this->publishedAt ??= $now;
        $this->updatedAt ??= $now;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int { return $this->id; }

    public function getSlugFr(): string { return $this->slugFr; }
    public function setSlugFr(string $v): self { $this->slugFr = $v; return $this; }

    public function getSlugEn(): string { return $this->slugEn; }
    public function setSlugEn(string $v): self { $this->slugEn = $v; return $this; }

    public function getSeoTitleFr(): string { return $this->seoTitleFr; }
    public function setSeoTitleFr(string $v): self { $this->seoTitleFr = $v; return $this; }

    public function getSeoTitleEn(): string { return $this->seoTitleEn; }
    public function setSeoTitleEn(string $v): self { $this->seoTitleEn = $v; return $this; }

    public function getMetaDescFr(): string { return $this->metaDescFr; }
    public function setMetaDescFr(string $v): self { $this->metaDescFr = $v; return $this; }

    public function getMetaDescEn(): string { return $this->metaDescEn; }
    public function setMetaDescEn(string $v): self { $this->metaDescEn = $v; return $this; }

    public function getH1Fr(): string { return $this->h1Fr; }
    public function setH1Fr(string $v): self { $this->h1Fr = $v; return $this; }

    public function getH1En(): string { return $this->h1En; }
    public function setH1En(string $v): self { $this->h1En = $v; return $this; }

    public function getContentFr(): string { return $this->contentFr; }
    public function setContentFr(string $v): self { $this->contentFr = $v; return $this; }

    public function getContentEn(): string { return $this->contentEn; }
    public function setContentEn(string $v): self { $this->contentEn = $v; return $this; }

    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $v): self { $this->publishedAt = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $v): self { $this->updatedAt = $v; return $this; }

    // --------- Cover image getters/setters/helpers ---------

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    public function setCoverImage(?string $filename): self
    {
        $this->coverImage = $filename;
        return $this;
    }

    public function setCoverAltFr(?string $alt): self
    {
        $this->coverAltFr = $alt;
        return $this;
    }

    public function setCoverAltEn(?string $alt): self
    {
        $this->coverAltEn = $alt;
        return $this;
    }

    public function getCoverAlt(string $locale): ?string
    {
        return $locale === 'fr' ? $this->coverAltFr : $this->coverAltEn;
    }

    // ✅ Helper: gives relative public path for Twig asset()
    public function getCoverPath(): string
    {
        return $this->coverImage;
    }
}

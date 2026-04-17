<?php

namespace App\Entity;

use App\Repository\EmailCampaignRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailCampaignRepository::class)]
class EmailCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $subject = '';

    #[ORM\Column(type: 'text')]
    private string $htmlContent = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $recipients = [];

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $failedRecipients = [];

    #[ORM\Column]
    private int $recipientCount = 0;

    #[ORM\Column]
    private int $failedCount = 0;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $sentByEmail = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

    public function __construct()
    {
        $this->sentAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getHtmlContent(): string
    {
        return $this->htmlContent;
    }

    public function setHtmlContent(string $htmlContent): static
    {
        $this->htmlContent = $htmlContent;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @param list<string> $recipients
     */
    public function setRecipients(array $recipients): static
    {
        $this->recipients = array_values($recipients);
        $this->recipientCount = count($this->recipients);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFailedRecipients(): array
    {
        return $this->failedRecipients;
    }

    /**
     * @param list<string> $failedRecipients
     */
    public function setFailedRecipients(array $failedRecipients): static
    {
        $this->failedRecipients = array_values($failedRecipients);
        $this->failedCount = count($this->failedRecipients);

        return $this;
    }

    public function getRecipientCount(): int
    {
        return $this->recipientCount;
    }

    public function setRecipientCount(int $recipientCount): static
    {
        $this->recipientCount = $recipientCount;

        return $this;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function setFailedCount(int $failedCount): static
    {
        $this->failedCount = $failedCount;

        return $this;
    }

    public function getSentByEmail(): ?string
    {
        return $this->sentByEmail;
    }

    public function setSentByEmail(?string $sentByEmail): static
    {
        $this->sentByEmail = $sentByEmail;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}

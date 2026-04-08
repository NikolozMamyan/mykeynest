<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    private const DEFAULT_FROM_EMAIL = 'noreply@key-nest.com';
    private const DEFAULT_FROM_NAME = 'MYKEYNEST';

    public function __construct(
        private MailerInterface $mailer,
        private Environment $twig
    ) {
    }

    public function send(
        string $to,
        string $subject,
        string $template,
        array $context = [],
        ?string $replyTo = null,
        ?string $pdfTemplate = null,
        ?string $pdfFilename = null
    ): void {
        $html = $this->twig->render($template, $context);
        $text = $this->buildTextBody($html);

        $email = (new Email())
            ->from(new Address(self::DEFAULT_FROM_EMAIL, self::DEFAULT_FROM_NAME))
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $email->replyTo($replyTo);
        }

        if ($pdfTemplate !== null && $pdfFilename !== null) {
            $pdfContent = $this->generatePdfFromTemplate($pdfTemplate, $context);
            $email->attach($pdfContent, $pdfFilename, 'application/pdf');
        }

        $this->mailer->send($email);
    }

    private function generatePdfFromTemplate(string $template, array $context): string
    {
        $html = $this->twig->render($template, $context);

        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildTextBody(string $html): string
    {
        $text = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)>/i', "\n", $html);
        $text = preg_replace('/<li>/i', '- ', $text ?? $html);
        $text = strip_tags($text ?? $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }
}

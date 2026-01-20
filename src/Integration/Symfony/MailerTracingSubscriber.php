<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Stacktracer\SymfonyBundle\Service\TracingService;

/**
 * Mailer subscriber for tracking email sending operations.
 *
 * Creates breadcrumbs and spans with mail.* semantic conventions for
 * email sending, delivery confirmation, and failures.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class MailerTracingSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    /** @var array<string, float> */
    private array $startTimes = [];

    public function __construct(TracingService $tracing)
    {
        $this->tracing = $tracing;
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class => ['onMessage', 100],
            SentMessageEvent::class => ['onSentMessage', 0],
            FailedMessageEvent::class => ['onFailedMessage', 0],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $messageId = spl_object_id($message);
        $this->startTimes[$messageId] = microtime(true);

        $this->tracing->addBreadcrumb(
            'mail',
            'Sending email',
            [
                'mail.to' => $this->formatAddresses($message->getTo()),
                'mail.subject' => $this->sanitizeSubject($message->getSubject() ?? ''),
                'mail.transport' => $event->getTransport(),
            ],
            'debug'
        );
    }

    public function onSentMessage(SentMessageEvent $event): void
    {
        $message = $event->getMessage()->getOriginalMessage();

        if (!$message instanceof Email) {
            return;
        }

        $messageId = spl_object_id($message);
        $duration = null;

        if (isset($this->startTimes[$messageId])) {
            $duration = (microtime(true) - $this->startTimes[$messageId]) * 1000;
            unset($this->startTimes[$messageId]);
        }

        $sentMessage = $event->getMessage();
        $debug = $sentMessage->getDebug();

        $data = [
            'mail.to' => $this->formatAddresses($message->getTo()),
            'mail.from' => $this->formatAddresses($message->getFrom()),
            'mail.subject' => $this->sanitizeSubject($message->getSubject() ?? ''),
            'mail.sent' => true,
        ];

        if ($duration !== null) {
            $data['mail.duration_ms'] = round($duration, 2);
        }

        if ($sentMessage->getMessageId() !== '') {
            $data['mail.message_id'] = $sentMessage->getMessageId();
        }

        $this->tracing->addBreadcrumb(
            'mail',
            'Email sent successfully',
            $data,
            'info'
        );

        // Create a span for the email send
        $span = $this->tracing->startSpan('mail.send', 'mail');
        $span->setAttributes($data);
        $span->setStatus('ok');
        $this->tracing->endSpan($span);
    }

    public function onFailedMessage(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();

        if (!$message instanceof Email) {
            return;
        }

        $messageId = spl_object_id($message);
        $duration = null;

        if (isset($this->startTimes[$messageId])) {
            $duration = (microtime(true) - $this->startTimes[$messageId]) * 1000;
            unset($this->startTimes[$messageId]);
        }

        $error = $event->getError();

        $data = [
            'mail.to' => $this->formatAddresses($message->getTo()),
            'mail.from' => $this->formatAddresses($message->getFrom()),
            'mail.subject' => $this->sanitizeSubject($message->getSubject() ?? ''),
            'mail.sent' => false,
            'mail.error' => $error->getMessage(),
            'mail.error_type' => get_class($error),
        ];

        if ($duration !== null) {
            $data['mail.duration_ms'] = round($duration, 2);
        }

        $this->tracing->addBreadcrumb(
            'mail',
            'Email sending failed',
            $data,
            'error'
        );

        // Create a span for the failed email
        $span = $this->tracing->startSpan('mail.send', 'mail');
        $span->setAttributes($data);
        $span->setStatus('error');
        $span->setAttribute('error.type', get_class($error));
        $span->setAttribute('error.message', $error->getMessage());
        $this->tracing->endSpan($span);

        // Also capture as exception for visibility
        $this->tracing->captureException($error);
    }

    /**
     * @param Address[] $addresses
     */
    private function formatAddresses(array $addresses): string
    {
        $formatted = array_map(
            static fn (Address $addr) => $addr->getAddress(),
            array_slice($addresses, 0, 5) // Limit to 5 addresses
        );

        $result = implode(', ', $formatted);

        if (count($addresses) > 5) {
            $result .= sprintf(' (+%d more)', count($addresses) - 5);
        }

        return $result;
    }

    private function sanitizeSubject(string $subject): string
    {
        // Truncate long subjects
        if (strlen($subject) > 100) {
            return substr($subject, 0, 100).'...';
        }

        return $subject;
    }
}

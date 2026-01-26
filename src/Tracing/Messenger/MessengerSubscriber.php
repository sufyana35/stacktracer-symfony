<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Messenger;

use Stacktracer\SymfonyBundle\Model\Span;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * Symfony Messenger subscriber for tracing async message/job processing.
 *
 * Creates OTEL-compatible spans with messaging.* and job.* semantic conventions
 * for all dispatched and consumed messages.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class MessengerSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

    /** @var array<string, Span> */
    private array $activeSpans = [];

    /** @var array<string, float> */
    private array $startTimes = [];

    private bool $captureSoftFails;

    private bool $isolateScopeByMessage;

    public function __construct(
        TracingService $tracing,
        bool $captureSoftFails = true,
        bool $isolateScopeByMessage = true
    ) {
        $this->tracing = $tracing;
        $this->captureSoftFails = $captureSoftFails;
        $this->isolateScopeByMessage = $isolateScopeByMessage;
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => ['onSendMessage', 0],
            WorkerMessageReceivedEvent::class => ['onMessageReceived', 100],
            WorkerMessageHandledEvent::class => ['onMessageHandled', 0],
            WorkerMessageFailedEvent::class => ['onMessageFailed', 0],
        ];
    }

    public function onSendMessage(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $messageClass = get_class($message);
        $messageName = $this->getShortClassName($messageClass);

        $span = $this->tracing->startSpan(sprintf('%s publish', $messageName), 'messaging');
        $span->setOrigin('auto.messaging');

        // OTEL messaging.* semantic conventions
        $span->setAttribute('messaging.operation.type', 'publish');
        $span->setAttribute('messaging.message.type', $messageClass);
        $span->setAttribute('messaging.message.name', $messageName);

        // Check for bus name
        /** @var BusNameStamp|null $busStamp */
        $busStamp = $envelope->last(BusNameStamp::class);
        if ($busStamp !== null) {
            $span->setAttribute('messaging.destination.name', $busStamp->getBusName());
        }

        // Check for delay
        /** @var DelayStamp|null $delayStamp */
        $delayStamp = $envelope->last(DelayStamp::class);
        if ($delayStamp !== null) {
            $span->setAttribute('messaging.delay_ms', $delayStamp->getDelay());
        }

        // Extract job-specific attributes from message if available
        $this->extractJobAttributes($span, $message);

        $this->tracing->endSpan($span);
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        // Push scope to isolate breadcrumbs per message
        if ($this->isolateScopeByMessage) {
            $this->tracing->pushScope();
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $messageClass = get_class($message);
        $messageName = $this->getShortClassName($messageClass);
        $receiptHandle = $event->getReceiverName();

        $spanKey = spl_object_id($envelope) . ':' . $receiptHandle;

        $span = $this->tracing->startSpan(sprintf('%s process', $messageName), 'messaging');
        $span->setOrigin('auto.messaging');

        // OTEL messaging.* semantic conventions
        $span->setAttribute('messaging.operation.type', 'process');
        $span->setAttribute('messaging.message.type', $messageClass);
        $span->setAttribute('messaging.message.name', $messageName);
        $span->setAttribute('messaging.destination.name', $receiptHandle);

        // Check for redelivery (retry)
        /** @var RedeliveryStamp|null $redeliveryStamp */
        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        if ($redeliveryStamp !== null) {
            $span->setAttribute('messaging.retry_count', $redeliveryStamp->getRetryCount());
        }

        // Check if from failure transport
        /** @var SentToFailureTransportStamp|null $failureStamp */
        $failureStamp = $envelope->last(SentToFailureTransportStamp::class);
        if ($failureStamp !== null) {
            $span->setAttribute('messaging.from_failure_transport', true);
        }

        // Extract job-specific attributes
        $this->extractJobAttributes($span, $message);

        $this->activeSpans[$spanKey] = $span;
        $this->startTimes[$spanKey] = microtime(true);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $receiptHandle = $event->getReceiverName();
        $spanKey = spl_object_id($envelope) . ':' . $receiptHandle;

        if (!isset($this->activeSpans[$spanKey])) {
            return;
        }

        $span = $this->activeSpans[$spanKey];
        $span->setStatus('ok');

        if (isset($this->startTimes[$spanKey])) {
            $duration = (microtime(true) - $this->startTimes[$spanKey]) * 1000;
            $span->setAttribute('job.duration_ms', round($duration, 2));
        }

        $this->tracing->endSpan($span);

        // Pop scope to clean up breadcrumbs
        if ($this->isolateScopeByMessage) {
            $this->tracing->popScope();
        }

        unset($this->activeSpans[$spanKey], $this->startTimes[$spanKey]);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $receiptHandle = $event->getReceiverName();
        $spanKey = spl_object_id($envelope) . ':' . $receiptHandle;

        // Skip if soft fails are disabled and message will be retried
        if (!$this->captureSoftFails && $event->willRetry()) {
            if (isset($this->activeSpans[$spanKey])) {
                $span = $this->activeSpans[$spanKey];
                $span->setStatus('ok'); // Mark as ok since it will be retried
                $this->tracing->endSpan($span);

                if ($this->isolateScopeByMessage) {
                    $this->tracing->popScope();
                }

                unset($this->activeSpans[$spanKey], $this->startTimes[$spanKey]);
            }

            return;
        }

        if (!isset($this->activeSpans[$spanKey])) {
            // Create a new span if we missed the receive event
            $message = $envelope->getMessage();
            $messageClass = get_class($message);
            $messageName = $this->getShortClassName($messageClass);

            $span = $this->tracing->startSpan(sprintf('%s process', $messageName), 'messaging');
            $span->setAttribute('messaging.operation.type', 'process');
            $span->setAttribute('messaging.message.type', $messageClass);
            $span->setAttribute('messaging.message.name', $messageName);
        } else {
            $span = $this->activeSpans[$spanKey];
        }

        $throwable = $event->getThrowable();

        $span->setStatus('error');
        $span->setAttribute('error.type', get_class($throwable));
        $span->setAttribute('error.message', $throwable->getMessage());
        $span->setAttribute('job.failed', true);
        $span->setAttribute('job.will_retry', $event->willRetry());

        if (isset($this->startTimes[$spanKey])) {
            $duration = (microtime(true) - $this->startTimes[$spanKey]) * 1000;
            $span->setAttribute('job.duration_ms', round($duration, 2));
        }

        // Capture the exception with full context
        $this->captureException($throwable, $event->willRetry());

        $this->tracing->endSpan($span);

        // Pop scope to clean up breadcrumbs
        if ($this->isolateScopeByMessage) {
            $this->tracing->popScope();
        }

        unset($this->activeSpans[$spanKey], $this->startTimes[$spanKey]);
    }

    /**
     * Captures an exception, unpacking wrapped exceptions from Messenger.
     */
    private function captureException(\Throwable $exception, bool $willRetry): void
    {
        // Unpack HandlerFailedException to get the real exceptions
        if ($exception instanceof HandlerFailedException && method_exists($exception, 'getNestedExceptions')) {
            foreach ($exception->getNestedExceptions() as $nestedException) {
                $this->tracing->captureException($nestedException);
            }

            return;
        }

        // Unpack DelayedMessageHandlingException
        if ($exception instanceof DelayedMessageHandlingException && method_exists($exception, 'getExceptions')) {
            foreach ($exception->getExceptions() as $nestedException) {
                $this->tracing->captureException($nestedException);
            }

            return;
        }

        $this->tracing->captureException($exception);
    }

    private function extractJobAttributes(Span $span, object $message): void
    {
        // Extract common job attributes via reflection
        $reflection = new \ReflectionClass($message);

        // Check for job ID
        foreach (['id', 'jobId', 'messageId', 'uuid'] as $idProp) {
            if ($reflection->hasProperty($idProp)) {
                $prop = $reflection->getProperty($idProp);
                $prop->setAccessible(true);
                $value = $prop->getValue($message);
                if ($value !== null) {
                    $span->setAttribute('job.id', (string) $value);
                    break;
                }
            }
        }

        // Check for queue name
        foreach (['queue', 'queueName'] as $queueProp) {
            if ($reflection->hasProperty($queueProp)) {
                $prop = $reflection->getProperty($queueProp);
                $prop->setAccessible(true);
                $value = $prop->getValue($message);
                if ($value !== null) {
                    $span->setAttribute('job.queue', (string) $value);
                    break;
                }
            }
        }

        // Check for priority
        foreach (['priority'] as $priorityProp) {
            if ($reflection->hasProperty($priorityProp)) {
                $prop = $reflection->getProperty($priorityProp);
                $prop->setAccessible(true);
                $value = $prop->getValue($message);
                if ($value !== null) {
                    $span->setAttribute('job.priority', (int) $value);
                    break;
                }
            }
        }
    }

    private function getShortClassName(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }
}

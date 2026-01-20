<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Twig;

use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Error\Error as TwigError;

/**
 * Twig error subscriber for capturing template errors.
 *
 * Captures Twig template errors with enhanced context including
 * template name, line number, and source context.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class TwigSubscriber implements EventSubscriberInterface
{
    private TracingService $tracing;

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
            ExceptionEvent::class => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof TwigError) {
            return;
        }

        $templateFile = $exception->getSourceContext()?->getPath() ?? $exception->getSourceContext()?->getName();
        $templateLine = $exception->getTemplateLine();

        $data = [
            'template.error' => true,
            'template.name' => $exception->getSourceContext()?->getName() ?? 'unknown',
            'template.line' => $templateLine,
            'template.error_type' => get_class($exception),
            'template.error_message' => $exception->getRawMessage(),
        ];

        if ($templateFile !== null) {
            $data['template.file'] = $templateFile;
        }

        $this->tracing->addBreadcrumb(
            'template',
            sprintf('Twig error in %s', $data['template.name']),
            $data,
            'error'
        );

        // Create a span for the template error
        $span = $this->tracing->startSpan('template.error', 'template');
        $span->setAttributes($data);
        $span->setStatus('error');
        $span->setAttribute('error.type', get_class($exception));
        $span->setAttribute('error.message', $exception->getMessage());
        $this->tracing->endSpan($span);
    }
}

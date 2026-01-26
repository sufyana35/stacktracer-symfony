<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Tracing\Form;

use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

/**
 * Form subscriber for tracking form submissions and validation errors.
 *
 * Creates breadcrumbs and spans with form.* semantic conventions for form
 * submissions. Captures validation errors to help debug user-facing issues.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class FormSubscriber implements EventSubscriberInterface
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
            FormEvents::PRE_SUBMIT => ['onPreSubmit', 100],
            FormEvents::POST_SUBMIT => ['onPostSubmit', -100],
        ];
    }

    public function onPreSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        // Only track root forms
        if (!$form->isRoot()) {
            return;
        }

        $formName = $form->getName() ?: 'unnamed_form';
        $formKey = spl_object_id($form);

        $this->startTimes[$formKey] = microtime(true);

        $this->tracing->addBreadcrumb(
            'form',
            'Form submission started',
            [
                'form.name' => $formName,
                'form.type' => $this->getFormTypeName($form),
            ],
            'debug'
        );
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        // Only track root forms
        if (!$form->isRoot()) {
            return;
        }

        $formName = $form->getName() ?: 'unnamed_form';
        $formKey = spl_object_id($form);
        $duration = null;

        if (isset($this->startTimes[$formKey])) {
            $duration = (microtime(true) - $this->startTimes[$formKey]) * 1000;
            unset($this->startTimes[$formKey]);
        }

        $errors = $this->collectErrors($form);
        $isValid = $form->isValid();

        $data = [
            'form.name' => $formName,
            'form.type' => $this->getFormTypeName($form),
            'form.valid' => $isValid,
            'form.submitted' => $form->isSubmitted(),
        ];

        if ($duration !== null) {
            $data['form.duration_ms'] = round($duration, 2);
        }

        if (!$isValid && !empty($errors)) {
            $data['form.error_count'] = count($errors);
            $data['form.errors'] = array_slice($errors, 0, 10); // Limit to 10 errors
            $data['form.fields_with_errors'] = array_unique(array_column($errors, 'field'));
        }

        $this->tracing->addBreadcrumb(
            'form',
            $isValid ? 'Form submitted successfully' : 'Form validation failed',
            $data,
            $isValid ? 'info' : 'warning'
        );

        // Create a span for form processing if spans are enabled
        if (!$isValid && !empty($errors)) {
            $span = $this->tracing->startSpan(sprintf('form.%s', $formName), 'form');
            $span->setOrigin('auto.form');
            $span->setAttribute('form.name', $formName);
            $span->setAttribute('form.type', $this->getFormTypeName($form));
            $span->setAttribute('form.valid', false);
            $span->setAttribute('form.error_count', count($errors));
            $span->setAttribute('form.fields_with_errors', array_unique(array_column($errors, 'field')));

            if ($duration !== null) {
                $span->setAttribute('form.duration_ms', round($duration, 2));
            }

            $span->setStatus('error');
            $this->tracing->endSpan($span);
        }
    }

    /**
     * Recursively collect all form errors.
     *
     * @return array<int, array{field: string, message: string}>
     */
    private function collectErrors(FormInterface $form, string $prefix = ''): array
    {
        $errors = [];
        $fieldName = $prefix ? $prefix . '.' . $form->getName() : ($form->getName() ?: 'form');

        /** @var FormError $error */
        foreach ($form->getErrors() as $error) {
            $errors[] = [
                'field' => $fieldName,
                'message' => $error->getMessage(),
            ];
        }

        foreach ($form->all() as $child) {
            $errors = array_merge($errors, $this->collectErrors($child, $fieldName));
        }

        return $errors;
    }

    private function getFormTypeName(FormInterface $form): string
    {
        $config = $form->getConfig();
        $type = $config->getType();
        $innerType = $type->getInnerType();
        $class = get_class($innerType);

        // Get short class name
        $pos = strrpos($class, '\\');

        return $pos !== false ? substr($class, $pos + 1) : $class;
    }
}

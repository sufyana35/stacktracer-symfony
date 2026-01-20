<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\Integration\Symfony;

use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/**
 * Security subscriber for tracking authentication and authorization events.
 *
 * Creates breadcrumbs and spans with security.* semantic conventions for
 * login attempts, failures, logouts, and access denied events.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class SecurityTracingSubscriber implements EventSubscriberInterface
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
            InteractiveLoginEvent::class => ['onInteractiveLogin', 0],
            LoginFailureEvent::class => ['onLoginFailure', 0],
            LogoutEvent::class => ['onLogout', 0],
            SwitchUserEvent::class => ['onSwitchUser', 0],
            AuthenticationSuccessEvent::class => ['onAuthenticationSuccess', 0],
            ExceptionEvent::class => ['onKernelException', 5], // Higher priority to catch security exceptions
        ];
    }

    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        $token = $event->getAuthenticationToken();
        $request = $event->getRequest();

        $data = [
            'security.event' => 'login_success',
            'security.user' => $this->getUserIdentifier($token),
            'security.ip' => $request->getClientIp(),
            'security.firewall' => $this->getFirewallName($request),
        ];

        if ($token !== null) {
            $data['security.roles'] = $token->getRoleNames();
        }

        $this->tracing->addBreadcrumb(
            'security',
            'User logged in',
            $data,
            'info'
        );

        // Set user context for the session
        $this->tracing->setUser([
            'id' => $this->getUserIdentifier($token),
            'ip' => $request->getClientIp(),
        ]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getException();
        $passport = $event->getPassport();

        $data = [
            'security.event' => 'login_failure',
            'security.ip' => $request->getClientIp(),
            'security.firewall' => $this->getFirewallName($request),
            'security.failure_reason' => $exception->getMessage(),
            'security.exception_type' => get_class($exception),
        ];

        // Try to get attempted username
        if ($passport !== null) {
            try {
                $badge = $passport->getBadge('Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge');
                if ($badge !== null && method_exists($badge, 'getUserIdentifier')) {
                    $data['security.attempted_user'] = $badge->getUserIdentifier();
                }
            } catch (\Exception $e) {
                // Badge not found, skip
            }
        }

        $this->tracing->addBreadcrumb(
            'security',
            'Login attempt failed',
            $data,
            'warning'
        );

        // Create a span for failed login
        $span = $this->tracing->startSpan('security.login_failure', 'security');
        $span->setAttributes($data);
        $span->setStatus('error');
        $this->tracing->endSpan($span);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        $request = $event->getRequest();

        $this->tracing->addBreadcrumb(
            'security',
            'User logged out',
            [
                'security.event' => 'logout',
                'security.user' => $token !== null ? $this->getUserIdentifier($token) : 'unknown',
                'security.ip' => $request->getClientIp(),
                'security.firewall' => $this->getFirewallName($request),
            ],
            'info'
        );

        // Clear user context
        $this->tracing->clearUser();
    }

    public function onSwitchUser(SwitchUserEvent $event): void
    {
        $targetUser = $event->getTargetUser();
        $request = $event->getRequest();
        $originalToken = $event->getToken();

        $this->tracing->addBreadcrumb(
            'security',
            'User switched (impersonation)',
            [
                'security.event' => 'switch_user',
                'security.original_user' => $originalToken !== null ? $this->getUserIdentifier($originalToken) : 'unknown',
                'security.target_user' => $targetUser->getUserIdentifier(),
                'security.ip' => $request->getClientIp(),
            ],
            'warning'
        );
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $token = $event->getAuthenticationToken();

        // Only track if we have a real user (not anonymous)
        if ($token === null || empty($token->getRoleNames())) {
            return;
        }

        $this->tracing->addBreadcrumb(
            'security',
            'Authentication successful',
            [
                'security.event' => 'auth_success',
                'security.user' => $this->getUserIdentifier($token),
                'security.roles' => $token->getRoleNames(),
            ],
            'debug'
        );
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();

        if ($exception instanceof AccessDeniedException) {
            $this->tracing->addBreadcrumb(
                'security',
                'Access denied',
                [
                    'security.event' => 'access_denied',
                    'security.path' => $request->getPathInfo(),
                    'security.method' => $request->getMethod(),
                    'security.ip' => $request->getClientIp(),
                    'security.message' => $exception->getMessage(),
                    'security.attributes' => $exception->getAttributes(),
                ],
                'error'
            );
        } elseif ($exception instanceof AuthenticationException) {
            $this->tracing->addBreadcrumb(
                'security',
                'Authentication required',
                [
                    'security.event' => 'auth_required',
                    'security.path' => $request->getPathInfo(),
                    'security.ip' => $request->getClientIp(),
                    'security.message' => $exception->getMessage(),
                ],
                'warning'
            );
        }
    }

    private function getUserIdentifier(?TokenInterface $token): string
    {
        if ($token === null) {
            return 'anonymous';
        }

        try {
            return $token->getUserIdentifier();
        } catch (\Exception $e) {
            return 'unknown';
        }
    }

    private function getFirewallName(mixed $request): string
    {
        if (!method_exists($request, 'attributes')) {
            return 'unknown';
        }

        return $request->attributes->get('_firewall_context', 'unknown');
    }
}

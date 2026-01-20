<?php

declare(strict_types=1);

namespace Stacktracer\SymfonyBundle\EventSubscriber;

use Stacktracer\SymfonyBundle\Model\User;
use Stacktracer\SymfonyBundle\Service\TracingService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Automatically captures user context from Symfony Security.
 *
 * Listens to login events and request events to set the current user
 * in the tracing context, enabling user-scoped error tracking.
 *
 * @author Stacktracer <hello@stacktracer.io>
 */
final class LoginListener implements EventSubscriberInterface
{
    private TracingService $tracing;

    private ?TokenStorageInterface $tokenStorage;

    public function __construct(TracingService $tracing, ?TokenStorageInterface $tokenStorage = null)
    {
        $this->tracing = $tracing;
        $this->tokenStorage = $tokenStorage;
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];

        // LoginSuccessEvent was added in Symfony 5.1
        if (class_exists(LoginSuccessEvent::class)) {
            $events[LoginSuccessEvent::class] = ['onLoginSuccess', 0];
        }

        // AuthenticationSuccessEvent is available in older versions
        if (class_exists(AuthenticationSuccessEvent::class)) {
            $events[AuthenticationSuccessEvent::class] = ['onAuthenticationSuccess', 0];
        }

        return $events;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->tracing->isEnabled()) {
            return;
        }

        if ($this->tokenStorage === null) {
            return;
        }

        $this->setUserFromToken($this->tokenStorage->getToken());
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->tracing->isEnabled()) {
            return;
        }

        $this->setUserFromSymfonyUser($event->getUser());
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        if (!$this->tracing->isEnabled()) {
            return;
        }

        $this->setUserFromToken($event->getAuthenticationToken());
    }

    private function setUserFromToken(?TokenInterface $token): void
    {
        if ($token === null) {
            return;
        }

        $symfonyUser = $token->getUser();
        if (!$symfonyUser instanceof UserInterface) {
            return;
        }

        $user = $this->createUserFromSymfonyUser($symfonyUser);

        // Check for impersonation (SwitchUserToken)
        if ($token instanceof SwitchUserToken) {
            $originalToken = $token->getOriginalToken();
            $originalUser = $originalToken->getUser();
            if ($originalUser instanceof UserInterface) {
                $user->setData([
                    'impersonator' => $originalUser->getUserIdentifier(),
                ]);
            }
        }

        $this->tracing->setUser($user);
    }

    private function setUserFromSymfonyUser(UserInterface $symfonyUser): void
    {
        $user = $this->createUserFromSymfonyUser($symfonyUser);
        $this->tracing->setUser($user);
    }

    private function createUserFromSymfonyUser(UserInterface $symfonyUser): User
    {
        $user = new User();
        $user->setUsername($symfonyUser->getUserIdentifier());

        // Try to get ID if available
        if (method_exists($symfonyUser, 'getId')) {
            $id = $symfonyUser->getId();
            if ($id !== null) {
                $user->setId((string) $id);
            }
        }

        // Try to get email if available
        if (method_exists($symfonyUser, 'getEmail')) {
            $email = $symfonyUser->getEmail();
            if ($email !== null) {
                $user->setEmail($email);
            }
        }

        // Set roles
        $roles = $symfonyUser->getRoles();
        if (!empty($roles)) {
            $user->setData(['roles' => $roles]);
        }

        return $user;
    }
}

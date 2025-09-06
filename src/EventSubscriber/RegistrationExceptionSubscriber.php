<?php declare(strict_types=1);

namespace App\EventSubscriber;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class RegistrationExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité plus élevée pour intercepter avant d'autres listeners
            KernelEvents::EXCEPTION => ['onKernelException', 128],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $prev      = $throwable->getPrevious();

        $isUniqueViolation = $throwable instanceof UniqueConstraintViolationException
            || $prev instanceof UniqueConstraintViolationException;

        if (!$isUniqueViolation) {
            return;
        }

        $request = $event->getRequest();
        $route   = (string) $request->attributes->get('_route', '');
        $path    = $request->getPathInfo();

        // Cible l'inscription de manière plus souple
        $isRegisterContext = str_contains($route, 'register') || str_starts_with($path, '/register');
        if (!$isRegisterContext) {
            return;
        }

        // Message utilisateur
        if ($session = $this->requestStack->getSession()) {
            $session->getFlashBag()->add('warning', 'Cet email est déjà utilisé. Veuillez en choisir un autre.');
        }

        // Redirection vers le formulaire d'inscription
        $event->setResponse(new RedirectResponse(
            $route ? $this->urlGenerator->generate($route) : '/register'
        ));
    }
}

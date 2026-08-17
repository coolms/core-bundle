<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Controller;

use CoolMS\Core\Template\TemplateRendererInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController as SymfonyAbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * Core Abstract Controller.
 *
 * Extends Symfony's AbstractController with format-aware response helpers that
 * cover JSON, XML, Inertia.js, and HTML (via VFS dTMPL templates with redirect fallback).
 *
 * Dependencies are injected via #[Required] setters so that concrete subclasses
 * are not forced to declare or forward these arguments in their own constructors.
 */
abstract class AbstractController extends SymfonyAbstractController
{
    private SerializerInterface $serializer;
    private NormalizerInterface $normalizer;
    private ?TemplateRendererInterface $templateRenderer = null;

    /**
     * Symfony calls this automatically on any concrete subclass (autoconfigure: true).
     */
    #[Required]
    public function setSerializer(SerializerInterface $serializer): void
    {
        $this->serializer = $serializer;
    }

    #[Required]
    public function setNormalizer(NormalizerInterface $normalizer): void
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Injected when a module provides a renderer (VFS aliases the interface to its
     * dTMPL handler). Symfony passes null if none is registered, keeping the system
     * functional without a template engine -- respondHtml() then redirects instead.
     */
    #[Required]
    public function setTemplateRenderer(?TemplateRendererInterface $templateRenderer): void
    {
        $this->templateRenderer = $templateRenderer;
    }

    /**
     * Format-aware success response.
     *
     * Priority:
     *   1. X-Inertia header -- JSON (Inertia.js sends Accept:text/html but expects JSON)
     *   2. Accept: text/html -- try VFS dTMPL render, fallback 303 redirect
     *   3. Accept: application/xml -- XML
     *   4. Default -- JSON
     *
     * @param object|array<string, mixed> $data         The DTO or plain array to serialize / pass as context
     * @param int                         $status       HTTP status code
     * @param Request                     $request      Current request (used for format + Inertia detection)
     * @param string                      $viewPath     VFS path (without .dtmpl extension) of the template to render for HTML clients
     * @param string                      $redirectPath Fallback redirect URL when VFS is unavailable
     *
     * @throws ExceptionInterface
     */
    protected function respond(
        object|array $data,
        int $status,
        Request $request,
        string $viewPath,
        string $redirectPath = '/',
    ): Response {
        if ($request->headers->has('X-Inertia')) {
            return new JsonResponse(
                $this->serializer->serialize($data, 'json'),
                $status,
                ['X-Inertia' => 'true'],
                true,
            );
        }

        return match ($request->getPreferredFormat('json')) {
            'html' => $this->respondHtml($data, $viewPath, $redirectPath),
            'xml' => new Response(
                $this->serializer->serialize($data, 'xml'),
                $status,
                ['Content-Type' => 'application/xml'],
            ),
            default => new JsonResponse(
                $this->serializer->serialize($data, 'json'),
                $status,
                [],
                true,
            ),
        };
    }

    /**
     * Format-aware error response.
     *
     * For HTML clients (non-Inertia): stores a message as an 'error' flash and redirects.
     * For all other clients: returns a JSON body with an 'error' key.
     */
    protected function respondError(
        string $message,
        int $status,
        Request $request,
        string $redirectPath = '/',
    ): Response {
        if (!$request->headers->has('X-Inertia') && 'html' === $request->getPreferredFormat('json')) {
            $this->addFlash('error', $message);

            return new RedirectResponse($redirectPath, Response::HTTP_SEE_OTHER);
        }

        return $this->json(['error' => $message], $status);
    }

    /**
     * Attempt a template render; fall back to a redirect when unavailable.
     *
     * The renderer resolves the acting user from the security context itself, so
     * nothing about the user is passed here. Any exception (storage unavailable,
     * path not found, template error) is silently swallowed and the redirect
     * fallback is used instead.
     *
     * @param object|array<string, mixed> $data
     */
    private function respondHtml(object|array $data, string $viewPath, string $redirectPath): Response
    {
        if (null !== $this->templateRenderer) {
            try {
                $context = is_array($data) ? $data : ['data' => $this->normalizer->normalize($data)];
                $html = $this->templateRenderer->render($viewPath . '.dtmpl', $context);

                return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
            } catch (Throwable) {
                // Renderer unavailable, path not found, or template error -- fall through to redirect
            }
        }

        return new RedirectResponse($redirectPath, Response::HTTP_SEE_OTHER);
    }
}

<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $path = rtrim($request->getPathInfo(), '/');
        $path = $path === '' ? '/' : $path;

        if (str_starts_with($path, '/api/')) {
            return new JsonResponse([
                'error' => 'Authentication required',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse('/login?next=' . rawurlencode($request->getRequestUri()));
    }
}
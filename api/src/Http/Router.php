<?php

declare(strict_types=1);

namespace Muninn\Api\Http;

/**
 * Minimal method + path router.
 *
 * Patterns use {name} placeholders that match one path segment, e.g.
 * "/api/v1/admin/invitations/{id}". Each route carries an access level that the
 * Application enforces before the handler runs, so no handler can forget it.
 */
final class Router
{
    /** Anyone may call the route. */
    public const ACCESS_PUBLIC = 'public';
    /** A signed-in user is required. */
    public const ACCESS_USER = 'user';
    /** A signed-in system administrator is required; others get 404. */
    public const ACCESS_SYSTEM_ADMIN = 'system_admin';
    /** A browser that opened a Magic Link (the visit cookie) is required; sign-in does not count (D059). */
    public const ACCESS_MAGIC_LINK = 'magic_link';

    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, access: string}> */
    private array $routes = [];

    /**
     * Registers a route.
     *
     * @param callable(Request, RequestContext): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler, string $access): void
    {
        // Deny by default: a typo in an access level must fail loudly at startup, never leave a
        // route without its checks.
        if (!in_array($access, [self::ACCESS_PUBLIC, self::ACCESS_USER, self::ACCESS_SYSTEM_ADMIN, self::ACCESS_MAGIC_LINK], true)) {
            throw new \InvalidArgumentException('Unknown access level for ' . $pattern . ': ' . $access);
        }

        // Turn "{id}" into a named capture group that matches one path segment.
        $patternRegex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => '#^' . $patternRegex . '$#',
            'handler' => $handler,
            'access' => $access,
        ];
    }

    /**
     * Finds the route for a request.
     *
     * @return array{handler: callable, access: string, parameters: array<string, string>}
     * @throws HttpException 404 when no pattern matches, 405 when only the method is wrong.
     */
    public function match(string $method, string $path): array
    {
        $allowedMethodsForPath = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $patternMatches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowedMethodsForPath[] = $route['method'];
                continue;
            }

            // Keep only the named captures ({id} etc.), not the numeric ones.
            $routeParameters = array_filter($patternMatches, 'is_string', ARRAY_FILTER_USE_KEY);

            return [
                'handler' => $route['handler'],
                'access' => $route['access'],
                'parameters' => array_map('rawurldecode', $routeParameters),
            ];
        }

        if ($allowedMethodsForPath !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowedMethodsForPath)));
        }

        throw HttpException::notFound();
    }

    /** True when some route exists for this path with any method (used for CORS preflight). */
    public function hasPath(string $path): bool
    {
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path)) {
                return true;
            }
        }

        return false;
    }
}

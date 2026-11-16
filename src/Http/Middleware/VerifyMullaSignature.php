<?php

declare(strict_types=1);

namespace Mulla\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mulla\Exceptions\SignatureVerificationException;
use Mulla\Laravel\Exceptions\MullaConfigurationException;
use Mulla\Webhooks\Event;
use Mulla\Webhooks\Webhook;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the Mulla signature against the raw request body and attaches the parsed
 * event to the request. Requests that fail verification never reach your code.
 *
 * Can be used on your own routes too: Route::post('/hooks/mulla', ...)->middleware(VerifyMullaSignature::class).
 */
final class VerifyMullaSignature
{
    /** Request attribute holding the verified Mulla\Webhooks\Event. */
    public const ATTRIBUTE = 'mulla_event';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $secret = $this->config->get('mulla.webhook.secret');
        if (!is_string($secret) || trim($secret) === '') {
            // Never accept unverified events: fail loudly so Mulla retries once it is fixed.
            $this->logger->error(MullaConfigurationException::missingWebhookSecret()->getMessage());

            return new JsonResponse(['message' => 'Webhook secret is not configured.'], 500);
        }

        try {
            $event = (new Webhook(trim($secret), (int) $this->config->get('mulla.webhook.tolerance', 300)))
                ->parse($request->getContent(), self::headers($request));
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('Mulla webhook rejected: ' . $e->getMessage(), ['ip' => $request->ip()]);

            return new JsonResponse(['message' => 'Invalid Mulla signature.'], 400);
        }

        $request->attributes->set(self::ATTRIBUTE, $event);

        return $next($request);
    }

    /** The verified event attached by this middleware. */
    public static function event(Request $request): ?Event
    {
        $event = $request->attributes->get(self::ATTRIBUTE);

        return $event instanceof Event ? $event : null;
    }

    /** @return array<string, string> */
    private static function headers(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $value = is_array($values) ? reset($values) : $values;
            if (is_string($value)) {
                $headers[strtolower((string) $name)] = $value;
            }
        }

        return $headers;
    }
}

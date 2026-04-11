<?php
/**
 * Minimal autoloader + Laminas shims for standalone testing without VuFind.
 */

// --- Laminas shims (just enough to satisfy HubClient type hints) ---

namespace Laminas\Log {
    interface LoggerAwareInterface {
        public function setLogger(\Laminas\Log\LoggerInterface $logger);
    }
    interface LoggerInterface {}
    trait LoggerAwareTrait {
        protected $logger;
        public function setLogger(LoggerInterface $logger) {
            $this->logger = $logger;
        }
    }
}

namespace Laminas\Http {
    class Client {
        public function reset(): void {}
        public function setUri(string $uri): void {}
        public function setOptions(array $options): void {}
        public function setHeaders(array $headers): void {}
        public function send(): object { return new \stdClass(); }
    }
}

namespace {

spl_autoload_register(function ($class) {
    // BibframeHub namespace
    if (str_starts_with($class, 'BibframeHub\\')) {
        $path = __DIR__ . '/../module/BibframeHub/src/'
            . str_replace('\\', '/', $class) . '.php';
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

/**
 * Curl-based HTTP client extending the Laminas\Http\Client shim
 * so it passes the type hint in HubClient::__construct().
 */
class SimpleHttpClient extends \Laminas\Http\Client
{
    private string $uri = '';
    private array $options = [];
    private array $headers = [];

    public function reset(): void
    {
        $this->uri = '';
        $this->options = [];
        $this->headers = [];
    }

    public function setUri(string $uri): void
    {
        $this->uri = $uri;
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    public function send(): SimpleHttpResponse
    {
        $ch = curl_init($this->uri);

        $curlHeaders = [];
        foreach ($this->headers as $name => $value) {
            $curlHeaders[] = "$name: $value";
        }

        $maxRedirects = $this->options['maxredirects'] ?? 5;
        $timeout = $this->options['timeout'] ?? 10;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
            CURLOPT_MAXREDIRS => $maxRedirects,
            CURLOPT_HEADER => true,
        ]);

        if ($maxRedirects === 0) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        }

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed: $error");
        }

        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return new SimpleHttpResponse($httpCode, $body, $rawHeaders);
    }
}

class SimpleHttpResponse
{
    private int $statusCode;
    private string $body;
    private array $headers;

    public function __construct(int $statusCode, string $body, string $rawHeaders)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = [];

        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $this->headers[strtolower(trim($name))] = trim($value);
            }
        }
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function getHeaders(): SimpleHeaderBag
    {
        return new SimpleHeaderBag($this->headers);
    }
}

class SimpleHeaderBag
{
    private array $headers;

    public function __construct(array $headers)
    {
        $this->headers = $headers;
    }

    public function get(string $name): ?SimpleHeader
    {
        $key = strtolower($name);
        if (isset($this->headers[$key])) {
            return new SimpleHeader($this->headers[$key]);
        }
        return null;
    }
}

class SimpleHeader
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getFieldValue(): string
    {
        return $this->value;
    }
}

} // end namespace

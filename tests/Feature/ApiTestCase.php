<?php
/**
 * Base TestCase for API Feature tests
 */

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    /**
     * Create a mock PSR-7 request
     */
    protected function createMockRequest(
        string $method = 'GET',
        array $queryParams = [],
        ?array $parsedBody = null,
        array $attributes = []
    ): object {
        return new class($method, $queryParams, $parsedBody, $attributes) {
            private string $method;
            private array $queryParams;
            private ?array $parsedBody;
            private array $attributes;

            public function __construct(string $method, array $queryParams, ?array $parsedBody, array $attributes)
            {
                $this->method = $method;
                $this->queryParams = $queryParams;
                $this->parsedBody = $parsedBody;
                $this->attributes = $attributes;
            }

            public function getMethod(): string
            {
                return $this->method;
            }

            public function getQueryParams(): array
            {
                return $this->queryParams;
            }

            public function getParsedBody(): ?array
            {
                return $this->parsedBody;
            }

            public function getAttribute(string $name, $default = null)
            {
                return $this->attributes[$name] ?? $default;
            }
        };
    }

    /**
     * Create a mock PSR-7 response
     */
    protected function createMockResponse(): object
    {
        return new class {
            private string $body = '';
            private array $headers = [];
            private int $status = 200;

            public function getBody(): object
            {
                $body = &$this->body;
                return new class($body) {
                    private string $body;

                    public function __construct(string &$body)
                    {
                        $this->body = &$body;
                    }

                    public function write(string $data): int
                    {
                        $this->body .= $data;
                        return strlen($data);
                    }

                    public function __toString(): string
                    {
                        return $this->body;
                    }
                };
            }

            public function withHeader(string $name, string $value): self
            {
                $clone = clone $this;
                $clone->headers[$name] = $value;
                return $clone;
            }

            public function withStatus(int $code): self
            {
                $clone = clone $this;
                $clone->status = $code;
                return $clone;
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function getHeader(string $name): array
            {
                return isset($this->headers[$name]) ? [$this->headers[$name]] : [];
            }

            public function getBodyContents(): string
            {
                return $this->body;
            }
        };
    }

    /**
     * Assert that response is JSON
     */
    protected function assertJsonResponse(object $response): array
    {
        $content = $response->getBodyContents();
        $data = json_decode($content, true);

        $this->assertNotNull($data, "Response is not valid JSON: {$content}");
        return $data;
    }

    /**
     * Assert response has success structure
     */
    protected function assertSuccessResponse(array $data): void
    {
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
    }

    /**
     * Assert response has error structure
     */
    protected function assertErrorResponse(array $data): void
    {
        $this->assertTrue(
            isset($data['error']) || (isset($data['success']) && $data['success'] === false),
            'Response should be an error'
        );
    }
}

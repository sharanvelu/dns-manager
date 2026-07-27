<?php

declare(strict_types = 1);

namespace App\Connectors\DTOs;

final readonly class TestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
        /** Extra details, e.g. ['zone' => 'example.com', 'version' => 'v6.1'] */
        public array $details = [],
    ) {
    }

    public static function success(string $message, array $details = []): self
    {
        return new self(true, $message, $details);
    }

    public static function failure(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}

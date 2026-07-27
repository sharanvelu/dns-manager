<?php

declare(strict_types = 1);

namespace App\Connectors\DTOs;

/**
 * Declarative form-field definition used by the Providers UI to render a
 * connector's configuration form.
 */
final readonly class ConfigField
{
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text', // text | password | url | boolean
        public bool $secret = false,
        public bool $required = true,
        public ?string $help = null,
        public mixed $default = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'secret' => $this->secret,
            'required' => $this->required,
            'help' => $this->help,
            'default' => $this->default,
        ];
    }
}

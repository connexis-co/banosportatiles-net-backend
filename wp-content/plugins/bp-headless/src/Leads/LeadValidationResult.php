<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

final readonly class LeadValidationResult
{
    /**
     * @param  array<string, string>  $errors  field => message
     * @param  list<string>  $ignored  attribution fields dropped because of their format (never an error)
     */
    public function __construct(public ?LeadData $lead, public array $errors, public array $ignored = []) {}

    public function isValid(): bool
    {
        return $this->lead !== null && $this->errors === [];
    }
}

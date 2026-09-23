<?php

declare(strict_types=1);

namespace BanosPortatiles\Headless\Leads;

final readonly class LeadValidationResult
{
    /**
     * @param  array<string, string>  $errors  field => message
     */
    public function __construct(public ?LeadData $lead, public array $errors) {}

    public function isValid(): bool
    {
        return $this->lead !== null && $this->errors === [];
    }
}

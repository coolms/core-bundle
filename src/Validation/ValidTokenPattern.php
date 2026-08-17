<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Validation;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Validates that a string is a valid token pattern.
 *
 * A token pattern may contain:
 *  - Any characters valid in filenames
 *  - {const:name} placeholders where 'name' is in the optional allowedTokens list
 *
 * Usage:
 *   #[ValidTokenPattern(allowedTokens: ['counter', 'date', 'datetime', 'random'])]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class ValidTokenPattern extends Constraint
{
    public string $message = 'Token "{{ token }}" is not allowed.';
    public string $invalidChars = 'Pattern contains invalid filename characters.';
    public string $allowedMessage = 'Allowed tokens: {{ allowed }}.';

    /** @param string[] $allowedTokens */
    public function __construct(
        public readonly array $allowedTokens = [],
        mixed $options = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct($options, $groups, $payload);
    }
}

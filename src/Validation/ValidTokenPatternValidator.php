<?php

declare(strict_types=1);

namespace CoolMS\CoreBundle\Validation;

use CoolMS\CoreModule\Service\PatternRenderer;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidTokenPatternValidator extends ConstraintValidator
{
    public function __construct(private readonly PatternRenderer $renderer)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidTokenPattern) {
            throw new UnexpectedTypeException($constraint, ValidTokenPattern::class);
        }

        if (!is_string($value) || '' === $value) {
            return;
        }

        // Check for invalid filename characters (strip {const:*} placeholders first)
        $withoutTokens = (string) preg_replace('/\{const:[^}]+\}/', '', $value);
        if (preg_match('/[<>:"\/\\\\|?*\x00-\x1f]/', $withoutTokens)) {
            $this->context->buildViolation($constraint->invalidChars)->addViolation();
        }

        // Check all tokens are in the allowed list (skip check when list is empty)
        if ([] === $constraint->allowedTokens) {
            return;
        }

        $used = $this->renderer->extractTokens($value);
        foreach ($used as $token) {
            if (!in_array($token, $constraint->allowedTokens, true)) {
                $this->context
                    ->buildViolation($constraint->message)
                    ->setParameter('{{ token }}', $token)
                    ->setParameter('{{ allowed }}', implode(', ', $constraint->allowedTokens))
                    ->addViolation();
            }
        }
    }
}

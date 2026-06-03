<?php

namespace App\Lifecycle;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Typed exception for state-machine refusals — gives the HTTP exception
 * handler a clean target to map to 422 with a helpful message, instead
 * of leaking generic InvalidArgumentException as 500.
 *
 * Extends InvalidArgumentException so callers that already catch the
 * generic shape (and existing tests) keep working.
 */
class InvalidTransition extends InvalidArgumentException
{
    public function __construct(
        public readonly Model $model,
        public readonly BackedEnum|string $from,
        public readonly BackedEnum|string $to,
        public readonly ?string $reason = null,
    ) {
        $modelName = class_basename($model);
        $fromName = $from instanceof BackedEnum ? (string) $from->value : $from;
        $toName = $to instanceof BackedEnum ? (string) $to->value : $to;
        $suffix = $reason ? " — {$reason}" : '';

        parent::__construct("{$modelName} cannot transition from '{$fromName}' to '{$toName}'{$suffix}.");
    }
}

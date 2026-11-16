<?php
declare(strict_types=1);

namespace App\Flags;

use Closure;
use InvalidArgumentException;

/**
 * Turns a flag's JSON targeting rules into a closure.
 *
 * Rule format:
 *
 * ```
 * {"rollout": 25, "segments": [{"attr": "country", "op": "in", "values": ["PL", "DE"]}]}
 * ```
 *
 * A flag is on when it is enabled, every segment matches and the caller falls inside the rollout.
 * All parsing and validation happens in compile(); the returned closure only compares values.
 */
class RuleCompiler
{
    /**
     * @var list<string>
     */
    public const ATTRIBUTES = ['user', 'country', 'plan'];

    /**
     * Validate a rules value.
     *
     * @param mixed $rules Rules as decoded from JSON, or null.
     * @return list<string> Error messages, empty when the rules are valid.
     */
    public function validate(mixed $rules): array
    {
        if ($rules === null) {
            return [];
        }
        if (!is_array($rules) || ($rules !== [] && array_is_list($rules))) {
            return ['Rules must be an object.'];
        }

        $errors = [];
        foreach (array_keys($rules) as $key) {
            if (!in_array($key, ['rollout', 'segments'], true)) {
                $errors[] = sprintf('Unknown rules key "%s".', $key);
            }
        }
        if (array_key_exists('rollout', $rules)) {
            $rollout = $rules['rollout'];
            if (!is_int($rollout) || $rollout < 0 || $rollout > 100) {
                $errors[] = 'Rollout must be an integer from 0 to 100.';
            }
        }
        if (array_key_exists('segments', $rules)) {
            $segments = $rules['segments'];
            if (!is_array($segments) || !array_is_list($segments)) {
                $errors[] = 'Segments must be a list.';
            } else {
                foreach ($segments as $i => $segment) {
                    $errors = array_merge($errors, $this->validateSegment($i, $segment));
                }
            }
        }

        return $errors;
    }

    /**
     * Compile a flag into a closure `fn(array $context): bool`.
     *
     * @param string $flagCode Flag code, part of the rollout bucket.
     * @param bool $enabled Whether the flag is enabled.
     * @param array<string, mixed>|null $rules Targeting rules, null for everyone.
     * @return \Closure(array<string, mixed>): bool
     * @throws \InvalidArgumentException When the rules are invalid.
     */
    public function compile(string $flagCode, bool $enabled, ?array $rules): Closure
    {
        $errors = $this->validate($rules);
        if ($errors !== []) {
            throw new InvalidArgumentException(
                sprintf('Invalid rules for flag "%s": %s', $flagCode, implode(' ', $errors)),
            );
        }
        if (!$enabled) {
            return static fn(array $context): bool => false;
        }

        $checks = [];
        foreach ($rules['segments'] ?? [] as $segment) {
            $checks[] = $this->compileSegment($segment);
        }
        $rollout = $rules['rollout'] ?? 100;

        return static function (array $context) use ($checks, $rollout, $flagCode): bool {
            foreach ($checks as $check) {
                if (!$check($context)) {
                    return false;
                }
            }
            if ($rollout >= 100) {
                return true;
            }
            $user = $context['user'] ?? null;
            if (!is_string($user)) {
                return false;
            }

            return crc32($flagCode . ':' . $user) % 100 < $rollout;
        };
    }

    /**
     * @param int $index Segment position, for messages.
     * @param mixed $segment Segment value.
     * @return list<string>
     */
    private function validateSegment(int $index, mixed $segment): array
    {
        if (!is_array($segment)) {
            return [sprintf('Segment %d must be an object.', $index)];
        }
        $attr = $segment['attr'] ?? null;
        $op = $segment['op'] ?? null;
        if (!in_array($attr, self::ATTRIBUTES, true)) {
            return [sprintf('Segment %d has an unknown attribute.', $index)];
        }
        $values = $segment['values'] ?? null;
        [$allowed, $valid] = match ($op) {
            'eq', 'neq' => [['attr', 'op', 'value'], is_string($segment['value'] ?? null)],
            'in', 'not_in' => [
                ['attr', 'op', 'values'],
                is_array($values) && $values !== [] && array_is_list($values)
                    && array_filter($values, 'is_string') === $values,
            ],
            default => [null, false],
        };
        if ($allowed === null) {
            return [sprintf('Segment %d has an unknown op.', $index)];
        }
        if (array_diff(array_keys($segment), $allowed) !== []) {
            return [sprintf('Segment %d has unknown keys.', $index)];
        }

        return $valid ? [] : [sprintf('Segment %d has an invalid value for op "%s".', $index, $op)];
    }

    /**
     * @param array<string, mixed> $segment Validated segment.
     * @return \Closure(array<string, mixed>): bool
     */
    private function compileSegment(array $segment): Closure
    {
        $attr = $segment['attr'];
        $values = $segment['values'] ?? [$segment['value']];
        // neq and not_in are the negation of eq and in, so a missing attribute matches them.
        $negate = in_array($segment['op'], ['neq', 'not_in'], true);

        return static function (array $context) use ($attr, $values, $negate): bool {
            $value = $context[$attr] ?? null;
            $found = is_string($value) && in_array($value, $values, true);

            return $negate ? !$found : $found;
        };
    }
}

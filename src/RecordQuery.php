<?php

declare(strict_types=1);

namespace Storh;

final class RecordQuery
{
    private ?string $after_id = null;

    private ?int $from_ms = null;

    private ?int $until_ms = null;

    private ?int $limit = null;

    /** @var array<string, scalar|null> */
    private array $field_equals = array();

    /** @var null|callable(string, \Throwable): void */
    private mixed $error_handler = null;

    /** @var null|callable(string): void */
    private mixed $segment_open_handler = null;

    public static function all(): self
    {
        return new self();
    }

    public function after(string $id): self
    {
        UuidV7::assert_valid($id);
        $next           = clone $this;
        $next->after_id = $id;

        return $next;
    }

    public function time_range_ms(?int $from_ms, ?int $until_ms): self
    {
        $next           = clone $this;
        $next->from_ms  = $from_ms;
        $next->until_ms = $until_ms;

        return $next;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new StorageException('Record query limit must be at least 1.');
        }

        $next        = clone $this;
        $next->limit = $limit;

        return $next;
    }

    public function where_equal(string $field, mixed $value): self
    {
        if ('' === trim($field)) {
            throw new StorageException('Record query field cannot be empty.');
        }

        if (null !== $value && ! is_scalar($value)) {
            throw new StorageException('Record query value must be scalar or null.');
        }

        $next                         = clone $this;
        $next->field_equals[ $field ] = $value;

        return $next;
    }

    public function continue_on_error(callable $handler): self
    {
        $next                = clone $this;
        $next->error_handler = $handler;

        return $next;
    }

    public function on_segment_open(callable $handler): self
    {
        $next                       = clone $this;
        $next->segment_open_handler = $handler;

        return $next;
    }

    public function after_id(): ?string
    {
        return $this->after_id;
    }

    public function lower_id(): ?string
    {
        return null === $this->from_ms ? null : UuidV7::min_for_timestamp_ms($this->from_ms);
    }

    public function upper_id(): ?string
    {
        return null === $this->until_ms ? null : UuidV7::max_for_timestamp_ms($this->until_ms);
    }

    public function limit_value(): ?int
    {
        return $this->limit;
    }

    public function matches(StorageRecord $record): bool
    {
        $id = $record->id();

        if (null !== $this->after_id && strcmp($id, $this->after_id) <= 0) {
            return false;
        }

        $lower = $this->lower_id();
        if (null !== $lower && strcmp($id, $lower) < 0) {
            return false;
        }

        $upper = $this->upper_id();
        if (null !== $upper && strcmp($id, $upper) > 0) {
            return false;
        }

        $data = $record->data();
        foreach ($this->field_equals as $field => $value) {
            if (! array_key_exists($field, $data) || $data[ $field ] !== $value) {
                return false;
            }
        }

        return true;
    }

    public function handle_error(string $id, \Throwable $throwable): bool
    {
        if (null === $this->error_handler) {
            return false;
        }

        ( $this->error_handler )($id, $throwable);

        return true;
    }

    public function notify_segment_open(string $segment): void
    {
        if (null !== $this->segment_open_handler) {
            ( $this->segment_open_handler )($segment);
        }
    }
}

<?php

declare(strict_types=1);

namespace Storh;

final class QueryBuilder
{
    /** @var list<list<QueryCondition>> */
    private array $groups = array();

    private ?string $order_field = null;

    private string $order_direction = 'asc';

    private ?int $limit = null;

    private ?string $cursor = null;

    public function __construct(private readonly FileStoreInterface $store)
    {
        $this->groups = array(array());
    }

    public function where(string $field): QueryFieldBuilder
    {
        return new QueryFieldBuilder($this, $field);
    }

    public function add_condition(QueryCondition $condition): self
    {
        $next = clone $this;
        foreach ($next->groups as $index => $group) {
            $next->groups[ $index ][] = $condition;
        }

        return $next;
    }

    public function andWhere(callable $callback): self
    {
        $branch = $callback(new self($this->store));
        if (! $branch instanceof self) {
            throw new StorageException('andWhere callback must return a QueryBuilder.');
        }

        $next = clone $this;
        foreach ($branch->flat_conditions() as $condition) {
            foreach ($next->groups as $index => $group) {
                $next->groups[ $index ][] = $condition;
            }
        }

        return $next;
    }

    public function orWhere(callable $callback): self
    {
        $branch = $callback(new self($this->store));
        if (! $branch instanceof self) {
            throw new StorageException('orWhere callback must return a QueryBuilder.');
        }

        $next = clone $this;
        foreach ($branch->groups as $group) {
            $next->groups[] = $group;
        }

        return $next;
    }

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (! in_array($direction, array( 'asc', 'desc' ), true)) {
            throw new StorageException('Query order direction must be asc or desc.');
        }

        $next                  = clone $this;
        $next->order_field     = $field;
        $next->order_direction = $direction;

        return $next;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new StorageException('Query limit must be at least 1.');
        }

        $next        = clone $this;
        $next->limit = $limit;

        return $next;
    }

    public function cursor(string $id): self
    {
        UuidV7::assert_valid($id);
        $next         = clone $this;
        $next->cursor = $id;

        return $next;
    }

    public function page(int $size): self
    {
        return $this->limit($size);
    }

    /**
     * @return list<StorageRecord>
     */
    public function get(): array
    {
        $records = $this->candidate_records();

        if (null !== $this->order_field) {
            usort(
                $records,
                function (StorageRecord $left, StorageRecord $right): int {
                    $result = QueryCondition::compare($this->order_value($left), $this->order_value($right));

                    return 'desc' === $this->order_direction ? -$result : $result;
                }
            );
        }

        if (null !== $this->limit) {
            $records = array_slice($records, 0, $this->limit);
        }

        return array_values($records);
    }

    public function first(): ?StorageRecord
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function count(): int
    {
        if ($this->store instanceof DocPerFileStore) {
            return $this->store->count_records($this);
        }

        if ($this->store instanceof SegmentedLogStore) {
            return $this->store->count_records($this);
        }

        $count = 0;
        foreach ($this->store->stream(null) as $record) {
            if (! $this->matches($record)) {
                continue;
            }

            $count++;
            if (null !== $this->limit && $count >= $this->limit) {
                return $count;
            }
        }

        return $count;
    }

    /**
     * @return array{store: string, plan: string, indexes: list<array<string, mixed>>, groups: int}
     */
    public function explain(): array
    {
        if ($this->store instanceof DocPerFileStore) {
            return $this->store->explain($this);
        }

        return array(
            'store'   => $this->store::class,
            'plan'    => 'full_scan',
            'indexes' => array(),
            'groups'  => count($this->groups),
        );
    }

    /**
     * @return list<list<QueryCondition>>
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function cursor_id(): ?string
    {
        return $this->cursor;
    }

    public function limit_value(): ?int
    {
        return $this->limit;
    }

    public function has_ordering(): bool
    {
        return null !== $this->order_field;
    }

    /**
     * @return array{field: string, value: mixed}|null
     */
    public function simple_equal_filter(): ?array
    {
        if (null !== $this->cursor || 1 !== count($this->groups) || 1 !== count($this->groups[0])) {
            return null;
        }

        $condition = $this->groups[0][0];
        if ('eq' !== $condition->operator()) {
            return null;
        }

        return array(
            'field' => $condition->field(),
            'value' => $condition->value(),
        );
    }

    public function matches(StorageRecord $record): bool
    {
        return $this->matches_data($record->id(), $record->data());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function matches_data(string $id, array $data): bool
    {
        if (null !== $this->cursor && strcmp($id, $this->cursor) <= 0) {
            return false;
        }

        foreach ($this->groups as $group) {
            $matches = true;
            foreach ($group as $condition) {
                if (! $condition->matches_data($id, $data)) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<QueryCondition>
     */
    private function flat_conditions(): array
    {
        $conditions = array();
        foreach ($this->groups as $group) {
            foreach ($group as $condition) {
                $conditions[] = $condition;
            }
        }

        return $conditions;
    }

    /**
     * @return list<StorageRecord>
     */
    private function candidate_records(): array
    {
        $id_records = $this->direct_id_records();
        if (null !== $id_records) {
            return $id_records;
        }

        if ($this->store instanceof DocPerFileStore) {
            return $this->store->query_records($this);
        }

        $record_query = $this->record_query();
        if (null !== $record_query) {
            return iterator_to_array($this->store->stream($record_query), false);
        }

        $records = array();
        $limit = $this->limit;
        $can_stop_early = null !== $limit && null === $this->order_field;
        $matched = 0;
        foreach ($this->store->stream(null) as $record) {
            if ($this->matches($record)) {
                $records[] = $record;
                $matched++;
                if ($can_stop_early && $matched >= $limit) {
                    break;
                }
            }
        }

        return $records;
    }

    /**
     * @return list<StorageRecord>|null
     */
    private function direct_id_records(): ?array
    {
        if (1 !== count($this->groups) || 1 !== count($this->groups[0])) {
            return null;
        }

        $condition = $this->groups[0][0];
        if ('id' !== $condition->field() || 'eq' !== $condition->operator()) {
            return null;
        }

        $id = $condition->value();
        if (! is_string($id) || ! UuidV7::is_valid($id)) {
            return array();
        }

        if (null !== $this->cursor && strcmp($id, $this->cursor) <= 0) {
            return array();
        }

        $record = $this->store->get($id);
        if (null === $record || ! $this->matches($record)) {
            return array();
        }

        return array($record);
    }

    private function record_query(): ?RecordQuery
    {
        if (null !== $this->order_field || 1 !== count($this->groups)) {
            return null;
        }

        $query = RecordQuery::all();
        if (null !== $this->cursor) {
            $query = $query->after($this->cursor);
        }

        if (null !== $this->limit) {
            $query = $query->limit($this->limit);
        }

        $seen_fields = array();
        foreach ($this->groups[0] as $condition) {
            if ('eq' !== $condition->operator() || 'id' === $condition->field()) {
                return null;
            }

            $value = $condition->value();
            if (null !== $value && ! is_scalar($value)) {
                return null;
            }

            $field = $condition->field();
            if (array_key_exists($field, $seen_fields)) {
                if ($seen_fields[ $field ] !== $value) {
                    return null;
                }

                continue;
            }

            $seen_fields[ $field ] = $value;
            $query = $query->where_equal($field, $value);
        }

        return $query;
    }

    private function order_value(StorageRecord $record): mixed
    {
        if ('id' === $this->order_field) {
            return $record->id();
        }

        $data = $record->data();

        return is_string($this->order_field) && array_key_exists($this->order_field, $data)
            ? $data[ $this->order_field ]
            : null;
    }
}

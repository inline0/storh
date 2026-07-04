<?php

declare(strict_types=1);

namespace Storh\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Storh\Cache;
use Storh\DocPerFileStore;
use Storh\Jsonc;
use Storh\LogQueue;
use Storh\QueryBuilder;
use Storh\RecordQuery;
use Storh\SegmentedLogStore;
use Storh\StorageRecord;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class PropertyStorageTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-property-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_doc_store_random_operations_match_reference_scan_indexes_and_queries(): void
    {
        $ids = $this->fixed_ids(36, 1_700_100_000_000);
        $store = $this->new_indexed_doc_store('doc-fuzz');
        $reference = array();
        $seed = 7331;

        for ($step = 0; $step < 140; $step++) {
            $slot = $this->next_int($seed, count($ids));
            $action = $this->next_int($seed, 100);

            if ($action < 66) {
                $data = $this->doc_data($slot, $step);
                $store->put($data, $ids[ $slot ]);
                $reference[ $ids[ $slot ] ] = $data;
            } elseif ($action < 84) {
                $store->delete($ids[ $slot ]);
                unset($reference[ $ids[ $slot ] ]);
            } elseif ($action < 91) {
                $store = $this->new_indexed_doc_store('doc-fuzz');
            } elseif ($action < 96) {
                $this->assertSame(4, $store->reindex()['fields']);
            } else {
                $this->assertTrue($store->compact()['ok']);
            }

            if (0 === $step % 7) {
                $this->assert_doc_store_matches_reference($store, $reference, $ids);
            }
        }

        $store = $this->new_indexed_doc_store('doc-fuzz');
        $this->assert_doc_store_matches_reference($store, $reference, $ids);
        $this->assertSame(4, $store->reindex()['fields']);
        $this->assert_doc_store_matches_reference($store, $reference, $ids);
    }

    public function test_segmented_log_random_operations_match_reference_after_compaction_and_reopen(): void
    {
        $ids = $this->fixed_ids(40, 1_700_200_000_000);
        $store = new SegmentedLogStore($this->root, 'log-fuzz', 512, 4, cache: Cache::memory(256));
        $reference = array();
        $seed = 9719;

        for ($step = 0; $step < 130; $step++) {
            $slot = $this->next_int($seed, count($ids));
            $action = $this->next_int($seed, 100);

            if ($action < 70) {
                $data = $this->log_data($slot, $step);
                $store->put($data, $ids[ $slot ]);
                $reference[ $ids[ $slot ] ] = $data;
            } elseif ($action < 86) {
                $store->delete($ids[ $slot ]);
                unset($reference[ $ids[ $slot ] ]);
            } elseif ($action < 94) {
                $store->compact();
            } else {
                $store = new SegmentedLogStore($this->root, 'log-fuzz', 512, 4, cache: Cache::memory(256));
            }

            if (0 === $step % 8) {
                $this->assert_log_store_matches_reference($store, $reference, $ids);
            }
        }

        $store->compact();
        $store = new SegmentedLogStore($this->root, 'log-fuzz', 512, 4, cache: Cache::memory(256));
        $this->assert_log_store_matches_reference($store, $reference, $ids);
    }

    public function test_log_queue_random_state_machine_matches_reference_after_reopen(): void
    {
        $ids = $this->fixed_ids(45, 1_700_300_000_000);
        $queue = new LogQueue($this->root, 'queue-fuzz');
        $payloads = array();
        $pending = array();
        $processing = array();
        $done = array();
        $ever_enqueued = array();
        $seed = 1933;

        for ($step = 0; $step < 150; $step++) {
            $action = $this->next_int($seed, 100);

            if ($action < 42) {
                $id = $ids[ $this->next_int($seed, count($ids)) ];
                if (! isset($ever_enqueued[ $id ])) {
                    $payload = $this->queue_payload(count($ever_enqueued), $step);
                    $this->assertSame($id, $queue->enqueue($payload, $id));
                    $payloads[ $id ] = $payload;
                    $pending[] = $id;
                    $ever_enqueued[ $id ] = true;
                }
            } elseif ($action < 62) {
                $expected = array_shift($pending);
                $record = $queue->claim();
                if (null === $expected) {
                    $this->assertNull($record);
                } else {
                    $this->assertInstanceOf(StorageRecord::class, $record);
                    $this->assertSame($expected, $record->id());
                    $this->assertSame($payloads[ $expected ], $record->data());
                    $processing[ $expected ] = true;
                }
            } elseif ($action < 74) {
                $limit = 1 + $this->next_int($seed, 4);
                $records = $queue->claimMany($limit);
                $expected = array_splice($pending, 0, $limit);
                $this->assertSame($expected, $this->record_ids($records));
                foreach ($expected as $id) {
                    $this->assertSame($payloads[ $id ], $records[ array_search($id, $expected, true) ]->data());
                    $processing[ $id ] = true;
                }
            } elseif ($action < 88) {
                $processing_ids = array_keys($processing);
                if (array() !== $processing_ids) {
                    $id = $processing_ids[ $this->next_int($seed, count($processing_ids)) ];
                    $keep_done = 0 === $this->next_int($seed, 2);
                    $queue->complete($id, $keep_done);
                    unset($processing[ $id ], $payloads[ $id ]);
                    if ($keep_done) {
                        $done[ $id ] = true;
                    } else {
                        unset($done[ $id ]);
                    }
                }
            } elseif ($action < 94) {
                $processing_ids = array_keys($processing);
                $expected = count($processing_ids);
                $this->assertSame($expected, $queue->requeue_timed_out(0));
                foreach ($processing_ids as $id) {
                    unset($processing[ $id ]);
                    $pending[] = $id;
                }
            } elseif ($action < 98) {
                $this->assertSame(count($done), $queue->purgeDone(0));
                $done = array();
            } else {
                $queue = new LogQueue($this->root, 'queue-fuzz');
            }

            if (0 === $step % 6) {
                $this->assert_queue_matches_reference($queue, $pending, $processing, $done);
            }
        }

        $queue = new LogQueue($this->root, 'queue-fuzz');
        $this->assert_queue_matches_reference($queue, $pending, $processing, $done);
    }

    public function test_jsonc_random_objects_round_trip_losslessly(): void
    {
        $seed = 24691;

        for ($index = 0; $index < 80; $index++) {
            $value = $this->jsonc_value($seed, 0);
            $object = array(
                'index'  => $index,
                'name'   => 'record "' . $index . '" // literal',
                'active' => 0 === $this->next_int($seed, 2),
                'value'  => $value,
            );

            $encoded = Jsonc::encode_object($object);
            $this->assertSame($object, Jsonc::decode_object($encoded));
            $this->assertSame($object, Jsonc::decode_object(Jsonc::encode_compact_object($object)));
        }
    }

    private function new_indexed_doc_store(string $collection): DocPerFileStore
    {
        $store = new DocPerFileStore($this->root, $collection, cache: Cache::memory(256));
        if (array() === $store->indexes()->definitions()) {
            $store->indexes()
                ->field('status')->index()
                ->field('rank')->range()
                ->field('flag')->index()
                ->field('nullable')->sync();
        }

        return $store;
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param list<string> $ids
     */
    private function assert_doc_store_matches_reference(DocPerFileStore $store, array $reference, array $ids): void
    {
        $expected = $this->sorted_record_map($reference);
        $this->assertSame($expected, $this->record_map(iterator_to_array($store->stream(), false)));
        $this->assertSame($expected, $this->record_map($store->query()->get()));
        $this->assertSame(count($expected), $store->query()->count());
        $this->assertSame(count($expected), $store->stats()['records']);
        $this->assertTrue($store->verify()['ok']);

        foreach (array_slice($ids, 0, 8) as $id) {
            $record = $store->get($id);
            $this->assertSame($reference[ $id ] ?? null, null === $record ? null : $record->data());
        }

        $this->assert_indexed_doc_query(
            $store,
            $reference,
            $store->query()->where('status')->eq('published'),
            static fn(array $data): bool => ( $data['status'] ?? null ) === 'published'
        );
        $this->assert_indexed_doc_query(
            $store,
            $reference,
            $store->query()->where('flag')->eq(true),
            static fn(array $data): bool => ( $data['flag'] ?? null ) === true
        );
        $this->assert_doc_query(
            $store->query()->where('nullable')->eq(null),
            $reference,
            static fn(array $data): bool => array_key_exists('nullable', $data) && null === $data['nullable']
        );
        $this->assert_doc_query(
            $store->query()->where('optional')->missing(),
            $reference,
            static fn(array $data): bool => ! array_key_exists('optional', $data)
        );
        $this->assert_doc_query(
            $store->query()->where('slug')->prefix('item-1'),
            $reference,
            static fn(array $data): bool => isset($data['slug']) && is_string($data['slug']) && str_starts_with($data['slug'], 'item-1')
        );

        $ordered = $this->reference_ids(
            $reference,
            static fn(array $data): bool => ( $data['rank'] ?? -1 ) >= 500,
            'rank',
            6
        );
        $actual = $this->record_ids($store->query()->where('rank')->gte(500)->orderBy('rank')->limit(6)->get());
        $this->assertSame($ordered, $actual);

        $cursor = $ids[12];
        $expected_page = array_values(
            array_filter(
                array_keys($expected),
                static fn(string $id): bool => $id > $cursor
            )
        );
        $expected_page = array_slice($expected_page, 0, 5);
        $actual_page = $this->record_ids($store->query()->cursor($cursor)->page(5)->get());
        $this->assertSame($expected_page, $actual_page);
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param callable(array<string, mixed>): bool $predicate
     */
    private function assert_doc_query(QueryBuilder $query, array $reference, callable $predicate): void
    {
        $expected = $this->reference_ids($reference, $predicate);
        $this->assertSame($expected, $this->record_ids($query->get()));
        $this->assertSame(count($expected), $query->count());
        $this->assertSame($expected[0] ?? null, $query->first()?->id());
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param callable(array<string, mixed>): bool $predicate
     */
    private function assert_indexed_doc_query(
        DocPerFileStore $store,
        array $reference,
        QueryBuilder $query,
        callable $predicate
    ): void {
        $this->assert_doc_query($query, $reference, $predicate);

        $expected = $this->reference_ids($reference, $predicate);
        $candidate_ids = $store->indexes()->candidate_ids($query);
        $this->assertIsArray($candidate_ids);
        sort($candidate_ids);
        sort($expected);
        $this->assertSame($expected, $candidate_ids);
        $this->assertSame(count($expected), $store->indexes()->candidate_count($query));
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param list<string> $ids
     */
    private function assert_log_store_matches_reference(SegmentedLogStore $store, array $reference, array $ids): void
    {
        $expected = $this->sorted_record_map($reference);
        $this->assertSame($expected, $this->record_map(iterator_to_array($store->stream(), false)));
        $this->assertSame(count($expected), $store->stats()['records']);
        $this->assertTrue($store->verify()['ok']);

        $published = static fn(array $data): bool => ( $data['status'] ?? null ) === 'published';
        $this->assert_log_query($store, $reference, RecordQuery::all()->where_equal('status', 'published'), $published);
        $this->assertSame(count($this->reference_ids($reference, $published)), $store->query()->where('status')->eq('published')->count());

        $from = 1_700_200_000_010;
        $until = 1_700_200_000_026;
        $this->assert_log_query(
            $store,
            $reference,
            RecordQuery::all()->time_range_ms($from, $until),
            static fn(array $_data, string $id): bool => UuidV7::timestamp_ms($id) >= $from && UuidV7::timestamp_ms($id) <= $until
        );

        $probe_id = $ids[ $this->next_stable_index(count($ids), count($reference)) ];
        $expected_record = $reference[ $probe_id ] ?? null;
        $actual = $store->query()->where('id')->eq($probe_id)->first();
        $this->assertSame($expected_record, null === $actual ? null : $actual->data());
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param callable(array<string, mixed>, string): bool $predicate
     */
    private function assert_log_query(
        SegmentedLogStore $store,
        array $reference,
        RecordQuery $query,
        callable $predicate
    ): void {
        $expected = $this->reference_ids_with_id($reference, $predicate);
        $actual = $this->record_ids(iterator_to_array($store->stream($query), false));
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param list<string> $pending
     * @param array<string, true> $processing
     * @param array<string, true> $done
     */
    private function assert_queue_matches_reference(LogQueue $queue, array $pending, array $processing, array $done): void
    {
        $counts = array(
            'pending'    => count($pending),
            'processing' => count($processing),
            'done'       => count($done),
        );

        $this->assertSame($counts, $queue->counts());
        $stats = $queue->stats();
        $this->assertSame($counts['pending'], $stats['pending']);
        $this->assertSame($counts['processing'], $stats['processing']);
        $this->assertSame($counts['done'], $stats['done']);
        $this->assertTrue($queue->verify()['ok']);
    }

    /**
     * @return list<string>
     */
    private function fixed_ids(int $count, int $start_ms): array
    {
        UuidV7::reset_for_tests();

        $ids = array();
        for ($index = 0; $index < $count; $index++) {
            $ids[] = UuidV7::generate($start_ms + $index);
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function doc_data(int $slot, int $step): array
    {
        $data = array(
            'slug'   => 'item-' . $slot . '-' . $step,
            'status' => array( 'draft', 'published', 'archived' )[ ( $slot + $step ) % 3 ],
            'rank'   => $slot * 100 + ( $step % 97 ),
            'bucket' => ( $slot + $step ) % 7,
            'flag'   => 0 === ( $slot + $step ) % 2,
        );

        if (0 === ( $slot + $step ) % 4) {
            $data['nullable'] = null;
        }
        if (0 === ( $slot + $step ) % 5) {
            $data['optional'] = 'present';
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function log_data(int $slot, int $step): array
    {
        return array(
            'status' => array( 'draft', 'published', 'archived' )[ ( $slot * 3 + $step ) % 3 ],
            'bucket' => $slot % 6,
            'score'  => $step,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function queue_payload(int $slot, int $step): array
    {
        return array(
            'type' => 0 === $slot % 2 ? 'email' : 'sync',
            'slot' => $slot,
            'step' => $step,
        );
    }

    /**
     * @param list<StorageRecord> $records
     * @return list<string>
     */
    private function record_ids(array $records): array
    {
        return array_map(static fn(StorageRecord $record): string => $record->id(), $records);
    }

    /**
     * @param list<StorageRecord> $records
     * @return array<string, array<string, mixed>>
     */
    private function record_map(array $records): array
    {
        $map = array();
        foreach ($records as $record) {
            $map[ $record->id() ] = $record->data();
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @return array<string, array<string, mixed>>
     */
    private function sorted_record_map(array $reference): array
    {
        ksort($reference);

        return $reference;
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param callable(array<string, mixed>): bool $predicate
     * @return list<string>
     */
    private function reference_ids(array $reference, callable $predicate, ?string $order_field = null, ?int $limit = null): array
    {
        $rows = array();
        foreach ($reference as $id => $data) {
            if ($predicate($data)) {
                $rows[ $id ] = $data;
            }
        }

        if (null !== $order_field) {
            uasort(
                $rows,
                static function (array $left, array $right) use ($order_field): int {
                    return ( $left[ $order_field ] ?? null ) <=> ( $right[ $order_field ] ?? null );
                }
            );

            $ids = array_keys($rows);
        } else {
            ksort($rows);
            $ids = array_keys($rows);
        }

        return null === $limit ? $ids : array_slice($ids, 0, $limit);
    }

    /**
     * @param array<string, array<string, mixed>> $reference
     * @param callable(array<string, mixed>, string): bool $predicate
     * @return list<string>
     */
    private function reference_ids_with_id(array $reference, callable $predicate): array
    {
        $ids = array();
        foreach ($reference as $id => $data) {
            if ($predicate($data, $id)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }

    private function next_stable_index(int $count, int $salt): int
    {
        return 0 === $count ? 0 : ( $salt * 17 + 11 ) % $count;
    }

    private function next_int(int &$seed, int $exclusive_max): int
    {
        $seed = ( $seed * 1103515245 + 12345 ) & 0x7fffffff;

        return $seed % $exclusive_max;
    }

    private function jsonc_value(int &$seed, int $depth): mixed
    {
        $kind = $depth >= 2 ? $this->next_int($seed, 5) : $this->next_int($seed, 8);

        return match ($kind) {
            0 => $this->next_int($seed, 1000) - 500,
            1 => ( $this->next_int($seed, 1000) / 10 ) + 0.5,
            2 => 0 === $this->next_int($seed, 2),
            3 => null,
            4 => 'value "' . $this->next_int($seed, 100) . '" \\ slash',
            5 => array(
                $this->jsonc_value($seed, $depth + 1),
                $this->jsonc_value($seed, $depth + 1),
            ),
            default => array(
                'left'  => $this->jsonc_value($seed, $depth + 1),
                'right' => $this->jsonc_value($seed, $depth + 1),
            ),
        };
    }
}

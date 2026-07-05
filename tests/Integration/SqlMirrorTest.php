<?php

declare(strict_types=1);

namespace Storh\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Storh\DocPerFileStore;
use Storh\Schema;
use Storh\SegmentedLogStore;
use Storh\SqlMirror;
use Storh\StorageException;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class SqlMirrorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\PDO::class) || ! in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is unavailable.');
        }

        UuidV7::reset_for_tests();
        $this->root = sys_get_temp_dir() . '/storh-mirror-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_install_push_and_sql_joins_across_mirrored_collections(): void
    {
        $products_schema = Schema::collection('products')
            ->string('slug')->unique()
            ->string('name')->index()
            ->float('price')->range()
            ->bool('active')->required(array( 'slug', 'name' ));

        $purchases_schema = Schema::collection('purchases')
            ->string('product_id')->index()
            ->int('amount')->range()
            ->string('reference')->unique();

        $products  = new DocPerFileStore($this->root, 'products', schema: $products_schema);
        $purchases = new DocPerFileStore($this->root, 'purchases', schema: $purchases_schema);

        $plugin = $products->put(array(
            'slug'   => 'my-plugin',
            'name'   => 'My Plugin ünïcøde',
            'price'  => 49.5,
            'active' => true,
            'tags'   => array( 'wp', 'storh' ),
        ));
        $theme = $products->put(array(
            'slug'   => 'my-theme',
            'name'   => 'My Theme',
            'price'  => 20.0,
            'active' => false,
        ));

        for ($i = 0; $i < 6; $i++) {
            $purchases->put(array(
                'product_id' => 0 === $i % 3 ? $theme->id() : $plugin->id(),
                'amount'     => 1000 + $i,
                'reference'  => 'ref-' . $i,
            ));
        }

        $pdo    = new \PDO('sqlite:' . $this->root . '/mirror.db');
        $mirror = ( new SqlMirror($pdo, 'app_') )
            ->collection($products, 'products', $products_schema)
            ->collection($purchases, 'purchases', $purchases_schema);

        $mirror->install();
        $mirror->install();

        $result = $mirror->push();
        $this->assertSame(array( 'inserted' => 8, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0 ), $result);

        $this->assertSame('app_products', $mirror->table('products'));

        $joined = $pdo->query(
            'SELECT p.slug, COUNT(*) AS sales, SUM(o.amount) AS total
             FROM app_purchases o
             INNER JOIN app_products p ON p.id = o.product_id
             GROUP BY p.slug
             ORDER BY p.slug'
        );
        $this->assertNotFalse($joined);
        $this->assertSame(
            array(
                array( 'slug' => 'my-plugin', 'sales' => 4, 'total' => 4012 ),
                array( 'slug' => 'my-theme', 'sales' => 2, 'total' => 2003 ),
            ),
            $joined->fetchAll(\PDO::FETCH_ASSOC)
        );

        $row = $pdo->query("SELECT name, price, active, data FROM app_products WHERE slug = 'my-plugin'");
        $this->assertNotFalse($row);
        $product = $row->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($product);
        $this->assertSame('My Plugin ünïcøde', $product['name']);
        $this->assertEqualsWithDelta(49.5, (float) $product['price'], 0.0001);
        $this->assertSame(1, (int) $product['active']);
        $this->assertIsString($product['data']);
        $decoded = json_decode($product['data'], true);
        $this->assertIsArray($decoded);
        $this->assertSame(array( 'wp', 'storh' ), $decoded['tags']);

        $health = $mirror->verify();
        $this->assertTrue($health['ok'], implode(' | ', $health['errors']));
        $this->assertSame(2, $health['stats']['products']['records']);
        $this->assertSame(6, $health['stats']['purchases']['rows']);
    }

    public function test_incremental_push_tracks_inserts_updates_deletes_and_unchanged(): void
    {
        $store  = new DocPerFileStore($this->root, 'pages');
        $first  = $store->put(array( 'slug' => 'first', 'views' => 1 ));
        $second = $store->put(array( 'slug' => 'second', 'views' => 2 ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($store, 'pages');
        $mirror->install();
        $mirror->push();

        $store->put(array( 'slug' => 'first', 'views' => 10 ), $first->id());
        $store->delete($second->id());
        $store->put(array( 'slug' => 'third', 'views' => 3 ));

        $result = $mirror->push();
        $this->assertSame(array( 'inserted' => 1, 'updated' => 1, 'deleted' => 1, 'unchanged' => 0 ), $result);

        $repeat = $mirror->push();
        $this->assertSame(array( 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'unchanged' => 2 ), $repeat);

        $rows = $pdo->query('SELECT id, data FROM storh_pages ORDER BY id');
        $this->assertNotFalse($rows);
        $mirrored = $rows->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $mirrored);
        $this->assertSame($first->id(), $mirrored[0]['id']);
    }

    public function test_flush_pushes_only_listed_ids_and_removes_deleted_ones(): void
    {
        $store = new DocPerFileStore($this->root, 'pages');
        $kept    = $store->put(array( 'slug' => 'kept' ));
        $removed = $store->put(array( 'slug' => 'removed' ));
        $ignored = $store->put(array( 'slug' => 'ignored' ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($store, 'pages');
        $mirror->install();
        $mirror->flush('pages', array( $removed->id() ));

        $store->delete($removed->id());
        $result = $mirror->flush('pages', array( $kept->id(), $kept->id(), $removed->id() ));
        $this->assertSame(array( 'upserted' => 1, 'deleted' => 1 ), $result);

        $ids = $pdo->query('SELECT id FROM storh_pages ORDER BY id');
        $this->assertNotFalse($ids);
        $this->assertSame(array( $kept->id() ), $ids->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertNotNull($store->get($ignored->id()));
    }

    public function test_verify_reports_drift_and_rebuild_heals_it(): void
    {
        $store = new DocPerFileStore($this->root, 'pages');
        $store->put(array( 'slug' => 'one' ));
        $missing = $store->put(array( 'slug' => 'two' ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($store, 'pages');
        $mirror->install();
        $mirror->push();

        $pdo->exec("UPDATE storh_pages SET hash = 'drifted' WHERE id = '" . $missing->id() . "'");
        $pdo->exec("INSERT INTO storh_pages (id, hash, data) VALUES ('018bcfe5-6800-7abc-8def-0123456789ab', 'x', '{}')");
        $pdo->exec("DELETE FROM storh_pages WHERE id != '" . $missing->id() . "' AND id != '018bcfe5-6800-7abc-8def-0123456789ab'");

        $health = $mirror->verify();
        $this->assertFalse($health['ok']);
        $this->assertSame(1, $health['stats']['pages']['missing']);
        $this->assertSame(1, $health['stats']['pages']['stale']);
        $this->assertSame(1, $health['stats']['pages']['orphaned']);
        $this->assertCount(3, $health['errors']);

        $rebuilt = $mirror->rebuild();
        $this->assertSame(array( 'inserted' => 2, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0 ), $rebuilt);
        $this->assertTrue($mirror->verify()['ok']);

        $mirror->uninstall();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
        $this->assertNotFalse($tables);
        $this->assertSame(array(), $tables->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function test_segmented_log_collections_mirror_live_records(): void
    {
        $log = new SegmentedLogStore($this->root, 'events', 2048);
        $ids = array();
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $log->put(array( 'sequence' => $i, 'type' => 'event' ))->id();
        }
        $log->delete($ids[0]);
        $log->delete($ids[1]);
        $log->compact();

        $schema = Schema::collection('events')->int('sequence')->index();
        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($log, 'events', $schema);
        $mirror->install();

        $result = $mirror->push();
        $this->assertSame(8, $result['inserted']);

        $count = $pdo->query('SELECT COUNT(*) FROM storh_events WHERE sequence >= 5');
        $this->assertNotFalse($count);
        $this->assertSame(5, (int) $count->fetchColumn());

        $mirror->flush('events', array( $ids[2] ));
        $this->assertTrue($mirror->verify()['ok']);
    }

    public function test_mirror_unique_index_is_a_backstop_for_unenforced_duplicates(): void
    {
        $schema = Schema::collection('pages')->string('slug')->unique();
        $store  = new DocPerFileStore($this->root, 'pages');
        $store->put(array( 'slug' => 'dup' ));
        $store->put(array( 'slug' => 'dup' ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($store, 'pages', $schema);
        $mirror->install();

        try {
            $mirror->push();
            $this->fail('Expected a unique constraint failure from the mirror.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('SQL mirror write failed', $exception->getMessage());
        }

        $count = $pdo->query('SELECT COUNT(*) FROM storh_pages');
        $this->assertNotFalse($count);
        $this->assertSame(0, (int) $count->fetchColumn(), 'A failed push must roll back completely.');
    }

    public function test_registration_and_configuration_are_validated(): void
    {
        $store = new DocPerFileStore($this->root, 'pages');
        $pdo   = new \PDO('sqlite::memory:');

        try {
            new SqlMirror($pdo, 'bad prefix');
            $this->fail('Expected a prefix validation failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('prefix', $exception->getMessage());
        }

        try {
            new SqlMirror($pdo, 'storh_', 'pgsql');
            $this->fail('Expected an unsupported driver failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Unsupported SQL mirror driver: pgsql', $exception->getMessage());
        }

        $mirror = new SqlMirror($pdo);
        try {
            $mirror->collection($store, 'bad name');
            $this->fail('Expected a collection name validation failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('collection name', $exception->getMessage());
        }

        $mirror->collection($store, 'pages');
        try {
            $mirror->collection($store, 'pages');
            $this->fail('Expected a duplicate registration failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('already registered', $exception->getMessage());
        }

        try {
            $mirror->table('unknown');
            $this->fail('Expected an unknown collection failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('Unknown SQL mirror collection', $exception->getMessage());
        }

        try {
            $mirror->collection($store, 'mixed', Schema::collection('mixed')->mixed('anything')->index());
            $this->fail('Expected a mixed indexed field failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('mixed schema field', $exception->getMessage());
        }

        $unindexed_mixed = Schema::collection('loose')->mixed('anything')->required(array( 'anything' ));
        $loose = ( new SqlMirror($pdo, 'loose_') )->collection($store, 'loose', $unindexed_mixed);
        $loose->install();
        $this->assertSame('loose_loose', $loose->table('loose'));

        try {
            $mirror->collection($store, 'reserved', Schema::collection('reserved')->string('data')->index());
            $this->fail('Expected a reserved field name failure.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('cannot map schema field name', $exception->getMessage());
        }
    }

    public function test_sql_failures_surface_as_storage_exceptions(): void
    {
        $store = new DocPerFileStore($this->root, 'pages');
        $store->put(array( 'slug' => 'one' ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo) )->collection($store, 'pages');

        try {
            $mirror->rebuild();
            $this->fail('Expected a statement failure without installed tables.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('SQL mirror statement failed', $exception->getMessage());
        }

        try {
            $mirror->push();
            $this->fail('Expected a push failure without installed tables.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('SQL mirror statement failed', $exception->getMessage());
        }

        $mirror->install();
        $pdo->beginTransaction();
        try {
            $mirror->flush('pages', array());
            $this->fail('Expected a transaction failure inside an active transaction.');
        } catch (StorageException $exception) {
            $this->assertStringContainsString('SQL mirror transaction failed', $exception->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_mysql_dialect_generates_expected_sql(): void
    {
        $store  = new DocPerFileStore($this->root, 'pages');
        $schema = Schema::collection('pages')
            ->string('slug')->unique()
            ->string('kind')->index()
            ->string('title')->required(array( 'slug' ))
            ->int('views')->index()
            ->float('score')->range()
            ->bool('active')->required(array( 'active' ));

        $pdo    = new \PDO('sqlite::memory:');
        $mirror = ( new SqlMirror($pdo, 'wp_', 'mysql') )->collection($store, 'pages', $schema);

        $collection = $this->collection_entry($mirror, 'pages');
        $create = $this->invoke_private($mirror, 'create_table_sql', array( $collection ));
        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `wp_pages` ('
            . '`id` CHAR(36) NOT NULL PRIMARY KEY, '
            . '`hash` CHAR(32) NOT NULL, '
            . '`slug` VARCHAR(191), '
            . '`kind` VARCHAR(191), '
            . '`title` TEXT, '
            . '`views` BIGINT, '
            . '`score` DOUBLE, '
            . '`active` TINYINT(1), '
            . '`data` LONGTEXT NOT NULL, '
            . 'UNIQUE KEY `wp_pages_slug_idx` (`slug`), '
            . 'KEY `wp_pages_kind_idx` (`kind`), '
            . 'KEY `wp_pages_views_idx` (`views`), '
            . 'KEY `wp_pages_score_idx` (`score`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $create
        );

        $this->assertSame(array(), $this->invoke_private($mirror, 'create_index_sql', array( $collection )));

        $upsert = $this->invoke_private($mirror, 'upsert_sql', array( $collection ));
        $this->assertIsString($upsert);
        $this->assertStringContainsString('INSERT INTO `wp_pages`', $upsert);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE `hash` = VALUES(`hash`)', $upsert);
        $this->assertStringContainsString('`data` = VALUES(`data`)', $upsert);
        $this->assertStringNotContainsString('`id` = VALUES', $upsert);
    }

    /**
     * @return array<string, mixed>
     */
    private function collection_entry(SqlMirror $mirror, string $name): array
    {
        $entry = $this->invoke_private($mirror, 'registered', array( $name ));
        $this->assertIsArray($entry);

        return $entry;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function invoke_private(object $instance, string $method, array $arguments): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);

        return $reflection->invokeArgs($instance, $arguments);
    }
}

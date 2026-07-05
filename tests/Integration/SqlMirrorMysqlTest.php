<?php

declare(strict_types=1);

namespace Storh\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Storh\DocPerFileStore;
use Storh\Schema;
use Storh\SqlMirror;
use Storh\StorageException;
use Storh\Tests\Support\TestFilesystem;
use Storh\UuidV7;

final class SqlMirrorMysqlTest extends TestCase
{
    private string $root = '';

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (false === getenv('STORH_MYSQL_HOST')) {
            $this->markTestSkipped('STORH_MYSQL_HOST is not configured.');
        }

        UuidV7::reset_for_tests();
        $this->root   = sys_get_temp_dir() . '/storh-mysql-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->prefix = 'storh_' . bin2hex(random_bytes(4)) . '_';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove_path($this->root);

        parent::tearDown();
    }

    public function test_mysqli_roundtrip_with_joins_flush_and_rebuild(): void
    {
        if (! class_exists(\mysqli::class)) {
            $this->markTestSkipped('mysqli is unavailable.');
        }

        $mysqli = new \mysqli(
            (string) getenv('STORH_MYSQL_HOST'),
            (string) getenv('STORH_MYSQL_USER'),
            (string) getenv('STORH_MYSQL_PASSWORD'),
            (string) getenv('STORH_MYSQL_DATABASE'),
            (int) getenv('STORH_MYSQL_PORT')
        );

        [$products, $purchases, $products_schema, $purchases_schema, $ids] = $this->seed_stores();

        $mirror = ( new SqlMirror($mysqli, $this->prefix) )
            ->collection($products, 'products', $products_schema)
            ->collection($purchases, 'purchases', $purchases_schema);

        try {
            $mirror->install();
            $result = $mirror->push();
            $this->assertSame(array( 'inserted' => 8, 'updated' => 0, 'deleted' => 0, 'unchanged' => 0 ), $result);

            $joined = $mysqli->query(
                'SELECT p.slug, COUNT(*) AS sales, SUM(o.amount) AS total
                 FROM ' . $mirror->table('purchases') . ' o
                 INNER JOIN ' . $mirror->table('products') . ' p ON p.id = o.product_id
                 GROUP BY p.slug
                 ORDER BY p.slug'
            );
            $this->assertInstanceOf(\mysqli_result::class, $joined);
            $rows = $joined->fetch_all(\MYSQLI_ASSOC);
            $this->assertCount(2, $rows);
            $this->assertSame('my-plugin', $rows[0]['slug']);
            $this->assertSame(4, (int) $rows[0]['sales']);
            $this->assertSame(4012, (int) $rows[0]['total']);

            $products->delete($ids['theme']);
            $this->assertSame(1, $mirror->push()['deleted']);

            $products->put(array( 'slug' => 'my-plugin', 'name' => 'Renamed', 'price' => 50.0, 'active' => true ), $ids['plugin']);
            $flushed = $mirror->flush('products', array( $ids['plugin'], $ids['theme'] ));
            $this->assertSame(array( 'upserted' => 1, 'deleted' => 1 ), $flushed);

            $health = $mirror->verify();
            $this->assertTrue($health['ok'], implode(' | ', $health['errors']));

            $rebuilt = $mirror->rebuild();
            $this->assertSame(7, $rebuilt['inserted']);
        } finally {
            $mirror->uninstall();
            $mysqli->close();
        }
    }

    public function test_pdo_mysql_roundtrip_and_unique_backstop_rolls_back(): void
    {
        if (! class_exists(\PDO::class) || ! in_array('mysql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is unavailable.');
        }

        $pdo = new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) getenv('STORH_MYSQL_HOST'),
                (int) getenv('STORH_MYSQL_PORT'),
                (string) getenv('STORH_MYSQL_DATABASE')
            ),
            (string) getenv('STORH_MYSQL_USER'),
            (string) getenv('STORH_MYSQL_PASSWORD')
        );

        [$products, $purchases, $products_schema, $purchases_schema] = $this->seed_stores();

        $mirror = ( new SqlMirror($pdo, $this->prefix) )
            ->collection($products, 'products', $products_schema)
            ->collection($purchases, 'purchases', $purchases_schema);

        $duplicates_schema = Schema::collection('duplicates')->string('slug')->unique();
        $duplicates = new DocPerFileStore($this->root, 'duplicates');
        $duplicates->put(array( 'slug' => 'dup' ));
        $duplicates->put(array( 'slug' => 'dup' ));
        $mirror->collection($duplicates, 'duplicates', $duplicates_schema);

        try {
            $mirror->install();
            $this->assertSame(8, $mirror->push('products')['inserted'] + $mirror->push('purchases')['inserted']);

            $count = $pdo->query('SELECT COUNT(*) FROM ' . $mirror->table('purchases') . ' WHERE amount >= 1003');
            $this->assertNotFalse($count);
            $this->assertSame(3, (int) $count->fetchColumn());

            try {
                $mirror->push('duplicates');
                $this->fail('Expected a unique constraint failure from the mirror.');
            } catch (StorageException $exception) {
                $this->assertStringContainsString('SQL mirror statement failed', $exception->getMessage());
            }

            $orphans = $pdo->query('SELECT COUNT(*) FROM ' . $mirror->table('duplicates'));
            $this->assertNotFalse($orphans);
            $this->assertSame(0, (int) $orphans->fetchColumn(), 'A failed push must roll back completely.');

            $this->assertTrue($mirror->verify()['stats']['products']['records'] > 0);
        } finally {
            $mirror->uninstall();
        }
    }

    /**
     * @return array{0: DocPerFileStore, 1: DocPerFileStore, 2: Schema, 3: Schema, 4: array{plugin: string, theme: string}}
     */
    private function seed_stores(): array
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

        return array(
            $products,
            $purchases,
            $products_schema,
            $purchases_schema,
            array( 'plugin' => $plugin->id(), 'theme' => $theme->id() ),
        );
    }
}

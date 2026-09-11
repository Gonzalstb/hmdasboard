<?php

namespace Tests\Feature;

use App\Domain\SchemaInstaller;
use App\Domain\SqliteImporter;
use App\Support\FileStore;
use App\Support\SqliteStore;
use PDO;
use Tests\TestCase;

class SqliteImportTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir().'/mytickets-import-'.$this->uniq();
        mkdir($this->dataDir, 0777, true);
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'mytickets.data_dir' => $this->dataDir,
            'mytickets.seed_email' => 'dest@example.com',
            'mytickets.seed_name' => 'Dest',
            'mytickets.seed_password' => 'secret12',
        ]);
        $this->app->forgetInstance(SqliteStore::class);
        $this->app->forgetInstance(FileStore::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->dataDir);
    }

    public function test_import_preserves_ids_and_does_not_write_source(): void
    {
        $sourceDir = sys_get_temp_dir().'/mytickets-source-'.$this->uniq();
        mkdir($sourceDir, 0777, true);
        $sourcePath = $sourceDir.'/source.sqlite';
        $sourceStore = new SqliteStore(new PDO('sqlite:'.$sourcePath));
        config(['mytickets.seed_email' => 'owner@example.com', 'mytickets.seed_name' => 'Owner', 'mytickets.seed_password' => 'secret12']);
        SchemaInstaller::ensure($sourceStore);
        $sourceStore->prepare('INSERT INTO tickets(id,ticket_key,title,summary,status_id,user_id) VALUES(41,?,?,?,?,?)')
            ->bind('HM-41', 'Ticket importado', '', 1, 1)->run();
        $sourceStore->prepare('INSERT INTO permanent_notes(id,title,content,user_id) VALUES(7,?,?,?)')
            ->bind('Nota', 'contenido', 1)->run();
        $sourceHash = hash_file('sha256', $sourcePath);
        $sourceMtime = filemtime($sourcePath);

        $dest = $this->app->make(SqliteStore::class);
        $files = $this->app->make(FileStore::class);
        $result = SqliteImporter::import($sourcePath, $dest, $files);

        $this->assertSame(1, $result['tables']['tickets']);
        $this->assertSame(1, $result['tables']['permanent_notes']);
        $this->assertSame('HM-41', $dest->prepare('SELECT ticket_key k FROM tickets WHERE id=41')->first()->k);
        $this->assertSame('contenido', $dest->prepare('SELECT content c FROM permanent_notes WHERE id=7')->first()->c);
        $this->assertSame('owner@example.com', $dest->prepare('SELECT email e FROM users WHERE id=1')->first()->e);
        $this->assertSame($sourceHash, hash_file('sha256', $sourcePath));
        $this->assertSame($sourceMtime, filemtime($sourcePath));

        try {
            SqliteImporter::import($sourcePath, $dest, $files, [], false);
            $this->fail('El segundo import no debería sobrescribir sin --force.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('ya tiene datos', $error->getMessage());
        }

        $this->deleteDir($sourceDir);
    }

    private function uniq(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function deleteDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\FileStore;
use App\Support\SqliteStore;
use PDO;

final class SqliteImporter
{
    /** @var list<string> */
    public const TABLES = [
        'app_meta',
        'users',
        'statuses',
        'labels',
        'attention_markers',
        'tickets',
        'ticket_labels',
        'comments',
        'ticket_status_history',
        'reminders',
        'permanent_notes',
        'permanent_note_attachments',
        'billing_carryovers',
        'agenda_tasks',
        'agenda_task_comments',
        'standup_guides',
        'standup_items',
    ];

    /**
     * @param  list<string>  $fileSearchDirs
     * @return array{tables: array<string, int>, files: int, skipped_files: list<string>}
     */
    public static function import(string $sourcePath, SqliteStore $dest, FileStore $files, array $fileSearchDirs = [], bool $force = false): array
    {
        $sourcePath = realpath($sourcePath) ?: $sourcePath;
        if (! is_file($sourcePath)) {
            throw new \RuntimeException('No encuentro el SQLite de origen: '.$sourcePath);
        }
        $source = new PDO('sqlite:'.$sourcePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        try {
            $source->exec('PRAGMA query_only = ON');
        } catch (\Throwable) {
        }

        SchemaInstaller::installTables($dest);

        $existingTickets = (int) ($dest->prepare('SELECT COUNT(*) total FROM tickets')->first()->total ?? 0);
        $existingNotes = (int) ($dest->prepare('SELECT COUNT(*) total FROM permanent_notes')->first()->total ?? 0);
        $existingUsers = (int) ($dest->prepare('SELECT COUNT(*) total FROM users')->first()->total ?? 0);
        if (($existingTickets > 0 || $existingNotes > 0 || $existingUsers > 0) && ! $force) {
            throw new \RuntimeException('El destino ya tiene datos. No se ha tocado nada. Pasa --force solo si quieres reemplazar el destino (el SQLite original no se borra).');
        }

        if ($force) {
            foreach (array_reverse(self::TABLES) as $table) {
                $dest->prepare('DELETE FROM `'.$table.'`')->run();
            }
            try {
                $dest->prepare('DELETE FROM sessions')->run();
            } catch (\Throwable) {
            }
        }

        $copied = [];
        foreach (self::TABLES as $table) {
            $copied[$table] = self::copyTable($source, $dest, $table);
        }
        self::resetAutoIncrement($dest);

        $fileResult = self::copyAttachments($source, $files, $fileSearchDirs);

        return [
            'tables' => $copied,
            'files' => $fileResult['copied'],
            'skipped_files' => $fileResult['skipped'],
        ];
    }

    public static function detectWranglerSqlite(): ?string
    {
        $dir = dirname(base_path()).'/project/.wrangler/state/v3/d1/miniflare-D1DatabaseObject';
        if (! is_dir($dir)) {
            return null;
        }
        $best = null;
        $bestTickets = -1;
        foreach (glob($dir.'/*.sqlite') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            try {
                $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $tickets = (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
                $hasUsers = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
                $score = $tickets + ($hasUsers ? 1000 : 0);
                if ($score > $bestTickets) {
                    $bestTickets = $score;
                    $best = $path;
                }
            } catch (\Throwable) {
            }
        }

        return $best;
    }

    private static function copyTable(PDO $source, SqliteStore $dest, string $table): int
    {
        $exists = $source->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=".$source->quote($table))->fetchColumn();
        if (! (int) $exists) {
            return 0;
        }
        $sourceCols = [];
        foreach ($source->query('PRAGMA table_info('.$table.')') as $column) {
            $sourceCols[] = (string) $column['name'];
        }
        $destCols = array_map(static fn (object $column): string => (string) $column->name, $dest->tableColumns($table));
        $columns = array_values(array_intersect($sourceCols, $destCols));
        if ($columns === []) {
            return 0;
        }
        $quoted = implode(',', array_map(static fn (string $name): string => '`'.$name.'`', $columns));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $insert = 'INSERT INTO `'.$table.'` ('.$quoted.') VALUES ('.$placeholders.')';
        $rows = $source->query('SELECT '.$quoted.' FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
            $dest->prepare($insert)->bind(...$values)->run();
            $count++;
        }

        return $count;
    }

    private static function resetAutoIncrement(SqliteStore $dest): void
    {
        if (! $dest->isMysql()) {
            return;
        }
        foreach (self::TABLES as $table) {
            if (! $dest->hasColumn($table, 'id')) {
                continue;
            }
            $max = (int) ($dest->prepare('SELECT COALESCE(MAX(id),0) total FROM `'.$table.'`')->first()->total ?? 0);
            $dest->pdo()->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT='.($max + 1));
        }
    }

    /**
     * @param  list<string>  $fileSearchDirs
     * @return array{copied: int, skipped: list<string>}
     */
    private static function copyAttachments(PDO $source, FileStore $files, array $fileSearchDirs): array
    {
        $exists = (int) $source->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='permanent_note_attachments'")->fetchColumn();
        if (! $exists) {
            return ['copied' => 0, 'skipped' => []];
        }
        $rows = $source->query('SELECT storage_key, file_name FROM permanent_note_attachments')->fetchAll(PDO::FETCH_ASSOC);
        $copied = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $key = (string) $row['storage_key'];
            $name = (string) $row['file_name'];
            $found = self::findAttachmentFile($key, $name, $fileSearchDirs);
            if ($found === null) {
                $skipped[] = $name !== '' ? $name : $key;

                continue;
            }
            $files->put($key, (string) file_get_contents($found));
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * @param  list<string>  $fileSearchDirs
     */
    private static function findAttachmentFile(string $storageKey, string $fileName, array $fileSearchDirs): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $storageKey), '/');
        $candidates = [];
        foreach ($fileSearchDirs as $dir) {
            if ($dir === '' || ! is_dir($dir)) {
                continue;
            }
            $candidates[] = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($fileName !== '') {
                $candidates[] = $dir.DIRECTORY_SEPARATOR.$fileName;
            }
        }
        if ($fileName !== '') {
            $normalized = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fileName) ?: $fileName;
            $candidates[] = dirname(base_path()).'/attachments/'.$fileName;
            $candidates[] = dirname(base_path()).'/attachments/'.$normalized;
            $candidates[] = dirname(base_path()).'/attachments/WMF_BritishDressage_Handover_v1.3_3.pdf';
        }
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        $blobDir = dirname(base_path()).'/project/.wrangler/state/v3/r2/mytickets-files-local/blobs';
        if (is_dir($blobDir)) {
            $blobs = array_values(array_filter(glob($blobDir.'/*') ?: [], 'is_file'));
            if (count($blobs) === 1) {
                return $blobs[0];
            }
        }

        return null;
    }
}

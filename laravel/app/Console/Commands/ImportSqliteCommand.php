<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\SqliteImporter;
use App\Support\FileStore;
use App\Support\SqliteStore;
use Illuminate\Console\Command;

final class ImportSqliteCommand extends Command
{
    protected $signature = 'mytickets:import-sqlite
                            {path? : Ruta al SQLite de origen. Si omites path, usa --wrangler}
                            {--wrangler : Importa la base local de Wrangler D1 (la que tiene tus tickets)}
                            {--force : Vacía el destino y lo reemplaza. El SQLite original no se borra}
                            {--files= : Carpeta extra donde buscar adjuntos}';

    protected $description = 'Copia tickets, notas y adjuntos desde SQLite a la base configurada (MySQL o SQLite) sin modificar el archivo origen';

    public function handle(SqliteStore $db, FileStore $files): int
    {
        $path = (string) $this->argument('path');
        if ($this->option('wrangler') || $path === '') {
            $detected = SqliteImporter::detectWranglerSqlite();
            if ($detected === null) {
                $this->error('No encuentro el SQLite de Wrangler. Pasa la ruta completa al archivo .sqlite.');

                return self::FAILURE;
            }
            $path = $detected;
        }

        $this->info('Origen (solo lectura): '.$path);
        $this->info('Destino: '.($db->isMysql() ? 'MySQL/MariaDB' : 'SQLite local').'. El archivo original no se modifica.');

        $search = array_values(array_filter([
            (string) $this->option('files'),
            dirname(base_path()).'/attachments',
            dirname(base_path()).'/project/.wrangler/state/v3/r2/mytickets-files-local/blobs',
        ]));

        try {
            $result = SqliteImporter::import($path, $db, $files, $search, (bool) $this->option('force'));
        } catch (\Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        foreach ($result['tables'] as $table => $count) {
            $this->line(sprintf('  %s: %d', $table, $count));
        }
        $this->info('Adjuntos copiados: '.$result['files']);
        if ($result['skipped_files'] !== []) {
            $this->warn('Adjuntos no encontrados: '.implode(', ', $result['skipped_files']));
        }
        $this->info('Listo. Tus datos siguen en el SQLite original; ahora también están en el destino.');

        return self::SUCCESS;
    }
}

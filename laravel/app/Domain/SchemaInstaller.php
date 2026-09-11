<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\PasswordHasher;
use App\Support\SqliteStore;
use App\Support\Values;

final class SchemaInstaller
{
    /** @var list<array{0: string, 1: string, 2: int, 3: int}> */
    private const DEFAULT_STATUSES = [
        ['Open', '#64748B', 0, 0],
        ['Estimated', '#8B5CF6', 0, 1],
        ['Schedule', '#2563EB', 0, 2],
        ['Work in Progress', '#4F46E5', 0, 3],
        ['Pending Release to BD-Lab', '#0891B2', 0, 4],
        ['BD-Lab Testing', '#0D9488', 0, 5],
        ['Pending Release to BDOnline', '#EA580C', 0, 6],
        ['Awaiting for Client', '#D97706', 0, 7],
        ['Reopened', '#DB2777', 0, 8],
        ['Done', '#059669', 1, 9],
        ['Cancelled', '#DC2626', 1, 10],
    ];

    public static function ensure(SqliteStore $db): void
    {
        if ($db->schemaReady) {
            return;
        }
        try {
            $ready = $db->prepare("SELECT COUNT(*) total FROM app_meta WHERE value='done' AND `key` IN ('permanent_note_attachments_v1','billing_carryovers_v1','agenda_tasks_v1','agenda_tasks_v2','agenda_tasks_v3','agenda_task_comments_v1','users_v1')")->first();
            if ((int) ($ready->total ?? 0) === 7) {
                $db->schemaReady = true;

                return;
            }
        } catch (\Throwable) {
        }
        self::setup($db);
        $db->schemaReady = true;
    }

    public static function installTables(SqliteStore $db): void
    {
        $statements = $db->isMysql() ? self::mysqlDdl() : self::sqliteDdl();
        self::execDdl($db, $statements);
        self::ensureUserTables($db);
    }

    /**
     * @param  list<string>  $statements
     */
    private static function execDdl(SqliteStore $db, array $statements): void
    {
        foreach ($statements as $sql) {
            $db->prepare($sql)->run();
        }
    }

    private static function setup(SqliteStore $db): void
    {
        self::installTables($db);

        $count = $db->prepare('SELECT COUNT(*) total FROM statuses')->first();
        if (! (int) ($count->total ?? 0)) {
            $db->batch(array_map(
                fn (array $row) => $db->prepare('INSERT INTO statuses(name,color,is_done,position) VALUES(?,?,?,?)')->bind($row[0], $row[1], $row[2], $row[3]),
                self::DEFAULT_STATUSES,
            ));
        }

        foreach ([
            'permanent_note_attachments_v1',
            'billing_carryovers_v1',
            'agenda_tasks_v1',
            'agenda_tasks_v2',
            'agenda_tasks_v3',
            'agenda_task_comments_v1',
        ] as $key) {
            $db->prepare($db->upsertMeta())->bind($key, 'done')->run();
        }

        self::migrateUsers($db);
    }

    /**
     * @return list<string>
     */
    private static function sqliteDdl(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS statuses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE IF NOT EXISTS labels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS attention_markers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0)',
            "CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_key TEXT NOT NULL, title TEXT NOT NULL, summary TEXT NOT NULL DEFAULT '', status_id INTEGER NOT NULL, priority TEXT NOT NULL DEFAULT 'medium', next_action TEXT NOT NULL DEFAULT '', attention_marker_id INTEGER, avatar_key TEXT, is_focus INTEGER NOT NULL DEFAULT 0, due_date TEXT, jira_url TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT, cancelled_at TEXT)",
            'CREATE TABLE IF NOT EXISTS ticket_labels (ticket_id INTEGER NOT NULL, label_id INTEGER NOT NULL, PRIMARY KEY(ticket_id,label_id))',
            'CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            "CREATE TABLE IF NOT EXISTS ticket_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, from_status_id INTEGER, from_status_name TEXT, from_status_color TEXT, to_status_id INTEGER NOT NULL, to_status_name TEXT NOT NULL, to_status_color TEXT NOT NULL, event_type TEXT NOT NULL DEFAULT 'change', changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'CREATE INDEX IF NOT EXISTS ticket_status_history_ticket_changed_idx ON ticket_status_history(ticket_id,changed_at DESC,id DESC)',
            'CREATE TABLE IF NOT EXISTS reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, due_date TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)',
            "CREATE TABLE IF NOT EXISTS permanent_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, archived_at TEXT)",
            'CREATE TABLE IF NOT EXISTS permanent_note_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER NOT NULL, storage_key TEXT NOT NULL UNIQUE, file_name TEXT NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx ON permanent_note_attachments(note_id,created_at,id)',
            "CREATE TABLE IF NOT EXISTS billing_carryovers (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', jira_url TEXT NOT NULL DEFAULT '', worked_month TEXT NOT NULL, invoice_month TEXT NOT NULL, minutes INTEGER NOT NULL DEFAULT 0, client TEXT NOT NULL DEFAULT '', project TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', defer_reason TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', invoiced_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_billing_carryovers_ticket_worked_unique ON billing_carryovers(ticket_id,worked_month)',
            'CREATE INDEX IF NOT EXISTS idx_billing_carryovers_status_invoice ON billing_carryovers(status,invoice_month)',
            "CREATE TABLE IF NOT EXISTS agenda_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_date TEXT NOT NULL, content TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, is_done INTEGER NOT NULL DEFAULT 0, link_type TEXT NOT NULL DEFAULT '', link_id INTEGER, copied_from_id INTEGER, subtasks TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)",
            'CREATE INDEX IF NOT EXISTS idx_agenda_tasks_date_position ON agenda_tasks(task_date,position,id)',
            'CREATE TABLE IF NOT EXISTS agenda_task_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, subtask_id INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target ON agenda_task_comments(task_id,subtask_id,created_at,id)',
            "CREATE TABLE IF NOT EXISTS standup_guides (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', standup_date TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS standup_items (id INTEGER PRIMARY KEY AUTOINCREMENT, guide_id INTEGER NOT NULL, section TEXT NOT NULL DEFAULT 'points', position INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'CREATE INDEX IF NOT EXISTS standup_items_guide_position_idx ON standup_items(guide_id,position,id)',
            'CREATE TABLE IF NOT EXISTS app_meta (`key` TEXT PRIMARY KEY, value TEXT NOT NULL)',
        ];
    }

    /**
     * @return list<string>
     */
    private static function mysqlDdl(): array
    {
        $ts = "VARCHAR(40) NOT NULL DEFAULT (DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'))";
        $tsNull = 'VARCHAR(40) NULL';

        return [
            'CREATE TABLE IF NOT EXISTS statuses (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, color VARCHAR(16) NOT NULL, is_done INT NOT NULL DEFAULT 0, position INT NOT NULL DEFAULT 0, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS labels (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, color VARCHAR(16) NOT NULL, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS attention_markers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, color VARCHAR(16) NOT NULL, position INT NOT NULL DEFAULT 0, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            "CREATE TABLE IF NOT EXISTS tickets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ticket_key VARCHAR(80) NOT NULL, title VARCHAR(255) NOT NULL, summary TEXT NOT NULL DEFAULT (''), status_id BIGINT NOT NULL, priority VARCHAR(32) NOT NULL DEFAULT 'medium', next_action TEXT NOT NULL DEFAULT (''), attention_marker_id BIGINT NULL, avatar_key VARCHAR(255) NULL, is_focus INT NOT NULL DEFAULT 0, due_date VARCHAR(40) NULL, jira_url VARCHAR(500) NOT NULL DEFAULT '', created_at {$ts}, updated_at {$ts}, completed_at {$tsNull}, cancelled_at {$tsNull}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE TABLE IF NOT EXISTS ticket_labels (ticket_id BIGINT NOT NULL, label_id BIGINT NOT NULL, PRIMARY KEY(ticket_id,label_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            "CREATE TABLE IF NOT EXISTS comments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT NOT NULL, content TEXT NOT NULL, created_at {$ts}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS ticket_status_history (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT NOT NULL, from_status_id BIGINT NULL, from_status_name VARCHAR(120) NULL, from_status_color VARCHAR(16) NULL, to_status_id BIGINT NOT NULL, to_status_name VARCHAR(120) NOT NULL, to_status_color VARCHAR(16) NOT NULL, event_type VARCHAR(32) NOT NULL DEFAULT 'change', changed_at {$ts}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS ticket_status_history_ticket_changed_idx ON ticket_status_history(ticket_id,changed_at,id)',
            "CREATE TABLE IF NOT EXISTS reminders (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, content TEXT NOT NULL, due_date VARCHAR(40) NOT NULL, is_done INT NOT NULL DEFAULT 0, created_at {$ts}, completed_at {$tsNull}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS permanent_notes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL DEFAULT '', content TEXT NOT NULL, created_at {$ts}, updated_at {$ts}, archived_at {$tsNull}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS permanent_note_attachments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, note_id BIGINT NOT NULL, storage_key VARCHAR(255) NOT NULL, file_name VARCHAR(255) NOT NULL, content_type VARCHAR(120) NOT NULL, size_bytes BIGINT NOT NULL, created_at {$ts}, UNIQUE KEY permanent_note_attachments_storage_key_unique (storage_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx ON permanent_note_attachments(note_id,created_at,id)',
            "CREATE TABLE IF NOT EXISTS billing_carryovers (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, ticket_id BIGINT NOT NULL, ticket_key VARCHAR(80) NOT NULL DEFAULT '', ticket_title VARCHAR(255) NOT NULL DEFAULT '', jira_url VARCHAR(500) NOT NULL DEFAULT '', worked_month VARCHAR(7) NOT NULL, invoice_month VARCHAR(7) NOT NULL, minutes INT NOT NULL DEFAULT 0, client VARCHAR(120) NOT NULL DEFAULT '', project VARCHAR(120) NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT (''), defer_reason TEXT NOT NULL DEFAULT (''), status VARCHAR(32) NOT NULL DEFAULT 'pending', invoiced_at {$tsNull}, created_at {$ts}, updated_at {$ts}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_billing_carryovers_ticket_worked_unique ON billing_carryovers(ticket_id,worked_month)',
            'CREATE INDEX IF NOT EXISTS idx_billing_carryovers_status_invoice ON billing_carryovers(status,invoice_month)',
            "CREATE TABLE IF NOT EXISTS agenda_tasks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, task_date VARCHAR(10) NOT NULL, content TEXT NOT NULL, position INT NOT NULL DEFAULT 0, is_done INT NOT NULL DEFAULT 0, link_type VARCHAR(32) NOT NULL DEFAULT '', link_id BIGINT NULL, copied_from_id BIGINT NULL, subtasks TEXT NOT NULL DEFAULT ('[]'), created_at {$ts}, updated_at {$ts}, completed_at {$tsNull}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS idx_agenda_tasks_date_position ON agenda_tasks(task_date,position,id)',
            "CREATE TABLE IF NOT EXISTS agenda_task_comments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, task_id BIGINT NOT NULL, subtask_id BIGINT NOT NULL DEFAULT 0, content TEXT NOT NULL, created_at {$ts}, updated_at {$ts}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target ON agenda_task_comments(task_id,subtask_id,created_at,id)',
            "CREATE TABLE IF NOT EXISTS standup_guides (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL DEFAULT '', standup_date VARCHAR(10) NOT NULL, created_at {$ts}, updated_at {$ts}, user_id BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS standup_items (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, guide_id BIGINT NOT NULL, section VARCHAR(40) NOT NULL DEFAULT 'points', position INT NOT NULL DEFAULT 0, content TEXT NOT NULL, ticket_key VARCHAR(80) NOT NULL DEFAULT '', ticket_title VARCHAR(255) NOT NULL DEFAULT '', is_done INT NOT NULL DEFAULT 0, created_at {$ts}) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS standup_items_guide_position_idx ON standup_items(guide_id,position,id)',
            'CREATE TABLE IF NOT EXISTS app_meta (`key` VARCHAR(64) NOT NULL, value VARCHAR(255) NOT NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            "CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL, name VARCHAR(80) NOT NULL DEFAULT '', password_hash VARCHAR(255) NOT NULL, password_salt VARCHAR(255) NOT NULL, created_at {$ts}, updated_at {$ts}, UNIQUE KEY users_email_unique (email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS sessions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, token VARCHAR(128) NOT NULL, expires_at VARCHAR(40) NOT NULL, created_at {$ts}, UNIQUE KEY sessions_token_unique (token)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            'CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at)',
        ];
    }

    private static function ensureUserTables(SqliteStore $db): void
    {
        if ($db->isMysql()) {
            return;
        }
        foreach ([
            "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'CREATE TABLE IF NOT EXISTS sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token)',
            'CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at)',
        ] as $sql) {
            $db->prepare($sql)->run();
        }
        foreach (['statuses', 'labels', 'attention_markers', 'tickets', 'reminders', 'permanent_notes', 'standup_guides', 'agenda_tasks', 'billing_carryovers'] as $table) {
            self::addUserIdColumn($db, $table);
        }
    }

    private static function migrateUsers(SqliteStore $db): void
    {
        $key = 'users_v1';
        self::ensureUserTables($db);
        $applied = $db->prepare('SELECT value FROM app_meta WHERE `key`=?')->bind($key)->first();
        $seed = self::seedUser();
        $owner = null;
        if ($seed !== null) {
            $owner = $db->prepare('SELECT id FROM users WHERE email=?')->bind($seed['email'])->first();
            if (! $owner) {
                $hashed = PasswordHasher::hash($seed['password']);
                $inserted = $db->prepare('INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?)')
                    ->bind($seed['email'], $seed['name'], $hashed['hash'], $hashed['salt'])->run();
                $owner = (object) ['id' => (int) $inserted->meta->last_row_id];
            }
        } else {
            $owner = $db->prepare('SELECT id FROM users ORDER BY id LIMIT 1')->first();
        }
        if (! $owner) {
            if (($applied->value ?? null) === 'done') {
                return;
            }
            throw new \RuntimeException('Configura SEED_USER_EMAIL, SEED_USER_NAME y SEED_USER_PASSWORD');
        }
        $userId = (int) $owner->id;
        if (($applied->value ?? null) !== 'done') {
            foreach (['statuses', 'labels', 'attention_markers', 'tickets', 'reminders', 'permanent_notes', 'standup_guides', 'agenda_tasks', 'billing_carryovers'] as $table) {
                $db->prepare('UPDATE '.$table.' SET user_id=? WHERE user_id IS NULL')->bind($userId)->run();
            }
            $db->prepare($db->upsertMeta())->bind($key, 'done')->run();
        }
        self::seedWorkspace($db, $userId);
    }

    private static function addUserIdColumn(SqliteStore $db, string $table): void
    {
        if ($db->hasColumn($table, 'user_id')) {
            return;
        }
        $type = $db->isMysql() ? 'BIGINT NULL' : 'INTEGER';
        $db->prepare('ALTER TABLE '.$table.' ADD COLUMN user_id '.$type)->run();
    }

    public static function seedWorkspace(SqliteStore $db, int $userId): void
    {
        $statusCount = $db->prepare('SELECT COUNT(*) total FROM statuses WHERE user_id=?')->bind($userId)->first();
        if (! (int) ($statusCount->total ?? 0)) {
            $db->batch(array_map(
                fn (array $row) => $db->prepare('INSERT INTO statuses(name,color,is_done,position,user_id) VALUES(?,?,?,?,?)')->bind($row[0], $row[1], $row[2], $row[3], $userId),
                self::DEFAULT_STATUSES,
            ));
        }
    }

    /** @return array{email: string, name: string, password: string}|null */
    public static function seedUser(): ?array
    {
        $email = strtolower(trim((string) config('mytickets.seed_email')));
        $name = trim((string) config('mytickets.seed_name'));
        $password = (string) config('mytickets.seed_password');
        if ($email === '' || $name === '' || $password === '') {
            return null;
        }

        return ['email' => $email, 'name' => $name, 'password' => $password];
    }

    public static function createUser(SqliteStore $db, object $payload, bool $seed = true): array
    {
        $normalized = Values::normalizeEmail($payload->email ?? '');
        if (! Values::validEmail($normalized)) {
            throw new \RuntimeException('Escribe un correo válido.');
        }
        if (! Values::text($payload->name ?? null)) {
            throw new \RuntimeException('Escribe un nombre.');
        }
        if (strlen((string) ($payload->password ?? '')) < 6) {
            throw new \RuntimeException('La contraseña debe tener al menos 6 caracteres.');
        }
        $existing = $db->prepare('SELECT id FROM users WHERE email=?')->bind($normalized)->first();
        if ($existing) {
            throw new \RuntimeException('Ese correo ya está registrado.');
        }
        $hashed = PasswordHasher::hash((string) $payload->password);
        $inserted = $db->prepare('INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?)')
            ->bind($normalized, substr(Values::text($payload->name), 0, 80), $hashed['hash'], $hashed['salt'])->run();
        $row = $db->prepare('SELECT id,email,name,created_at createdAt FROM users WHERE id=?')
            ->bind((int) $inserted->meta->last_row_id)->first();
        if ($seed) {
            self::seedWorkspace($db, (int) $row->id);
        }

        return Values::publicUser($row);
    }
}

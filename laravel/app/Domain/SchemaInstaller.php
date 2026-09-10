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
            $ready = $db->prepare("SELECT COUNT(*) total FROM app_meta WHERE value='done' AND key IN ('permanent_note_attachments_v1','billing_carryovers_v1','agenda_tasks_v1','agenda_tasks_v2','agenda_tasks_v3','agenda_task_comments_v1','users_v1')")->first();
            if ((int) ($ready->total ?? 0) === 7) {
                $db->schemaReady = true;

                return;
            }
        } catch (\Throwable) {
        }
        self::setup($db);
        $db->schemaReady = true;
    }

    private static function setup(SqliteStore $db): void
    {
        $db->batch([
            $db->prepare('CREATE TABLE IF NOT EXISTS statuses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0)'),
            $db->prepare('CREATE TABLE IF NOT EXISTS labels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL)'),
            $db->prepare('CREATE TABLE IF NOT EXISTS attention_markers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS tickets (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_key TEXT NOT NULL, title TEXT NOT NULL, summary TEXT NOT NULL DEFAULT '', status_id INTEGER NOT NULL, priority TEXT NOT NULL DEFAULT 'medium', next_action TEXT NOT NULL DEFAULT '', attention_marker_id INTEGER, avatar_key TEXT, is_focus INTEGER NOT NULL DEFAULT 0, due_date TEXT, jira_url TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT, cancelled_at TEXT)"),
            $db->prepare('CREATE TABLE IF NOT EXISTS ticket_labels (ticket_id INTEGER NOT NULL, label_id INTEGER NOT NULL, PRIMARY KEY(ticket_id,label_id))'),
            $db->prepare('CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS ticket_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, from_status_id INTEGER, from_status_name TEXT, from_status_color TEXT, to_status_id INTEGER NOT NULL, to_status_name TEXT NOT NULL, to_status_color TEXT NOT NULL, event_type TEXT NOT NULL DEFAULT 'change', changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
            $db->prepare('CREATE INDEX IF NOT EXISTS ticket_status_history_ticket_changed_idx ON ticket_status_history(ticket_id,changed_at DESC,id DESC)'),
            $db->prepare('CREATE TABLE IF NOT EXISTS reminders (id INTEGER PRIMARY KEY AUTOINCREMENT, content TEXT NOT NULL, due_date TEXT NOT NULL, is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS permanent_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, archived_at TEXT)"),
            $db->prepare('CREATE TABLE IF NOT EXISTS permanent_note_attachments (id INTEGER PRIMARY KEY AUTOINCREMENT, note_id INTEGER NOT NULL, storage_key TEXT NOT NULL UNIQUE, file_name TEXT NOT NULL, content_type TEXT NOT NULL, size_bytes INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'),
            $db->prepare('CREATE INDEX IF NOT EXISTS permanent_note_attachments_note_idx ON permanent_note_attachments(note_id,created_at,id)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS billing_carryovers (id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', jira_url TEXT NOT NULL DEFAULT '', worked_month TEXT NOT NULL, invoice_month TEXT NOT NULL, minutes INTEGER NOT NULL DEFAULT 0, client TEXT NOT NULL DEFAULT '', project TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', defer_reason TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', invoiced_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
            $db->prepare('CREATE UNIQUE INDEX IF NOT EXISTS idx_billing_carryovers_ticket_worked_unique ON billing_carryovers(ticket_id,worked_month)'),
            $db->prepare('CREATE INDEX IF NOT EXISTS idx_billing_carryovers_status_invoice ON billing_carryovers(status,invoice_month)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS agenda_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_date TEXT NOT NULL, content TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, is_done INTEGER NOT NULL DEFAULT 0, link_type TEXT NOT NULL DEFAULT '', link_id INTEGER, copied_from_id INTEGER, subtasks TEXT NOT NULL DEFAULT '[]', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT)"),
            $db->prepare('CREATE INDEX IF NOT EXISTS idx_agenda_tasks_date_position ON agenda_tasks(task_date,position,id)'),
            $db->prepare('CREATE TABLE IF NOT EXISTS agenda_task_comments (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, subtask_id INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'),
            $db->prepare('CREATE INDEX IF NOT EXISTS idx_agenda_task_comments_target ON agenda_task_comments(task_id,subtask_id,created_at,id)'),
            $db->prepare("CREATE TABLE IF NOT EXISTS standup_guides (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', standup_date TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
            $db->prepare("CREATE TABLE IF NOT EXISTS standup_items (id INTEGER PRIMARY KEY AUTOINCREMENT, guide_id INTEGER NOT NULL, section TEXT NOT NULL DEFAULT 'points', position INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, ticket_key TEXT NOT NULL DEFAULT '', ticket_title TEXT NOT NULL DEFAULT '', is_done INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
            $db->prepare('CREATE INDEX IF NOT EXISTS standup_items_guide_position_idx ON standup_items(guide_id,position,id)'),
            $db->prepare('CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)'),
        ]);

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
            $db->prepare('INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)')->bind($key, 'done')->run();
        }

        self::migrateUsers($db);
    }

    private static function migrateUsers(SqliteStore $db): void
    {
        $key = 'users_v1';
        $db->batch([
            $db->prepare("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, name TEXT NOT NULL DEFAULT '', password_hash TEXT NOT NULL, password_salt TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"),
            $db->prepare('CREATE TABLE IF NOT EXISTS sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, expires_at TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'),
            $db->prepare('CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token)'),
            $db->prepare('CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at)'),
        ]);
        foreach (['statuses', 'labels', 'attention_markers', 'tickets', 'reminders', 'permanent_notes', 'standup_guides', 'agenda_tasks', 'billing_carryovers'] as $table) {
            self::addUserIdColumn($db, $table);
        }
        $applied = $db->prepare('SELECT value FROM app_meta WHERE key=?')->bind($key)->first();
        $seed = self::seedUser();
        $owner = null;
        if ($seed !== null) {
            $owner = $db->prepare('SELECT id FROM users WHERE email=?')->bind($seed['email'])->first();
            if (! $owner) {
                $hashed = PasswordHasher::hash($seed['password']);
                $owner = $db->prepare('INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?) RETURNING id')
                    ->bind($seed['email'], $seed['name'], $hashed['hash'], $hashed['salt'])->first();
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
            $db->prepare('INSERT OR REPLACE INTO app_meta(key,value) VALUES(?,?)')->bind($key, 'done')->run();
        }
        self::seedWorkspace($db, $userId);
    }

    private static function addUserIdColumn(SqliteStore $db, string $table): void
    {
        $columns = $db->prepare('PRAGMA table_info('.$table.')')->all()->results;
        foreach ($columns as $column) {
            if (($column->name ?? '') === 'user_id') {
                return;
            }
        }
        $db->prepare('ALTER TABLE '.$table.' ADD COLUMN user_id INTEGER')->run();
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
        $row = $db->prepare('INSERT INTO users(email,name,password_hash,password_salt) VALUES(?,?,?,?) RETURNING id,email,name,created_at createdAt')
            ->bind($normalized, substr(Values::text($payload->name), 0, 80), $hashed['hash'], $hashed['salt'])->first();
        if ($seed) {
            self::seedWorkspace($db, (int) $row->id);
        }

        return Values::publicUser($row);
    }
}

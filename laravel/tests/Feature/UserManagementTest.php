<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\FileStore;
use App\Support\SqliteStore;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir().'/mytickets-users-'.uniqid();
        mkdir($this->dataDir, 0777, true);
        parent::setUp();
        $this->disableCookieEncryption();
        config([
            'database.default' => 'sqlite',
            'mytickets.data_dir' => $this->dataDir,
            'mytickets.seed_email' => 'admin@example.com',
            'mytickets.seed_name' => 'Admin',
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

    public function test_seed_user_is_superadmin_and_can_create_users(): void
    {
        $admin = $this->login('admin@example.com', 'secret12');
        $data = $this->api('GET', '/api/data', $admin);
        $data->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.role', 'superadmin');
        $this->assertCount(1, $data->json('users'));

        $created = $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'clave12',
            'role' => 'user',
        ]);
        $created->assertOk();
        $this->assertCount(2, $created->json('users'));
        $this->assertSame('user', collect($created->json('users'))->firstWhere('email', 'ana@example.com')['role']);
    }

    public function test_regular_user_cannot_manage_or_list_users(): void
    {
        $admin = $this->login('admin@example.com', 'secret12');
        $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'clave12',
        ])->assertOk();

        $ana = $this->login('ana@example.com', 'clave12');
        $data = $this->api('GET', '/api/data', $ana);
        $data->assertOk()
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonPath('user.role', 'user');
        $this->assertSame([], $data->json('users'));

        $this->api('POST', '/api/data', $ana, [
            'action' => 'create_user',
            'name' => 'Luis',
            'email' => 'luis@example.com',
            'password' => 'clave12',
        ])->assertStatus(403)->assertJson(['error' => 'No tienes permiso para gestionar usuarios.']);

        $adminId = (int) $this->api('GET', '/api/data', $admin)->json('user.id');
        $this->api('POST', '/api/data', $ana, [
            'action' => 'update_user',
            'id' => $adminId,
            'name' => 'Hack',
            'email' => 'admin@example.com',
            'role' => 'user',
        ])->assertStatus(403);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'reset_user_password',
            'id' => $adminId,
            'password' => 'hacked1',
        ])->assertStatus(403);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'delete_user',
            'id' => $adminId,
        ])->assertStatus(403);
    }

    public function test_users_only_see_and_mutate_their_own_records(): void
    {
        $admin = $this->login('admin@example.com', 'secret12');
        $adminData = $this->api('GET', '/api/data', $admin);
        $adminStatus = (int) $adminData->json('statuses.0.id');
        $this->api('POST', '/api/data', $admin, [
            'action' => 'save_ticket',
            'ticketKey' => 'ADM-1',
            'title' => 'Ticket del admin',
            'statusId' => $adminStatus,
        ])->assertOk();
        $this->api('POST', '/api/data', $admin, [
            'action' => 'save_permanent_note',
            'title' => 'Nota admin',
            'content' => 'secreto del admin',
        ])->assertOk();
        $adminTicketId = (int) collect($this->api('GET', '/api/data', $admin)->json('tickets'))->firstWhere('ticketKey', 'ADM-1')['id'];
        $adminNoteId = (int) collect($this->api('GET', '/api/data', $admin)->json('permanentNotes'))->firstWhere('title', 'Nota admin')['id'];

        $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'clave12',
        ])->assertOk();

        $ana = $this->login('ana@example.com', 'clave12');
        $anaData = $this->api('GET', '/api/data', $ana);
        $anaData->assertOk();
        $this->assertSame([], $anaData->json('tickets'));
        $this->assertSame([], $anaData->json('permanentNotes'));
        $anaStatus = (int) $anaData->json('statuses.0.id');
        $this->assertNotSame($adminStatus, $anaStatus);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'save_ticket',
            'ticketKey' => 'ANA-1',
            'title' => 'Ticket de Ana',
            'statusId' => $anaStatus,
        ])->assertOk();
        $anaTicketId = (int) collect($this->api('GET', '/api/data', $ana)->json('tickets'))->firstWhere('ticketKey', 'ANA-1')['id'];

        $this->api('POST', '/api/data', $ana, [
            'action' => 'save_ticket',
            'id' => $adminTicketId,
            'ticketKey' => 'HACK',
            'title' => 'No deberia',
            'statusId' => $anaStatus,
        ])->assertStatus(400);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'delete_ticket',
            'id' => $adminTicketId,
        ])->assertStatus(400);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'status',
            'id' => $adminTicketId,
            'statusId' => $anaStatus,
        ])->assertStatus(400);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'status',
            'id' => $anaTicketId,
            'statusId' => $adminStatus,
        ])->assertStatus(400);

        $this->api('POST', '/api/data', $ana, [
            'action' => 'archive_permanent_note',
            'id' => $adminNoteId,
            'archived' => true,
        ])->assertStatus(400);

        $this->api('GET', '/api/permanent-note-attachments/1', $ana)
            ->assertStatus(404);

        $adminView = $this->api('GET', '/api/data', $admin);
        $this->assertNull(collect($adminView->json('tickets'))->firstWhere('ticketKey', 'ANA-1'));
        $this->assertNotNull(collect($adminView->json('tickets'))->firstWhere('ticketKey', 'ADM-1'));
        $this->assertSame('Ticket del admin', collect($adminView->json('tickets'))->firstWhere('ticketKey', 'ADM-1')['title']);
        $this->assertSame('secreto del admin', collect($adminView->json('permanentNotes'))->firstWhere('title', 'Nota admin')['content']);
    }

    public function test_superadmin_can_edit_reset_password_and_delete_users(): void
    {
        $admin = $this->login('admin@example.com', 'secret12');
        $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'clave12',
        ])->assertOk();
        $anaId = (int) collect($this->api('GET', '/api/data', $admin)->json('users'))->firstWhere('email', 'ana@example.com')['id'];

        $this->api('POST', '/api/data', $admin, [
            'action' => 'update_user',
            'id' => $anaId,
            'name' => 'Ana Lopez',
            'email' => 'ana.lopez@example.com',
            'role' => 'user',
        ])->assertOk();

        $this->api('POST', '/api/data', $admin, [
            'action' => 'reset_user_password',
            'id' => $anaId,
            'password' => 'nueva12',
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'ana@example.com',
            'password' => 'clave12',
        ])->assertStatus(401);

        $ana = $this->login('ana.lopez@example.com', 'nueva12');
        $this->api('GET', '/api/data', $ana)
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana Lopez');

        $this->api('POST', '/api/data', $admin, [
            'action' => 'delete_user',
            'id' => $anaId,
        ])->assertOk();

        $this->postJson('/api/login', [
            'email' => 'ana.lopez@example.com',
            'password' => 'nueva12',
        ])->assertStatus(401);
    }

    public function test_passwords_are_hashed_and_superadmin_cannot_delete_self(): void
    {
        $admin = $this->login('admin@example.com', 'secret12');
        $adminId = (int) $this->api('GET', '/api/data', $admin)->json('user.id');
        $db = $this->app->make(SqliteStore::class);
        $row = $db->prepare('SELECT password_hash passwordHash FROM users WHERE id=?')->bind($adminId)->first();
        $this->assertNotSame('secret12', (string) $row->passwordHash);
        $this->assertNotEmpty($row->passwordHash);

        $this->api('POST', '/api/data', $admin, [
            'action' => 'delete_user',
            'id' => $adminId,
        ])->assertStatus(400)->assertJson(['error' => 'No puedes eliminar tu propio usuario.']);

        $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'X',
            'email' => 'bad',
            'password' => 'clave12',
        ])->assertStatus(400);

        $this->api('POST', '/api/data', $admin, [
            'action' => 'create_user',
            'name' => 'X',
            'email' => 'ok@example.com',
            'password' => '123',
        ])->assertStatus(400);
    }

    /**
     * @return array<string, string>
     */
    private function login(string $email, string $password): array
    {
        $login = $this->postJson('/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $login->assertOk();
        $token = collect($login->headers->getCookies())->firstWhere(
            fn ($cookie) => $cookie->getName() === 'mt_session'
        )?->getValue();
        $this->assertNotEmpty($token);

        return ['mt_session' => $token];
    }

    private function api(string $method, string $uri, array $cookies, ?array $payload = null): TestResponse
    {
        $content = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->call(
            $method,
            $uri,
            [],
            $cookies,
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $content,
        );
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

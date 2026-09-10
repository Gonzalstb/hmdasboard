<?php

namespace Tests\Feature;

use Tests\TestCase;

class MyTicketsApiTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir().'/mytickets-laravel-test-'.uniqid();
        mkdir($this->dataDir, 0777, true);
        parent::setUp();
        $this->disableCookieEncryption();
        config([
            'mytickets.data_dir' => $this->dataDir,
            'mytickets.seed_email' => 'test@example.com',
            'mytickets.seed_name' => 'Test',
            'mytickets.seed_password' => 'secret12',
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->dataDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->dataDir);
        }
    }

    public function test_home_serves_spa(): void
    {
        $this->get('/')->assertOk()->assertSee('Inicia sesión', false);
    }

    public function test_data_requires_login(): void
    {
        $this->getJson('/api/data')
            ->assertStatus(401)
            ->assertJson(['error' => 'Debes iniciar sesión.']);
    }

    public function test_login_and_workspace_roundtrip(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'secret12',
        ]);
        $login->assertOk()->assertJsonPath('user.email', 'test@example.com');

        $token = collect($login->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === 'mt_session')?->getValue();
        $this->assertNotEmpty($token);

        $data = $this->call('GET', '/api/data', [], ['mt_session' => $token]);
        $data->assertOk()
            ->assertJsonPath('user.email', 'test@example.com');
        $this->assertCount(11, $data->json('statuses'));

        $note = $this->call(
            'POST',
            '/api/data',
            [],
            ['mt_session' => $token],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode([
                'action' => 'save_permanent_note',
                'title' => 'Hostinger',
                'content' => 'nota de prueba',
            ]),
        );
        $note->assertOk();
        $this->assertNotEmpty($note->json('savedNoteId'));
    }
}

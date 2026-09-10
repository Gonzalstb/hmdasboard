<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\ActionDispatcher;
use App\Domain\AuthSessions;
use App\Domain\NoteAttachments;
use App\Domain\SchemaInstaller;
use App\Domain\WorkspaceData;
use App\Support\FileStore;
use App\Support\PasswordHasher;
use App\Support\SqliteStore;
use App\Support\Values;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

final class AppController extends Controller
{
    private const NO_STORE = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ];

    public function __construct(
        private readonly SqliteStore $db,
        private readonly FileStore $files,
    ) {}

    public function page(): Response
    {
        $styles = file_get_contents(resource_path('ui/styles.css')) ?: '';
        $markup = rtrim(file_get_contents(resource_path('ui/markup.html')) ?: '', "\n");
        $script = file_get_contents(resource_path('ui/client.js')) ?: '';
        $html = implode("\n", [
            '<!doctype html>',
            '<html lang="es">',
            '<head>',
            '  <meta charset="utf-8">',
            '  <meta name="viewport" content="width=device-width,initial-scale=1">',
            '  <title>MyTickets · Agenda visual</title>',
            '  <style>',
            $styles,
            '  </style>',
            '</head>',
            '<body>',
            $markup,
            '  <script>',
            $script,
            '  </script>',
            '</body>',
            '</html>',
        ]);

        return response($html, 200, [...self::NO_STORE, 'Content-Type' => 'text/html; charset=utf-8']);
    }

    public function login(Request $request)
    {
        if ($request->method() !== 'POST') {
            return response('Method not allowed', 405, self::NO_STORE);
        }
        try {
            SchemaInstaller::ensure($this->db);
            $payload = $this->jsonBody($request);
            $email = Values::normalizeEmail($payload->email ?? '');
            $user = $this->db->prepare('SELECT id,email,name,password_hash passwordHash,password_salt passwordSalt FROM users WHERE email=?')->bind($email)->first();
            if (! $user || ! PasswordHasher::verify((string) ($payload->password ?? ''), $user->passwordHash, $user->passwordSalt)) {
                return $this->json(['error' => 'Correo o contraseña incorrectos.'], 401);
            }
            $token = AuthSessions::create($this->db, (int) $user->id);

            return $this->json(['user' => Values::publicUser($user)])->cookie($this->sessionCookie($token));
        } catch (\Throwable $error) {
            return $this->json(['error' => $error->getMessage() ?: 'No se pudo iniciar sesión.'], 400);
        }
    }

    public function logout(Request $request)
    {
        if ($request->method() !== 'POST') {
            return response('Method not allowed', 405, self::NO_STORE);
        }
        AuthSessions::destroy($this->db, $request->cookie((string) config('mytickets.cookie')));

        return $this->json(['ok' => true])->cookie($this->clearSessionCookie());
    }

    public function data(Request $request)
    {
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        try {
            $result = $request->method() === 'POST'
                ? ActionDispatcher::run($this->db, $this->jsonBody($request), $this->files, (int) $user->id)
                : [];
            $cookie = null;
            if (! empty($result['newSession'])) {
                $cookie = $this->sessionCookie((string) $result['newSession']);
                unset($result['newSession']);
            }
            $response = $this->json([...WorkspaceData::get($this->db, (int) $user->id), ...$result]);
            if ($cookie) {
                $response->cookie($cookie);
            }

            return $response;
        } catch (\Throwable $error) {
            return $this->json(['error' => $error->getMessage() ?: 'No se pudo guardar.'], 400);
        }
    }

    public function uploadAttachments(Request $request)
    {
        if ($request->method() !== 'POST') {
            return response('Method not allowed', 405, self::NO_STORE);
        }
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        try {
            $uploaded = $request->file('files', []);
            if (! is_array($uploaded)) {
                $uploaded = $uploaded ? [$uploaded] : [];
            }
            NoteAttachments::save($this->db, $this->files, $request->input('noteId'), $uploaded, (int) $user->id);

            return $this->json(WorkspaceData::get($this->db, (int) $user->id));
        } catch (\Throwable $error) {
            return $this->json(['error' => $error->getMessage() ?: 'No se pudieron guardar los documentos.'], 400);
        }
    }

    public function downloadAttachment(Request $request, int $id): mixed
    {
        if ($request->method() !== 'GET') {
            return response('Method not allowed', 405, self::NO_STORE);
        }
        $user = $this->requireUser($request);
        if ($user instanceof \Illuminate\Http\JsonResponse) {
            return $user;
        }
        try {
            $attachment = $this->db->prepare('SELECT a.storage_key storageKey,a.file_name fileName,a.content_type contentType FROM permanent_note_attachments a JOIN permanent_notes n ON n.id=a.note_id WHERE a.id=? AND n.user_id=?')
                ->bind($id, (int) $user->id)->first();
            if (! $attachment) {
                return response('Documento no encontrado', 404, self::NO_STORE);
            }
            $object = $this->files->get((string) $attachment->storageKey);
            if (! $object) {
                return response('Documento no encontrado', 404, self::NO_STORE);
            }
            $inline = $request->query('download') !== '1' && preg_match('#^(application/pdf|image/|text/)#i', (string) $attachment->contentType) === 1;
            $disposition = ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode((string) $attachment->fileName);

            return response()->file($object->path, [
                ...self::NO_STORE,
                'Content-Type' => (string) ($attachment->contentType ?: 'application/octet-stream'),
                'Content-Disposition' => $disposition,
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $error) {
            return $this->json(['error' => $error->getMessage() ?: 'No se pudo abrir el documento.'], 400);
        }
    }

    private function requireUser(Request $request): mixed
    {
        try {
            SchemaInstaller::ensure($this->db);
        } catch (\Throwable $error) {
            return $this->json(['error' => $error->getMessage() ?: 'La base de datos no está disponible.'], 400);
        }
        $user = AuthSessions::user($this->db, $request->cookie((string) config('mytickets.cookie')));
        if (! $user) {
            return $this->json(['error' => 'Debes iniciar sesión.'], 401);
        }

        return $user;
    }

    private function jsonBody(Request $request): object
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $decoded = json_decode((string) $request->getContent());

            return is_object($decoded) ? $decoded : (object) [];
        }

        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private function json(array $body, int $status = 200): \Illuminate\Http\JsonResponse
    {
        return response()->json($body, $status, self::NO_STORE);
    }

    private function sessionCookie(string $token): Cookie
    {
        return cookie((string) config('mytickets.cookie'), $token, 60 * 24 * 30, '/', null, null, true, false, 'Lax');
    }

    private function clearSessionCookie(): Cookie
    {
        return cookie((string) config('mytickets.cookie'), '', -2628000, '/', null, null, true, false, 'Lax');
    }
}

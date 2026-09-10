<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\FileStore;
use App\Support\SqliteStore;
use App\Support\Values;
use Illuminate\Http\UploadedFile;

final class NoteAttachments
{
    private const MIME = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public static function safeName(mixed $value): string
    {
        $name = preg_replace('/[\\\\\\/\x00-\x1f\x7f]/', '_', (string) ($value ?: 'documento')) ?? 'documento';
        $name = trim($name);
        $name = substr($name, 0, 180);

        return $name !== '' ? $name : 'documento';
    }

    /** @param  list<UploadedFile>  $files */
    public static function save(SqliteStore $db, FileStore $files, mixed $noteId, array $files, int $userId): void
    {
        SchemaInstaller::ensure($db);
        $id = (int) Values::number($noteId);
        $note = $db->prepare('SELECT id,archived_at archivedAt FROM permanent_notes WHERE id=? AND user_id=?')->bind($id, $userId)->first();
        if (! $note) {
            throw new \RuntimeException('La nota permanente ya no existe.');
        }
        if ($note->archivedAt) {
            throw new \RuntimeException('Restaura la nota antes de añadir documentos.');
        }
        $uploads = array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile && $file->isValid()));
        if (! $uploads) {
            throw new \RuntimeException('Selecciona al menos un documento.');
        }
        if (count($uploads) > 5) {
            throw new \RuntimeException('Puedes adjuntar un máximo de 5 documentos cada vez.');
        }
        $saved = [];
        try {
            foreach ($uploads as $file) {
                $size = (int) $file->getSize();
                if ($size < 1 || $size > 10 * 1024 * 1024) {
                    throw new \RuntimeException('Cada documento debe ocupar entre 1 byte y 10 MB.');
                }
                $fileName = self::safeName($file->getClientOriginalName());
                $parts = explode('.', $fileName);
                $extension = strtolower((string) array_pop($parts));
                $contentType = self::MIME[$extension] ?? null;
                if (! $contentType) {
                    throw new \RuntimeException('El formato de '.$fileName.' no está permitido.');
                }
                $storageKey = 'permanent-notes/'.$id.'/'.(string) \Illuminate\Support\Str::uuid();
                $files->put($storageKey, (string) file_get_contents($file->getRealPath()));
                $db->prepare('INSERT INTO permanent_note_attachments(note_id,storage_key,file_name,content_type,size_bytes) VALUES(?,?,?,?,?)')
                    ->bind($id, $storageKey, $fileName, $contentType, $size)->run();
                $saved[] = $storageKey;
            }
            $suggestedTitle = substr(preg_replace('/\.[^.]+$/', '', self::safeName($uploads[0]->getClientOriginalName())) ?? '', 0, 100);
            $suggestedTitle = trim($suggestedTitle) !== '' ? trim($suggestedTitle) : 'Documento';
            $db->prepare("UPDATE permanent_notes SET title=CASE WHEN trim(title)='' THEN ? ELSE title END,updated_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=?")
                ->bind($suggestedTitle, $id, $userId)->run();
        } catch (\Throwable $error) {
            foreach ($saved as $key) {
                $files->delete($key);
                $db->prepare('DELETE FROM permanent_note_attachments WHERE storage_key=?')->bind($key)->run();
            }
            throw $error;
        }
    }
}

<?php

namespace App\Services\Documents;

use App\Exceptions\DomainException;
use App\Models\Business;
use App\Models\PrivateDocument;
use App\Support\Context;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Private files outside DocumentRoot, tenant-scoped, content-hashed. */
class DocumentStore
{
    public const SAFE_UPLOAD_MIMES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];

    public function put(Business $business, string $kind, string $originalName, string $contents, string $mime): PrivateDocument
    {
        $ulid = strtolower((string) Str::ulid());
        $ext = self::SAFE_UPLOAD_MIMES[$mime] ?? 'bin';
        $rel = $business->ulid.'/'.date('Y/m').'/'.$ulid.'.'.$ext;
        $abs = rtrim(config('finance.storage.private_root'), '/').'/'.$rel;
        if (! is_dir(dirname($abs)) && ! mkdir(dirname($abs), 0770, true) && ! is_dir(dirname($abs))) {
            throw new \RuntimeException('Cannot create private storage directory');
        }
        file_put_contents($abs, $contents, LOCK_EX);
        @chmod($abs, 0640);

        return PrivateDocument::create([
            'business_id' => $business->id,
            'ulid' => $ulid,
            'kind' => $kind,
            'original_name' => self::safeName($originalName),
            'stored_path' => $rel,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'created_by' => app(Context::class)->user?->id,
        ]);
    }

    /** @param list<string>|null $allowedMimes */
    public function storeUpload(Business $business, UploadedFile $file, string $kind, ?array $allowedMimes = null): PrivateDocument
    {
        $allowed = $allowedMimes ?? array_keys(self::SAFE_UPLOAD_MIMES);
        $maxBytes = config('finance.storage.max_upload_kb') * 1024;
        if (! $file->isValid() || $file->getSize() > $maxBytes) {
            throw new DomainException('Upload is missing or too large.');
        }
        $contents = (string) file_get_contents($file->getRealPath());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        if (! in_array($mime, $allowed, true)) {
            throw new DomainException('Unsupported file type. Allowed: PDF, PNG, JPEG.');
        }

        return $this->put($business, $kind, $file->getClientOriginalName(), $contents, $mime);
    }

    public static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '_', basename($name)) ?: 'file';

        return mb_substr(trim($name, ' .'), 0, 150) ?: 'file';
    }

    public function dataUri(?PrivateDocument $doc): ?string
    {
        if (! $doc || ! in_array($doc->mime, ['image/png', 'image/jpeg'], true) || ! is_file($doc->absolutePath())) {
            return null;
        }

        return 'data:'.$doc->mime.';base64,'.base64_encode($doc->contents());
    }

    /** Delete tenant documents; files are removed only after the surrounding transaction commits. */
    public function deleteMany(int $businessId, array $ids): void
    {
        foreach (PrivateDocument::where('business_id', $businessId)->whereIn('id', array_filter($ids))->get() as $doc) {
            $path = $doc->absolutePath();
            $doc->delete();
            \Illuminate\Support\Facades\DB::afterCommit(fn () => is_file($path) && @unlink($path));
        }
    }

    public function download(PrivateDocument $doc, ?string $filename = null, bool $inline = false)
    {
        $name = self::safeName($filename ?? $doc->original_name);

        return response()->file($doc->absolutePath(), [
            'Content-Type' => $doc->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

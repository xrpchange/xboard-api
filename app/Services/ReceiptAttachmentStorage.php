<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Delete receipt PDF files referenced by receipt_links_json (from verify-email-content).
 * Parses link URL to get date folder and token (filename); optionally uses config root.
 * Promotes files from tmp to permanent when receipt_links_json is persisted.
 */
class ReceiptAttachmentStorage
{
    /** Path segment under which email attachments are stored (date/token.pdf or date/tmp/token.pdf). */
    public const ATTACHMENTS_SUBDIR = 'email_attachments';

    /**
     * Resolve a stored relative path (e.g. email_attachments/2026-01-15/token.pdf) to an absolute filesystem path if the file exists.
     */
    public static function absolutePathIfExists(string $relativePath): ?string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $root = config('payment_verification.attachments_root');
        if (is_string($root) && $root !== '') {
            $fullPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            return is_file($fullPath) ? $fullPath : null;
        }

        if (! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        return Storage::disk('local')->path($relativePath);
    }

    /**
     * Parse a receipt link URL and return the relative storage path (email_attachments/...).
     * Returns null if the link does not match expected format.
     *
     * Link format: .../email-attachments/YYYY-MM-DD/TOKEN.pdf or .../email-attachments/YYYY-MM-DD/tmp/TOKEN.pdf
     */
    public static function linkToRelativePath(string $link): ?string
    {
        $path = parse_url($link, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        // Match email-attachments/YYYY-MM-DD/TOKEN.pdf or email-attachments/YYYY-MM-DD/tmp/TOKEN.pdf
        if (! preg_match('#email-attachments/(\d{4}-\d{2}-\d{2})/(?:tmp/)?([a-f0-9]{32})\.pdf$#i', $path, $m)) {
            return null;
        }
        $date = $m[1];
        $token = $m[2];
        $hasTmp = str_contains($path, $date.'/tmp/');

        return $hasTmp
            ? self::ATTACHMENTS_SUBDIR.'/'.$date.'/tmp/'.$token.'.pdf'
            : self::ATTACHMENTS_SUBDIR.'/'.$date.'/'.$token.'.pdf';
    }

    /**
     * Parse a receipt link and return date, token, and whether it points to tmp.
     *
     * @return array{date: string, token: string, is_tmp: bool}|null
     */
    public static function parseReceiptLink(string $link): ?array
    {
        $path = parse_url($link, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        if (! preg_match('#email-attachments/(\d{4}-\d{2}-\d{2})/(?:tmp/)?([a-f0-9]{32})\.pdf$#i', $path, $m)) {
            return null;
        }
        $isTmp = str_contains($path, $m[1].'/tmp/');

        return [
            'date' => $m[1],
            'token' => $m[2],
            'is_tmp' => $isTmp,
        ];
    }

    /**
     * If the link points to a file in tmp, move that file to the permanent path (date/token.pdf)
     * and return the new link (without /tmp/). Otherwise return the original link.
     * Used when persisting receipt_links_json so stored links point to permanent paths.
     */
    public static function moveTmpToPermanentAndReturnNewLink(string $link): string
    {
        $parsed = self::parseReceiptLink($link);
        if ($parsed === null || ! $parsed['is_tmp']) {
            return $link;
        }
        $tmpPath = self::ATTACHMENTS_SUBDIR.'/'.$parsed['date'].'/tmp/'.$parsed['token'].'.pdf';
        $permanentPath = self::ATTACHMENTS_SUBDIR.'/'.$parsed['date'].'/'.$parsed['token'].'.pdf';

        // PDFs from verify-email-content are written with Storage::disk('local'). If PAYMENT_VERIFICATION_ATTACHMENTS_ROOT
        // is set for deletes/prune but the tmp file only exists under the default disk, we must still move via Storage.
        // Never rewrite the public URL to /date/token.pdf unless the file was actually moved (otherwise chat links 404).
        $moved = false;
        $root = config('payment_verification.attachments_root');
        if (is_string($root) && $root !== '') {
            $tmpFull = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $tmpPath);
            $permFull = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $permanentPath);
            if (is_file($tmpFull)) {
                try {
                    $dir = dirname($permFull);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    rename($tmpFull, $permFull);
                    Log::info('ReceiptAttachmentStorage: moved tmp to permanent via root', ['from' => $tmpPath, 'to' => $permanentPath]);
                    $moved = true;
                } catch (\Throwable $e) {
                    Log::warning('ReceiptAttachmentStorage: failed to move tmp to permanent via root', ['from' => $tmpPath, 'error' => $e->getMessage()]);

                    return $link;
                }
            }
        }
        if (! $moved && Storage::disk('local')->exists($tmpPath)) {
            try {
                $content = Storage::disk('local')->get($tmpPath);
                Storage::disk('local')->put($permanentPath, $content);
                Storage::disk('local')->delete($tmpPath);
                Log::info('ReceiptAttachmentStorage: moved tmp to permanent via Storage', ['from' => $tmpPath, 'to' => $permanentPath]);
                $moved = true;
            } catch (\Throwable $e) {
                Log::warning('ReceiptAttachmentStorage: failed to move tmp to permanent via Storage', ['from' => $tmpPath, 'error' => $e->getMessage()]);

                return $link;
            }
        }
        if (! $moved) {
            return $link;
        }

        $path = parse_url($link, PHP_URL_PATH);
        $newPath = preg_replace('#/tmp/([a-f0-9]{32})\.pdf$#i', '/$1.pdf', $path);
        $scheme = parse_url($link, PHP_URL_SCHEME);
        $host = parse_url($link, PHP_URL_HOST);
        $port = parse_url($link, PHP_URL_PORT);
        $base = ($scheme ?: 'https').'://'.$host.($port ? ':'.$port : '');

        return $base.$newPath;
    }

    /**
     * Delete one file by relative path (e.g. email_attachments/2025-03-16/abc.pdf).
     * If config payment_verification.attachments_root is set, path is resolved against that root; otherwise Storage::disk('local').
     */
    public static function deleteByRelativePath(string $relativePath): void
    {
        $root = config('payment_verification.attachments_root');
        if (is_string($root) && $root !== '') {
            $fullPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($fullPath)) {
                try {
                    unlink($fullPath);
                    Log::info('ReceiptAttachmentStorage: deleted file via root', ['path' => $fullPath]);
                } catch (\Throwable $e) {
                    Log::warning('ReceiptAttachmentStorage: failed to delete file via root', ['path' => $fullPath, 'error' => $e->getMessage()]);
                }
            }

            return;
        }
        if (Storage::disk('local')->exists($relativePath)) {
            try {
                Storage::disk('local')->delete($relativePath);
                Log::info('ReceiptAttachmentStorage: deleted file via Storage', ['path' => $relativePath]);
            } catch (\Throwable $e) {
                Log::warning('ReceiptAttachmentStorage: failed to delete file via Storage', ['path' => $relativePath, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Delete all receipt PDF files referenced in receipt_links array (each item has 'link' key).
     *
     * @param  array<int, array{link?: string, filename?: string}>  $receiptLinks
     */
    public static function deleteFilesFromReceiptLinks(array $receiptLinks): void
    {
        foreach ($receiptLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = $item['link'] ?? null;
            if (! is_string($link) || $link === '') {
                continue;
            }
            $relativePath = self::linkToRelativePath($link);
            if ($relativePath !== null) {
                self::deleteByRelativePath($relativePath);
            }
        }
    }

    /**
     * Delete files that were present in old receipt links but are absent in new receipt links.
     * Compares normalized relative paths derived from links (filename field is ignored).
     *
     * @param  array<int, array{link?: string, filename?: string}>  $oldReceiptLinks
     * @param  array<int, array{link?: string, filename?: string}>  $newReceiptLinks
     */
    public static function deleteFilesRemovedFromReceiptLinks(array $oldReceiptLinks, array $newReceiptLinks): void
    {
        $newPaths = [];
        foreach ($newReceiptLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = $item['link'] ?? null;
            if (! is_string($link) || $link === '') {
                continue;
            }
            $relativePath = self::linkToRelativePath($link);
            if ($relativePath !== null) {
                $newPaths[$relativePath] = true;
            }
        }

        foreach ($oldReceiptLinks as $item) {
            if (! is_array($item)) {
                continue;
            }
            $link = $item['link'] ?? null;
            if (! is_string($link) || $link === '') {
                continue;
            }
            $relativePath = self::linkToRelativePath($link);
            if ($relativePath === null) {
                continue;
            }
            if (! isset($newPaths[$relativePath])) {
                self::deleteByRelativePath($relativePath);
            }
        }
    }

    /**
     * Delete the tmp folder for a given date (email_attachments/{date}/tmp).
     * Used by daily cleanup to remove test PDFs with a one-day buffer.
     */
    public static function deleteTmpFolderForDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $relativeDir = self::ATTACHMENTS_SUBDIR.'/'.$date.'/tmp';
        $root = config('payment_verification.attachments_root');
        if (is_string($root) && $root !== '') {
            $fullDir = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
            if (! is_dir($fullDir)) {
                return true;
            }
            try {
                $files = array_diff(scandir($fullDir), ['.', '..']);
                foreach ($files as $file) {
                    unlink($fullDir.DIRECTORY_SEPARATOR.$file);
                }
                rmdir($fullDir);
                Log::info('ReceiptAttachmentStorage: deleted tmp dir via root', ['path' => $fullDir]);

                return true;
            } catch (\Throwable $e) {
                Log::warning('ReceiptAttachmentStorage: failed to delete tmp dir via root', ['path' => $fullDir, 'error' => $e->getMessage()]);

                return false;
            }
        }
        if (! Storage::disk('local')->exists($relativeDir)) {
            return true;
        }
        try {
            Storage::disk('local')->deleteDirectory($relativeDir);
            Log::info('ReceiptAttachmentStorage: deleted tmp dir via Storage', ['path' => $relativeDir]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ReceiptAttachmentStorage: failed to delete tmp dir via Storage', ['path' => $relativeDir, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Delete whole date folders (email_attachments/{date}) older than N days.
     * Used daily with 15-day delay so receipt PDFs are removed together with transaction prune.
     *
     * @return array{deleted: list<string>, errors: list<string>}
     */
    public static function deleteDateFoldersOlderThanDays(int $days): array
    {
        $cutoff = now()->subDays($days)->format('Y-m-d');
        $deleted = [];
        $errors = [];

        $root = config('payment_verification.attachments_root');
        if (is_string($root) && $root !== '') {
            $baseDir = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.self::ATTACHMENTS_SUBDIR;
            if (! is_dir($baseDir)) {
                return ['deleted' => [], 'errors' => []];
            }
            $entries = array_diff(scandir($baseDir), ['.', '..']);
            foreach ($entries as $name) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                    continue;
                }
                if ($name >= $cutoff) {
                    continue;
                }
                $fullPath = $baseDir.DIRECTORY_SEPARATOR.$name;
                if (! is_dir($fullPath)) {
                    continue;
                }
                try {
                    self::deleteDirectoryRecursive($fullPath);
                    $deleted[] = self::ATTACHMENTS_SUBDIR.'/'.$name;
                    Log::info('ReceiptAttachmentStorage: deleted date folder via root', ['path' => $fullPath]);
                } catch (\Throwable $e) {
                    $errors[] = $fullPath.': '.$e->getMessage();
                    Log::warning('ReceiptAttachmentStorage: failed to delete date folder via root', ['path' => $fullPath, 'error' => $e->getMessage()]);
                }
            }

            return ['deleted' => $deleted, 'errors' => $errors];
        }

        $dirs = Storage::disk('local')->directories(self::ATTACHMENTS_SUBDIR);
        foreach ($dirs as $dirPath) {
            $name = basename($dirPath);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                continue;
            }
            if ($name >= $cutoff) {
                continue;
            }
            try {
                Storage::disk('local')->deleteDirectory($dirPath);
                $deleted[] = $dirPath;
                Log::info('ReceiptAttachmentStorage: deleted date folder via Storage', ['path' => $dirPath]);
            } catch (\Throwable $e) {
                $errors[] = $dirPath.': '.$e->getMessage();
                Log::warning('ReceiptAttachmentStorage: failed to delete date folder via Storage', ['path' => $dirPath, 'error' => $e->getMessage()]);
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    private static function deleteDirectoryRecursive(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                self::deleteDirectoryRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

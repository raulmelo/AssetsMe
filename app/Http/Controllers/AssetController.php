<?php

namespace App\Http\Controllers;

use App\Features\Convert\ImageResize;
use App\Models\Asset;
use App\Models\Folder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    /**
     * Health check endpoint.
     */
    public function health(): JsonResponse
    {
        return new JsonResponse(['ok' => true], Response::HTTP_OK);
    }

    /**
     * Upload one or more files to the assets storage.
     */
    public function upload(Request $request): JsonResponse
    {
        $maxFileSize = (int) config('assetsme.max_file_size', 10 * 1024 * 1024);
        $maxKilobytes = (int) max(1, (int) ceil($maxFileSize / 1024));

        $validator = Validator::make(
            $request->all(),
            [
                'folder' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\\/\-]+$/'],
                'file' => ['nullable', 'file', 'max:'.$maxKilobytes],
                'files' => ['nullable', 'array'],
                'files.*' => ['file', 'max:'.$maxKilobytes],
            ],
            [
                'folder.regex' => 'Folder may only contain letters, numbers, slashes, dashes, and underscores.',
            ],
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $folder = $this->normalizeFolder($validated['folder'] ?? null);

        // Get user ID for folder resolution
        $userId = $request->user()?->id ?? $request->attributes->get('token_user_id');
        if (!$userId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Resolve folder ID from folder path
        $folderId = $this->resolveFolderId($folder, $userId);

        $uploadedFiles = [];
        $singleFile = $request->file('file');
        if ($singleFile instanceof UploadedFile) {
            $uploadedFiles[] = $singleFile;
        }

        $multipleFiles = $request->file('files', []);
        if (! is_array($multipleFiles)) {
            $multipleFiles = [$multipleFiles];
        }

        foreach ($multipleFiles as $file) {
            if ($file instanceof UploadedFile) {
                $uploadedFiles[] = $file;
            }
        }

        if ($uploadedFiles === []) {
            return $this->validationErrorResponse([
                'file' => ['No file was provided.'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $disk = $this->disk();
        $imageResize = new ImageResize($disk);

        $variantDefinitions = [];
        $variantErrors = [];

        foreach (['small', 'medium', 'large'] as $variantKey) {
            try {
                $variantDefinitions[$variantKey] = $imageResize->resolveVariantSize($request->query($variantKey), $variantKey);
            } catch (InvalidArgumentException $exception) {
                $variantErrors[$variantKey] = [$exception->getMessage()];
            }
        }

        if ($variantErrors !== []) {
            return $this->validationErrorResponse($variantErrors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $results = [];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        foreach ($uploadedFiles as $file) {
            if (! $file->isValid()) {
                return $this->validationErrorResponse(['files' => ['One or more files are invalid.']]);
            }

            if ($file->getSize() > $maxFileSize) {
                return $this->validationErrorResponse(['files' => ['One or more files exceed the maximum size.']]);
            }

            $detectedMime = $finfo->file($file->getRealPath());

            if ($detectedMime === false) {
                return $this->validationErrorResponse(['files' => ['Unable to detect file MIME type.']]);
            }

            if ($this->isForbiddenMime($detectedMime)) {
                return $this->validationErrorResponse(['files' => ['The provided file type is not allowed.']]);
            }

            $originalName = $file->getClientOriginalName();

            $fileName = $this->buildFileName($file, $detectedMime);
            $relativePath = ltrim(($folder ? $folder.'/' : '').$fileName, '/');

            while ($disk->exists($relativePath)) {
                usleep(1000);
                $fileName = $this->buildFileName($file, $detectedMime);
                $relativePath = ltrim(($folder ? $folder.'/' : '').$fileName, '/');
            }

            $storedPath = $disk->putFileAs($folder ?? '', $file, $fileName);

            if ($storedPath === false) {
                return new JsonResponse([
                    'message' => 'Failed to store uploaded file.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $asset = Asset::create([
                'path' => $relativePath,
                'folder_id' => $folderId,
                'folder' => $folder,
                'owner_id' => $userId,
                'original_name' => $originalName,
                'mime' => $detectedMime,
                'size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'uploaded_by' => $userId,
            ]);

            $variantResult = ['sizes' => [], 'metadata' => []];

            try {
                $variantResult = $imageResize->generateVariants($relativePath, $folder, $fileName, $variantDefinitions);
            } catch (\Throwable $exception) {
                return new JsonResponse([
                    'message' => 'Failed to generate one or more image variants.',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $sizes = $variantResult['sizes'] ?? [];
            $variantMetadata = $variantResult['metadata'] ?? [];

            if ($variantMetadata !== []) {
                $asset->generated_thumbs = array_merge((array) ($asset->generated_thumbs ?? []), $variantMetadata);
                $asset->save();
            }

            $result = [
                'id' => $asset->id,
                'url' => $disk->url($relativePath),
                'path' => $asset->path,
                'folder_id' => $asset->folder_id,
                'folder' => $asset->folder,
                'mime' => $asset->mime,
                'size' => $asset->size,
                'original_name' => $asset->original_name,
                'checksum' => $asset->checksum,
                'created_at' => $asset->created_at,
            ];

            if ($sizes !== []) {
                $result['sizes'] = $sizes;
            }

            $results[] = $result;
        }

        return new JsonResponse(['data' => $results], Response::HTTP_CREATED);
    }

    /**
     * List assets for the provided folder.
     */
    public function list(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'folder' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_\\/\-]+$/'],
                'page' => ['nullable', 'integer', 'min:1'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ],
            [
                'folder.regex' => 'Folder may only contain letters, numbers, slashes, dashes, and underscores.',
            ],
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $folder = $this->normalizeFolder($validated['folder'] ?? null);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);

        Paginator::currentPageResolver(static fn () => $page);

        $query = Asset::query()->orderByDesc('created_at');

        if ($folder !== null) {
            $query->where('folder', $folder);
        } else {
            $query->where(function ($query) {
                $query->whereNull('folder')->orWhere('folder', '');
            });
        }

        $paginator = $query->simplePaginate($perPage);
        $disk = $this->disk();

        $items = collect($paginator->items())->map(function (Asset $asset) use ($disk) {
            return [
                'id' => $asset->id,
                'path' => $asset->path,
                'folder' => $asset->folder,
                'original_name' => $asset->original_name,
                'mime' => $asset->mime,
                'size' => $asset->size,
                'checksum' => $asset->checksum,
                'uploaded_by' => $asset->uploaded_by,
                'created_at' => $asset->created_at,
                'updated_at' => $asset->updated_at,
                'url' => $disk->url($asset->path),
            ];
        })->values();

        return new JsonResponse([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'next_page_url' => $paginator->nextPageUrl(),
                'prev_page_url' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    /**
     * Delete an asset by path.
     */
    public function delete(Request $request): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'path' => ['required', 'string', 'regex:/^[a-zA-Z0-9_\\.\-/]+$/'],
            ],
            [
                'path.regex' => 'Path may only contain letters, numbers, dots, slashes, dashes, and underscores.',
            ],
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $path = $this->normalizePath($validated['path']);

        if ($path === null) {
            return $this->validationErrorResponse(['path' => ['The path provided is invalid.']]);
        }

        $asset = Asset::query()->where('path', $path)->first();

        if (! $asset) {
            return new JsonResponse(['message' => 'Asset not found.'], Response::HTTP_NOT_FOUND);
        }

        $disk = $this->disk();
        $disk->delete($asset->path);
        $asset->delete();

        return new JsonResponse(['deleted' => true]);
    }

    /**
     * Normalize a folder string.
     */
    private function normalizeFolder(?string $folder): ?string
    {
        if ($folder === null) {
            return null;
        }

        $folder = trim($folder);
        $folder = trim($folder, '/');

        if ($folder === '') {
            return null;
        }

        return $folder;
    }

    /**
     * Normalize a path and guard against directory traversal.
     */
    private function normalizePath(string $path): ?string
    {
        $normalized = trim($path);
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    /**
     * Determine if the provided MIME type is disallowed.
     */
    private function isForbiddenMime(string $mime): bool
    {
        return str_contains($mime, 'php') || str_starts_with($mime, 'text/x-php');
    }

    /**
     * Guess a file extension from the detected MIME type.
     */
    private function extensionFromMime(string $mime): ?string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
        ];

        return $map[$mime] ?? null;
    }

    /**
     * Build the filename for the original upload using slug + timestamp + extension.
     */
    private function buildFileName(UploadedFile $file, ?string $detectedMime = null): string
    {
        $originalName = $file->getClientOriginalName();
        $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME));

        if ($baseName === '') {
            $baseName = 'asset';
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: '');
        $extension = preg_replace('/[^a-z0-9]/i', '', $extension);

        if ($extension === '') {
            $extension = $this->extensionFromMime($detectedMime ?? $file->getMimeType()) ?? 'bin';
        }

        $timestamp = now()->format('YmdHisv'); // microsecond precision guards against collisions

        return sprintf('%s-%s.%s', $baseName, $timestamp, $extension);
    }

    /**
     * Provide a consistent validation error response.
     */
    private function validationErrorResponse(array $errors, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse([
            'message' => 'Validation failed.',
            'errors' => $errors,
        ], $status);
    }

    /**
     * Retrieve the configured filesystem disk for assets.
     */
    private function disk(): FilesystemAdapter
    {
        return Storage::disk(config('assetsme.disk', 'assets'));
    }

    /**
     * Resolve folder ID from folder path string.
     * Creates folders if they don't exist.
     */
    private function resolveFolderId(?string $folderPath, int $userId): ?int
    {
        if (!$folderPath || trim($folderPath) === '') {
            return null;
        }

        $folderPath = trim($folderPath, '/');
        if ($folderPath === '') {
            return null;
        }

        // Split the path into parts
        $pathParts = explode('/', $folderPath);
        $currentParentId = null;
        $currentFolder = null;

        foreach ($pathParts as $folderName) {
            if (trim($folderName) === '') {
                continue;
            }

            // Look for existing folder
            $existingFolder = Folder::where('name', $folderName)
                ->where('parent_id', $currentParentId)
                ->first();

            if ($existingFolder) {
                $currentFolder = $existingFolder;
                $currentParentId = $existingFolder->id;
            } else {
                // Create new folder
                $currentFolder = Folder::create([
                    'name' => $folderName,
                    'parent_id' => $currentParentId,
                    'owner_id' => $userId,
                    'access_level' => 'private',
                ]);
                $currentParentId = $currentFolder->id;
            }
        }

        return $currentFolder?->id;
    }
}

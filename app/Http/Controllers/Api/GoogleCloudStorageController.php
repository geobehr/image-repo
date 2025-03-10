<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Google\Cloud\Storage\StorageClient;
class GoogleCloudStorageController extends Controller
{
    public function listContents(Request $request)
    {
        $request->validate([
            'path' => 'string|nullable',
            'recursive' => 'boolean'
        ]);
        
        $path = $request->input('path', '/');
        $recursive = $request->input('recursive', false);
        
        try {
            $keyFilePath = storage_path('app/google-cloud-key.json');
            $bucketName = config('filesystems.disks.gcs.bucket');
            $projectId = config('filesystems.disks.gcs.project_id');
            
            if (!file_exists($keyFilePath)) {
                throw new \Exception('Google Cloud key file not found at: ' . $keyFilePath);
            }
            
            if (empty($bucketName)) {
                throw new \Exception('Google Cloud Storage bucket name not configured. Please check GOOGLE_CLOUD_STORAGE_BUCKET in .env');
            }
            
            $storage = new StorageClient([
                'keyFilePath' => $keyFilePath
            ]);
            
            try {
                $bucket = $storage->bucket($bucketName);
                if (!$bucket->exists()) {
                    throw new \Exception('Bucket does not exist: ' . $bucketName);
                }
            } catch (\Exception $e) {
                throw new \Exception(sprintf(
                    'Failed to access bucket: %s. Project ID: %s. Error: %s',
                    $bucketName,
                    $projectId,
                    $e->getMessage()
                ));
            }
            
            // Initialize items array
            $items = [];
            
            // For root listing, we need to handle it specially
            if ($path === '/') {
                // First list all objects to find directories
                $allObjects = $bucket->objects();
                $directories = [];
                
                // Find all unique directories
                foreach ($allObjects as $object) {
                    $name = $object->name();
                    $parts = explode('/', $name);
                    
                    // If there's a slash, we have a directory
                    if (count($parts) > 1) {
                        $topDir = $parts[0];
                        if (!empty($topDir) && !isset($directories[$topDir])) {
                            $directories[$topDir] = true;
                            $items[] = [
                                'path' => $topDir,
                                'type' => 'directory',
                                'size' => 0,
                                'last_modified' => null
                            ];
                        }
                    } else if (!empty($name) && substr($name, -1) !== '/') {
                        // This is a top-level file
                        $items[] = [
                            'path' => $name,
                            'type' => 'file',
                            'size' => (string)($object->info()['size'] ?? 0),
                            'last_modified' => strtotime($object->info()['updated'] ?? '') * 1000
                        ];
                    }
                }
            } else {
                // For non-root paths, use the standard approach
                $options = [
                    'prefix' => trim($path, '/') . '/',
                    'delimiter' => '/'
                ];
                
                if ($recursive) {
                    unset($options['delimiter']);
                }
                
                // Get the objects
                $objects = $bucket->objects($options);
                
                // Get directories first if not recursive
                if (!$recursive) {
                    foreach ($objects->prefixes() as $prefix) {
                        $items[] = [
                            'path' => rtrim($prefix, '/'),
                            'type' => 'directory',
                            'size' => 0,
                            'last_modified' => null
                        ];
                    }
                }
                
                // Then get files
                foreach ($objects as $object) {
                    $name = $object->name();
                    
                    // Skip directory markers
                    if (substr($name, -1) === '/') {
                        continue;
                    }
                    
                    // For non-recursive, skip files not in current directory
                    if (!$recursive) {
                        $parent = dirname($name);
                        if ($parent !== trim($path, '/')) {
                            continue;
                        }
                    }
                    
                    $items[] = [
                        'path' => $name,
                        'type' => 'file',
                        'size' => (string)($object->info()['size'] ?? 0),
                        'last_modified' => strtotime($object->info()['updated'] ?? '') * 1000
                    ];
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => $items
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'path' => 'required|string'
        ]);

        try {
            $keyFilePath = storage_path('app/google-cloud-key.json');
            $bucketName = config('filesystems.disks.gcs.bucket');
            
            if (!file_exists($keyFilePath)) {
                throw new \Exception('Google Cloud key file not found at: ' . $keyFilePath);
            }
            
            if (empty($bucketName)) {
                throw new \Exception('Google Cloud Storage bucket name not configured. Please check GOOGLE_CLOUD_STORAGE_BUCKET in .env');
            }
            
            $storage = new StorageClient([
                'keyFilePath' => $keyFilePath
            ]);
            
            $bucket = $storage->bucket($bucketName);
            
            $file = $request->file('file');
            $targetPath = trim($request->input('path'), '/');
            
            // If targetPath already includes a filename, use it directly
            if (pathinfo($targetPath, PATHINFO_EXTENSION)) {
                $fullPath = $targetPath;
            } else {
                // If targetPath is a directory or empty, append the original filename
                $filename = $file->getClientOriginalName();
                $fullPath = $targetPath ? "$targetPath/$filename" : $filename;
            }
            
            // Ensure we don't have any double paths
            $fullPath = str_replace('//', '/', $fullPath);
            
            $bucket->upload(
                fopen($file->getRealPath(), 'r'),
                ['name' => $fullPath]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'path' => $fullPath,
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function delete(Request $request)
    {
        $request->validate([
            'paths' => 'required|array',
            'paths.*' => 'required|string',
            'strategy' => 'string|in:newest,oldest,largest,smallest,all'
        ]);

        try {
            $keyFilePath = storage_path('app/google-cloud-key.json');
            $bucketName = config('filesystems.disks.gcs.bucket');
            
            if (!file_exists($keyFilePath)) {
                throw new \Exception('Google Cloud key file not found at: ' . $keyFilePath);
            }
            
            if (empty($bucketName)) {
                throw new \Exception('Google Cloud Storage bucket name not configured. Please check GOOGLE_CLOUD_STORAGE_BUCKET in .env');
            }
            
            $storage = new StorageClient([
                'keyFilePath' => $keyFilePath
            ]);
            
            $bucket = $storage->bucket($bucketName);
            $paths = $request->input('paths');
            $strategy = $request->input('strategy', 'all');
            $fileGroups = [];
            $deletedFiles = [];

            // Group files by basename for duplicate comparison
            foreach ($paths as $path) {
                // Clean up the path
                $path = trim($path, '/');
                $path = str_replace('//', '/', $path);
                $basename = basename($path);
                if (!isset($fileGroups[$basename])) {
                    $fileGroups[$basename] = [];
                }
                
                try {
                    $object = $bucket->object($path);
                    if ($object->exists()) {
                        $info = $object->info();
                        $fileGroups[$basename][] = [
                            'path' => $path,
                            'size' => $info['size'] ?? 0,
                            'last_modified' => strtotime($info['updated'] ?? '') * 1000,
                            'object' => $object
                        ];
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            foreach ($fileGroups as $basename => $files) {
                $toDelete = $files;
                
                if ($strategy !== 'all' && count($files) > 1) {
                    usort($files, function($a, $b) use ($strategy) {
                        switch ($strategy) {
                            case 'newest':
                                return $b['last_modified'] - $a['last_modified'];
                            case 'oldest':
                                return $a['last_modified'] - $b['last_modified'];
                            case 'largest':
                                return $b['size'] - $a['size'];
                            case 'smallest':
                                return $a['size'] - $b['size'];
                        }
                    });
                    
                    // Remove the file we want to keep
                    array_shift($files);
                    $toDelete = $files;
                }

                foreach ($toDelete as $file) {
                    $file['object']->delete();
                    $deletedFiles[] = $file['path'];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'deleted_files' => $deletedFiles
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function copy(Request $request)
    {
        $request->validate([
            'source' => 'required|string',
            'destination' => 'required|string'
        ]);

        try {
            $source = $request->input('source');
            $destination = $request->input('destination');

            if (!Storage::disk('gcs')->exists($source)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Source file does not exist'
                ], 404);
            }

            Storage::disk('gcs')->copy($source, $destination);

            return response()->json([
                'success' => true,
                'data' => [
                    'source' => $source,
                    'destination' => $destination
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function findDuplicates(Request $request)
    {
        $request->validate([
            'path' => 'required|string',
            'methods' => 'required|array',
            'methods.*' => 'required|string|in:content,filename,size,dimensions,combined',
            'size_tolerance' => 'numeric|min:0|max:100',
            'recursive' => 'boolean',
            'image_only' => 'boolean'
        ]);

        $path = $request->input('path');
        $methods = $request->input('methods', ['content']);
        $sizeTolerance = $request->input('size_tolerance', 0);
        $recursive = $request->input('recursive', false);
        $imageOnly = $request->input('image_only', false);

        try {
            $files = collect(Storage::disk('gcs')->files($path))->map(function($path) {
                return [
                    'path' => $path,
                    'type' => 'file',
                    'size' => Storage::disk('gcs')->size($path),
                    'last_modified' => Storage::disk('gcs')->lastModified($path)
                ];
            });
            
            if ($recursive) {
                $directories = Storage::disk('gcs')->directories($path);
                foreach ($directories as $directory) {
                    $subFiles = collect(Storage::disk('gcs')->files($directory))->map(function($path) {
                        return [
                            'path' => $path,
                            'type' => 'file',
                            'size' => Storage::disk('gcs')->size($path),
                            'last_modified' => Storage::disk('gcs')->lastModified($path)
                        ];
                    });
                    $files = $files->merge($subFiles);
                }
            }
            
            $contents = $files->map(function($item) {
                return [
                    'path' => $item['path'],
                    'type' => $item['type'],
                    'size' => $item['size'],
                    'last_modified' => $item['timestamp'],
                    'mime_type' => $item['mime_type'] ?? null
                ];
            })->toArray();
            $fileGroups = [];
            $duplicates = [];
            $imageManager = new ImageManager(new Driver());

            foreach ($contents as $item) {
                if ($item['type'] !== 'file') continue;

                $filePath = $item['path'];
                $fileSize = $item['size'] ?? 0;
                $fileName = basename($filePath);
                $isImage = str_starts_with($item['mime_type'] ?? '', 'image/');

                if ($imageOnly && !$isImage) continue;

                $fileInfo = [
                    'path' => $filePath,
                    'size' => $fileSize,
                    'last_modified' => $item['lastModified'] ?? null,
                    'is_image' => $isImage
                ];

                if ($isImage) {
                    try {
                        $tempPath = tempnam(sys_get_temp_dir(), 'gcs_');
                        file_put_contents($tempPath, Storage::disk('gcs')->get($filePath));
                        $image = $imageManager->read($tempPath);
                        $fileInfo['dimensions'] = [
                            'width' => $image->width(),
                            'height' => $image->height()
                        ];
                        unlink($tempPath);
                    } catch (\Exception $e) {
                        // Skip if image processing fails
                        continue;
                    }
                }

                foreach ($methods as $method) {
                    $key = match($method) {
                        'filename' => $fileName,
                        'size' => $this->getSizeGroup($fileSize, $sizeTolerance),
                        'dimensions' => $isImage ? "{$fileInfo['dimensions']['width']}x{$fileInfo['dimensions']['height']}" : null,
                        'content' => $this->getFileHash($filePath),
                        default => null
                    };

                    if ($key === null) continue;

                    if (!isset($fileGroups[$method][$key])) {
                        $fileGroups[$method][$key] = [];
                    }
                    $fileGroups[$method][$key][] = $fileInfo;
                }
            }

            // Process duplicates
            foreach ($fileGroups as $method => $groups) {
                foreach ($groups as $key => $files) {
                    if (count($files) > 1) {
                        if ($method === 'combined') {
                            // For combined method, check if files match across all other methods
                            $matchedFiles = $this->findCombinedDuplicates($files, $fileGroups);
                            if (count($matchedFiles) > 1) {
                                $duplicates[] = [
                                    'files' => $matchedFiles,
                                    'match_type' => 'combined',
                                    'matched_criteria' => array_diff($methods, ['combined'])
                                ];
                            }
                        } else {
                            $duplicates[] = [
                                'files' => $files,
                                'match_type' => $method
                            ];
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'duplicates' => $duplicates,
                    'total_groups' => count($duplicates),
                    'total_duplicate_files' => array_sum(array_map(fn($group) => count($group['files']), $duplicates)),
                    'methods' => $methods,
                    'image_only' => $imageOnly
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getFileHash($path)
    {
        try {
            return md5(Storage::disk('gcs')->get($path));
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getSizeGroup($size, $tolerance)
    {
        if ($tolerance === 0) return $size;
        
        $toleranceBytes = ($size * $tolerance) / 100;
        return floor($size / $toleranceBytes) * $toleranceBytes;
    }

    private function findCombinedDuplicates($files, $fileGroups)
    {
        $matchedFiles = $files;
        
        foreach ($fileGroups as $method => $groups) {
            if ($method === 'combined') continue;
            
            $currentMatches = [];
            foreach ($groups as $group) {
                $intersection = array_filter($group, function($file) use ($matchedFiles) {
                    return in_array($file['path'], array_column($matchedFiles, 'path'));
                });
                
                if (count($intersection) > 1) {
                    $currentMatches = array_merge($currentMatches, $intersection);
                }
            }
            
            if (empty($currentMatches)) {
                return [];
            }
            
            $matchedFiles = array_intersect_key($matchedFiles, $currentMatches);
        }
        
        return $matchedFiles;
    }
}

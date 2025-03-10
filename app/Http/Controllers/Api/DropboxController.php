<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
class DropboxController extends Controller
{

    public function listContents(Request $request)
    {
        $path = $request->input('path', '/');
        
        try {
            $files = collect(Storage::disk('dropbox')->files($path))->map(function($path) {
                return [
                    'path' => $path,
                    'type' => 'file',
                    'size' => Storage::disk('dropbox')->size($path),
                    'last_modified' => Storage::disk('dropbox')->lastModified($path)
                ];
            });
            
            $directories = collect(Storage::disk('dropbox')->directories($path))->map(function($path) {
                return [
                    'path' => $path,
                    'type' => 'directory',
                    'size' => 0,
                    'last_modified' => null
                ];
            });
            
            $contents = $files->merge($directories)->map(function($item) {
                return [
                    'path' => $item['path'],
                    'type' => $item['type'],
                    'size' => $item['size'] ?? 0,
                    'last_modified' => $item['timestamp'] ?? null,
                    'mime_type' => $item['mime_type'] ?? null
                ];
            })->toArray();
            
            return response()->json([
                'success' => true,
                'data' => $contents
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to list contents: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Copy a file or directory from one location to another in Dropbox
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $request->validate([
            'from' => 'required|string',
            'to' => 'required|string'
        ]);

        $from = $request->input('from');
        $to = $request->input('to');

        try {
            if (Storage::disk('dropbox')->exists($from)) {
                // Copy the file directly
                Storage::disk('dropbox')->copy($from, $to);
                
                return response()->json([
                    'success' => true,
                    'message' => 'File copied successfully'
                ]);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Source file not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to copy file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload a file to Dropbox
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file',
            'path' => 'required|string'
        ]);

        try {
            $file = $request->file('file');
            $path = $request->input('path');
            
            // Ensure path ends with filename
            if (substr($path, -1) === '/') {
                $path .= $file->getClientOriginalName();
            }
            
            // Upload file to Dropbox
            Storage::disk('dropbox')->putFileAs('', $file, $path);
            
            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'path' => $path
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload file: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a file or directory from Dropbox
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $request->validate([
            'paths' => 'required|array',
            'paths.*' => 'required|string',
            'strategy' => 'string|in:all,newest,oldest,largest,smallest'
        ]);

        $paths = $request->input('paths');
        $strategy = $request->input('strategy', 'all');

        try {
            $results = [];
            $processedPaths = [];

            if ($strategy !== 'all') {
                // Group files by their base name for selective deletion
                $fileGroups = [];
                foreach ($paths as $path) {
                    $basename = basename($path);
                    if (!isset($fileGroups[$basename])) {
                        $fileGroups[$basename] = [];
                    }
                    if (Storage::disk('dropbox')->exists($path)) {
                        $fileGroups[$basename][] = [
                            'path' => $path,
                            'size' => Storage::disk('dropbox')->size($path),
                            'last_modified' => Storage::disk('dropbox')->lastModified($path)
                        ];
                    }
                }

                // Apply deletion strategy for each group
                foreach ($fileGroups as $basename => $files) {
                    if (empty($files)) continue;

                    // Sort files based on strategy
                    switch ($strategy) {
                        case 'newest':
                            usort($files, fn($a, $b) => $b['last_modified'] - $a['last_modified']);
                            array_shift($files); // Keep newest
                            break;
                        case 'oldest':
                            usort($files, fn($a, $b) => $a['last_modified'] - $b['last_modified']);
                            array_shift($files); // Keep oldest
                            break;
                        case 'largest':
                            usort($files, fn($a, $b) => $b['size'] - $a['size']);
                            array_shift($files); // Keep largest
                            break;
                        case 'smallest':
                            usort($files, fn($a, $b) => $a['size'] - $b['size']);
                            array_shift($files); // Keep smallest
                            break;
                    }

                    // Add remaining files to deletion list
                    foreach ($files as $file) {
                        try {
                            Storage::disk('dropbox')->delete($file['path']);
                            $results[] = [
                                'path' => $file['path'],
                                'status' => 'deleted'
                            ];
                        } catch (\Exception $e) {
                            $results[] = [
                                'path' => $file['path'],
                                'status' => 'error',
                                'message' => $e->getMessage()
                            ];
                        }
                        $processedPaths[] = $file['path'];
                    }
                }
            } else {
                $processedPaths = $paths;
            }

            // Process deletions
            foreach ($processedPaths as $path) {
                try {
                    if (Storage::disk('dropbox')->exists($path)) {
                        Storage::disk('dropbox')->delete($path);
                        $results[] = [
                            'path' => $path,
                            'success' => true,
                            'message' => 'Deleted successfully'
                        ];
                    } else {
                        $results[] = [
                            'path' => $path,
                            'success' => false,
                            'message' => 'File not found'
                        ];
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'path' => $path,
                        'success' => false,
                        'message' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'results' => $results,
                    'total_processed' => count($processedPaths),
                    'total_deleted' => count(array_filter($results, fn($r) => $r['success'])),
                    'strategy' => $strategy
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process deletions: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Find duplicate files in a directory based on content hash
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Find duplicate files using various methods
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
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
        $recursive = $request->input('recursive', false);
        $sizeTolerance = $request->input('size_tolerance', 1);
        $imageOnly = $request->input('image_only', false);

        try {
            $files = collect(Storage::disk('dropbox')->files($path))->map(function($path) {
                return [
                    'path' => $path,
                    'type' => 'file',
                    'size' => Storage::disk('dropbox')->size($path),
                    'last_modified' => Storage::disk('dropbox')->lastModified($path)
                ];
            });
            
            if ($recursive) {
                $directories = Storage::disk('dropbox')->directories($path);
                foreach ($directories as $directory) {
                    $subFiles = collect(Storage::disk('dropbox')->files($directory))->map(function($path) {
                        return [
                            'path' => $path,
                            'type' => 'file',
                            'size' => Storage::disk('dropbox')->size($path),
                            'last_modified' => Storage::disk('dropbox')->lastModified($path)
                        ];
                    });
                    $files = $files->merge($subFiles);
                }
            }
            
            $contents = $files->map(function($item) {
                return [
                    'path' => $item['path'],
                    'type' => $item['type'],
                    'size' => $item['size'] ?? 0,
                    'last_modified' => $item['timestamp'] ?? null,
                    'mime_type' => $item['mime_type'] ?? null
                ];
            })->toArray();
            $fileGroups = [];
            $duplicates = [];
            $imageManager = new ImageManager(new Driver());

            foreach ($contents as $item) {
                if ($item['type'] === 'file') {
                    // Skip non-image files if image_only is true
                    $extension = strtolower(pathinfo($item['path'], PATHINFO_EXTENSION));
                    $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                    if ($imageOnly && !$isImage) {
                        continue;
                    }

                    $fileInfo = [
                        'path' => $item['path'],
                        'size' => $item['size'],
                        'last_modified' => $item['last_modified'],
                        'is_image' => $isImage
                    ];

                    if ($isImage) {
                        $content = Storage::disk('dropbox')->get($item['path']);
                        $image = $imageManager->read($content);
                        $fileInfo['dimensions'] = [
                            'width' => $image->width(),
                            'height' => $image->height()
                        ];
                    }

                    foreach ($methods as $method) {
                        $key = $this->generateKey($method, $fileInfo, $content ?? null, $sizeTolerance);
                        if (!isset($fileGroups[$method])) {
                            $fileGroups[$method] = [];
                        }
                        if (!isset($fileGroups[$method][$key])) {
                            $fileGroups[$method][$key] = [];
                        }
                        $fileGroups[$method][$key][] = $fileInfo;
                    }
                }
            }

            // Process groups into duplicates
            foreach ($methods as $method) {
                if ($method === 'combined') {
                    continue; // Handle combined method separately
                }

                foreach ($fileGroups[$method] ?? [] as $key => $group) {
                    if (count($group) > 1) {
                        if ($method === 'size') {
                            $sizeGroups = $this->groupFilesByTolerance($group, $sizeTolerance);
                            foreach ($sizeGroups as $sizeGroup) {
                                if (count($sizeGroup) > 1) {
                                    $duplicates[] = [
                                        'files' => $sizeGroup,
                                        'size' => $sizeGroup[0]['size'],
                                        'match_type' => $method
                                    ];
                                }
                            }
                        } else {
                            $duplicates[] = [
                                'files' => $group,
                                'size' => $group[0]['size'],
                                'match_type' => $method,
                                'dimensions' => $group[0]['dimensions'] ?? null
                            ];
                        }
                    }
                }
            }

            // Handle combined method if requested
            if (in_array('combined', $methods)) {
                $duplicates = array_merge($duplicates, $this->findCombinedDuplicates($fileGroups, $methods));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'duplicates' => $duplicates,
                    'total_groups' => count($duplicates),
                    'total_duplicate_files' => array_sum(array_map(function($group) {
                        return count($group['files']) - 1;
                    }, $duplicates)),
                    'methods' => $methods,
                    'size_tolerance' => in_array('size', $methods) ? $sizeTolerance : null,
                    'image_only' => $imageOnly
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to find duplicates: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate a key for grouping files based on the detection method
     */
    private function generateKey(string $method, array $fileInfo, ?string $content, float $sizeTolerance): string
    {
        switch ($method) {
            case 'content':
                return hash('sha256', $content);
            case 'filename':
                return basename($fileInfo['path']);
            case 'size':
                return $this->getSizeRangeKey($fileInfo['size'], $sizeTolerance);
            case 'dimensions':
                if (!isset($fileInfo['dimensions'])) {
                    return 'no_dimensions';
                }
                return $fileInfo['dimensions']['width'] . 'x' . $fileInfo['dimensions']['height'];
            default:
                throw new \InvalidArgumentException('Invalid duplicate detection method');
        }
    }

    /**
     * Find duplicates using combined criteria
     */
    private function findCombinedDuplicates(array $fileGroups, array $methods): array
    {
        $combinedDuplicates = [];
        $methodsToCompare = array_filter($methods, fn($m) => $m !== 'combined');

        // Get intersection of all method groups
        $firstMethod = reset($methodsToCompare);
        foreach ($fileGroups[$firstMethod] ?? [] as $group) {
            if (count($group) <= 1) continue;

            $commonFiles = $group;
            foreach (array_slice($methodsToCompare, 1) as $method) {
                $commonFiles = $this->findCommonFiles($commonFiles, $fileGroups[$method] ?? []);
                if (count($commonFiles) <= 1) break;
            }

            if (count($commonFiles) > 1) {
                $combinedDuplicates[] = [
                    'files' => $commonFiles,
                    'size' => $commonFiles[0]['size'],
                    'match_type' => 'combined',
                    'dimensions' => $commonFiles[0]['dimensions'] ?? null,
                    'matched_criteria' => $methodsToCompare
                ];
            }
        }

        return $combinedDuplicates;
    }

    /**
     * Find common files between a set of files and groups
     */
    private function findCommonFiles(array $files, array $groups): array
    {
        $common = [];
        foreach ($files as $file) {
            foreach ($groups as $group) {
                if ($this->fileExistsInGroup($file, $group)) {
                    $common[] = $file;
                    break;
                }
            }
        }
        return $common;
    }

    /**
     * Check if a file exists in a group based on path
     */
    private function fileExistsInGroup(array $file, array $group): bool
    {
        foreach ($group as $groupFile) {
            if ($groupFile['path'] === $file['path']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get size range key for grouping files by size with tolerance
     *
     * @param int $size File size in bytes
     * @param float $tolerance Tolerance percentage
     * @return string
     */
    private function getSizeRangeKey(int $size, float $tolerance): string
    {
        // Create a range key that groups files within the tolerance percentage
        $rangeSize = max(1, floor($size * ($tolerance / 100)));
        return floor($size / $rangeSize) * $rangeSize;
    }

    /**
     * Group files that are within the size tolerance of each other
     *
     * @param array $files Array of file information
     * @param float $tolerance Tolerance percentage
     * @return array
     */
    private function groupFilesByTolerance(array $files, float $tolerance): array
    {
        $groups = [];
        $used = [];

        foreach ($files as $i => $file) {
            if (isset($used[$i])) continue;

            $group = [$file];
            $used[$i] = true;

            foreach ($files as $j => $compareFile) {
                if ($i === $j || isset($used[$j])) continue;

                $sizeDiff = abs($file['size'] - $compareFile['size']);
                $toleranceBytes = $file['size'] * ($tolerance / 100);

                if ($sizeDiff <= $toleranceBytes) {
                    $group[] = $compareFile;
                    $used[$j] = true;
                }
            }

            if (count($group) > 1) {
                $groups[] = $group;
            }
        }

        return $groups;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Dropbox\Client as DropboxClient;

class DropboxController extends Controller
{

    public function test()
    {
        try {
            $client = new DropboxClient(config('filesystems.disks.dropbox.token'));
            $accountInfo = $client->getAccountInfo();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'account_id' => $accountInfo['account_id'] ?? null,
                    'name' => $accountInfo['name']['display_name'] ?? null,
                    'email' => $accountInfo['email'] ?? null
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function listContents(Request $request)
    {
        $path = $request->input('path', '');
        
        // Ensure path is never null and format it correctly for Dropbox
        $path = $path === '/' ? '' : trim($path);
        
        try {
            $token = config('filesystems.disks.dropbox.token');
            if (empty($token)) {
                throw new \Exception('Dropbox token is not configured');
            }
            
            $client = new DropboxClient($token);
            $contents = [];
            
            try {
                // Log the path we're trying to list
                Log::info('Attempting to list Dropbox folder:', ['path' => $path]);
                
                // List folder with all options
                $options = [
                    'recursive' => false,
                    'include_media_info' => false,
                    'include_deleted' => false,
                    'include_has_explicit_shared_members' => false,
                    'include_mounted_folders' => true,
                    'limit' => 2000
                ];
                
                // For root directory, use '/' instead of empty string
                $result = $client->rpcEndpointRequest('files/list_folder', [
                    'path' => $path === '' ? '' : $path,
                    'recursive' => false,
                    'include_media_info' => false,
                    'include_deleted' => false,
                    'include_has_explicit_shared_members' => false,
                    'include_mounted_folders' => true,
                    'limit' => 2000
                ]);
                
                Log::debug('Dropbox response:', ['result' => $result]);
                
                if (!isset($result['entries']) || !is_array($result['entries'])) {
                    throw new \Exception('Invalid response from Dropbox: missing entries array');
                }
                
                foreach ($result['entries'] as $entry) {
                    $item = [
                        'path' => $entry['path_display'],
                        'type' => $entry['.tag'] === 'folder' ? 'directory' : 'file',
                        'last_modified' => null
                    ];
                    
                    if ($entry['.tag'] === 'file') {
                        $item['size'] = (string)($entry['size'] ?? 0);
                        $item['last_modified'] = isset($entry['server_modified']) ? strtotime($entry['server_modified']) * 1000 : null;
                    } else {
                        $item['size'] = '0';
                    }
                    
                    $contents[] = $item;
                }
            } catch (\Exception $e) {
                Log::error('Error listing Dropbox contents:', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Get the actual error message from the Dropbox API response
                $message = $e->getMessage();
                if ($e instanceof ClientException) {
                    $response = json_decode($e->getResponse()->getBody()->getContents(), true);
                    Log::debug('Dropbox error response:', ['response' => $response]);
                    if (isset($response['error_summary'])) {
                        $message = $response['error_summary'];
                    } elseif (isset($response['error']) && isset($response['error']['.tag'])) {
                        $message = 'Dropbox API error: ' . $response['error']['.tag'];
                    }
                }
                
                throw new \Exception($message);
            }
            
            return response()->json([
                'success' => true,
                'data' => $contents
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
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
                    'data' => [
                        'from' => $from,
                        'to' => $to
                    ]
                ]);
            }
            
            return response()->json([
                'success' => false,
                'error' => 'Source file not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
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
                'data' => [
                    'path' => $path,
                    'size' => (string)$file->getSize(),
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

    /**
     * Delete a file or directory from Dropbox
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function delete(Request $request)
    {
        $request->validate([
            'files' => 'required|array',
            'files.*' => 'required|string'
        ]);

        $files = $request->input('files');
        $deletedFiles = [];

        try {
            foreach ($files as $file) {
                if (Storage::disk('dropbox')->exists($file)) {
                    Storage::disk('dropbox')->delete($file);
                    $deletedFiles[] = $file;
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


}

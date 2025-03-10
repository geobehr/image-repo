<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Spatie\Dropbox\Client as DropboxClient;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use Spatie\FlysystemDropbox\DropboxAdapter;
use Google\Cloud\Storage\StorageClient;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('dropbox', function ($app, $config) {
            $client = new DropboxClient(
                $config['authorization_token']
            );

            /** @var FilesystemAdapter $adapter */
            $adapter = new DropboxAdapter($client);
            return new Filesystem($adapter, ['case_sensitive' => false]);
        });

        Storage::extend('gcs', function ($app, $config) {
            $storageClient = new StorageClient([
                'projectId' => $config['project_id'],
                'keyFilePath' => $config['key_file']
            ]);

            $bucket = $storageClient->bucket($config['bucket']);
            
            /** @var FilesystemAdapter $adapter */
            $adapter = new GoogleCloudStorageAdapter($bucket, $config['path_prefix'] ?? '');
            return new Filesystem($adapter, [
                'visibility' => $config['visibility'] ?? 'public',
            ]);
        });
    }
}

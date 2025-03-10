<?php

use Illuminate\Http\UploadedFile;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageObject;
use Illuminate\Support\Facades\Storage;
use Mockery;

beforeEach(function () {
    // Mock the StorageClient
    $this->mockStorageClient = Mockery::mock(StorageClient::class);
    $this->mockBucket = Mockery::mock(Bucket::class);
    $this->mockObject = Mockery::mock(StorageObject::class);
    
    // Set up basic mocking chain
    $this->mockStorageClient->shouldReceive('bucket')->andReturn($this->mockBucket);
    $this->mockBucket->shouldReceive('exists')->andReturn(true);
    
    // Bind the mock to the container
    $this->app->bind(StorageClient::class, fn() => $this->mockStorageClient);
});

afterEach(function () {
    Mockery::close();
});

test('list contents of root directory', function () {
    // Mock objects for root directory listing
    $mockCommunities = Mockery::mock(StorageObject::class);
    $mockCommunities->shouldReceive('name')->andReturn('communities/file.txt');
    $mockCommunities->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockHomes = Mockery::mock(StorageObject::class);
    $mockHomes->shouldReceive('name')->andReturn('homes/file.txt');
    $mockHomes->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockModels = Mockery::mock(StorageObject::class);
    $mockModels->shouldReceive('name')->andReturn('models/file.txt');
    $mockModels->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockSubdir = Mockery::mock(StorageObject::class);
    $mockSubdir->shouldReceive('name')->andReturn('subdir/file.txt');
    $mockSubdir->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockFile = Mockery::mock(StorageObject::class);
    $mockFile->shouldReceive('name')->andReturn('test.txt');
    $mockFile->shouldReceive('info')->andReturn([
        'size' => 13,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockIterator = Mockery::mock('Iterator');
    $mockIterator->shouldReceive('rewind');
    $mockIterator->shouldReceive('valid')->andReturn(true, true, true, true, true, false);
    $mockIterator->shouldReceive('current')->andReturnValues([
        $mockCommunities,
        $mockHomes,
        $mockModels,
        $mockSubdir,
        $mockFile
    ]);
    $mockIterator->shouldReceive('next');
    
    // Set up expectations
    $this->mockBucket->shouldReceive('objects')->andReturn($mockIterator);
    $mockIterator->shouldReceive('prefixes')->andReturn([]);
    
    // Make the request
    $response = $this->getJson('/api/gcs/list');
    
    // Assert response structure
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                ['path' => 'communities', 'type' => 'directory', 'size' => 0, 'last_modified' => null],
                ['path' => 'homes', 'type' => 'directory', 'size' => 0, 'last_modified' => null],
                ['path' => 'models', 'type' => 'directory', 'size' => 0, 'last_modified' => null],
                ['path' => 'subdir', 'type' => 'directory', 'size' => 0, 'last_modified' => null],
                ['path' => 'test.txt', 'type' => 'file', 'size' => '13', 'last_modified' => 1741619762000],
            ]
        ]);
});

test('list contents of subdirectory', function () {
    // Mock the objects iterator for subdirectory
    $mockObject = Mockery::mock(StorageObject::class);
    $mockObject->shouldReceive('name')->andReturn('subdir/file1.txt');
    $mockObject->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    $mockIterator = Mockery::mock('Iterator');
    $mockIterator->shouldReceive('current')->andReturn($mockObject);
    $mockIterator->shouldReceive('next');
    $mockIterator->shouldReceive('rewind');
    $mockIterator->shouldReceive('valid')->andReturn(true, false);
    $mockIterator->shouldReceive('prefixes')->andReturn(['subdir/nested/']);

    // Set up expectations for the bucket
    $this->mockBucket->shouldReceive('objects')
        ->with(['prefix' => 'subdir/', 'delimiter' => '/'])
        ->andReturn($mockIterator);

    // Mock the file object
    $mockFileObject = Mockery::mock(StorageObject::class);
    $mockFileObject->shouldReceive('name')->andReturn('subdir/file1.txt');
    $mockFileObject->shouldReceive('info')->andReturn([
        'size' => 100,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);

    // Set up file object expectations
    $this->mockBucket->shouldReceive('object')
        ->with('subdir/file1.txt')
        ->andReturn($mockFileObject);
    
    // Make the request
    $response = $this->getJson('/api/gcs/list?path=subdir');
    
    // Assert response structure
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                ['path' => 'subdir/nested', 'type' => 'directory', 'size' => 0, 'last_modified' => null],
                ['path' => 'subdir/file1.txt', 'type' => 'file', 'size' => '100', 'last_modified' => 1741619762000],
            ]
        ]);
});

test('upload file to root directory', function () {
    // Create a fake file (size in KB)
    $file = UploadedFile::fake()->create('test.txt', 0.1); // 0.1 KB = ~100 bytes
    
    // Mock the upload process
    $this->mockBucket->shouldReceive('upload')
        ->withArgs(function ($resource, $options) {
            return is_resource($resource) && $options['name'] === 'test.txt';
        })
        ->once();
    
    // Make the request
    $response = $this->postJson('/api/gcs/upload', [
        'file' => $file,
        'path' => '/'
    ]);
    
    // Assert response structure
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'path' => 'test.txt',
                'size' => 102,
                'mime_type' => 'text/plain'
            ]
        ]);
});

test('upload file to subdirectory', function () {
    // Create a fake file (size in KB)
    $file = UploadedFile::fake()->create('test.txt', 0.1); // 0.1 KB = ~100 bytes
    
    // Mock the upload process
    $this->mockBucket->shouldReceive('upload')
        ->withArgs(function ($resource, $options) {
            return is_resource($resource) && $options['name'] === 'subdir/test.txt';
        })
        ->once();
    
    // Make the request
    $response = $this->postJson('/api/gcs/upload', [
        'file' => $file,
        'path' => 'subdir'
    ]);
    
    // Assert response structure
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'path' => 'test.txt',
                'size' => 102,
                'mime_type' => 'text/plain'
            ]
        ]);
});

test('delete file', function () {
    // Mock the object
    $mockObject = Mockery::mock(StorageObject::class);
    $mockObject->shouldReceive('exists')->andReturn(true);
    $mockObject->shouldReceive('delete')->once();
    $mockObject->shouldReceive('info')->andReturn([
        'size' => 13,
        'updated' => '2025-03-10T11:27:01-04:00'
    ]);
    
    // Set up expectations
    $this->mockBucket->shouldReceive('object')->with('test.txt')->andReturn($mockObject);
    
    // Make the request
    $response = $this->deleteJson('/api/gcs/delete', [
        'paths' => ['test.txt'],
        'strategy' => 'all'
    ]);
    
    // Assert response structure
    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'deleted_files' => ['test.txt']
            ]
        ]);
});

test('handle missing google cloud key file', function () {
    // Mock storage_path helper
    $keyFilePath = '/non/existent/path/google-cloud-key.json';
    Storage::shouldReceive('path')->with('app/google-cloud-key.json')->andReturn($keyFilePath);
    
    // Mock the StorageClient to throw an exception
    $this->app->bind(StorageClient::class, function () use ($keyFilePath) {
        throw new \Exception('Google Cloud key file not found at: ' . $keyFilePath);
    });
    
    // Make the request
    $response = $this->getJson('/api/gcs/list');
    
    // Assert error response
    $response->assertStatus(500)
        ->assertJson([
            'success' => false,
            'error' => 'Google Cloud key file not found at: ' . $keyFilePath
        ]);
});

test('handle missing bucket configuration', function () {
    // Force the bucket name to be empty
    config(['filesystems.disks.gcs.bucket' => '']);
    
    // Make the request
    $response = $this->getJson('/api/gcs/list');
    
    // Assert error response
    $response->assertStatus(500)
        ->assertJson([
            'success' => false,
            'error' => 'Google Cloud Storage bucket name not configured. Please check GOOGLE_CLOUD_STORAGE_BUCKET in .env'
        ]);
});

// Helper function to create mock objects
function createMockObject($name, $info): StorageObject
{
    $mockObject = Mockery::mock(StorageObject::class);
    $mockObject->shouldReceive('name')->andReturn($name);
    $mockObject->shouldReceive('info')->andReturn($info);
    return $mockObject;
}

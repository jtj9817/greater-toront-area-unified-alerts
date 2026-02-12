<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GeospatialImportTest extends TestCase
{
    public function test_import_fails_on_large_file()
    {
        $largeFilePath = storage_path('large_file.json');

        // Mock file operations
        File::shouldReceive('exists')
            ->with($largeFilePath)
            ->andReturn(true);

        File::shouldReceive('size')
            ->with($largeFilePath)
            ->andReturn(51 * 1024 * 1024); // 51MB

        $this->artisan('import:toronto-geospatial-data', ['file' => $largeFilePath])
            ->assertFailed()
            ->expectsOutput('File size exceeds limit (50MB). Please split the file or increase limit.');
    }
}

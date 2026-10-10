<?php

namespace Tests\Feature;

use Dcat\Admin\Grid;
use Dcat\Admin\Grid\Tools\BatchActions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\TestCase;

class AdminRuntimeComponentTest extends TestCase
{
    public function test_bound_upload_component_preserves_file_and_chunk_request_values(): void
    {
        $path = tempnam($this->app->storagePath(), 'upload-component-');
        file_put_contents($path, 'Synthetic upload bytes');
        $file = new UploadedFile($path, 'fixture.txt', 'text/plain', null, true);
        $request = Request::create('/admin/goods', 'POST', ['_id' => '0', 'chunk' => '0', 'chunks' => '2', 'upload_column' => 'picture'], [], ['_file_' => $file]);
        $previousRequest = $this->app['request'];
        $this->app->instance('request', $request);
        $this->app->forgetInstance('admin.web-uploader');
        $previous = set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity & error_reporting()) throw new \ErrorException($message, 0, $severity, $file, $line);
            return false;
        });
        try {
            $upload = $this->app->make('admin.web-uploader');
            $this->assertSame('0', $upload->_id);
            $this->assertSame('0', $upload->chunk);
            $this->assertSame('2', $upload->chunks);
            $this->assertSame('picture', $upload->upload_column);
            $this->assertInstanceOf(UploadedFile::class, $upload->file);
            $this->assertSame($file->getRealPath(), $upload->file->getRealPath());
            $this->assertSame('fixture.txt', $upload->file->getClientOriginalName());
            $this->assertTrue($upload->isUploading());
            $this->assertTrue($upload->hasChunkFile());
        } finally {
            restore_error_handler();
            $this->app->instance('request', $previousRequest);
            $this->app->forgetInstance('admin.web-uploader');
            unlink($path);
        }
    }

    public function test_default_batch_factory_renders_the_original_delete_action(): void
    {
        $previous = set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity & error_reporting()) throw new \ErrorException($message, 0, $severity, $file, $line);
            return false;
        });
        try {
            $batch = new BatchActions();
            $property = new \ReflectionProperty($batch, 'actions');
            if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
            $action = $property->getValue($batch)->first();
            $grid = new Grid(new \App\Admin\Repositories\Goods());
            $action->setGrid($grid);
            $html = $action->render();
            $this->assertStringContainsString('data-action="batch-delete"', $html);
            $this->assertStringContainsString('data-url="', $html);
            $this->assertStringContainsString('icon-trash', $html);
        } finally {
            restore_error_handler();
        }
    }
}

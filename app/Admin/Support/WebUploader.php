<?php

namespace App\Admin\Support;

class WebUploader extends \Dcat\Admin\Support\WebUploader
{
    // Dcat populates these request values without normalizing their types.
    public $_id;
    public $chunk;
    public $chunks;
    public $upload_column;
    public $file;
}

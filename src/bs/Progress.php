<?php
namespace bs;

use FastRoute\RouteParser\Std;
use stdClass;

class Progress {

    private $tempFile = __DIR__.'/../../temp/progress.json';
    public function update($key, $value)
    {
        $content = 
            file_exists($this->tempFile) ?
            json_decode(file_get_contents($this->tempFile)) : new stdClass();
        $content->$key = $value;
        return file_put_contents($this->tempFile, json_encode($content));
    }
}
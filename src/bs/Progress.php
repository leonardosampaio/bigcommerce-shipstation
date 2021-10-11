<?php
namespace bs;

use FastRoute\RouteParser\Std;
use stdClass;

class Progress {

    private $tempFile = __DIR__.'/../../temp/progress.json';
    public function update($key, $value)
    {
        return file_put_contents($this->tempFile, json_encode(['key'=>$key,'value'=>$value]));
    }
    public function clean()
    {
        return file_put_contents($this->tempFile, json_encode([]));
    }
}
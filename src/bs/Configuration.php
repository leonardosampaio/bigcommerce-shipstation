<?php

namespace bs;

use stdClass;

class Configuration {

    private function getJsonFromConfigFile($filePath)
    {
        $result = new stdClass();

        if (!file_exists($filePath) || !is_readable($filePath))
        {
            $result->error = "Configuration $filePath not found or not readable";
            return $result;
        }

        return json_decode(file_get_contents($filePath));
    }
    
    public function getBigCommerce()
    {
        return $this->getJsonFromConfigFile(__DIR__.'/../../configuration/bigcommerce.json');
    }

    public function getShipStation()
    {
        return $this->getJsonFromConfigFile(__DIR__.'/../../configuration/shipstation.json');
    }

    public function getApplication()
    {
        return $this->getJsonFromConfigFile(__DIR__.'/../../configuration/application.json');
    }
}
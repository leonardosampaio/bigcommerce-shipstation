<?php

namespace bs;

use stdClass;

class CurlWrapper {

    /**
     * Initializes a curl consumer (https://www.php.net/manual/function.curl-init)
    */
    private function getInit($url, $headers, &$outputHeaders)
    {
        $consumer = curl_init();

        if ($headers)
        {
            curl_setopt($consumer, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt($consumer, CURLOPT_URL, $url);
        curl_setopt($consumer, CURLOPT_PORT, 443);
        curl_setopt($consumer, CURLOPT_POST, 0); 
        curl_setopt($consumer, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($consumer, CURLOPT_SSL_VERIFYPEER, 1);

        curl_setopt($consumer, CURLOPT_HEADER, 1);
        curl_setopt($consumer, CURLOPT_HEADERFUNCTION,
            function($curl, $header) use (&$outputHeaders)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2)
                {
                    return $len;
                }

                $outputHeaders[strtolower(trim($header[0]))][] = trim($header[1]);

                return $len;
        });

        curl_setopt($consumer, CURLOPT_FOLLOWLOCATION, TRUE);

        return $consumer;
    }

    /**
     * Parallel curl GET requests. If the server sends a rate limiting header,
     * pauses for the defined time before retrying
    */
    public function multiGet($urls, $headers = null)
    {
        $result = array();

        do {
            $result = array();
            $mh = curl_multi_init();
    
            $handles = array();
            $outputHeaders = array();
            foreach($urls as $page => $url)
            {
                $outputHeaders[$page] = [];
                $handle = $this->getInit($url, $headers, $outputHeaders[$page]);
                curl_multi_add_handle($mh, $handle);
                $handles[$page] = $handle;
            }
    
            $running = null;
            do {
                curl_multi_exec($mh, $running);
            } while ($running);
    
            foreach($handles as $page => $handle)
            {
                $response = explode("\r\n\r\n", curl_multi_getcontent($handle), 2);
                $responseHeaders = 
                    !empty($outputHeaders) && isset($outputHeaders[$page]) ? 
                    $outputHeaders[$page] : array();
                
                $bigCommerceRateLimit = 
                    isset($responseHeaders['x-rate-limit-requests-left']) && $responseHeaders['x-rate-limit-requests-left'][0] == 0 ?
                        $responseHeaders['x-rate-limit-time-reset-ms'][0] / 1000 : null;

                $shipStationRateLimit = isset($responseHeaders['x-rate-limit-remaining']) && $responseHeaders['x-rate-limit-remaining'][0] == 0 ? 
                    $responseHeaders['x-rate-limit-reset'][0] : null;

                if (!$bigCommerceRateLimit && !$shipStationRateLimit)
                {
                    unset($urls[$page]);

                    if (!empty($response) &&
                        sizeof($response) == 2 &&
                        !empty($response[1]) &&
                        in_array(substr($response[1],0,1),['{','[']))
                    {
                        $result[$page] = json_decode($response[1]);
                    }
                }
                else
                {
                    $sleepFor = 1;
                    if ($bigCommerceRateLimit)
                    {
                        $sleepFor = $bigCommerceRateLimit;
                    }
                    else if ($shipStationRateLimit)
                    {
                        $sleepFor = $shipStationRateLimit;
                    }

                    sleep($sleepFor);
                }

                curl_multi_remove_handle($mh, $handle);
            }
            
            curl_multi_close($mh);

            if (!empty($urls))
            {
                sleep(1);
            }
        }
        while (!empty($urls));

        return $result;
    }
}
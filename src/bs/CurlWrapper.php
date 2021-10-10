<?php

namespace bs;

use stdClass;

class CurlWrapper {

    private function getInit($url, $headers)
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

        //curl_setopt($consumer, CURLOPT_HEADER, 1);
        curl_setopt($consumer, CURLOPT_FOLLOWLOCATION, TRUE);

        return $consumer;
    }

    public function get($url, $headers = null)
    {
        $consumer = $this->getInit($url, $headers);

        $response = curl_errno($consumer) ? curl_errno($consumer) : curl_exec($consumer); 
        $httpcode = curl_getinfo($consumer, CURLINFO_HTTP_CODE);

        curl_close($consumer);

        $result = new stdClass();
        $result->httpcode = $httpcode;
        $result->response = in_array(substr($response,0,1),['{','[']) ?
            json_decode($response) : $response;

        return $result;
    }

    public function multiGet($urls, $headers = null)
    {
        $result = array();
        $mh = curl_multi_init();

        $handles = array();
        foreach($urls as $page => $url)
        {
            $handle = $this->getInit($url, $headers);
            curl_multi_add_handle($mh, $handle);
            $handles[$page] = $handle;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running);

        foreach($handles as $page => $handle)
        {
            $response = curl_multi_getcontent($handle);

            if (!empty($response) && in_array(substr($response,0,1),['{','[']))
            {
                $result[$page] = json_decode($response);
            }

            curl_multi_remove_handle($mh, $handle);
        }
        
        curl_multi_close($mh);

        return $result;
    }
}
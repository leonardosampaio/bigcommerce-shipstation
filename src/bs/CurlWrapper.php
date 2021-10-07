<?php

namespace bs;

class CurlWrapper {

    public function get($url, $headers = null)
    {
        $consumer = curl_init();

        if ($headers)
        {
            curl_setopt($consumer, CURLOPT_HTTPHEADER, $headers);
        }

        curl_setopt($consumer, CURLOPT_URL, $url);
        curl_setopt($consumer, CURLOPT_PORT, 443);
        curl_setopt($consumer, CURLOPT_HEADER, 0);
        curl_setopt($consumer, CURLOPT_POST, 0); 
        curl_setopt($consumer, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($consumer, CURLOPT_SSL_VERIFYPEER, 1);

        $response = curl_exec($consumer); 
        $httpcode = curl_getinfo($consumer, CURLINFO_HTTP_CODE);

        if (curl_errno($consumer))
        { 
            $response = curl_error($consumer);
            curl_close($consumer); 
        }
        return [
            'httpcode'=>$httpcode,
            'response'=>
                in_array(substr($response,0,1),['{','[']) ?
                    json_decode($response) : $response
        ];
    }
}
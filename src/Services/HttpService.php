<?php

namespace ThomasDeLuck\AmazonSP\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class HttpService
{
    protected $client;

    /**
     * Set client.
     *
     * @return $this
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Parse XML from string.
     *
     * @return mixed
     */
    public function parseXML(string $string)
    {
        $xml = simplexml_load_string($string, null, LIBXML_NOCDATA);

        return @json_decode(json_encode($xml));
    }

    /**
     * Read CSV content.
     *
     * @return mixed
     */
    public function readCSVContent(string $content, bool $hasHeader = true)
    {
        $results = [];
        $file = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($file, $content);
        $row = 0;
        if (($handle = fopen($file, 'r')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (!isset($headers)) {
                    $headers = $hasHeader ? $data : array_keys($data);
                }
                if ($row || !$hasHeader) {
                    $results[] = collect($headers)->combine($data);
                }
                $row++;
            }
            fclose($handle);
        }

        return $results;
    }

    /**
     * Send request.
     *
     * @return mixed
     */
    public function sendRequest(string $method, string $path, array $data = [])
    {
        try {
            $response = $this->client->request($method, $path, $data);
            $body = $response->getBody();

            if ($response->hasHeader('content-type')) {
                // XML content
                if (strpos($response->getHeader('content-type')[0], 'xml') !== false) {
                    return $this->parseXML($body);
                }

                // JSON content
                if (strpos($response->getHeader('content-type')[0], 'json') !== false) {
                    return @json_decode($body);
                }
            }

            // Raw content
            return $body;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $content = $response->getBody()->getContents();

            if ($response->hasHeader('content-type')) {
                // XML error
                if (strpos($response->getHeader('content-type')[0], 'xml') !== false) {
                    return $this->parseXML($content);
                }

                // JSON error
                if (strpos($response->getHeader('content-type')[0], 'json') !== false) {
                    return @json_decode($content);
                }
            }

            // Raw error
            throw $e;
        }
    }
}

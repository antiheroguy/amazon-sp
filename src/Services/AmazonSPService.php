<?php

namespace AVeryLongTips\AmazonSP\Services;

use GuzzleHttp\Client;

class AmazonSPService extends HttpService
{
    public const REGION = [
        'us-east-1' => 'https://sellingpartnerapi-na.amazon.com',
        'eu-west-1' => 'https://sellingpartnerapi-eu.amazon.com',
        'us-west-2' => 'https://sellingpartnerapi-fe.amazon.com',
    ];

    protected $config;
    protected $region;
    protected $accessToken;
    protected $refreshToken;

    public function __construct()
    {
        $this->config = config('amazon-sp');
    }

    /**
     * Set region.
     *
     * @return $this
     */
    public function setRegion(string $region)
    {
        $this->region = $region;

        return $this;
    }

    /**
     * Set access token.
     *
     * @return $this
     */
    public function setAccessToken(string $token)
    {
        $this->accessToken = $token;

        return $this;
    }

    /**
     * Set refresh.
     *
     * @return $this
     */
    public function setRefreshToken(string $token)
    {
        $this->refreshToken = $token;

        return $this;
    }

    /**
     * Sign signature then send request.
     * See: https://docs.aws.amazon.com/general/latest/gr/sigv4_signing.html.
     *
     * @return mixed
     */
    public function sendRequest(string $method, string $path, array $config = [])
    {
        if (!$this->region || !array_key_exists($this->region, self::REGION)) {
            throw new \Exception('Wrong or missing region');
        }

        $endpoint = self::REGION[$this->region];
        $this->setClient(new Client([
            'base_uri' => $endpoint,
        ]));

        if ($this->refreshToken) {
            $refreshData = $this->refreshToken();
            $this->setAccessToken($refreshData->access_token);
        }

        if (!$this->accessToken) {
            throw new \Exception('Missing access token');
        }

        $currentDate = gmdate('Ymd');
        $currentTime = gmdate('Ymd\\THis\\Z');
        $headers = [
            'host' => parse_url($endpoint)['host'],
            'x-amz-access-token' => $this->accessToken,
            'x-amz-date' => $currentTime,
            'user-agent' => 'Guzzle/1.0 (Language=PHP/7)',
        ];

        /** 1: Create a canonical */

        // 1.1: Start with the HTTP request method (GET, PUT, POST, etc.), followed by a newline character
        $canonicalRequest = $method . "\n";

        // 1.2: Add the canonical URI parameter, followed by a newline character
        $canonicalRequest .= $path . "\n";

        // 1.3: Add the canonical query string, followed by a newline character
        $query = $config['query'] ?? [];
        ksort($query);
        $canonicalRequest .= http_build_query($query) . "\n";

        // 1.4: Add the canonical headers, followed by a newline character
        $headers = array_merge($headers, $config['headers'] ?? []);
        $signedHeaders = '';
        ksort($headers);
        foreach ($headers as $key => $value) {
            $signedHeaders .= $key . ';';
            $canonicalRequest .= $key . ':' . $value . "\n";
        }
        $canonicalRequest .= "\n";

        // 1.5: Add the signed headers, followed by a newline character
        $signedHeaders = substr($signedHeaders, 0, -1);
        $canonicalRequest .= $signedHeaders . "\n";

        // 1.6: Use a hash (digest) function like SHA256 to create a hashed value from the payload in the body of the HTTP or HTTPS request
        $payload = $config['body'] ?? '';
        $canonicalRequest .= strtolower(bin2hex(hash('sha256', $payload, true)));

        // 1.8: Create a digest (hash) of the canonical request with the same algorithm that you used to hash the payload
        $canonicalRequest = strtolower(bin2hex(hash('sha256', $canonicalRequest, true)));

        // 2: Create a string to sign

        // 2.1: Start with the algorithm designation, followed by a newline character
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n";

        // 2.2: Append the request date value, followed by a newline character
        $stringToSign .= $currentTime . "\n";

        // 2.3: Append the credential scope value, followed by a newline character
        $credentialScope = $currentDate . '/' . $this->region . '/execute-api/aws4_request';
        $stringToSign .= $credentialScope . "\n";

        // 2.4: Append the hash of the canonical request
        $stringToSign .= $canonicalRequest;

        /** 3: Calculate the signature */

        // 3.1: Derive your signing key
        $kDate = hash_hmac('sha256', $currentDate, 'AWS4' . $this->config['access_key_secret'], true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 'execute-api', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = strtolower(bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true)));

        // 4: Add the signature to the HTTP request
        $headers['Authorization'] = 'AWS4-HMAC-SHA256' . ' ' . 'Credential=' . $this->config['access_key_id'] . '/' . $credentialScope . ', ' . 'SignedHeaders=' . $signedHeaders . ', ' . 'Signature=' . $signature;

        return parent::sendRequest($method, $path, array_merge(
            $config,
            [
                'headers' => $headers,
                'query' => $query,
                'body' => $payload,
            ]
        ));
    }

    /**
     * Step 1: Create feed document
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-1-create-a-feed-document.
     *
     * @return mixed
     */
    public function createFeedDocument()
    {
        $response = $this->sendRequest('POST', '/feeds/2020-09-04/documents', [
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
            ],
            'body' => json_encode([
                'contentType' => 'text/xml; charset=UTF-8',
            ]),
        ]);

        $this->handleError($response);

        return $response->payload;
    }

    /**
     * Step 2. Encrypt and upload the feed data
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-2-encrypt-and-upload-the-feed-data.
     *
     * @param mixed $payload
     * @param mixed $content
     *
     * @return mixed
     */
    public function encryptAndUploadFeedData($payload, $content)
    {
        $key = base64_decode($payload->encryptionDetails->key, true);
        $initializationVector = base64_decode($payload->encryptionDetails->initializationVector, true);
        $encryptedFeedData = openssl_encrypt(utf8_encode($content), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $initializationVector);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $payload->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $encryptedFeedData,
            CURLOPT_FAILONERROR => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml',
                'Content-Type: text/xml; charset=UTF-8',
            ],
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new \Exception(curl_error($ch), 400);
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($responseCode >= 400 || curl_errno($ch)) {
            throw new \Exception(curl_error($ch), $responseCode);
        }

        return true;
    }

    /**
     * Step 3. Create a feed
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-3-create-a-feed.
     *
     * @return mixed
     */
    public function createFeed(array $data)
    {
        $response = $this->sendRequest('POST', '/feeds/2020-09-04/feeds', [
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
            ],
            'body' => json_encode($data),
        ]);

        $this->handleError($response);

        return $response->payload->feedId;
    }

    /**
     * Step 4. Confirm feed processing
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-4-confirm-feed-processing.
     *
     * @return mixed
     */
    public function confirmFeedProcessing(string $feedId)
    {
        $processingStatus = 'NONE';

        while ($processingStatus !== 'DONE') {
            $response = $this->sendRequest('GET', "/feeds/2020-09-04/feeds/{$feedId}", [
                'headers' => [
                    'content-type' => 'application/json; charset=utf-8',
                ],
            ]);

            $this->handleError($response);

            $payload = $response->payload;
            $processingStatus = $payload->processingStatus ?? null;

            if (!$processingStatus || in_array($processingStatus, ['CANCELLED', 'FATAL'])) {
                throw new \Exception('The feed could not be completed', 400);
            }

            // To avoid exceeding quota for the requested resource
            sleep(5);
        }

        $feedDocumentId = $payload->resultFeedDocumentId ?? null;
        if (!$feedDocumentId) {
            throw new \Exception('Wrong feed document id');
        }

        return $feedDocumentId;
    }

    /**
     * Step 5. Get information for retrieving the feed processing report
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-5-get-information-for-retrieving-the-feed-processing-report.
     *
     * @param mixed $feedDocumentId
     *
     * @return mixed
     */
    public function getFeedDocument($feedDocumentId)
    {
        $response = $this->sendRequest('GET', "/feeds/2020-09-04/documents/{$feedDocumentId}", [
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
            ],
        ]);

        $this->handleError($response);

        return $response->payload;
    }

    /**
     * Step 6. Download and decrypt the feed processing report
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/feeds-api-use-case-guide/feeds-api-use-case-guide-2020-09-04.md#step-6-download-and-decrypt-the-feed-processing-report.
     *
     * @param mixed $payload
     *
     * @return mixed
     */
    public function downloadAndDecryptFeedData($payload)
    {
        $client = new Client();
        $response = $client->request('GET', $payload->url);
        $key = base64_decode($payload->encryptionDetails->key, true);
        $initializationVector = base64_decode($payload->encryptionDetails->initializationVector, true);
        $content = utf8_decode(openssl_decrypt($response->getBody()->getContents(), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $initializationVector));
        $data = $this->parseXML($content);

        $result = $data->Message->ProcessingReport->Result ?? null;
        if ($result && $result->ResultCode === 'Error') {
            throw new \Exception($result->ResultDescription, 400);
        }

        return $data;
    }

    /**
     * Step 1. Request a report
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/reports-api-use-case-guide/reports-api-use-case-guide-2020-09-04.md#_Step_1_Request_1.
     *
     * @return mixed
     */
    public function requestReport(array $body)
    {
        $response = $this->sendRequest('POST', '/reports/2020-09-04/reports', [
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
            ],
            'body' => json_encode($body),
        ]);

        $this->handleError($response);

        return $response->payload->reportId;
    }

    /**
     * Step 2. Confirm that report processing has completed
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/reports-api-use-case-guide/reports-api-use-case-guide-2020-09-04.md#step-2-confirm-that-report-processing-has-completed.
     *
     * @return mixed
     */
    public function confirmReportProcessing(string $reportId)
    {
        $processingStatus = 'NONE';

        while ($processingStatus !== 'DONE') {
            $response = $this->sendRequest('GET', "/reports/2020-09-04/reports/{$reportId}", [
                'headers' => [
                    'content-type' => 'application/json; charset=utf-8',
                ],
            ]);

            $this->handleError($response);

            $payload = $response->payload;
            $processingStatus = $payload->processingStatus ?? null;

            if (!$processingStatus || in_array($processingStatus, ['CANCELLED', 'FATAL'])) {
                throw new \Exception('The report could not be completed', 400);
            }

            // To avoid exceeding quota for the requested resource
            sleep(5);
        }

        $reportDocumentId = $payload->reportDocumentId ?? null;
        if (!$reportDocumentId) {
            throw new \Exception('Wrong report document id');
        }

        return $payload;
    }

    /**
     * Step 3. Retrieve the report
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/reports-api-use-case-guide/reports-api-use-case-guide-2020-09-04.md#step-1-get-information-required-to-retrieve-the-report.
     *
     * @return mixed
     */
    public function retrieveReportDocument(string $reportDocumentId)
    {
        $response = $this->sendRequest('GET', "/reports/2020-09-04/documents/{$reportDocumentId}");

        $this->handleError($response);

        return $response->payload;
    }

    /**
     * Step 3. Retrieve the report
     * See: https://github.com/amzn/selling-partner-api-docs/blob/main/guides/en-US/use-case-guides/reports-api-use-case-guide/reports-api-use-case-guide-2020-09-04.md#step-2-download-and-decrypt-the-report.
     *
     * @param mixed $payload
     *
     * @return mixed
     */
    public function downloadAndDecryptReportData($payload)
    {
        $client = new Client();
        $response = $client->request('GET', $payload->url);
        $key = base64_decode($payload->encryptionDetails->key, true);
        $initializationVector = base64_decode($payload->encryptionDetails->initializationVector, true);

        return openssl_decrypt($response->getBody()->getContents(), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $initializationVector);
    }

    /**
     * Handle common error.
     *
     * @param mixed $response
     */
    public function handleError($response)
    {
        if (!$response) {
            throw new \Exception('Request signature does not match', 400);
        }
        $errors = $response->errors ?? null;
        if ($errors) {
            $error = is_array($errors) ? $errors[0] : $errors;
            throw new \Exception($error->message, 400);
        }
    }

    /**
     * Get oauth URI.
     *
     * @return string
     */
    public function getAuthorizationUrl(string $state)
    {
        $query = [
            'application_id' => $this->config['application_id'],
            'state' => $state,
        ];

        if (config('app.env') !== 'production') {
            $query['version'] = 'beta';
        }

        return $this->config['seller_central_url'] . '/apps/authorize/consent?' . http_build_query($query);
    }

    /**
     * Authorization code.
     *
     * @return mixed
     */
    public function authorization(string $code)
    {
        $response = parent::sendRequest('POST', '/auth/o2/token', [
            'base_uri' => 'https://api.amazon.com',
            'auth' => [$this->config['client_id'], $this->config['client_secret']],
            'headers' => [
                'Authorization' => 'None',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
            ],
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config['redirect_uri'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ],
        ]);

        $error = $response->error_description ?? null;
        if ($error) {
            throw new \Exception($error, 400);
        }

        return $response;
    }

    /**
     * Refresh token.
     *
     * @return mixed
     */
    public function refreshToken()
    {
        if (!$this->refreshToken) {
            throw new \Exception('Missing refresh token');
        }

        $response = parent::sendRequest('POST', '/auth/o2/token', [
            'base_uri' => 'https://api.amazon.com',
            'auth' => [$this->config['client_id'], $this->config['client_secret']],
            'headers' => [
                'Authorization' => 'None',
                'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
            ],
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'redirect_uri' => $this->config['redirect_uri'],
                'client_id' => $this->config['client_id'],
                'client_secret' => $this->config['client_secret'],
            ],
        ]);

        $error = $response->error_description ?? null;
        if ($error) {
            throw new \Exception($error, 400);
        }

        return $response;
    }
}

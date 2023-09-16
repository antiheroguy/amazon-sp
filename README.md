[![Latest Stable Version](http://poser.pugx.org/antiheroguy/amazon-sp/v)](https://packagist.org/packages/antiheroguy/amazon-sp)
[![Total Downloads](http://poser.pugx.org/antiheroguy/amazon-sp/downloads)](https://packagist.org/packages/antiheroguy/amazon-sp)
[![Latest Unstable Version](http://poser.pugx.org/antiheroguy/amazon-sp/v/unstable)](https://packagist.org/packages/antiheroguy/amazon-sp)
[![License](http://poser.pugx.org/antiheroguy/amazon-sp/license)](https://packagist.org/packages/antiheroguy/amazon-sp)
[![PHP Version Require](http://poser.pugx.org/antiheroguy/amazon-sp/require/php)](https://packagist.org/packages/antiheroguy/amazon-sp)

# Amazon SP Guzzle
Guzzle client for Amazon SP API

## Installation
```composer require antiheroguy/amazon-sp```

## Usage

* Setup
```php
use AntiHeroGuy\AmazonSP\Services\AmazonSPService;

$service = new AmazonSPService();

$service->setRegion('us-west-2')

$service->setAccessToken('XXX');
// or
$service->setRefreshToken('XXX');
```

* Sample request
```php
$response = $service->sendRequest('GET', '/catalog/v0/items', [
    'headers' => [
        'content-type' => 'application/json; charset=utf-8',
    ],
    'query' => [
        'MarketplaceId' => 'A1VC38T7YXB528',
        'Query' => 'book',
    ],
]);

$service->handleError($response);

return $response;
```

* Sample feed
```php
$content =
'<?xml version="1.0"?>
<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">
	<Header>
		<DocumentVersion>1.01</DocumentVersion>
		<MerchantIdentifier>A1VC38T7YXB528</MerchantIdentifier>
	</Header>
	<MessageType>Inventory</MessageType>
	<Message>
		<MessageID>1923452925</MessageID>
		<OperationType>Update</OperationType>
		<Inventory>
			<SKU>XXX</SKU>
			<Quantity>10</Quantity>
		</Inventory>
	</Message>
</AmazonEnvelope>';

// Step 1
$feedPayload = $service->createFeedDocument();

// Step 2
$service->encryptAndUploadFeedData($feedPayload, $content);

// Step 3
$feedId = $service->createFeed([
    'feedType' => 'POST_INVENTORY_AVAILABILITY_DATA',
    'marketplaceIds' => ['A1VC38T7YXB528'],
    'inputFeedDocumentId' => $feedPayload->feedDocumentId,
]);

// Step 4
$feedDocumentId = $service->confirmFeedProcessing($feedId);

// Step 5
$documentPayload = $service->getFeedDocument($feedDocumentId);

// Step 6
return $service->downloadAndDecryptFeedData($documentPayload);
```

* Sample report
```php
// Step 1
$reportId = $service->requestReport([
    'reportType' => 'GET_FLAT_FILE_OPEN_LISTINGS_DATA',
    'marketplaceIds' => ['A1VC38T7YXB528'],
    'dataStartTime' => now()->sub(30, 'days')->format('Y-m-d\\TH:i:s\\Z'),
    'dataEndTime' => now()->format('Y-m-d\\TH:i:s\\Z'),
    'reportOptions' => [
        'ShowSalesChannel' => true,
    ],
]);

// Step 2
$reportPayload = $service->confirmReportProcessing($reportId);

// Step 3
$documentPayload = $service->retrieveReportDocument($reportPayload->reportDocumentId);
$content = $service->downloadAndDecryptReportData($documentPayload);
$content = iconv('SJIS', 'utf-8', $content);

return $service->readCSVContent(str_replace("\t", ',', $content));
```

* You can publish config by running `php artisan vendor:publish --tag=amazon-sp`
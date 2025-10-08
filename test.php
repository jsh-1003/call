<?php
require 'vendor/autoload.php';
use Aws\S3\S3Client;

$s3 = new S3Client([
    'region'  => 'ap-northeast-2',
    'version' => 'latest',
]);

$result = $s3->listBuckets();
print_r($result['Buckets']);

<?php
namespace FullFeed;

use \Aws\S3\S3Client;
use \Aws\Credentials\Credentials;

class Uploader
{
    public static function upload()
    {

        $credentials = new \Aws\Credentials\Credentials(awsAccessKey, awsSecretKey);

        $s3 = new \Aws\S3\S3Client([
            'profile' => 'default',
            'version' => 'latest',
            'region' => 'eu-west-1',
            'credentials' => $credentials,
        ]);

        $files = [
            [
                "Bucket" => awsS3BucketName,
                "Body" => file_get_contents(__DIR__ . "/../www/index.html"),
                'Key' => "index.html",
                "ContentType" => "text/html",
                "ACL" => "public-read",
            ],
            [
                "Bucket" => awsS3BucketName,
                "Body" => file_get_contents(__DIR__ . "/../www/latest.html"),
                "Key" => "latest.html",
                "ContentType" => "text/html",
                "ACL" => "public-read",
            ],
            [
                "Bucket" => awsS3BucketName,
                "Body" => file_get_contents(__DIR__ . "/../www/cache.manifest"),
                "Key" => "cache.manifest",
                "ContentType" => "text/cache-manifest",
                "ACL" => "public-read",
            ],
        ];

        foreach ($files as $object) {
            $s3->putObject($object);
        }
    }
}

<?php
declare(strict_types=1);

namespace TypeDock\Storage;

use TypeDock\Contract\StorageDriver;

class S3Storage implements StorageDriver
{
    private string $bucket;
    private string $url;
    private ?\Aws\S3\S3Client $client = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config = [])
    {
        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->url    = rtrim((string) ($config['url'] ?? ''), '/');
    }

    public function put(string $path, string $contents): bool
    {
        try {
            $this->getClient()->putObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($path, '/'),
                'Body'   => $contents,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function putFile(string $path, string $localPath): bool
    {
        try {
            $this->getClient()->putObject([
                'Bucket'     => $this->bucket,
                'Key'        => ltrim($path, '/'),
                'SourceFile' => $localPath,
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($path, '/'),
            ]);
            return (string) $result['Body'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->getClient()->doesObjectExist($this->bucket, ltrim($path, '/'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->getClient()->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => ltrim($path, '/'),
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function url(string $path): string
    {
        if ($this->url !== '') {
            return $this->url . '/' . ltrim($path, '/');
        }
        $region = (string) ($this->config['region'] ?? 'us-east-1');
        return "https://{$this->bucket}.s3.{$region}.amazonaws.com/" . ltrim($path, '/');
    }

    public function listFiles(string $directory): array
    {
        try {
            $result = $this->getClient()->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => ltrim($directory, '/') . '/',
            ]);
            $files = [];
            foreach ($result['Contents'] ?? [] as $object) {
                $files[] = (string) $object['Key'];
            }
            return $files;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getClient(): \Aws\S3\S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \TypeDock\Exception\TypeDockException('aws/aws-sdk-php is required for S3 storage. Run: composer require aws/aws-sdk-php');
        }

        $args = [
            'version' => 'latest',
            'region'  => $this->config['region'] ?? 'us-east-1',
            'credentials' => [
                'key'    => $this->config['key'] ?? '',
                'secret' => $this->config['secret'] ?? '',
            ],
        ];

        if (!empty($this->config['endpoint'])) {
            $args['endpoint']                = $this->config['endpoint'];
            $args['use_path_style_endpoint'] = true;
        }

        $this->client = new \Aws\S3\S3Client($args);
        return $this->client;
    }
}

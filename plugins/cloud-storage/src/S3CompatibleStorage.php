<?php
declare(strict_types=1);

namespace TypeDock\Plugin\CloudStorage;

use TypeDock\Contract\StorageDriver;

final class S3CompatibleStorage implements StorageDriver
{
    private string $bucket;
    private string $publicUrl;
    private string $keyPrefix;
    private ?\AsyncAws\S3\S3Client $client = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->bucket    = (string) ($config['bucket'] ?? '');
        $this->publicUrl = rtrim((string) ($config['public_url'] ?? ''), '/');
        $this->keyPrefix = trim((string) ($config['key_prefix'] ?? ''), '/');
    }

    public function put(string $path, string $contents): bool
    {
        try {
            $this->getClient()->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->key($path),
                'Body'   => $contents,
            ])->resolve();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function putFile(string $path, string $localPath): bool
    {
        $resource = @fopen($localPath, 'rb');
        if ($resource === false) {
            return false;
        }

        try {
            $this->getClient()->putObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->key($path),
                'Body'   => $resource,
            ])->resolve();
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($resource);
        }
    }

    public function get(string $path): ?string
    {
        try {
            $result = $this->getClient()->getObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->key($path),
            ]);
            return $result->getBody()->getContentAsString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function exists(string $path): bool
    {
        try {
            $this->getClient()->headObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->key($path),
            ])->resolve();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->getClient()->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $this->key($path),
            ])->resolve();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function url(string $path): string
    {
        $key = $this->key($path);
        if ($this->publicUrl !== '') {
            return $this->publicUrl . '/' . $key;
        }

        $endpoint = rtrim((string) ($this->config['endpoint'] ?? ''), '/');
        if ($endpoint !== '') {
            return $this->endpointUrl($endpoint, $key);
        }

        $region = (string) ($this->config['region'] ?? 'us-east-1');
        return "https://{$this->bucket}.s3.{$region}.amazonaws.com/" . $key;
    }

    public function listFiles(string $directory): array
    {
        try {
            $prefix = $this->key($directory);
            if ($prefix !== '') {
                $prefix .= '/';
            }
            $result = $this->getClient()->listObjectsV2([
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
            ]);

            $files = [];
            foreach ($result as $object) {
                if (!is_object($object) || !method_exists($object, 'getKey')) {
                    continue;
                }
                $files[] = $this->stripPrefix((string) $object->getKey());
            }
            return $files;
        } catch (\Throwable) {
            return [];
        }
    }

    private function getClient(): \AsyncAws\S3\S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!class_exists(\AsyncAws\S3\S3Client::class)) {
            throw new \RuntimeException('async-aws/s3 is required for Cloud Storage.');
        }

        $args = [
            'region'          => (string) ($this->config['region'] ?? 'us-east-1'),
            'accessKeyId'     => (string) ($this->config['access_key_id'] ?? ''),
            'accessKeySecret' => (string) ($this->config['secret_access_key'] ?? ''),
        ];

        $endpoint = trim((string) ($this->config['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $args['endpoint'] = $endpoint;
        }
        if ((bool) ($this->config['use_path_style_endpoint'] ?? false)) {
            $args['pathStyleEndpoint'] = true;
        }
        if ((bool) ($this->config['send_chunked_body'] ?? false)) {
            $args['sendChunkedBody'] = true;
        }

        $this->client = new \AsyncAws\S3\S3Client($args);
        return $this->client;
    }

    private function key(string $path): string
    {
        $path = ltrim($path, '/');
        return $this->keyPrefix === '' ? $path : $this->keyPrefix . '/' . $path;
    }

    private function stripPrefix(string $key): string
    {
        if ($this->keyPrefix === '') {
            return $key;
        }
        $prefix = $this->keyPrefix . '/';
        return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    private function endpointUrl(string $endpoint, string $key): string
    {
        if ((bool) ($this->config['use_path_style_endpoint'] ?? false)) {
            return $endpoint . '/' . rawurlencode($this->bucket) . '/' . $key;
        }

        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['host'])) {
            return $endpoint . '/' . $key;
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port   = isset($parts['port']) ? ':' . (string) $parts['port'] : '';
        $path   = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        return $scheme . '://' . $this->bucket . '.' . $parts['host'] . $port . $path . '/' . $key;
    }
}

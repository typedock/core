# Cloud Storage

Official S3-compatible storage provider for TypeDock media uploads.

Use it for Amazon S3, Cloudflare R2, MinIO, and other S3-compatible object
storage services. Enable the plugin from Settings -> Modules, then configure
it from the Cloud Storage plugin admin page.

For Amazon S3, bucket, region, access key ID, and secret access key are the
only fields normally required. Endpoint, public URL, key prefix, path-style,
and chunked body options are for compatible providers and CDN-backed buckets.

The plugin requires `async-aws/s3` to be available either in the project
Composer install or in the plugin's own `vendor/` directory.

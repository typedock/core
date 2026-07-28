# Cloud Storage

Official S3-compatible storage provider for TypeDock media uploads.

Cloud Storage is released as a separate `typedock-cloud-storage-*.zip`
attachment alongside each TypeDock release. In the admin, open
**Settings -> Modules**, upload the zip, and enable the plugin. The release
archive already contains its production Composer dependencies.

If the host rejects the browser upload because of `upload_max_filesize` or
`post_max_size`, extract the archive locally and upload the resulting
`cloud-storage/` directory into TypeDock's `plugins/` directory over FTP.

Use it for Amazon S3, Cloudflare R2, MinIO, and other S3-compatible object
storage services. Enable the plugin from Settings -> Modules, then configure
it from the Cloud Storage plugin admin page.

For Amazon S3, bucket, region, access key ID, and secret access key are the
only fields normally required. Endpoint, public URL, key prefix, path-style,
and chunked body options are for compatible providers and CDN-backed buckets.

Source checkouts require `composer install --no-dev -o` in this plugin
directory. Published plugin archives already include `vendor/`.

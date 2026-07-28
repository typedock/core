<?php
declare(strict_types=1);

$options = getopt('', [
    'version:',
    'channel:',
    'zip:',
    'zip-url:',
    'signature-url:',
    'release-notes-url:',
    'min-core-from::',
    'max-core-from::',
    'security',
    'breaking',
]);

foreach (['version', 'channel', 'zip', 'zip-url', 'signature-url', 'release-notes-url'] as $required) {
    if (!isset($options[$required]) || trim((string) $options[$required]) === '') {
        fwrite(STDERR, "Missing required option --{$required}\n");
        exit(2);
    }
}

$zip = (string) $options['zip'];
if (!is_file($zip)) {
    fwrite(STDERR, "Release zip not found: {$zip}\n");
    exit(2);
}

$metadata = [
    'schema_version' => 1,
    'channel' => (string) $options['channel'],
    'version' => ltrim((string) $options['version'], 'v'),
    'released_at' => gmdate(\DateTimeInterface::ATOM),
    'min_php' => '8.2.0',
    // RC6 is the first release that can safely self-apply the split package.
    'min_core_from' => (string) ($options['min-core-from'] ?? '1.0.0-rc6'),
    'max_core_from' => (string) ($options['max-core-from'] ?? ''),
    'zip_url' => (string) $options['zip-url'],
    'signature_url' => (string) $options['signature-url'],
    'sha256' => hash_file('sha256', $zip),
    'size_bytes' => (int) filesize($zip),
    'release_notes_url' => (string) $options['release-notes-url'],
    'revoked_versions' => [],
    'breaking_changes' => array_key_exists('breaking', $options),
    'security' => array_key_exists('security', $options),
];

echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

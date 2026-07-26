<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

use Closure;
use RuntimeException;

/**
 * Minimal Hrana-over-HTTP v2 client for remote libSQL services.
 *
 * Turso and Bunny Database expose the same /v2/pipeline endpoint and typed
 * value format. Transactions use a conditional Hrana batch in one HTTP
 * roundtrip, so they do not require an interactive session or baton.
 */
final class HranaHttpClient
{
    private string $endpoint;
    private string $lastInsertId = '0';
    private int $timeout;
    private int $connectTimeout;
    private bool $allowInsecure;
    private ?\CurlHandle $curlHandle = null;

    /**
     * @param array{timeout?:int,connect_timeout?:int,allow_insecure?:bool} $options
     * @param (Closure(string,list<string>,string):array{status:int,body:string})|null $sender
     */
    public function __construct(
        string $url,
        #[\SensitiveParameter] private readonly string $authToken,
        array $options = [],
        private readonly ?Closure $sender = null,
    ) {
        if (trim($url) === '') {
            throw new RuntimeException('LIBSQL_DATABASE_URL is required for the remote libSQL driver.');
        }
        if ($authToken === '') {
            throw new RuntimeException('LIBSQL_AUTH_TOKEN is required for the remote libSQL driver.');
        }

        $this->allowInsecure = (bool) ($options['allow_insecure'] ?? false);
        $this->timeout = max(1, (int) ($options['timeout'] ?? 15));
        $this->connectTimeout = max(1, (int) ($options['connect_timeout'] ?? 5));
        $this->endpoint = $this->pipelineUrl($url);
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array{rows:list<array<string,mixed>>,affected:int,columns:int,last_insert_id:?string}
     */
    public function execute(string $sql, array $params = []): array
    {
        $response = $this->send([
            'requests' => [
                ['type' => 'execute', 'stmt' => $this->statement($sql, $params)],
                ['type' => 'close'],
            ],
        ]);
        $executeResponse = $this->firstResponse($response, 'execute');
        $result = $executeResponse['result'] ?? null;
        if (!is_array($result)) {
            throw new RuntimeException('Remote libSQL returned a malformed statement result.');
        }

        return $this->decodeStatementResult($result);
    }

    /**
     * Execute write statements atomically in one non-interactive Hrana batch.
     *
     * BEGIN and each write are conditional on all previous steps succeeding.
     * COMMIT runs only when every write succeeds; otherwise ROLLBACK runs.
     *
     * @param list<array{sql:string,params?:array<int|string,mixed>}> $statements
     * @return list<array{rows:list<array<string,mixed>>,affected:int,columns:int,last_insert_id:?string}>
     */
    public function executeAtomicBatch(array $statements): array
    {
        if ($statements === []) {
            return [];
        }

        $steps = [
            ['stmt' => $this->statement('BEGIN', [])],
        ];

        foreach ($statements as $statement) {
            $stepIndex = count($steps);
            $steps[] = [
                'condition' => $this->allPreviousStepsSucceeded($stepIndex),
                'stmt' => $this->statement(
                    $statement['sql'],
                    $statement['params'] ?? [],
                ),
            ];
        }

        $commitIndex = count($steps);
        $steps[] = [
            'condition' => $this->allPreviousStepsSucceeded($commitIndex),
            'stmt' => $this->statement('COMMIT', []),
        ];
        $rollbackIndex = count($steps);
        $steps[] = [
            'condition' => [
                'type' => 'and',
                'conds' => [
                    ['type' => 'ok', 'step' => 0],
                    [
                        'type' => 'not',
                        'cond' => ['type' => 'ok', 'step' => $commitIndex],
                    ],
                ],
            ],
            'stmt' => $this->statement('ROLLBACK', []),
        ];

        $response = $this->send([
            'requests' => [
                ['type' => 'batch', 'batch' => ['steps' => $steps]],
                ['type' => 'close'],
            ],
        ]);
        $batchResponse = $this->firstResponse($response, 'batch');
        $batchResult = $batchResponse['result'] ?? null;
        if (!is_array($batchResult)) {
            throw new RuntimeException('Remote libSQL returned a malformed batch result.');
        }

        $stepResults = is_array($batchResult['step_results'] ?? null)
            ? $batchResult['step_results']
            : [];
        $stepErrors = is_array($batchResult['step_errors'] ?? null)
            ? $batchResult['step_errors']
            : [];

        for ($index = 0; $index <= $commitIndex; $index++) {
            $error = $stepErrors[$index] ?? null;
            if (!is_array($error)) {
                continue;
            }

            $label = match ($index) {
                0 => 'BEGIN',
                $commitIndex => 'COMMIT',
                default => 'statement ' . $index,
            };
            $message = "Remote libSQL atomic batch failed at {$label}: "
                . $this->errorMessage($error);

            if ($index > 0) {
                $rollbackError = $stepErrors[$rollbackIndex] ?? null;
                if (is_array($rollbackError)) {
                    $message .= '; ROLLBACK failed: ' . $this->errorMessage($rollbackError);
                } elseif (!is_array($stepResults[$rollbackIndex] ?? null)) {
                    $message .= '; ROLLBACK was not confirmed';
                }
            }

            throw new RuntimeException($message);
        }

        if (!is_array($stepResults[$commitIndex] ?? null)) {
            throw new RuntimeException('Remote libSQL atomic batch did not commit.');
        }

        $results = [];
        foreach ($statements as $offset => $_statement) {
            $result = $stepResults[$offset + 1] ?? null;
            if (!is_array($result)) {
                throw new RuntimeException(
                    'Remote libSQL atomic batch omitted statement ' . ($offset + 1) . '.'
                );
            }
            $results[] = $this->decodeStatementResult($result);
        }

        return $results;
    }

    public function lastInsertId(): string
    {
        return $this->lastInsertId;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>
     */
    private function statement(string $sql, array $params): array
    {
        if (trim($sql) === '') {
            throw new RuntimeException('Remote libSQL cannot execute an empty statement.');
        }

        $statement = ['sql' => $sql];
        if ($params === []) {
            return $statement;
        }

        if (array_is_list($params)) {
            $statement['args'] = array_map(
                fn(mixed $value): array => $this->encodeValue($value),
                $params,
            );
            return $statement;
        }

        $statement['named_args'] = [];
        foreach ($params as $name => $value) {
            $statement['named_args'][] = [
                'name' => ltrim((string) $name, ':@$'),
                'value' => $this->encodeValue($value),
            ];
        }

        return $statement;
    }

    /**
     * @return array{type:string,conds:list<array{type:string,step:int}>}
     */
    private function allPreviousStepsSucceeded(int $stepIndex): array
    {
        $conditions = [];
        for ($index = 0; $index < $stepIndex; $index++) {
            $conditions[] = ['type' => 'ok', 'step' => $index];
        }

        return ['type' => 'and', 'conds' => $conditions];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function send(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Authorization: Bearer ' . $this->authToken,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: TypeDock-Hrana/1',
        ];

        $http = $this->sender !== null
            ? ($this->sender)($this->endpoint, $headers, $body)
            : $this->sendHttp($this->endpoint, $headers, $body);

        if ($http['status'] < 200 || $http['status'] >= 300) {
            $message = trim(substr($http['body'], 0, 500));
            throw new RuntimeException(
                "Remote libSQL HTTP {$http['status']}"
                . ($message !== '' ? ': ' . $message : '')
            );
        }

        $decoded = json_decode($http['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Remote libSQL returned an invalid JSON response.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string}
     */
    private function sendHttp(string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            if ($this->curlHandle === null) {
                $handle = curl_init();
                if ($handle === false) {
                    throw new RuntimeException('Failed to initialize the HTTP client.');
                }
                $this->curlHandle = $handle;
            }

            $handle = $this->curlHandle;
            // curl_reset clears per-transfer options but deliberately keeps
            // the handle's connection and DNS caches. Sequential SQL requests
            // can therefore reuse the same HTTPS connection for this PHP
            // request instead of repeating DNS, TCP, and TLS setup.
            curl_reset($handle);
            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_FRESH_CONNECT => false,
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT => $this->timeout,
            ]);

            $responseBody = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);

            if ($responseBody === false) {
                throw new RuntimeException('Remote libSQL request failed: ' . $error);
            }

            return ['status' => $status, 'body' => $responseBody];
        }

        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException(
                'Remote libSQL requires ext-curl or allow_url_fopen.'
            );
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $http_response_header = [];
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header;
        $status = 0;
        if (isset($responseHeaders[0])
            && preg_match('/\s(\d{3})(?:\s|$)/', $responseHeaders[0], $matches)
        ) {
            $status = (int) $matches[1];
        }
        if ($responseBody === false) {
            throw new RuntimeException('Remote libSQL request failed.');
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function firstResponse(array $response, string $expectedType): array
    {
        $results = $response['results'] ?? null;
        if (!is_array($results) || !isset($results[0]) || !is_array($results[0])) {
            throw new RuntimeException('Remote libSQL response did not contain a result.');
        }

        $entry = $results[0];
        if (($entry['type'] ?? null) === 'error') {
            $error = is_array($entry['error'] ?? null) ? $entry['error'] : [];
            throw new RuntimeException($this->errorMessage($error));
        }

        $result = $entry['response'] ?? null;
        if (!is_array($result) || ($result['type'] ?? null) !== $expectedType) {
            throw new RuntimeException("Remote libSQL did not return a {$expectedType} response.");
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @return array{rows:list<array<string,mixed>>,affected:int,columns:int,last_insert_id:?string}
     */
    private function decodeStatementResult(array $result): array
    {
        $columns = [];
        foreach (($result['cols'] ?? []) as $column) {
            $columns[] = is_array($column) ? (string) ($column['name'] ?? '') : '';
        }

        $rows = [];
        foreach (($result['rows'] ?? []) as $values) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($columns as $index => $name) {
                $cell = $values[$index] ?? ['type' => 'null'];
                $row[$name] = is_array($cell) ? $this->decodeValue($cell) : null;
            }
            $rows[] = $row;
        }

        $lastInsertId = $result['last_insert_rowid'] ?? null;
        $lastInsertId = $lastInsertId === null ? null : (string) $lastInsertId;
        if ($lastInsertId !== null) {
            $this->lastInsertId = $lastInsertId;
        }

        return [
            'rows' => $rows,
            'affected' => (int) ($result['affected_row_count'] ?? 0),
            'columns' => count($columns),
            'last_insert_id' => $lastInsertId,
        ];
    }

    /**
     * @return array{type:string,value?:string,base64?:string}
     */
    private function encodeValue(mixed $value): array
    {
        if ($value === null) {
            return ['type' => 'null'];
        }
        if ($value instanceof HranaBlob) {
            return ['type' => 'blob', 'base64' => base64_encode($value->data)];
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            return [
                'type' => 'blob',
                'base64' => base64_encode($contents === false ? '' : $contents),
            ];
        }
        if (is_bool($value)) {
            return ['type' => 'integer', 'value' => $value ? '1' : '0'];
        }
        if (is_int($value)) {
            return ['type' => 'integer', 'value' => (string) $value];
        }
        if (is_float($value)) {
            return ['type' => 'float', 'value' => (string) $value];
        }
        if (is_string($value)) {
            return ['type' => 'text', 'value' => $value];
        }

        throw new RuntimeException('Unsupported Hrana parameter type: ' . get_debug_type($value));
    }

    /**
     * @param array<string,mixed> $value
     */
    private function decodeValue(array $value): mixed
    {
        return match ($value['type'] ?? null) {
            'null' => null,
            'integer' => (int) ($value['value'] ?? 0),
            'float' => (float) ($value['value'] ?? 0),
            'text' => (string) ($value['value'] ?? ''),
            'blob' => $this->decodeBlob((string) ($value['base64'] ?? '')),
            default => throw new RuntimeException('Remote libSQL returned an unknown value type.'),
        };
    }

    /**
     * @param array<string,mixed> $error
     */
    private function errorMessage(array $error): string
    {
        $message = (string) ($error['message'] ?? 'Remote libSQL statement failed.');
        $code = (string) ($error['code'] ?? '');
        return $code !== '' ? "{$code}: {$message}" : $message;
    }

    private function decodeBlob(string $value): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new RuntimeException('Remote libSQL returned an invalid BLOB value.');
        }
        return $decoded;
    }

    private function pipelineUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, 'libsql://')) {
            $url = 'https://' . substr($url, strlen('libsql://'));
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        ) {
            throw new RuntimeException(
                'Remote libSQL URL must use libsql://, https://, or loopback http://.'
            );
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Remote libSQL URL must not contain credentials or a fragment.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        if ($scheme === 'http' && !$this->allowInsecure && !$this->isLoopback($host)) {
            throw new RuntimeException('Remote libSQL requires HTTPS outside loopback development.');
        }

        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':' . (int) $parts['port'];
        }

        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if (!str_ends_with($path, '/v2/pipeline')) {
            $path .= '/v2/pipeline';
        }

        return "{$scheme}://{$authority}{$path}";
    }

    private function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
    }
}

<?php

namespace GlpiPlugin\Bridge\Connector\SolarWinds;

use GlpiPlugin\Bridge\Connection;
use GlpiPlugin\Bridge\Contract\ConnectorInterface;
use RuntimeException;

/**
 * HTTP client for the SolarWinds Service Desk (Samanage) REST API.
 *
 * Auth note: Samanage requires X-Samanage-Authorization: Bearer <token>.
 * The standard Authorization header returns 401.
 */
class SolarWindsClient implements ConnectorInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authType,
        private readonly string $secret,
        private readonly string $user = '',
        private readonly string $customHeaderName = ''
    ) {
    }

    public static function fromConnection(Connection $connection): static
    {
        if (($connection->fields['system_type'] ?? '') !== Connection::TYPE_SOLARWINDS) {
            throw new RuntimeException('Only SolarWinds connections are supported by this client.');
        }

        return new static(
            (string) $connection->fields['base_url'],
            (string) $connection->fields['auth_type'],
            $connection->getDecryptedSecret(),
            (string) ($connection->fields['auth_user'] ?? ''),
            (string) ($connection->fields['custom_header_name'] ?? '')
        );
    }

    // ------------------------------------------------------------------ //
    // ConnectorInterface
    // ------------------------------------------------------------------ //

    public function testConnection(): array
    {
        $start = hrtime(true);
        try {
            $response  = $this->request('/incidents.json', ['per_page' => 1]);
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
            return [
                'ok'         => true,
                'status'     => $response['status_code'],
                'latency_ms' => $latencyMs,
                'total'      => $response['total_count'],
                'message'    => 'OK',
            ];
        } catch (\Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);
            $status    = 0;
            if (preg_match('/HTTP (\d{3})/', $e->getMessage(), $m)) {
                $status = (int) $m[1];
            }
            return [
                'ok'         => false,
                'status'     => $status,
                'latency_ms' => $latencyMs,
                'total'      => 0,
                'message'    => $e->getMessage(),
            ];
        }
    }

    public function scanIncidents(int $limit = 10): array
    {
        $result = $this->listIncidents([], 1, max(1, min($limit, 100)));

        return [
            'endpoint'    => $result['endpoint'],
            'status_code' => $result['status_code'],
            'total'       => $result['total'],
            'count'       => $result['count'],
            'records'     => $result['records'],
        ];
    }

    public function listIncidents(array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $perPage  = max(1, min($perPage, 100));
        $query    = array_merge(['per_page' => $perPage, 'page' => $page], $filters);
        $response = $this->request('/incidents.json', $query);
        $records  = $this->extractRecords($response['json'], 'incidents');

        return [
            'endpoint'    => $response['url'],
            'status_code' => $response['status_code'],
            'total'       => $response['total_count'],
            'page'        => $page,
            'per_page'    => $perPage,
            'count'       => count($records),
            'records'     => $records,
        ];
    }

    public function getResourceTypes(): array
    {
        return [
            'incidents' => ['label' => 'Incidents',  'implemented' => true],
            'changes'   => ['label' => 'Changes',    'implemented' => false],
            'problems'  => ['label' => 'Problems',   'implemented' => false],
        ];
    }

    public function downloadAttachment(string $url): ?array
    {
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }

        // Samanage returns relative URLs like /attachments/{hash}/{file}
        if (str_starts_with($url, '/')) {
            $url = rtrim($this->baseUrl, '/') . $url;
        }

        $filename = basename((string) parse_url($url, PHP_URL_PATH)) ?: 'attachment';
        $curl     = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->buildAuthHeaders(),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $content  = curl_exec($curl);
        $status   = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $mime     = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($content === false || $content === '' || $status < 200 || $status >= 300) {
            return null;
        }

        return [
            'content'  => $content,
            'filename' => $filename,
            'mime'     => trim(explode(';', $mime)[0] ?? 'application/octet-stream'),
        ];
    }

    public function getIncidentComments(int $incidentId): array
    {
        $response = $this->request("/incidents/{$incidentId}/comments.json");
        $json     = $response['json'];
        return array_is_list($json) ? $json : [];
    }

    // ------------------------------------------------------------------ //
    // HTTP layer
    // ------------------------------------------------------------------ //

    private function request(string $path, array $query = []): array
    {
        if ($this->secret === '' && $this->authType !== Connection::AUTH_BASIC) {
            throw new RuntimeException('This connection has no authentication secret configured.');
        }

        $url     = $this->buildUrl($path, $query);
        $headers = array_merge([
            'Accept: application/vnd.samanage.v2.1+json',
            'Content-Type: application/json',
        ], $this->buildAuthHeaders());

        if (!function_exists('curl_init')) {
            return $this->requestWithStreams($url, $headers);
        }

        $responseHeaders = [];
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseHeaders): int {
                $pos = strpos($header, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($header, 0, $pos)))] = trim(substr($header, $pos + 1));
                }
                return strlen($header);
            },
        ]);

        $body   = curl_exec($curl);
        $error  = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('SolarWinds request failed: ' . $error);
        }

        return $this->decodeResponse(
            $url,
            $status,
            $body,
            (int) ($responseHeaders['x-total-count'] ?? 0)
        );
    }

    private function requestWithStreams(string $url, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => 45,
                'ignore_errors' => true,
            ],
        ]);

        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('SolarWinds request failed.');
        }

        $status     = 0;
        $totalCount = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                $status = (int) $m[1];
            }
            if (preg_match('/^X-Total-Count:\s*(\d+)/i', $header, $m)) {
                $totalCount = (int) $m[1];
            }
        }

        return $this->decodeResponse($url, $status, $body, $totalCount);
    }

    private function decodeResponse(string $url, int $status, string $body, int $totalCount = 0): array
    {
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($json) ? json_encode($json) : substr($body, 0, 500);
            throw new RuntimeException("SolarWinds returned HTTP $status: $message");
        }

        if (!is_array($json)) {
            throw new RuntimeException('SolarWinds returned a non-JSON response.');
        }

        return [
            'url'         => $url,
            'status_code' => $status,
            'total_count' => $totalCount,
            'json'        => $json,
        ];
    }

    private function buildUrl(string $path, array $query): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $url;
    }

    private function buildAuthHeaders(): array
    {
        if ($this->authType === Connection::AUTH_BASIC) {
            if ($this->user === '' || $this->secret === '') {
                throw new RuntimeException('Basic authentication requires user and password.');
            }
            return ['X-Samanage-Authorization: Basic ' . base64_encode($this->user . ':' . $this->secret)];
        }

        if ($this->authType === Connection::AUTH_CUSTOM_HEADER) {
            if ($this->customHeaderName === '') {
                throw new RuntimeException('Custom header authentication requires a header name.');
            }
            return [$this->customHeaderName . ': ' . $this->secret];
        }

        $token = $this->secret;
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = substr($token, 7);
        }
        return ['X-Samanage-Authorization: Bearer ' . $token];
    }

    private function extractRecords(array $json, string $collectionName): array
    {
        if (array_is_list($json)) {
            return $json;
        }
        if (isset($json[$collectionName]) && is_array($json[$collectionName])) {
            return $json[$collectionName];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }
        return [];
    }
}

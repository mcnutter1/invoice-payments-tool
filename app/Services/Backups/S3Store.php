<?php

namespace App\Services\Backups;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Minimal S3 client (PUT/GET/DELETE) signed with AWS Signature V4; works with S3-compatible endpoints. */
class S3Store implements BackupStore
{
    /** Response headers of the last request (used by diagnostics to confirm encryption and storage class). */
    public array $lastHeaders = [];

    /** @param array{bucket:string,region:string,key:string,secret:string,endpoint:?string,path_style:bool} $cfg */
    public function __construct(private readonly array $cfg) {}

    public function put(string $key, string $bytes, ?string $storageClass = null): void
    {
        $headers = ['content-type' => 'application/octet-stream', 'x-amz-server-side-encryption' => 'AES256'];
        if ($storageClass && $storageClass !== 'STANDARD') $headers['x-amz-storage-class'] = $storageClass;
        $this->check($this->send('PUT', $key, $bytes, $headers));
    }

    public function get(string $key): string
    {
        return $this->check($this->send('GET', $key))->body();
    }

    public function delete(string $key): void
    {
        $res = $this->send('DELETE', $key);
        if ($res->status() !== 404) $this->check($res);
    }

    private function check(Response $res): Response
    {
        $this->lastHeaders = array_change_key_case(array_map(fn ($v) => is_array($v) ? ($v[0] ?? '') : $v, $res->headers()));
        if (! $res->successful()) throw S3Exception::fromResponse($res->status(), $res->body(), $res->header('x-amz-bucket-region') ?: null);

        return $res;
    }

    private function send(string $method, string $key, string $body = '', array $headers = []): Response
    {
        [$url, $host, $path] = $this->target($key);
        $now = time();
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $date = gmdate('Ymd', $now);
        $payloadHash = hash('sha256', $body);
        $headers = array_change_key_case($headers) + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        ksort($headers);
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) $canonicalHeaders .= $k.':'.trim((string) $v)."\n";
        $signed = implode(';', array_keys($headers));
        $canonical = implode("\n", [$method, $path, '', $canonicalHeaders, $signed, $payloadHash]);
        $scope = $date.'/'.$this->cfg['region'].'/s3/aws4_request';
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', $date, 'AWS4'.$this->cfg['secret'], true);
        foreach ([$this->cfg['region'], 's3', 'aws4_request'] as $part) $k = hash_hmac('sha256', $part, $k, true);
        $headers['authorization'] = 'AWS4-HMAC-SHA256 Credential='.$this->cfg['key'].'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256', $toSign, $k);
        unset($headers['host']);
        $type = $headers['content-type'] ?? 'application/octet-stream';
        unset($headers['content-type']);

        $req = Http::timeout(60)->connectTimeout(10)->withHeaders($headers);
        if ($body !== '') $req = $req->withBody($body, $type);

        return $req->send($method, $url);
    }

    /** @return array{0:string,1:string,2:string} [url, host, canonical path] */
    private function target(string $key): array
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
        $bucket = $this->cfg['bucket'];
        if (! empty($this->cfg['endpoint'])) {
            $base = rtrim($this->cfg['endpoint'], '/');
            $host = (string) parse_url($base, PHP_URL_HOST).(parse_url($base, PHP_URL_PORT) ? ':'.parse_url($base, PHP_URL_PORT) : '');
            if ($this->cfg['path_style']) return [$base.'/'.$bucket.'/'.$encoded, $host, '/'.$bucket.'/'.$encoded];
            $scheme = parse_url($base, PHP_URL_SCHEME);
            $host = $bucket.'.'.$host;

            return [$scheme.'://'.$host.'/'.$encoded, $host, '/'.$encoded];
        }
        $host = $bucket.'.s3.'.$this->cfg['region'].'.amazonaws.com';

        return ['https://'.$host.'/'.$encoded, $host, '/'.$encoded];
    }
}

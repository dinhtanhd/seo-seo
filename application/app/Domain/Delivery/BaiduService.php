<?php

namespace App\Domain\Delivery;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class BaiduService
{
    public function __construct(private readonly BaiduAdapter $adapter = new BaiduAdapter) {}

    public function configure(int $siteId, ?string $token, string $endpoint = BaiduAdapter::ENDPOINT, string $status = 'not_configured', bool $schemaVerified = false): void
    {
        if ($endpoint !== BaiduAdapter::ENDPOINT) {
            throw new \InvalidArgumentException('Chỉ cho phép endpoint Baidu đã khai báo.');
        }
        if (! in_array($status, ['active', 'paused', 'not_configured'], true)) {
            throw new \InvalidArgumentException('Trạng thái cấu hình Baidu không hợp lệ.');
        }
        if ($status === 'active' && (! $token || ! $schemaVerified)) {
            throw new \InvalidArgumentException('Cần token và xác nhận schema tài khoản trước khi bật gửi thật.');
        }
        DB::table('baidu_configs')->updateOrInsert(['site_id' => $siteId], ['endpoint' => $endpoint,
            'encrypted_token' => $token ? Crypt::encryptString($token) : null, 'status' => $status, 'schema_verified' => $schemaVerified, 'updated_at' => now()]);
    }

    public function enqueue(int $siteId, array $publicationIds): array
    {
        $ids = [];
        foreach (array_unique($publicationIds) as $publicationId) {
            $publication = DB::table('page_publications as pub')->join('pages as p', 'p.id', '=', 'pub.page_id')
                ->where('pub.id', $publicationId)->where('pub.site_id', $siteId)->whereColumn('p.current_publication_id', 'pub.id')
                ->where('p.status', 'live')->select('pub.*', 'p.canonical_path')->first();
            if (! $publication) {
                throw new \InvalidArgumentException('Publication không phải bản live thuộc site này.');
            }
            $domain = DB::table('site_domains')->where('site_id', $siteId)->where('is_primary', true)->where('status', 'active')->first();
            if (! $domain) {
                throw new \InvalidArgumentException('Site chưa có domain canonical active.');
            }
            $snapshot = json_decode($publication->snapshot_json, true) ?? [];
            if (($snapshot['page']['noindex'] ?? false) === true) {
                continue;
            }
            $url = $snapshot['canonical_url'] ?? null;
            $urlParts = $url ? parse_url($url) : null;
            if (! $urlParts || strtolower($urlParts['host'] ?? '') !== $domain->host_ascii || ! in_array($urlParts['scheme'] ?? '', ['http', 'https'], true)
                || isset($urlParts['user']) || isset($urlParts['pass']) || preg_match('/[\r\n\x00]/', $url)) {
                throw new \InvalidArgumentException('Canonical snapshot không khớp domain site.');
            }
            $config = DB::table('baidu_configs')->where('site_id', $siteId)->first();
            $status = $config && $config->status === 'active' && $config->schema_verified && $config->encrypted_token ? 'queued' : 'not_configured';
            $key = 'baidu:'.$siteId.':'.$publicationId;
            DB::table('baidu_submissions')->insertOrIgnore(['site_id' => $siteId, 'publication_id' => $publicationId, 'url' => $url,
                'idempotency_key' => $key, 'status' => $status, 'attempts' => 0, 'created_at' => now()]);
            if ($status === 'queued') {
                DB::table('baidu_submissions')->where('idempotency_key', $key)->where('status', 'not_configured')->update(['status' => 'queued']);
            }
            $ids[] = DB::table('baidu_submissions')->where('idempotency_key', $key)->value('id');
        }

        return $ids;
    }

    public function submit(int $submissionId): array
    {
        [$submission, $config] = DB::transaction(function () use ($submissionId) {
            $item = DB::table('baidu_submissions')->where('id', $submissionId)->lockForUpdate()->first();
            if (! $item) {
                throw new \InvalidArgumentException('Không tìm thấy Baidu submission.');
            }
            if (in_array($item->status, ['submitted', 'partial', 'unknown', 'rejected', 'submitting'], true)) {
                return [$item, null];
            }
            $receipt = json_decode($item->receipt_json ?? '{}', true) ?? [];
            if ($item->status === 'retry_wait' && ($receipt['_transport_retry_after_at'] ?? 0) > time()) {
                return [$item, null];
            }
            $config = DB::table('baidu_configs')->where('site_id', $item->site_id)->first();
            if (! $config || ! $config->encrypted_token || ! $config->schema_verified || $config->status !== 'active') {
                DB::table('baidu_submissions')->where('id', $item->id)->update(['status' => 'not_configured']);
                $item->status = 'not_configured';

                return [$item, null];
            }
            $domain = DB::table('site_domains')->where('site_id', $item->site_id)->where('is_primary', true)->where('status', 'active')->whereNotNull('verified_at')->first();
            if (! $domain || parse_url($item->url, PHP_URL_HOST) !== $domain->host_ascii) {
                throw new \RuntimeException('Domain Baidu chưa được xác minh hoặc không khớp URL.');
            }
            if ($item->attempts >= 4) {
                DB::table('baidu_submissions')->where('id', $item->id)->update(['status' => 'failed', 'error' => 'Đã hết số lần thử cho phép.']);
                $item->status = 'failed';

                return [$item, null];
            }
            $live = DB::table('pages')->where('site_id', $item->site_id)->where('current_publication_id', $item->publication_id)->where('status', 'live')->exists();
            if (! $live) {
                throw new \RuntimeException('Publication đã thay đổi hoặc không còn live; không gửi URL cũ.');
            }
            DB::table('baidu_submissions')->where('id', $item->id)->update(['status' => 'submitting', 'attempts' => $item->attempts + 1, 'started_at' => now()]);

            return [$item, $config];
        }, 3);
        if (! $config) {
            $receipt = json_decode($submission->receipt_json ?? '{}', true) ?? [];

            return ['status' => $submission->status, 'indexed' => 'unknown', 'remaining' => null,
                'retry_after' => max(1, ($receipt['_transport_retry_after_at'] ?? time() + 60) - time())];
        }
        $result = $this->adapter->submit($config->endpoint, parse_url($submission->url, PHP_URL_HOST), Crypt::decryptString($config->encrypted_token), [$submission->url]);
        // Provider data cannot accidentally leak the request token through a echoed error.
        $token = Crypt::decryptString($config->encrypted_token);
        $receiptData = $result['status'] === 'retry_wait' ? ['_transport_retry_after_at' => time() + ($result['retry_after'] ?? 60)] : $result['receipt'];
        $receipt = $receiptData === null ? null : str_replace($token, '[redacted]', json_encode($receiptData, JSON_UNESCAPED_UNICODE));
        DB::table('baidu_submissions')->where('id', $submissionId)->where('status', 'submitting')->update(['status' => $result['status'],
            'receipt_json' => $receipt, 'error' => isset($result['error']) ? str_replace($token, '[redacted]', $result['error']) : null,
            'submitted_at' => in_array($result['status'], ['submitted', 'partial'], true) ? now() : null]);

        return $result;
    }

    public function recoverInterrupted(): int
    {
        // A dead worker could have transmitted already; recovery records uncertainty, never fictitious success.
        return DB::table('baidu_submissions')->where('status', 'submitting')->where('started_at', '<', now()->subMinutes(10))
            ->update(['status' => 'unknown', 'error' => 'Worker bị gián đoạn khi gửi; cần đối soát kết quả.']);
    }
}

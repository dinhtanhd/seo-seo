<?php

namespace App\Domain\Ingestion;

final class ContentFileParser
{
    public function parse(string $content, string $format, array $mapping = []): array
    {
        if (strlen($content) > 10 * 1024 * 1024 || ! mb_check_encoding($content, 'UTF-8')) {
            throw new \InvalidArgumentException('File phải là UTF-8 và không quá 10 MB.');
        }
        $content = preg_replace('/^\x{FEFF}/u', '', $content);
        if ($format === 'json') {
            $data = json_decode($content, true, 24, JSON_THROW_ON_ERROR);
            $records = isset($data['records']) ? $data['records'] : $data;
            if (! is_array($records) || ! array_is_list($records)) {
                throw new \InvalidArgumentException('JSON phải là danh sách records.');
            }
        } elseif ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, $content);
            rewind($stream);
            $header = fgetcsv($stream, null, ',', '"', '');
            if (! $header || count($header) !== count(array_unique($header))) {
                throw new \InvalidArgumentException('CSV thiếu header hoặc có cột trùng.');
            }
            $records = [];
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== count($header)) {
                    $records[] = ['_parse_error' => 'Số cột CSV không khớp header.'];
                } else {
                    $records[] = array_combine($header, $row);
                }
            }
            fclose($stream);
        } else {
            throw new \InvalidArgumentException('Chỉ hỗ trợ CSV/JSON nội dung.');
        }
        if (count($records) > 10000) {
            throw new \InvalidArgumentException('Mỗi import tối đa 10.000 records.');
        }
        foreach ($records as &$record) {
            if (! is_array($record)) {
                $record = ['_parse_error' => 'Record phải là object.'];

                continue;
            }
            foreach ($mapping as $canonical => $source) {
                if (array_key_exists($source, $record)) {
                    $record[$canonical] = $record[$source];
                }
            }
        }

        return $records;
    }
}

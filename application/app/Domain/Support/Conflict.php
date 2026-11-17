<?php

namespace App\Domain\Support;

class Conflict extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($reason.($detail ? ': '.$detail : ''));
    }
}

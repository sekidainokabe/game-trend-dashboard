<?php

namespace App\Services\Collector;

/**
 * 取得処理1回分の結果。一部のゲームだけ失敗しても処理全体は止めず、件数として残す
 */
final readonly class JobResult
{
    public function __construct(
        public int $total,
        public int $succeeded,
        public int $failed = 0,
        public ?string $message = null,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->failed === 0 => 'success',
            $this->succeeded > 0 => 'partial',
            default => 'failed',
        };
    }
}

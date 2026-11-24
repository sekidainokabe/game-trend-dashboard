<?php

namespace App\Services\Collector;

interface CollectorJob
{
    /** fetch_runs.job に残す名前 */
    public function name(): string;

    /**
     * 前回の成功から何時間たったら実行するか。0 なら毎回（毎時）実行する。
     * 「毎日0時」のように時刻で決めないのは、GitHub Actions の定期実行が遅れたり抜けたりしても、次の回で取り戻せるようにするため
     */
    public function intervalHours(): int;

    public function run(): JobResult;
}

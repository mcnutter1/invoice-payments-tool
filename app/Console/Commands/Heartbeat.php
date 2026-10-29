<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;

trait Heartbeat
{
    protected function beat(string $name, callable $work): int
    {
        DB::table('job_heartbeats')->updateOrInsert(['name' => $name], ['last_started_at' => now()]);
        DB::table('job_heartbeats')->updateOrInsert(['name' => 'scheduler'], ['last_started_at' => now(), 'last_success_at' => now()]);
        try {
            $result = $work();
            DB::table('job_heartbeats')->where('name', $name)->update(['last_success_at' => now(), 'last_error' => null]);
            if ($result) $this->line(json_encode($result));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::table('job_heartbeats')->where('name', $name)->update(['last_failure_at' => now(), 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)]);
            report($e);

            return self::FAILURE;
        }
    }
}

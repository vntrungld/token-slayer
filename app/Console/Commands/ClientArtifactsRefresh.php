<?php

namespace App\Console\Commands;

use App\Services\Client\ReleaseArtifacts;
use Illuminate\Console\Command;

class ClientArtifactsRefresh extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'client-artifacts:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-render the install scripts and re-hash the wheel, so ingest never pays a GitHub round-trip';

    /**
     * Warm the artifact cache out of band. This exists so `POST /api/events` —
     * the single append-only write path, fired several times per session per
     * developer — never resolves a release inline: `github.timeout` is 8 s
     * while the client's own curl gives up after 3 s, so a cache miss on the
     * hot path would abort the client's request before the body arrives AND
     * hold a PHP worker for the full timeout.
     *
     * @param  ReleaseArtifacts  $artifacts
     * @return int
     */
    public function handle(ReleaseArtifacts $artifacts): int
    {
        $artifacts->warm();
        $this->info('Client artifacts refreshed.');

        return self::SUCCESS;
    }
}

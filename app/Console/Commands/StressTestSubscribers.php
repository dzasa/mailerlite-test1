<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use CurlHandle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

class StressTestSubscribers extends Command
{
    /**
     * @var string
     */
    protected $signature = 'subscribers:stress
        {--requests=500 : Total requests to send}
        {--concurrency=25 : Requests kept in flight at once}
        {--duplicates=0 : Percentage of requests that reuse an earlier email}
        {--url= : Endpoint to hit (defaults to the container-local API)}
        {--watch : After sending, watch the queue drain into the database}';

    /**
     * @var string
     */
    protected $description = 'Fire concurrent subscriber creations at the API and report throughput, latency and errors';

    /**
     * Latency of every completed request, in milliseconds.
     *
     * @var list<float>
     */
    private array $latencies = [];

    /**
     * Response counts keyed by HTTP status, plus 'transport' for failures that
     * never produced a response.
     *
     * @var array<int|string, int>
     */
    private array $outcomes = [];

    public function handle(): int
    {
        $total = max(1, (int) $this->option('requests'));
        $concurrency = max(1, (int) $this->option('concurrency'));
        $duplicatePercent = min(100, max(0, (int) $this->option('duplicates')));
        $url = $this->option('url') ?: 'http://localhost/api/v1/subscribers';

        $this->components->info(sprintf(
            'Sending %s requests at concurrency %d to %s',
            number_format($total),
            $concurrency,
            $url,
        ));

        $subscribersBefore = Subscriber::query()->count();
        $token = bin2hex(random_bytes(4));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%%  %message%');
        $bar->setMessage('starting');
        $bar->start();

        $startedAt = microtime(true);
        $this->dispatchRequests($url, $token, $total, $concurrency, $duplicatePercent, $bar);
        $elapsed = microtime(true) - $startedAt;

        $bar->finish();
        $this->newLine(2);

        $this->reportThroughput($total, $elapsed);
        $this->reportOutcomes();
        $this->reportLatency();
        $this->reportPipeline($subscribersBefore, $total);
        $this->reportWarnings();

        if ($this->option('watch')) {
            $this->newLine();
            $this->call('subscribers:monitor');
        }

        return self::SUCCESS;
    }

    /**
     * Run the requests through a rolling curl_multi window so exactly
     * `concurrency` requests are in flight until the work runs out.
     */
    private function dispatchRequests(
        string $url,
        string $token,
        int $total,
        int $concurrency,
        int $duplicatePercent,
        ProgressBar $bar,
    ): void {
        $multi = curl_multi_init();
        $handles = [];
        $next = 0;

        while ($next < $total && count($handles) < $concurrency) {
            $handle = $this->createHandle($url, $token, $next, $duplicatePercent);
            curl_multi_add_handle($multi, $handle);
            $handles[(int) $handle] = $handle;
            $next++;
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);

            while (($completed = curl_multi_info_read($multi)) !== false) {
                /** @var CurlHandle $handle */
                $handle = $completed['handle'];

                $this->recordOutcome($handle, $completed['result']);
                $bar->setMessage($this->progressMessage());
                $bar->advance();

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($handles[(int) $handle]);

                if ($next < $total) {
                    $replacement = $this->createHandle($url, $token, $next, $duplicatePercent);
                    curl_multi_add_handle($multi, $replacement);
                    $handles[(int) $replacement] = $replacement;
                    $next++;
                }
            }
        } while ($running > 0 || $handles !== []);

        curl_multi_close($multi);
    }

    private function createHandle(string $url, string $token, int $index, int $duplicatePercent): CurlHandle
    {
        // Reuse an earlier address for the requested share of traffic so the
        // duplicate-rejection path is exercised alongside fresh creations.
        $isDuplicate = $duplicatePercent > 0 && $index > 0 && ($index % 100) < $duplicatePercent;
        $email = $isDuplicate
            ? sprintf('stress-%s-0@example.com', $token)
            : sprintf('stress-%s-%d@example.com', $token, $index);

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'status' => 'subscribed'], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        return $handle;
    }

    private function recordOutcome(CurlHandle $handle, int $curlResult): void
    {
        if ($curlResult !== CURLE_OK) {
            $this->outcomes['transport'] = ($this->outcomes['transport'] ?? 0) + 1;

            return;
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $this->outcomes[$status] = ($this->outcomes[$status] ?? 0) + 1;
        $this->latencies[] = curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
    }

    private function progressMessage(): string
    {
        $accepted = $this->outcomes[202] ?? 0;
        $rejected = array_sum($this->outcomes) - $accepted;

        return sprintf('%d accepted, %d other', $accepted, $rejected);
    }

    private function reportThroughput(int $total, float $elapsed): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Throughput</>', '');
        $this->components->twoColumnDetail('  requests sent', number_format($total));
        $this->components->twoColumnDetail('  wall clock', sprintf('%.2f s', $elapsed));
        $this->components->twoColumnDetail(
            '  requests/second',
            $elapsed > 0 ? sprintf('<fg=green;options=bold>%.1f</>', $total / $elapsed) : 'n/a',
        );
        $this->components->twoColumnDetail(
            '  accepted/second',
            $elapsed > 0 ? sprintf('%.1f', ($this->outcomes[202] ?? 0) / $elapsed) : 'n/a',
        );
    }

    private function reportOutcomes(): void
    {
        $labels = [
            202 => 'accepted (queued)',
            422 => 'rejected (duplicate or invalid)',
            429 => 'throttled by rate limiter',
            503 => 'claim store unavailable',
            500 => 'server error',
            'transport' => 'connection failed',
        ];

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Responses</>', '');

        ksort($this->outcomes);

        foreach ($this->outcomes as $status => $count) {
            $label = $labels[$status] ?? 'other';
            $colour = match (true) {
                $status === 202 => 'green',
                in_array($status, [422, 429], true) => 'yellow',
                default => 'red',
            };

            $this->components->twoColumnDetail(
                sprintf('  %s  %s', is_int($status) ? $status : '---', $label),
                sprintf('<fg=%s>%s</>', $colour, number_format($count)),
            );
        }
    }

    private function reportLatency(): void
    {
        if ($this->latencies === []) {
            return;
        }

        sort($this->latencies);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Latency (ms)</>', '');
        $this->components->twoColumnDetail('  min', sprintf('%.1f', $this->latencies[0]));
        $this->components->twoColumnDetail('  p50', sprintf('%.1f', $this->percentile(50)));
        $this->components->twoColumnDetail('  p95', sprintf('%.1f', $this->percentile(95)));
        $this->components->twoColumnDetail('  p99', sprintf('%.1f', $this->percentile(99)));
        $this->components->twoColumnDetail('  max', sprintf('%.1f', $this->latencies[count($this->latencies) - 1]));
    }

    private function percentile(int $percentile): float
    {
        $index = (int) ceil($percentile / 100 * count($this->latencies)) - 1;

        return $this->latencies[max(0, $index)];
    }

    /**
     * Show how much of the accepted work has actually reached MySQL. The gap
     * between accepted and persisted is the queue backlog, which is the whole
     * point of the design: the endpoint is not waiting for the database.
     */
    private function reportPipeline(int $subscribersBefore, int $total): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Pipeline</>', '');

        $accepted = $this->outcomes[202] ?? 0;
        $persisted = Subscriber::query()->count() - $subscribersBefore;

        $this->components->twoColumnDetail('  accepted by the API', number_format($accepted));
        $this->components->twoColumnDetail('  rows in MySQL so far', number_format($persisted));

        try {
            $this->components->twoColumnDetail('  jobs still queued', number_format(Queue::size()));
        } catch (Throwable) {
            $this->components->twoColumnDetail('  jobs still queued', 'unavailable');
        }

        if ($persisted < $accepted) {
            $this->components->twoColumnDetail(
                '  <fg=gray>note</>',
                '<fg=gray>worker is still draining; re-run to see the rest land</>',
            );
        }
    }

    private function reportWarnings(): void
    {
        $throttled = $this->outcomes[429] ?? 0;
        $unavailable = $this->outcomes[503] ?? 0;
        $failed = ($this->outcomes['transport'] ?? 0) + ($this->outcomes[500] ?? 0);

        $this->newLine();

        if ($throttled > 0) {
            $this->components->warn(sprintf(
                '%s requests were throttled. The limiter caps a single IP; raise SUBSCRIBERS_RATE_LIMIT_PER_MINUTE (0 disables it) to measure the endpoint instead of the limiter.',
                number_format($throttled),
            ));
        }

        if ($unavailable > 0) {
            $this->components->warn(sprintf('%s requests got 503 — Redis was unreachable or saturated.', number_format($unavailable)));
        }

        if ($failed > 0) {
            $this->components->warn(sprintf('%s requests failed outright. The web server likely ran out of workers (PHP_CLI_SERVER_WORKERS).', number_format($failed)));
        }

        if ($throttled === 0 && $unavailable === 0 && $failed === 0) {
            $this->components->info('Every request got a valid response.');
        }

        $this->line('  <fg=gray>Note: `artisan serve` is a development server. Treat these numbers as a relative measure of the request path, not production capacity.</>');
    }
}

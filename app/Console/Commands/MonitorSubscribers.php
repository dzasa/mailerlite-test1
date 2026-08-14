<?php

namespace App\Console\Commands;

use App\Models\Subscriber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Throwable;

class MonitorSubscribers extends Command
{
    /**
     * @var string
     */
    protected $signature = 'subscribers:monitor
        {--interval=1 : Seconds between samples}
        {--duration=0 : Stop after this many seconds (0 = run until the queue drains)}
        {--quiet-ticks=3 : Consecutive empty-queue samples before stopping}';

    /**
     * @var string
     */
    protected $description = 'Watch how fast queued subscribers are being written to the database';

    /**
     * Insert rates observed per sample, used for the closing summary.
     *
     * @var list<float>
     */
    private array $rates = [];

    public function handle(): int
    {
        $interval = max(0.1, (float) $this->option('interval'));
        $duration = max(0.0, (float) $this->option('duration'));
        $quietLimit = max(1, (int) $this->option('quiet-ticks'));

        $this->components->info('Watching subscriber inserts. Press Ctrl+C to stop.');
        $this->renderHeader();

        $startedAt = microtime(true);
        $startingRows = $this->rowCount();
        $previousRows = $startingRows;
        $previousAt = $startedAt;
        $quietTicks = 0;

        while (true) {
            usleep((int) ($interval * 1_000_000));

            $now = microtime(true);
            $rows = $this->rowCount();
            $queued = $this->queueDepth();

            $elapsedSinceSample = $now - $previousAt;
            $inserted = $rows - $previousRows;
            $rate = $elapsedSinceSample > 0 ? $inserted / $elapsedSinceSample : 0.0;
            $this->rates[] = $rate;

            $this->renderSample(
                elapsed: $now - $startedAt,
                totalRows: $rows,
                inserted: $inserted,
                rate: $rate,
                queued: $queued,
            );

            $previousRows = $rows;
            $previousAt = $now;

            // Stop once the queue has been empty and nothing is landing, so the
            // command is usable in scripts without a timeout.
            $quietTicks = ($queued === 0 && $inserted === 0) ? $quietTicks + 1 : 0;

            if ($duration > 0.0 && ($now - $startedAt) >= $duration) {
                break;
            }

            if ($duration <= 0.0 && $quietTicks >= $quietLimit) {
                break;
            }
        }

        $this->renderSummary($startingRows, microtime(true) - $startedAt);

        return self::SUCCESS;
    }

    private function renderHeader(): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  <fg=gray>%-9s %10s %9s %11s %9s %8s</>',
            'elapsed', 'rows', 'added', 'rows/sec', 'queued', 'eta',
        ));
        $this->line('  <fg=gray>'.str_repeat('─', 60).'</>');
    }

    private function renderSample(
        float $elapsed,
        int $totalRows,
        int $inserted,
        float $rate,
        ?int $queued,
    ): void {
        $eta = match (true) {
            $queued === null => '?',
            $queued === 0 => '—',
            $rate <= 0.0 => 'stalled',
            default => sprintf('%.0fs', $queued / $rate),
        };

        $rateColour = match (true) {
            $rate > 0 => 'green',
            $queued > 0 => 'red',
            default => 'gray',
        };

        $this->line(sprintf(
            '  %-9s %10s %9s <fg=%s>%11s</> %9s %8s',
            sprintf('%.1fs', $elapsed),
            number_format($totalRows),
            $inserted > 0 ? '+'.number_format($inserted) : '0',
            $rateColour,
            sprintf('%.1f', $rate),
            $queued === null ? '?' : number_format($queued),
            $eta,
        ));
    }

    private function renderSummary(int $startingRows, float $elapsed): void
    {
        $inserted = $this->rowCount() - $startingRows;

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Drain summary</>', '');
        $this->components->twoColumnDetail('  rows written', number_format($inserted));
        $this->components->twoColumnDetail('  time watched', sprintf('%.1f s', $elapsed));
        $this->components->twoColumnDetail(
            '  average rate',
            $elapsed > 0 ? sprintf('<fg=green;options=bold>%.1f rows/sec</>', $inserted / $elapsed) : 'n/a',
        );

        if ($this->rates !== []) {
            $this->components->twoColumnDetail('  peak rate', sprintf('%.1f rows/sec', max($this->rates)));
        }

        $remaining = $this->queueDepth();

        if ($remaining !== null && $remaining > 0) {
            $this->components->warn(sprintf(
                '%s jobs are still queued. Is the worker running? Check: docker compose logs queue',
                number_format($remaining),
            ));
        }
    }

    /**
     * Count persisted subscribers.
     *
     * COUNT(*) is deliberate: MAX(id) would be cheaper but insertOrIgnore can
     * consume auto-increment values on a duplicate, so it would overstate the
     * number of rows actually written.
     */
    private function rowCount(): int
    {
        return Subscriber::query()->count();
    }

    private function queueDepth(): ?int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            return null;
        }
    }
}

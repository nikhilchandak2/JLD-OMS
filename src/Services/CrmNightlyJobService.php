<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\CrmJobRunRepository;

/**
 * Idempotent nightly job: rebuild dormancy signals, then raise/close escalations.
 * Overlap is blocked by GET_LOCK and by crm_job_locks. A second run the same
 * day replaces today's signals and will not duplicate an existing episode.
 */
class CrmNightlyJobService
{
    private Database $database;
    private CrmJobRunRepository $jobs;
    private DormancyService $dormancy;
    private EscalationService $escalations;
    private ForecastService $forecasts;
    private PipelineDashboardService $pipeline;
    private array $config;

    public function __construct()
    {
        $this->database = new Database();
        $this->jobs = new CrmJobRunRepository();
        $this->dormancy = new DormancyService();
        $this->escalations = new EscalationService();
        $this->forecasts = new ForecastService();
        $this->pipeline = new PipelineDashboardService();
        $this->config = require dirname(__DIR__, 2) . '/config/dormancy.php';
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?string $asOf = null): array
    {
        $tz = new \DateTimeZone($this->config['timezone']);
        $asOf = $asOf ?: (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $jobName = (string)$this->config['job_name'];
        $lockName = 'jld_' . $jobName;
        $got = $this->database->fetch('SELECT GET_LOCK(?, 0) AS acquired', [$lockName]);
        if ((int)($got['acquired'] ?? 0) !== 1) {
            $runId = $this->jobs->start($jobName);
            $this->jobs->finish($runId, 'skipped', ['reason' => 'overlap', 'as_of' => $asOf], 'Another run holds the lock.');

            return ['status' => 'skipped', 'reason' => 'overlap', 'as_of' => $asOf, 'run_id' => $runId];
        }

        $lockedBy = gethostname() . ':' . getmypid();
        $tableLock = $this->jobs->tryLock($jobName, $lockedBy, (int)$this->config['stale_lock_minutes']);
        if (!$tableLock) {
            $this->database->fetch('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            $runId = $this->jobs->start($jobName);
            $this->jobs->finish($runId, 'skipped', ['reason' => 'lock_table', 'as_of' => $asOf], 'crm_job_locks row present.');

            return ['status' => 'skipped', 'reason' => 'lock_table', 'as_of' => $asOf, 'run_id' => $runId];
        }

        $runId = $this->jobs->start($jobName);
        try {
            $actuals = $this->forecasts->rebuildActuals($asOf);
            $pipeline = $this->pipeline->rebuild($asOf);
            $dormancy = $this->dormancy->rebuild($asOf);
            $escalation = $this->escalations->applyNightly($asOf, $dormancy['rows']);
            $summary = [
                'as_of' => $asOf,
                'signals' => $dormancy['signals'],
                'urgent' => $dormancy['urgent'],
                'watch' => $dormancy['watch'],
                'escalations_raised' => $escalation['raised'],
                'escalations_closed' => $escalation['closed'],
                'forecast_actual_rows' => $actuals['rows'],
                'pipeline_deals' => $pipeline['deals'],
            ];
            $this->jobs->finish($runId, 'ok', $summary);

            return array_merge(['status' => 'ok', 'run_id' => $runId], $summary);
        } catch (\Throwable $e) {
            $this->jobs->finish($runId, 'failed', ['as_of' => $asOf], $e->getMessage());
            throw $e;
        } finally {
            $this->jobs->unlock($jobName);
            $this->database->fetch('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }
    }

    public function latestRun(): ?array
    {
        return $this->jobs->latest((string)$this->config['job_name']);
    }
}

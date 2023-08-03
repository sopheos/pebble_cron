<?php

namespace Pebble\Cron;

use DateTimeImmutable;

class Cron
{
    /**
     * @var string
     */
    protected $script;

    /**
     * @var Job[]
     */
    protected $jobs = [];

    /**
     * @var array
     */
    protected $config = [
        'app' => null,
        'max_runtime' => 300,
        'stdout' => '/dev/null',
        'stderr' => '/dev/null',
        'tmpdir' => null,
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
        $this->script = __DIR__ . '/run-job.php';

        // TMPDIR
        if (!$this->config['tmpdir'] || !is_dir($this->config['tmpdir'])) {
            $this->config['tmpdir'] = Helper::getTempDir();
        }
        $this->config['tmpdir'] = rtrim($this->config['tmpdir'], '/') . '/';
    }

    /**
     * Add a job.
     *
     * @param string $command
     * @param string  $schedule
     * @return Job
     *
     * @throws Exception
     */
    public function add(string $name): Job
    {
        if (($this->config['app'] ?? null)) {
            $name = $this->config['app'] . '_' . $name;
        }

        $job = new Job($name);
        $job->maxRuntime($this->config['max_runtime']);
        $job->stdout($this->config['stdout']);
        $job->stderr($this->config['stdout']);

        $this->jobs[] = $job;

        return $job;
    }

    /**
     * Run all jobs.
     */
    public function run()
    {
        if (!extension_loaded('posix')) {
            throw new Exception('posix extension is required');
        }

        // Mise à jour de la liste des crons
        $filename = $this->config['tmpdir'] . 'cron.json';
        $content = json_encode($this->jobs, JSON_PRETTY_PRINT) . "
";
        file_put_contents($filename, $content);

        $binary = Helper::getPhpBinary();
        $scheduleChecker = new ScheduleChecker(new DateTimeImmutable("now"));
        foreach ($this->jobs as $job) {
            if (!$scheduleChecker->isDue($job->getSchedule())) {
                continue;
            }

            $command = $this->getExecutableCommand($job);
            exec("$binary $command 1> /dev/null 2>&1 &");
        }
    }

    /**
     * @param Job $job
     * @return string
     */
    protected function getExecutableCommand(Job $job)
    {
        $data = $job->export();
        $data['tmpdir'] = $this->config['tmpdir'];
        return sprintf('"%s" "%s"', $this->script, http_build_query($data));
    }
}

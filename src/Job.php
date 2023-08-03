<?php

namespace Pebble\Cron;

use JsonSerializable;

class Job implements JsonSerializable
{
    private string $name;
    private string $command = 'pwd';
    private string $schedule = '* * * * *';
    private int $max_runtime = 300;
    private string $stdout = '/dev/null';
    private string $stderr = '/dev/null';

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @param string $command
     * @return Job
     */
    public function command(string $command): Job
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @param string $schedule
     * @return Job
     */
    public function schedule(string $schedule): Job
    {
        $this->schedule = $schedule;
        return $this;
    }

    /**
     * @return Job
     */
    public function minutly(): Job
    {
        return $this->schedule("* * * * *");
    }

    /**
     * @return Job
     */
    public function hourly(): Job
    {
        return $this->schedule("0 * * * *");
    }

    /**
     * @param integer $hour
     * @return Job
     */
    public function daily(int $hour): Job
    {
        return $this->schedule("0 {$hour} * * *");
    }

    /**
     * @param integer $max
     * @return Job
     */
    public function maxRuntime(int $max): Job
    {
        $this->max_runtime = $max;
        return $this;
    }

    /**
     * @param string $out
     * @return Job
     */
    public function stdout(string $out): Job
    {
        $this->stdout = $out;
        return $this;
    }

    /**
     * @param string $out
     * @return Job
     */
    public function stderr(string $out): Job
    {
        $this->stderr = $out;
        return $this;
    }

    // -------------------------------------------------------------------------

    /**
     * @param string $name
     * @return string
     */
    public function getSchedule(): string
    {
        return $this->schedule;
    }

    /**
     * @return array
     */
    public function export(): array
    {
        if (!$this->name) {
            $this->name = md5($this->command);
        }

        return [
            'name' => $this->name,
            'command' => $this->command,
            'schedule' => $this->schedule,
            'max_runtime' => $this->max_runtime,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->export();
    }

    // -------------------------------------------------------------------------
}

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
     * @return static
     */
    public function command(string $command): static
    {
        $this->command = $command;
        return $this;
    }

    /**
     * @param string $schedule
     * @return static
     */
    public function schedule(string $schedule): static
    {
        $this->schedule = $schedule;
        return $this;
    }

    public function every($minute = "*", $hour = "*", $dayMonth = "*", $month = "*", $dayWeek = "*"): static
    {
        return $this->schedule("{$minute} {$hour} {$dayMonth} {$month} {$dayWeek}");
    }

    /**
     * @return static
     */
    public function minutly(): static
    {
        return $this->every();
    }

    /**
     * @return static
     */
    public function hourly(): static
    {
        return $this->every(minute: 0);
    }

    /**
     * @param integer $hour
     * @return static
     */
    public function daily(int $hour): static
    {
        return $this->every(hour: $hour, minute: 0);
    }

    /**
     * @param integer $max
     * @return static
     */
    public function maxRuntime(int $max): static
    {
        $this->max_runtime = $max;
        return $this;
    }

    /**
     * @param string $out
     * @return static
     */
    public function stdout(string $out): static
    {
        $this->stdout = $out;
        return $this;
    }

    /**
     * @param string $out
     * @return static
     */
    public function stderr(string $out): static
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
            'name'        => $this->name,
            'command'     => $this->command,
            'schedule'    => $this->schedule,
            'max_runtime' => $this->max_runtime,
            'stdout'      => $this->stdout,
            'stderr'      => $this->stderr,
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->export();
    }

    // -------------------------------------------------------------------------
}

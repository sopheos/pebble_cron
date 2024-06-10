<?php

namespace Pebble\Cron;

class JobRunner
{
    private array $config;
    private Lock $lock;
    private string $filename;
    private array $logs = [];
    private string $ref;

    public function __construct(array $config)
    {
        $this->ref = date('c', $_SERVER['REQUEST_TIME'] ?? time());
        $this->config = $config;
        $this->filename =  $this->config['tmpdir'] . Helper::escape($this->config['name']);
    }

    public function run()
    {
        $crashFile = $this->filename . '.crash';

        if (is_file($crashFile) || is_file($this->filename . '.disabled')) {
            return;
        }

        $this->readLogs();

        $lockFile = $this->filename . '.lock';
        $this->lock = new Lock($lockFile);

        // Temps d'execution trop long
        try {
            $this->checkMaxRuntime();
        } catch (Exception $ex) {
            $this->log("Warning", $ex->getMessage());
            return $this->writeLogs();
        }

        // Aquisition du verrou
        try {
            $this->lock->acquire();
        } catch (Exception $ex) {
            return $this->writeLogs();
        }

        // Execution du cron
        try {
            $start = time();
            $this->runFile();
            $delay = time() - $start;
            $this->log('Info', "Fini en {$delay}s");
        } catch (Exception $ex) {
            file_put_contents($crashFile, date('c'));
            $this->log("Error", $ex->getMessage());
        }

        $this->writeLogs();

        $this->lock->release();
    }

    protected function runFile()
    {
        // Start execution. Run in foreground (will block).
        $command = $this->config['command'] ?: 'pwd';
        $stdout = $this->config['stdout'] ?: '/dev/null';
        $stderr = $this->config['stderr'] ?: '/dev/null';
        $command = "$command 1>> \"$stdout\" 2>> \"$stderr\"";
        $retval = -1;
        $dummy = null;

        exec($command, $dummy, $retval);

        if ($retval !== 0) {
            throw new Exception("Cron exited with status '$retval'.");
        }
    }

    /**
     * @throws Exception
     */
    protected function checkMaxRuntime()
    {
        $maxRuntime = $this->config['max_runtime'] ?? null;
        if (!$maxRuntime) {
            return;
        }

        $runtime = $this->lock->getLifetime();
        if ($runtime < $maxRuntime) {
            return;
        }

        throw new Exception("Max runtime of $maxRuntime secs exceeded! Current runtime: $runtime secs");
    }

    /**
     * @param string $output
     * @return string
     */
    protected function getLogfile($output = 'stdout')
    {
        $logfile = $this->config['output_' . $output] ?? null;
        if ($logfile === null) {
            return false;
        }

        $logs = dirname($logfile);
        if (!file_exists($logs)) {
            mkdir($logs, 0755, true);
        }

        return $logfile;
    }

    protected function log(string $status, string $message)
    {
        $this->logs[] = [
            'ref' => $this->ref,
            'date' => date('c'),
            'status' => $status,
            'message' => $message,
        ];
    }

    protected function readLogs()
    {
        if (!is_file($this->filename . '.json')) {
            return;
        }

        $logs = json_decode(file_get_contents($this->filename . '.json'), true);
        $this->logs = is_array($logs) ? $logs : [];
    }

    protected function writeLogs()
    {
        $logs = array_slice($this->logs, -100, 100, false);
        file_put_contents($this->filename . '.json', json_encode($logs, JSON_PRETTY_PRINT));
    }
}

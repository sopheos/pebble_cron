<?php

namespace Pebble\Cron;

class Lock
{
    /**
     * @var string
     */
    private $lockFile;

    /**
     * @var resource
     */
    private $lockHandle;


    public function __construct(string $lockFile)
    {
        $this->lockFile = $lockFile;
    }

    /**
     * @throws Exception
     * @throws InfoException
     */
    public function acquire()
    {
        if ($this->lockHandle) {
            throw new Exception("Lock already acquired (Lockfile: {$this->lockFile}).");
        }

        if (!file_exists($this->lockFile) && !touch($this->lockFile)) {
            throw new Exception("Unable to create file (File: {$this->lockFile}).");
        }

        $fh = fopen($this->lockFile, 'rb+');
        if ($fh === false) {
            throw new Exception("Unable to open file (File: {$this->lockFile}).");
        }

        $attempts = 5;
        while ($attempts > 0) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->lockHandle = $fh;
                ftruncate($fh, 0);
                fwrite($fh, getmypid());

                return true;
            }
            usleep(250);
            --$attempts;
        }

        throw new Exception("Too much attempts (Lockfile: {$this->lockFile}).");
    }

    /**
     * @throws Exception
     */
    public function release()
    {
        if ($this->lockHandle === null) {
            throw new Exception("Lock NOT held - bug? Lockfile: {$this->lockFile}");
        }

        if ($this->lockHandle) {
            ftruncate($this->lockHandle, 0);
            flock($this->lockHandle, LOCK_UN);
        }

        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }

        $this->lockHandle = null;
    }

    /**
     * @return int
     */
    public function getLifetime()
    {
        if (!file_exists($this->lockFile)) {
            return 0;
        }

        $pid = file_get_contents($this->lockFile);
        if (empty($pid)) {
            return 0;
        }

        if (!posix_kill((int) $pid, 0)) {
            return 0;
        }

        $stat = stat($this->lockFile);

        return (time() - $stat['mtime']);
    }
}

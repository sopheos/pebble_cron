<?php

namespace Pebble\Cron;

use Cron\CronExpression;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionException;

class ScheduleChecker
{
    /**
     * @var DateTimeImmutable|null
     */
    private $now;

    public function __construct(DateTimeImmutable $now = null)
    {
        $this->now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable("now");
    }

    /**
     * @param string|callable $schedule
     * @return bool
     */
    public function isDue($schedule)
    {
        if (self::isCallable($schedule)) {
            return call_user_func($schedule, $this->now);
        }

        $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $schedule);
        if ($dateTime !== false) {
            return $dateTime->format('Y-m-d H:i') == $this->now->format('Y-m-d H:i');
        }

        $cronExpr = new CronExpression((string)$schedule);
        return $cronExpr->isDue($this->now);
    }

    private static function isCallable($value, bool $syntax_only = false, string &$callable_name = null)
    {
        if (is_array($value) && isset($value[0], $value[1]) && is_string($value[0]) && is_string($value[1])) {
            try {
                $class = new ReflectionClass($value[0]);
                $method = $class->getMethod($value[1]);

                if (!$method->isStatic()) {
                    trigger_error("PHP 8.0 {$class->getName()}::{$method->getName()} ne pas être appellé statiquement", E_USER_DEPRECATED);
                }
            } catch (ReflectionException $ex) {
            }
        }


        return $callable_name ? is_callable($value, $syntax_only, $callable_name) : is_callable($value, $syntax_only);
    }
}

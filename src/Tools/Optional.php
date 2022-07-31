<?php

namespace Eboubaker\Scrapper\Tools;

use Closure;
use RuntimeException;
use Throwable;

/**
 * my custom implementation of java.util.Optional
 */
class Optional
{
    private static ?self $EMPTY;
    /**
     * @var mixed
     */
    private $value;
    private bool $isMapped;

    /**
     * @param $value mixed
     * @param bool $isMapped
     */
    private function __construct($value, bool $isMapped = false)
    {
        $this->value = $value;
        $this->isMapped = $isMapped;
    }

    /**
     * @param $value mixed
     * @return static
     */
    public static function of($value): self
    {
        if (null === $value) {
            throw new RuntimeException("Null value passed to Optional::of");
        }
        return new static($value);
    }

    /**
     * @param $value mixed
     * @return static
     */
    public static function ofNullable($value): self
    {
        return new static($value);
    }

    /**
     * if value is not present return a new optional of $value, otherwise return this optional
     * @param $value callable|mixed
     * @return static|self
     */
    public function orElseNew($value): Optional
    {
        if ($this->isPresent()) {
            return $this;
        }
        if (is_callable($value)) {
            $value = call_user_func($value);
        }
        return new static($value);
    }

    public function isPresent(): bool
    {
        return null !== $this->value;
    }

    /**
     * @param $value callable|mixed
     * @return mixed
     */
    public function orElse($value)
    {
        if ($this->isPresent()) {
            return $this->value;
        }
        if (is_callable($value)) {
            return call_user_func($value);
        }
        return $value;
    }

    /**
     * @param $exception Closure|Throwable exception or exception supplier to be thrown if value is empty
     * @throws Throwable
     */
    public function orElseThrow($exception = null)
    {
        if (!$this->isPresent()) {
            if ($exception instanceof Closure) {
                $exception = call_user_func($exception);
            } else if (null === $exception) {
                $exception = new RuntimeException("No value present");
            }
            throw $exception;
        }

        return $this->value;
    }

    /**
     * @return mixed
     * @throws RuntimeException if the optional does not have a value
     */
    public function get()
    {
        if (null === $this->value) {
            throw new RuntimeException("No value present");
        }
        return $this->value;
    }

    public function ifPresent(callable $consumer): void
    {
        if ($this->isPresent()) {
            $consumer($this->value);
        }
    }

    public function ifEmpty(callable $callback): void
    {
        if (!$this->isPresent()) {
            $callback();
        }
    }

    /**
     * if value is present pass it to $mapper and wrap the result of $mapper into a new Optional.
     * if value is not present return an empty Optional
     * @template C
     * @param $mapper callable
     * @return static
     */
    public function map(callable $mapper): Optional
    {
        if (!$this->isPresent()) {
            return self::empty();
        }

        return new static($mapper($this->value));
    }

    public static function empty(): self
    {
        return self::$EMPTY ?? (self::$EMPTY = new self(null));
    }

    /**
     * if value is present pass it to $mapper and wrap the result of $mapper into a new Optional.<br>
     * if value is not present return an empty Optional.<br>
     * returns a new optional of the result of $mapper, calling mapOnce on that optional will not do anything
     */
    public function mapOnce(callable $mapper): Optional
    {
        if ($this->isPresent()) {
            if (!$this->isMapped) {
                return new static($mapper($this->value), true);
            } else {
                return $this;
            }
        } else {
            return self::empty();
        }
    }
}

<?php

/**
 * Exception thrown when a parser safety limit is exceeded.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */

namespace Erseco;

/**
 * Identifies which parser limit was exceeded and the observed value.
 *
 * @category Library
 * @package  MimeMailParser
 * @author   Ernesto Serrano <info@ernesto.es>
 * @license  MIT https://opensource.org/licenses/MIT
 * @link     https://github.com/erseco/mime-mail-parser
 */
class ParserLimitExceededException extends \RuntimeException
{
    private string $limitName;

    private int $limitValue;

    private int $actualValue;

    /**
     * @param string $limitName   Option name that was exceeded.
     * @param int    $limitValue  Configured limit.
     * @param int    $actualValue Observed value that exceeded the limit.
     * @param string $message     Optional custom message.
     */
    public function __construct(
        string $limitName,
        int $limitValue,
        int $actualValue,
        string $message = ''
    ) {
        $this->limitName = $limitName;
        $this->limitValue = $limitValue;
        $this->actualValue = $actualValue;

        if ($message === '') {
            $message = sprintf(
                'Parser limit "%s" exceeded: limit=%d, actual=%d.',
                $limitName,
                $limitValue,
                $actualValue
            );
        }

        parent::__construct($message);
    }

    /**
     * @return string Name of the exceeded limit option.
     */
    public function getLimitName(): string
    {
        return $this->limitName;
    }

    /**
     * @return int Configured limit value.
     */
    public function getLimitValue(): int
    {
        return $this->limitValue;
    }

    /**
     * @return int Observed value that exceeded the limit.
     */
    public function getActualValue(): int
    {
        return $this->actualValue;
    }
}

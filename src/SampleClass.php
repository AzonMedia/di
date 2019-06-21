<?php


namespace Azonmedia\Di;


/**
 * Class SampleClass
 *
 * Sample class for testing DI
 * @package Azonmedia\Di
 */
class SampleClass
{

    /**
     * SampleClass constructor.
     *
     * Has intentional default value in the middle of the argument list
     *
     * @param string $stringArg
     * @param int $intArg
     * @param bool $boolArg
     * @param float $floatArg
     * @param array $arrayArg
     * @param object $objectArg
     * @param callable $callableArg
     * @param iterable $iterableArg
     */
    public function __construct(
        string $stringArg,
        int $intArg,
        bool $boolArg = false,
        float $floatArg,
        array $arrayArg,
        object $objectArg,
        callable $callableArg,
        iterable $iterableArg = []
    ) {

    }
}
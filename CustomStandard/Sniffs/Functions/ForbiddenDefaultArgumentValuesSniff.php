<?php

namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ForbiddenDefaultArgumentValuesSniff implements Sniff {
    public function register() {
        return [
            T_CLOSURE,
            T_FN,
            T_FUNCTION,
        ];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $functionParameters = $phpcsFile->getMethodParameters($stackPtr);
        foreach ($functionParameters as $parameter) {
            if (is_int($parameter["default_token"] ?? null) === true) {
                $phpcsFile->addError(
                    "Default argument value, %s, is forbidden. All arguments must be passed explicitly.",
                    $parameter["default_token"],
                    "Forbidden",
                    [$parameter["name"] . " = " . $parameter["default"]],
                );
            }
        }
    }
}

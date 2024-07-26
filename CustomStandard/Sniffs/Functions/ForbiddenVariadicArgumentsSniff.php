<?php

namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ForbiddenVariadicArgumentsSniff implements Sniff {
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
            if (is_int($parameter["variadic_token"]) === true) {
                $phpcsFile->addError(
                    "Variadic arguments '...' are forbidden, array is required for %s",
                    $parameter["variadic_token"],
                    "Forbidden",
                    [$parameter["name"]],
                );
            }
        }
    }
}

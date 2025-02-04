<?php

namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\DocCommentHelper;

class ForbiddenVariadicArgumentsSniff implements Sniff {
    public function register() {
        return [
            T_CLOSURE,
            T_FN,
            T_FUNCTION,
        ];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();
        $code = $tokens[$stackPtr]["code"];

        if ($code === T_FUNCTION
            && DocCommentHelper::hasInheritdocAnnotation($phpcsFile, $stackPtr) === true) {
            return;
        }

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

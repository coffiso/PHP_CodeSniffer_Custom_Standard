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
        $tokens = $phpcsFile->getTokens();
        foreach ($functionParameters as $parameter) {
            $ptr = $parameter["token"];
            $prevCount = 1;

            //引数とスプラッド演算子の間にある空白をスキップ
            while($tokens[$ptr - $prevCount]["code"] === T_WHITESPACE) {
                $prevCount++;
            }

            if ($tokens[$ptr - $prevCount]["code"] === T_ELLIPSIS) {
                $phpcsFile->addError(
                    "Variadic arguments are forbidden, use array instead.",
                    $ptr - 1,
                    "Forbidden",
                );
            }
        }
    }
}

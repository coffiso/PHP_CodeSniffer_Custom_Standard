<?php

namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\FunctionHelper;

class RequireClosureReturnTypeHintSniff implements Sniff {
    public function register() {
        return [
            T_CLOSURE,
            T_FN,
        ];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $returnTypeHint = FunctionHelper::findReturnTypeHint($phpcsFile, $stackPtr);
        if ($returnTypeHint === null) {
            $phpcsFile->addError(
                "Requires type hint for return value of anonymous function.",
                $stackPtr,
                "RequireTypeHint"
            );
        }
    }
}

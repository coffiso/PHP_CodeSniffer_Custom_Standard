<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class RequireReadOnlyPropertiesSniff implements Sniff {
    public function register() {
        return [
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
        ];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();
        $ptr = $stackPtr + 1;
        $varPtr = $phpcsFile->findNext(T_VARIABLE, $ptr);
        $varName = $tokens[$varPtr]["content"];

        //最初にアクセス修飾子の前方にreadonlyがないかどうかをチェックする
        while ($tokens[$ptr]["code"] !== T_STRING) {
            if ($tokens[$ptr]["code"] === T_READONLY) {
                return;
            }

            $ptr++;
        }

        $ptr = $stackPtr - 1;
        //次にアクセス修飾子の後方にreadonlyがないかどうかをチェックする
        while ($tokens[$ptr]["code"] === T_WHITESPACE) {
            $ptr--;
            if ($tokens[$ptr]["code"] === T_READONLY) {
                return;
            }
        }

        $phpcsFile->addError(
            "'readonly' modifier is required for %s",
            $stackPtr,
            "RequireReadOnlyProperties",
            [$varName]
        );
    }
}

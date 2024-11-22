<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;

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

        //次に出現するトークンでインスタンスプロパティかどうかを判定する。'const'、'function'キーワードが出現したらインスタンスプロパティではないと判定する。
        $kindPtr = $phpcsFile->findNext([T_VARIABLE, T_CONST, T_FUNCTION], $ptr);
        if ($tokens[$kindPtr]["code"] !== T_VARIABLE) {
            return;
        }

        //念の為プロパティかどうかを判定する
        if (PropertyHelper::isProperty(phpcsFile: $phpcsFile, variablePointer: $kindPtr, promoted: true) === false) {
            return;
        }

        /**
         * 継承してプロパティをオーバーライドしており、かつ親クラスのプロパティが非readonlyな場合、サブクラス側でreadonlyを指定できないため、
         * PHPDocに`inheritDoc`アノテーションが存在する場合はオーバーライドしたプロパティとみなし、エラーを発生させない。
         */
        if (DocCommentHelper::hasInheritdocAnnotation($phpcsFile, $kindPtr) === true) {
            return;
        }

        $varName = $tokens[$kindPtr]["content"];

        //最初にアクセス修飾子の前方にreadonlyがないかどうかをチェックする
        while (isset($tokens[$ptr]["code"]) === true && $tokens[$ptr]["code"] !== T_STRING) {
            if ($tokens[$ptr]["code"] === T_READONLY) {
                return;
            }

            if ($tokens[$ptr]["code"] === T_STATIC) {
                return;
            }

            $ptr++;
        }

        $ptr = $stackPtr - 1;
        //次にアクセス修飾子の後方にreadonlyがないかどうかをチェックする
        while (isset($tokens[$ptr]["code"]) === true && $tokens[$ptr]["code"] === T_WHITESPACE) {
            $ptr--;
            if ($tokens[$ptr]["code"] === T_READONLY) {
                return;
            }

            if ($tokens[$ptr]["code"] === T_STATIC) {
                return;
            }
        }

        $phpcsFile->addError(
            "'readonly' modifier is required for %s. Or did you forget '{@inheritDoc}'?",
            $stackPtr,
            "RequireReadOnlyProperties",
            [$varName]
        );
    }
}

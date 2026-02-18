<?php

namespace CustomStandard\Sniffs\Commenting;

use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\AttributeHelper;
use SlevomatCodingStandard\Helpers\FixerHelper;
use SlevomatCodingStandard\Helpers\FunctionHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

/**
 * PHPDocの {@inheritDoc} を検出して #[\Override] 属性に変換し、コメント行を削除するSniff
 *
 * PHPバージョンに応じて3段階に動作を切り替える:
 * - PHP 8.3未満: 完全無効
 * - PHP 8.3以上 8.5未満: メソッドのみに適用
 * - PHP 8.5以上: メソッドおよびプロパティに適用
 */
class InheritDocToOverrideSniff implements Sniff {

    private const PHP_83 = 80300;

    private const PHP_85 = 80500;

    private const ERROR_CODE = "InheritDocToOverride";

    public function register(): array {
        return [
            T_DOC_COMMENT_OPEN_TAG,
        ];
    }

    public function process(File $phpcsFile, $docCommentOpenPointer): void {
        $phpVersion = $this->getPhpVersion();

        // PHP 8.3未満では完全無効
        if ($phpVersion < self::PHP_83) {
            return;
        }

        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]["comment_closer"];

        // PHPDoc内の @inheritDoc を検出する
        $inheritDocInfo = $this->findInheritDoc($phpcsFile, $docCommentOpenPointer);
        if ($inheritDocInfo === null) {
            return;
        }

        // PHPDocの対象宣言を特定する
        $ownerPointer = $this->findDocCommentOwnerPointer($phpcsFile, $docCommentOpenPointer);
        if ($ownerPointer === null) {
            return;
        }

        $ownerCode = $tokens[$ownerPointer]["code"];

        // メソッドの場合: T_FUNCTION かつクラス内メソッドであること
        if ($ownerCode === T_FUNCTION) {
            if (FunctionHelper::isMethod($phpcsFile, $ownerPointer) === false) {
                return;
            }
        } elseif ($ownerCode === T_VARIABLE) {
            // プロパティの場合: PHP 8.5以上のみ適用
            if ($phpVersion < self::PHP_85) {
                return;
            }
        } else {
            // class, interface, trait, enum, const などは対象外
            return;
        }

        $alreadyHasOverride = AttributeHelper::hasAttribute($phpcsFile, $ownerPointer, "\\Override");
        $isOnlyInheritDoc = $inheritDocInfo["isOnly"];

        $fix = $phpcsFile->addFixableError(
            '{@inheritDoc} should be replaced with #[\\Override] attribute.',
            $docCommentOpenPointer,
            self::ERROR_CODE
        );

        if ($fix === false) {
            return;
        }

        $phpcsFile->fixer->beginChangeset();

        // 1. @inheritDoc を含むPHPDocの処理
        if ($isOnlyInheritDoc === true) {
            // PHPDoc全体を削除する
            /** @var int $fixerStart */
            $fixerStart = TokenHelper::findLastTokenOnPreviousLine($phpcsFile, $docCommentOpenPointer);
            FixerHelper::removeBetweenIncluding($phpcsFile, $fixerStart, $commentCloserPointer);
        } else {
            // @inheritDoc 行のみを削除する
            $this->removeInheritDocLine($phpcsFile, $docCommentOpenPointer, $inheritDocInfo);
        }

        // 2. #[\Override] がまだ存在しない場合は挿入する
        if ($alreadyHasOverride === false) {
            $this->insertOverrideAttribute($phpcsFile, $ownerPointer, $docCommentOpenPointer, $isOnlyInheritDoc);
        }

        $phpcsFile->fixer->endChangeset();
    }

    /**
     * PHPDoc内の @inheritDoc / {@inheritDoc} を探す
     *
     * @param File $phpcsFile
     * @param int $docCommentOpenPointer
     *
     * @return array{isOnly: bool, lineTokens: array<int, int>}|null
     */
    private function findInheritDoc(File $phpcsFile, int $docCommentOpenPointer): ?array {
        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]["comment_closer"];

        // まず docComment 全体の中身を走査して @inheritDoc が含まれるか判定
        $hasInheritDoc = false;
        $inheritDocTokenPointer = null;
        $otherContentExists = false;

        for ($i = $docCommentOpenPointer + 1; $i < $commentCloserPointer; $i++) {
            $code = $tokens[$i]["code"];
            $content = $tokens[$i]["content"];

            // ホワイトスペースとスターはスキップ
            if ($code === T_DOC_COMMENT_WHITESPACE || $code === T_DOC_COMMENT_STAR) {
                continue;
            }

            // T_DOC_COMMENT_STRING: {@inheritDoc} がインラインタグとして含まれる場合
            if ($code === T_DOC_COMMENT_STRING) {
                if (preg_match('~\{@inheritDoc\}~i', $content) === 1) {
                    $hasInheritDoc = true;
                    $inheritDocTokenPointer = $i;

                    // この行に @inheritDoc 以外のテキストがあるかチェック
                    $stripped = preg_replace('~\{@inheritDoc\}~i', "", $content);
                    if (trim($stripped) !== "") {
                        $otherContentExists = true;
                    }
                } else {
                    $otherContentExists = true;
                }

                continue;
            }

            // T_DOC_COMMENT_TAG: @inheritDoc がタグとして出現する場合
            if ($code === T_DOC_COMMENT_TAG) {
                if (strcasecmp($content, "@inheritDoc") === 0 || strcasecmp($content, "@inheritdoc") === 0) {
                    $hasInheritDoc = true;
                    $inheritDocTokenPointer = $i;
                } else {
                    $otherContentExists = true;
                }

                continue;
            }

            // それ以外のdocコメントトークン（T_DOC_COMMENT_OPENやCLOSE以外）
            $otherContentExists = true;
        }

        if ($hasInheritDoc === false || $inheritDocTokenPointer === null) {
            return null;
        }

        // @inheritDoc が存在する行のトークン範囲を特定する
        $inheritDocLine = $tokens[$inheritDocTokenPointer]["line"];
        $lineTokenStart = null;
        $lineTokenEnd = null;

        for ($i = $docCommentOpenPointer + 1; $i < $commentCloserPointer; $i++) {
            if ($tokens[$i]["line"] === $inheritDocLine) {
                if ($lineTokenStart === null) {
                    $lineTokenStart = $i;
                }

                $lineTokenEnd = $i;
            }
        }

        return [
            "isOnly" => $otherContentExists === false,
            "lineTokens" => range($lineTokenStart, $lineTokenEnd),
            "inheritDocPointer" => $inheritDocTokenPointer,
        ];
    }

    /**
     * @inheritDoc 行のみをPHPDocから削除する
     *
     * @param File $phpcsFile
     * @param int $docCommentOpenPointer
     * @param array{isOnly: bool, lineTokens: array<int, int>, inheritDocPointer: int} $inheritDocInfo
     */
    private function removeInheritDocLine(File $phpcsFile, int $docCommentOpenPointer, array $inheritDocInfo): void {
        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]["comment_closer"];
        $inheritDocLine = $tokens[$inheritDocInfo["inheritDocPointer"]]["line"];

        // @inheritDoc行を含む行全体のトークンを削除する（改行を含む）
        for ($i = $docCommentOpenPointer + 1; $i < $commentCloserPointer; $i++) {
            if ($tokens[$i]["line"] === $inheritDocLine) {
                $phpcsFile->fixer->replaceToken($i, "");
            }
        }
    }

    /**
     * #[\Override] 属性を宣言の前に挿入する
     *
     * @param File $phpcsFile
     * @param int $ownerPointer 宣言のトークンポインタ
     * @param int $docCommentOpenPointer PHPDocの開始ポインタ
     * @param bool $isOnlyInheritDoc PHPDoc全体が削除されたかどうか
     */
    private function insertOverrideAttribute(
        File $phpcsFile,
        int $ownerPointer,
        int $docCommentOpenPointer,
        bool $isOnlyInheritDoc
    ): void {
        $tokens = $phpcsFile->getTokens();

        // 宣言の先頭（修飾子や属性の前）を見つける
        $insertBeforePointer = $this->findDeclarationStartPointer($phpcsFile, $ownerPointer, $docCommentOpenPointer);

        // インデントを取得する
        $indent = $this->getIndent($phpcsFile, $insertBeforePointer);

        if ($isOnlyInheritDoc === true) {
            // PHPDocが丸ごと削除された場合、PHPDocがあった場所に #[\Override] を挿入する
            // findLastTokenOnPreviousLine で削除した範囲の直後に挿入するため、
            // 宣言の先頭の前に内容を追加する
            $phpcsFile->fixer->addContentBefore($insertBeforePointer, "#[\\Override]\n" . $indent);
        } else {
            // PHPDocが残っている場合、宣言の先頭（修飾子や既存属性の前）に挿入する
            $phpcsFile->fixer->addContentBefore($insertBeforePointer, "#[\\Override]\n" . $indent);
        }
    }

    /**
     * 宣言の先頭ポインタを見つける（修飾子や属性の前）
     *
     * PHPDocより後ろ、かつ宣言トークンより前にある修飾子・属性の開始位置を返す
     *
     * @param File $phpcsFile
     * @param int $ownerPointer 宣言のトークンポインタ
     * @param int $docCommentOpenPointer PHPDocの開始ポインタ
     *
     * @return int 挿入先のポインタ
     */
    private function findDeclarationStartPointer(File $phpcsFile, int $ownerPointer, int $docCommentOpenPointer): int {
        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]["comment_closer"];

        $modifierTokens = [
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
            T_STATIC,
            T_FINAL,
            T_READONLY,
            T_ABSTRACT,
            T_VAR,
        ];

        $startPointer = $ownerPointer;

        // コメント閉じタグの後から宣言の前まで走査し、修飾子や属性を探す
        for ($i = $commentCloserPointer + 1; $i < $ownerPointer; $i++) {
            $code = $tokens[$i]["code"];

            if ($code === T_WHITESPACE) {
                continue;
            }

            if ($code === T_ATTRIBUTE) {
                // 属性がある場合、その開始位置を記録
                if ($i < $startPointer) {
                    $startPointer = $i;
                }

                // attribute_closer までスキップ
                $i = $tokens[$i]["attribute_closer"] ?? $i;
                continue;
            }

            if (in_array($code, $modifierTokens, true) === true) {
                if ($i < $startPointer) {
                    $startPointer = $i;
                }

                continue;
            }
        }

        return $startPointer;
    }

    /**
     * 指定トークンの行頭のインデントを取得する
     *
     * @param File $phpcsFile
     * @param int $pointer
     *
     * @return string
     */
    private function getIndent(File $phpcsFile, int $pointer): string {
        $tokens = $phpcsFile->getTokens();
        $line = $tokens[$pointer]["line"];

        // ポインタから逆方向に同一行のトークンを探索し、行頭の空白を取得する
        for ($i = $pointer - 1; $i >= 0; $i--) {
            if ($tokens[$i]["line"] !== $line) {
                break;
            }

            if ($tokens[$i]["code"] === T_WHITESPACE && $tokens[$i]["line"] === $line) {
                $content = $tokens[$i]["content"];
                if (preg_match("/^( +)$/", $content) === 1) {
                    return $content;
                }
            }
        }

        return "";
    }

    /**
     * PHPバージョンを取得する
     *
     * phpcs.xml の <config name="php_version"> を優先し、設定がなければ PHP_VERSION_ID を使用する
     *
     * @return int PHP_VERSION_ID 形式のバージョン番号
     */
    private function getPhpVersion(): int {
        $configVersion = Config::getConfigData("php_version");

        if ($configVersion !== null) {
            return (int) $configVersion;
        }

        return PHP_VERSION_ID;
    }

    /**
     * PHPDocの対象宣言（メソッドまたはプロパティ）のトークンポインタを特定する
     *
     * Slevomatの DocCommentHelper::findDocCommentOwnerPointer は型ヒント付きプロパティを
     * 正しく検出できないため、CommentingFormatSniff の isCommentForStructure と同様のロジックで
     * 修飾子・型トークン・属性をスキップして宣言トークンを探す。
     *
     * @param File $phpcsFile
     * @param int $docCommentOpenPointer PHPDoc開始タグのスタックポインタ
     *
     * @return int|null 宣言トークンのポインタ（T_FUNCTION or T_VARIABLE）、見つからない場合はnull
     */
    private function findDocCommentOwnerPointer(File $phpcsFile, int $docCommentOpenPointer): ?int {
        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]["comment_closer"];

        // アクセス修飾子トークン
        $modifierTokens = [
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
            T_STATIC,
            T_FINAL,
            T_READONLY,
            T_ABSTRACT,
            T_VAR,
        ];

        // 型宣言に使用されるトークン（スキップ対象）
        $typeTokens = [
            T_WHITESPACE,
            T_NULLABLE,
            T_STRING,
            T_NAME_FULLY_QUALIFIED,
            T_NAME_QUALIFIED,
            T_NAME_RELATIVE,
            T_TYPE_UNION,
            T_TYPE_INTERSECTION,
            T_NULL,
            T_FALSE,
            T_TRUE,
            T_SELF,
            T_PARENT,
            T_ARRAY,
            T_CALLABLE,
            T_OPEN_PARENTHESIS,
            T_CLOSE_PARENTHESIS,
        ];

        $numTokens = count($tokens);
        $seenModifier = false;

        for ($ptr = $commentCloserPointer + 1; $ptr < $numTokens; $ptr++) {
            $code = $tokens[$ptr]["code"];

            // PHP 8属性はattribute_closerまでスキップ
            if ($code === T_ATTRIBUTE) {
                $ptr = ($tokens[$ptr]["attribute_closer"] ?? $ptr);
                continue;
            }

            // 修飾子はスキップしつつ記録
            if (in_array($code, $modifierTokens, true) === true) {
                $seenModifier = true;
                continue;
            }

            // 型トークン・空白はスキップ
            if (in_array($code, $typeTokens, true) === true) {
                continue;
            }

            // T_FUNCTION → メソッドの可能性
            if ($code === T_FUNCTION) {
                return $ptr;
            }

            // T_VARIABLE → 修飾子が先行していればプロパティ
            if ($code === T_VARIABLE && $seenModifier === true) {
                return $ptr;
            }

            // それ以外のトークンに到達した場合は対象外
            return null;
        }

        return null;
    }
}

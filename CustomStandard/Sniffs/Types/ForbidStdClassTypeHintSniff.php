<?php

namespace CustomStandard\Sniffs\Types;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * stdClass 型の使用を禁止し、object 型への自動変換を行う Sniff
 *
 * 以下の箇所を対象とする:
 * - PHP型宣言（パラメータ・戻り値・プロパティの型ヒント）
 * - PHPDocタグ（@param, @return, @var, @property, @property-read, @property-write）
 *
 * 以下は対象外:
 * - new stdClass()
 * - instanceof stdClass
 * - extends stdClass / implements stdClass
 * - FQCN形式 (\stdClass) および stdClass:: 静的アクセス
 */
class ForbidStdClassTypeHintSniff implements Sniff
{
    private const ERROR_CODE_TYPE_HINT = 'ForbidStdClassTypeHint';

    private const ERROR_CODE_DOC_COMMENT = 'ForbidStdClassInDocComment';

    private const TARGET_DOC_TAGS = [
        '@param',
        '@return',
        '@var',
        '@property',
        '@property-read',
        '@property-write',
    ];

    /**
     * T_STRING で型宣言コンテキストでない場合にスキップする前トークン一覧
     *
     * @var list<int>
     */
    private const SKIP_PREV_TOKENS = [
        T_NEW,
        T_INSTANCEOF,
        T_EXTENDS,
        T_IMPLEMENTS,
        T_USE,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_ENUM,
    ];

    /**
     * T_STRING で型宣言コンテキストでない場合にスキップする後トークン一覧
     *
     * @var list<int>
     */
    private const SKIP_NEXT_TOKENS = [
        T_DOUBLE_COLON,
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_NS_SEPARATOR,
    ];

    public function register(): array
    {
        return [
            T_STRING,
            T_DOC_COMMENT_OPEN_TAG,
        ];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$stackPtr]['code'] === T_STRING) {
            $this->processTypeHint($phpcsFile, $stackPtr);
        } else {
            $this->processDocComment($phpcsFile, $stackPtr);
        }
    }

    /**
     * PHP型宣言（パラメータ・戻り値・プロパティ）内の stdClass を検出して修正する
     */
    private function processTypeHint(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        if ($tokens[$stackPtr]['content'] !== 'stdClass') {
            return;
        }

        // 前の非空白トークンを取得してコンテキストを判断
        $prevPtr = $phpcsFile->findPrevious(
            [T_WHITESPACE],
            $stackPtr - 1,
            null,
            true
        );

        if ($prevPtr !== false && in_array($tokens[$prevPtr]['code'], self::SKIP_PREV_TOKENS, true)) {
            return;
        }

        // 前トークンが T_NS_SEPARATOR の場合：
        //   \stdClass（グローバルFQCN）→ 対象（T_NS_SEPARATOR ごと object に置換）
        //   \Foo\stdClass 等（名前空間の一部）→ 除外
        $nsSepPtr = null;
        if ($prevPtr !== false && $tokens[$prevPtr]['code'] === T_NS_SEPARATOR) {
            $beforeNsPtr = $phpcsFile->findPrevious(
                [T_WHITESPACE],
                $prevPtr - 1,
                null,
                true
            );

            if ($beforeNsPtr !== false
                && in_array($tokens[$beforeNsPtr]['code'], [T_STRING, T_NS_SEPARATOR], true)
            ) {
                // Foo\stdClass / \Foo\stdClass の一部 → 除外
                return;
            }

            // \stdClass のグローバルFQCN → T_NS_SEPARATOR ごと置換対象にする
            $nsSepPtr = $prevPtr;
        }

        // 後の非空白トークンを取得してコンテキストを判断
        $nextPtr = $phpcsFile->findNext(
            [T_WHITESPACE],
            $stackPtr + 1,
            null,
            true
        );

        if ($nextPtr !== false && in_array($tokens[$nextPtr]['code'], self::SKIP_NEXT_TOKENS, true)) {
            return;
        }

        $fix = $phpcsFile->addFixableError(
            'stdClass type hint is forbidden. Use object instead.',
            $stackPtr,
            self::ERROR_CODE_TYPE_HINT
        );

        if ($fix === true) {
            if ($nsSepPtr !== null) {
                // \stdClass → object（バックスラッシュも削除）
                $phpcsFile->fixer->beginChangeset();
                $phpcsFile->fixer->replaceToken($nsSepPtr, '');
                $phpcsFile->fixer->replaceToken($stackPtr, 'object');
                $phpcsFile->fixer->endChangeset();
            } else {
                $phpcsFile->fixer->replaceToken($stackPtr, 'object');
            }
        }
    }

    /**
     * PHPDoc タグ内の stdClass を検出して修正する
     */
    private function processDocComment(File $phpcsFile, int $docCommentOpenPointer): void
    {
        $tokens = $phpcsFile->getTokens();
        $commentCloserPointer = $tokens[$docCommentOpenPointer]['comment_closer'];

        for ($i = $docCommentOpenPointer + 1; $i < $commentCloserPointer; $i++) {
            if ($tokens[$i]['code'] !== T_DOC_COMMENT_TAG) {
                continue;
            }

            if (!in_array($tokens[$i]['content'], self::TARGET_DOC_TAGS, true)) {
                continue;
            }

            // タグと同一行にある T_DOC_COMMENT_STRING を探す
            $tagLine = $tokens[$i]['line'];
            $stringPtr = null;

            for ($j = $i + 1; $j <= $commentCloserPointer; $j++) {
                if ($tokens[$j]['line'] !== $tagLine) {
                    break;
                }

                if ($tokens[$j]['code'] === T_DOC_COMMENT_TAG) {
                    break;
                }

                if ($tokens[$j]['code'] === T_DOC_COMMENT_STRING) {
                    $stringPtr = $j;
                    break;
                }
            }

            if ($stringPtr === null) {
                continue;
            }

            $content = $tokens[$stringPtr]['content'];

            // (?<![\\a-zA-Z0-9_]) : 直前に \, ワード文字がない（\Foo\stdClass の一部を除外）
            // \\?                  : 直前の \ を含めてマッチ（\stdClass → object に変換）
            // (?![a-zA-Z0-9_\\])  : 直後にワード文字・\ がない（MyStdClass 等の誤マッチを防ぐ）
            if (preg_match('/(?<![\\\\a-zA-Z0-9_])\\\\?stdClass(?![a-zA-Z0-9_\\\\])/', $content) !== 1) {
                continue;
            }

            $fix = $phpcsFile->addFixableError(
                'stdClass in PHPDoc tag is forbidden. Use object instead.',
                $stringPtr,
                self::ERROR_CODE_DOC_COMMENT
            );

            if ($fix === true) {
                $replaced = preg_replace('/(?<![\\\\a-zA-Z0-9_])\\\\?stdClass(?![a-zA-Z0-9_\\\\])/', 'object', $content);
                $phpcsFile->fixer->replaceToken($stringPtr, $replaced);
            }
        }
    }
}

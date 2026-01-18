<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * 名前空間のエイリアスインポートを禁止するスニフ
 * 
 * エイリアスインポートの代わりに、名前空間の部分インポートの使用を推奨します。
 * 名前衝突回避のためエイリアスインポートが必須の場合は指摘しません。
 */
class ForbidAliasedImportsSniff implements Sniff
{
    /**
     * 検知対象のトークン一覧
     *
     * @return array<int>
     */
    public function register(): array
    {
        return [T_USE];
    }

    /**
     * トークンを処理する
     *
     * @param File $phpcsFile PHPCSファイルオブジェクト
     * @param int $stackPtr スタックポインタ
     */
    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // クラス内のuse（トレイト使用）は除外
        if ($this->isTraitUse($phpcsFile, $stackPtr)) {
            return;
        }

        // use文の終了（セミコロンまで）を見つける
        $semicolon = $phpcsFile->findNext(T_SEMICOLON, $stackPtr);
        if ($semicolon === false) {
            return;
        }

        // use文にasキーワードがあるかチェック
        $asPtr = $phpcsFile->findNext(T_AS, $stackPtr, $semicolon);
        if ($asPtr === false) {
            // エイリアスなしなので問題なし
            return;
        }

        // use文の完全な名前空間とエイリアスを取得
        $useInfo = $this->parseUseStatement($phpcsFile, $stackPtr, $semicolon, $asPtr);
        if ($useInfo === null) {
            return;
        }

        // ファイル内で定義されているクラスとの名前衝突があるかチェック
        if ($this->hasCollisionWithDefinedClass($phpcsFile, $useInfo['className'])) {
            // 定義されているクラスとの名前衝突がある場合は指摘しない
            return;
        }

        // エイリアスインポートを検出
        $phpcsFile->addError(
            'Aliased imports are forbidden. Use non-aliased imports or partial namespace imports instead. Aliases are only allowed when the imported class name conflicts with a class defined in this file.',
            $asPtr,
            'ForbidAliasedImports'
        );
    }

    /**
     * use文がトレイトの使用かどうかを判定
     */
    private function isTraitUse(File $phpcsFile, int $usePtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        // useより前のトークンを探してクラススコープ内かチェック
        if (isset($tokens[$usePtr]['conditions']) && !empty($tokens[$usePtr]['conditions'])) {
            foreach ($tokens[$usePtr]['conditions'] as $conditionCode) {
                if ($conditionCode === T_CLASS || $conditionCode === T_TRAIT || $conditionCode === T_INTERFACE) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * use文を解析
     *
     * @return array{fullName: string, className: string, alias: string, namespace: string}|null
     */
    private function parseUseStatement(File $phpcsFile, int $usePtr, int $semicolon, int $asPtr): ?array
    {
        $tokens = $phpcsFile->getTokens();

        // use文の完全な名前空間を取得
        $fullName = '';
        for ($i = $usePtr + 1; $i < $asPtr; $i++) {
            if ($tokens[$i]['code'] === T_STRING || $tokens[$i]['code'] === T_NS_SEPARATOR) {
                $fullName .= $tokens[$i]['content'];
            } elseif (defined('T_NAME_FULLY_QUALIFIED') && $tokens[$i]['code'] === T_NAME_FULLY_QUALIFIED) {
                $fullName .= $tokens[$i]['content'];
            } elseif (defined('T_NAME_QUALIFIED') && $tokens[$i]['code'] === T_NAME_QUALIFIED) {
                $fullName .= $tokens[$i]['content'];
            }
        }

        // エイリアスを取得
        $alias = '';
        for ($i = $asPtr + 1; $i < $semicolon; $i++) {
            if ($tokens[$i]['code'] === T_STRING) {
                $alias .= $tokens[$i]['content'];
            }
        }

        if ($fullName === '' || $alias === '') {
            return null;
        }

        // クラス名と名前空間を取得
        $parts = explode('\\', ltrim($fullName, '\\'));
        $className = end($parts);
        $namespace = implode('\\', array_slice($parts, 0, -1));

        return [
            'fullName' => ltrim($fullName, '\\'),
            'className' => $className,
            'alias' => $alias,
            'namespace' => $namespace,
        ];
    }

    /**
     * ファイル内で定義されているクラスとの名前衝突があるかチェック
     */
    private function hasCollisionWithDefinedClass(File $phpcsFile, string $className): bool
    {
        $tokens = $phpcsFile->getTokens();

        // ファイル内の全クラス定義を検索
        for ($i = 0; $i < $phpcsFile->numTokens; $i++) {
            if ($tokens[$i]['code'] !== T_CLASS && $tokens[$i]['code'] !== T_INTERFACE && $tokens[$i]['code'] !== T_TRAIT && $tokens[$i]['code'] !== T_ANON_CLASS) {
                continue;
            }

            // 無名クラスはスキップ
            if ($tokens[$i]['code'] === T_ANON_CLASS) {
                continue;
            }

            // クラス名を取得
            $classNamePtr = $phpcsFile->findNext(T_STRING, $i + 1);
            if ($classNamePtr === false) {
                continue;
            }

            $definedClassName = $tokens[$classNamePtr]['content'];
            if ($definedClassName === $className) {
                return true;
            }
        }

        return false;
    }
}

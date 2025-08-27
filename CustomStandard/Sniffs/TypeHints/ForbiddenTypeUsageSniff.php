<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\TypeHints;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * 使用禁止クラス・インターフェースの型使用を検出するスニフ
 *
 * このスニフは以下の場面での禁止型使用を検出します：
 * - 関数・メソッドの引数型宣言
 * - 関数・メソッドの戻り値型宣言
 * - プロパティの型宣言
 * - Union型・Intersection型での使用
 * - クロージャ・Arrow関数での型使用
 * - インターフェース・抽象クラスでの型宣言
 */
final class ForbiddenTypeUsageSniff implements Sniff
{
    /**
     * 禁止クラス・インターフェースのリスト
     * キー: 禁止クラス名, 値: カスタムエラーメッセージ
     *
     * @var array<string,string>
     */
    public array $forbiddenTypes = [];

    /**
     * 検知対象のトークン一覧
     *
     * @return array<int,int>
     */
    public function register(): array
    {
        return [
            T_FUNCTION,      // 関数・メソッド
            T_VARIABLE,      // プロパティ（型宣言付き）
            T_FN,           // Arrow関数
            T_CLOSURE,      // クロージャ
            T_STRING,       // 型名（FQN対応のため追加）
        ];
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
        $token = $tokens[$stackPtr];

        match ($token['code']) {
            T_FUNCTION, T_FN, T_CLOSURE => $this->processFunctionLike($phpcsFile, $stackPtr),
            T_VARIABLE => $this->processProperty($phpcsFile, $stackPtr),
            T_STRING => $this->processStringToken($phpcsFile, $stackPtr),
            default => null,
        };
    }

    /**
     * 関数・メソッド・クロージャ・Arrow関数の型宣言をチェック
     */
    private function processFunctionLike(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // 引数の型宣言をチェック
        $this->checkParameterTypes($phpcsFile, $stackPtr);

        // 戻り値の型宣言をチェック
        $this->checkReturnType($phpcsFile, $stackPtr);
    }

    /**
     * 関数・メソッドの引数型宣言をチェック
     */
    private function checkParameterTypes(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // 引数リストの開始を見つける
        $openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $stackPtr);
        if ($openParen === false) {
            return;
        }

        $closeParen = $tokens[$openParen]['parenthesis_closer'];

        // 引数リスト内の型宣言をチェック
        for ($i = $openParen + 1; $i < $closeParen; $i++) {
            if ($tokens[$i]['code'] === T_STRING) {
                $this->checkTypeToken($phpcsFile, $i, 'parameter type');
            }
        }
    }

    /**
     * 戻り値型宣言をチェック
     */
    private function checkReturnType(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // 引数リストの終了を見つける
        $openParen = $phpcsFile->findNext(T_OPEN_PARENTHESIS, $stackPtr);
        if ($openParen === false) {
            return;
        }

        $closeParen = $tokens[$openParen]['parenthesis_closer'];

        // コロンを探して戻り値型をチェック
        $colon = $phpcsFile->findNext(T_COLON, $closeParen);
        if ($colon === false) {
            return;
        }

        // 戻り値型の開始を見つける
        $returnTypeStart = $phpcsFile->findNext([T_STRING, T_CALLABLE], $colon);
        if ($returnTypeStart === false) {
            return;
        }

        // 戻り値型の終了を見つける (開始波括弧まで)
        $openBrace = $phpcsFile->findNext([T_OPEN_CURLY_BRACKET, T_SEMICOLON], $returnTypeStart);
        if ($openBrace === false) {
            return;
        }

        // 戻り値型の範囲内で型をチェック
        for ($i = $returnTypeStart; $i < $openBrace; $i++) {
            if ($tokens[$i]['code'] === T_STRING) {
                $this->checkTypeToken($phpcsFile, $i, 'return type');
            }
        }
    }

    /**
     * プロパティの型宣言をチェック
     */
    private function processProperty(File $phpcsFile, int $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // プロパティの場合、直前に型宣言があるかチェック
        $prevToken = $phpcsFile->findPrevious([T_WHITESPACE], $stackPtr - 1, null, true);
        if ($prevToken === false) {
            return;
        }

        // 型宣言の可能性があるトークンをチェック
        if ($tokens[$prevToken]['code'] === T_STRING) {
            // アクセス修飾子の後に型宣言がある場合
            $modifier = $phpcsFile->findPrevious([T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_READONLY], $prevToken - 1);
            if ($modifier !== false) {
                $this->checkTypeToken($phpcsFile, $prevToken, 'property type');
            }
        }
    }

    /**
     * T_STRINGトークンを処理して型宣言コンテキストを検出
     */
    private function processStringToken(File $phpcsFile, int $stackPtr): void
    {
        if ($this->isInTypeDeclarationContext($phpcsFile, $stackPtr)) {
            $this->checkTypeToken($phpcsFile, $stackPtr, 'type declaration');
        }
    }

    /**
     * T_STRINGトークンが型宣言のコンテキストにあるかどうかを判定
     */
    private function isInTypeDeclarationContext(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        // 前のトークンをチェックして型宣言のコンテキストかどうかを判定
        $prevPtr = $phpcsFile->findPrevious([T_WHITESPACE], $stackPtr - 1, null, true);
        if ($prevPtr === false) {
            return false;
        }

        $prevToken = $tokens[$prevPtr];

        // 名前空間区切りの直後の場合（FQNの一部）
        if ($prevToken['code'] === T_NS_SEPARATOR) {
            return $this->isInTypeDeclarationContext($phpcsFile, $prevPtr);
        }

        // 以下のトークンの直後にある場合は型宣言のコンテキスト
        $typeDeclarationIndicators = [
            T_OPEN_PARENTHESIS,  // 関数引数の型: function test(Type $param)
            T_COLON,            // 戻り値型: function test(): Type
            T_COMMA,            // 複数引数の型: function test(Type1 $p1, Type2 $p2)
            T_TYPE_UNION,             // Union型: Type1|Type2
            T_TYPE_INTERSECTION,        // Intersection型: Type1&Type2
            T_NULLABLE,         // Nullable型: ?Type
            T_PUBLIC,           // プロパティ型: public Type $prop
            T_PRIVATE,          // プロパティ型: private Type $prop
            T_PROTECTED,        // プロパティ型: protected Type $prop
            T_STATIC,           // プロパティ型: static Type $prop
            T_READONLY,         // プロパティ型: readonly Type $prop
        ];

        if (in_array($prevToken['code'], $typeDeclarationIndicators)) {
            return true;
        }

        // より複雑なケースをチェック
        return $this->isComplexTypeContext($phpcsFile, $stackPtr);
    }

    /**
     * より複雑な型宣言コンテキストをチェック
     */
    private function isComplexTypeContext(File $phpcsFile, int $stackPtr): bool
    {
        $tokens = $phpcsFile->getTokens();

        // 関数引数リスト内かどうかをチェック
        $openParen = $phpcsFile->findPrevious(T_OPEN_PARENTHESIS, $stackPtr);
        if ($openParen !== false) {
            $closeParen = isset($tokens[$openParen]['parenthesis_closer'])
                ? $tokens[$openParen]['parenthesis_closer']
                : false;

            if ($closeParen !== false && $stackPtr > $openParen && $stackPtr < $closeParen) {
                // 関数定義の引数リスト内かチェック
                $functionPtr = $phpcsFile->findPrevious([T_FUNCTION, T_FN, T_CLOSURE], $openParen);
                if ($functionPtr !== false) {
                    // 次のトークンが変数（引数）かチェック
                    $nextVar = $phpcsFile->findNext([T_VARIABLE], $stackPtr);
                    if ($nextVar !== false && $nextVar < $closeParen) {
                        return true;
                    }
                }
            }
        }

        // プロパティ宣言かチェック
        $nextToken = $phpcsFile->findNext([T_WHITESPACE], $stackPtr + 1, null, true);
        if ($nextToken !== false && $tokens[$nextToken]['code'] === T_VARIABLE) {
            // プロパティのアクセス修飾子が前にあるかチェック
            $modifierPtr = $phpcsFile->findPrevious(
                [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_STATIC, T_READONLY],
                $stackPtr
            );
            if ($modifierPtr !== false) {
                // 修飾子と現在のトークンの間に他の複雑な構造がないかチェック
                $between = $phpcsFile->findNext(
                    [T_SEMICOLON, T_OPEN_CURLY_BRACKET, T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT],
                    $modifierPtr,
                    $stackPtr
                );
                if ($between === false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 型トークンが禁止型かどうかをチェック
     */
    private function checkTypeToken(File $phpcsFile, int $tokenPtr, string $context): void
    {
        $tokens = $phpcsFile->getTokens();

        // FQN（完全修飾名）を構築する
        $fqnResult = $this->buildFullQualifiedName($phpcsFile, $tokenPtr);
        $fullTypeName = $fqnResult['fqn'];
        $shortTypeName = $fqnResult['short'];

        // use文から解決された型名も取得
        $resolvedTypeName = $this->resolveFullTypeName($phpcsFile, $tokenPtr);

        // 禁止型かどうかをチェック（優先順位：FQN > 解決された型名 > 短縮型名）
        $errorMessage = null;
        $detectedType = null;

        if ($fullTypeName && isset($this->forbiddenTypes[$fullTypeName])) {
            $errorMessage = $this->forbiddenTypes[$fullTypeName];
            $detectedType = $fullTypeName;
        } elseif ($resolvedTypeName && isset($this->forbiddenTypes[$resolvedTypeName])) {
            $errorMessage = $this->forbiddenTypes[$resolvedTypeName];
            $detectedType = $resolvedTypeName;
        } elseif (isset($this->forbiddenTypes[$shortTypeName])) {
            $errorMessage = $this->forbiddenTypes[$shortTypeName];
            $detectedType = $shortTypeName;
        }

        if ($errorMessage !== null) {
            // カスタムエラーメッセージが設定されている場合はそれを使用、そうでなければデフォルトメッセージ
            if ($errorMessage !== '') {
                $error = $errorMessage;
            } else {
                $error = sprintf(
                    'Forbidden type "%s" found in %s.',
                    $detectedType,
                    $context
                );
            }

            $phpcsFile->addError($error, $tokenPtr, 'ForbiddenTypeUsage');
        }
    }

    /**
     * FQN（完全修飾名）を構築する
     *
     * @return array{fqn: string|null, short: string}
     */
    private function buildFullQualifiedName(File $phpcsFile, int $tokenPtr): array
    {
        $tokens = $phpcsFile->getTokens();
        $shortTypeName = $tokens[$tokenPtr]['content'];

        // 後方に向かって名前空間区切りがあるかチェック
        $parts = [$shortTypeName];
        $currentPtr = $tokenPtr;

        // 前方の名前空間部分を収集
        while (true) {
            $prevPtr = $phpcsFile->findPrevious([T_WHITESPACE], $currentPtr - 1, null, true);
            if ($prevPtr === false) {
                break;
            }

            if ($tokens[$prevPtr]['code'] === T_NS_SEPARATOR) {
                // 名前空間区切りの前にもう一つのT_STRINGがあるかチェック
                $beforeSepPtr = $phpcsFile->findPrevious([T_WHITESPACE], $prevPtr - 1, null, true);
                if ($beforeSepPtr !== false && $tokens[$beforeSepPtr]['code'] === T_STRING) {
                    array_unshift($parts, $tokens[$beforeSepPtr]['content']);
                    $currentPtr = $beforeSepPtr;
                } else {
                    // 先頭の区切り文字（\）の場合
                    break;
                }
            } else {
                break;
            }
        }

        // 後方の名前空間部分を収集
        $currentPtr = $tokenPtr;
        while (true) {
            $nextPtr = $phpcsFile->findNext([T_WHITESPACE], $currentPtr + 1, null, true);
            if ($nextPtr === false) {
                break;
            }

            if ($tokens[$nextPtr]['code'] === T_NS_SEPARATOR) {
                // 名前空間区切りの後にT_STRINGがあるかチェック
                $afterSepPtr = $phpcsFile->findNext([T_WHITESPACE], $nextPtr + 1, null, true);
                if ($afterSepPtr !== false && $tokens[$afterSepPtr]['code'] === T_STRING) {
                    $parts[] = $tokens[$afterSepPtr]['content'];
                    $currentPtr = $afterSepPtr;
                } else {
                    break;
                }
            } else {
                break;
            }
        }

        // FQNが構築された場合のみ返す（複数の部分がある場合）
        if (count($parts) > 1) {
            return [
                'fqn' => implode('\\', $parts),
                'short' => $shortTypeName,
            ];
        }

        // 先頭が名前空間区切りで始まる場合もチェック
        $prevPtr = $phpcsFile->findPrevious([T_WHITESPACE], $tokenPtr - 1, null, true);
        if ($prevPtr !== false && $tokens[$prevPtr]['code'] === T_NS_SEPARATOR) {
            // さらに前を確認して完全なFQNを構築
            $leadingParts = [];
            $checkPtr = $prevPtr;

            while (true) {
                $beforePtr = $phpcsFile->findPrevious([T_WHITESPACE], $checkPtr - 1, null, true);
                if ($beforePtr === false) {
                    break;
                }

                if ($tokens[$beforePtr]['code'] === T_STRING) {
                    array_unshift($leadingParts, $tokens[$beforePtr]['content']);
                    $separatorPtr = $phpcsFile->findPrevious([T_WHITESPACE], $beforePtr - 1, null, true);
                    if ($separatorPtr !== false && $tokens[$separatorPtr]['code'] === T_NS_SEPARATOR) {
                        $checkPtr = $separatorPtr;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }

            if (!empty($leadingParts)) {
                $allParts = array_merge($leadingParts, $parts);
                return [
                    'fqn' => implode('\\', $allParts),
                    'short' => $shortTypeName,
                ];
            } else {
                // 先頭が\で始まる単一クラス名の場合
                return [
                    'fqn' => $shortTypeName,
                    'short' => $shortTypeName,
                ];
            }
        }

        return [
            'fqn' => null,
            'short' => $shortTypeName,
        ];
    }

    /**
     * use文を考慮して完全修飾型名を解決
     */
    private function resolveFullTypeName(File $phpcsFile, int $tokenPtr): string
    {
        $tokens = $phpcsFile->getTokens();
        $typeName = $tokens[$tokenPtr]['content'];

        // 既に完全修飾名の場合はそのまま返す
        if (str_starts_with($typeName, '\\')) {
            return ltrim($typeName, '\\');
        }

        // use文から解決を試みる
        $useStatements = $this->findUseStatements($phpcsFile);

        // 完全にマッチするuse文を探す
        if (isset($useStatements[$typeName])) {
            return $useStatements[$typeName];
        }

        // 部分マッチ（名前空間付き）を探す
        foreach ($useStatements as $alias => $fullName) {
            if (str_ends_with($fullName, '\\' . $typeName)) {
                return $fullName;
            }
        }

        return $typeName;
    }

    /**
     * ファイル内のuse文を解析
     *
     * @return array<string,string> エイリアス => 完全修飾名のマップ
     */
    private function findUseStatements(File $phpcsFile): array
    {
        $tokens = $phpcsFile->getTokens();
        $useStatements = [];

        for ($i = 0; $i < $phpcsFile->numTokens; $i++) {
            if ($tokens[$i]['code'] === T_USE) {
                $useStatement = $this->parseUseStatement($phpcsFile, $i);
                if ($useStatement !== null) {
                    $useStatements[$useStatement['alias']] = $useStatement['fullName'];
                }
            }
        }

        return $useStatements;
    }

    /**
     * use文を解析
     *
     * @return array{alias: string, fullName: string}|null
     */
    private function parseUseStatement(File $phpcsFile, int $usePtr): ?array
    {
        $tokens = $phpcsFile->getTokens();

        // use文の終了（セミコロンまで）を見つける
        $semicolon = $phpcsFile->findNext(T_SEMICOLON, $usePtr);
        if ($semicolon === false) {
            return null;
        }

        // use文の内容を取得
        $fullName = '';
        $alias = '';
        $foundAs = false;

        for ($i = $usePtr + 1; $i < $semicolon; $i++) {
            if ($tokens[$i]['code'] === T_STRING || $tokens[$i]['code'] === T_NS_SEPARATOR) {
                if ($foundAs) {
                    $alias .= $tokens[$i]['content'];
                } else {
                    $fullName .= $tokens[$i]['content'];
                }
            } elseif ($tokens[$i]['code'] === T_AS) {
                $foundAs = true;
            }
        }

        // エイリアスが指定されていない場合は、クラス名をエイリアスとして使用
        if ($alias === '') {
            $parts = explode('\\', ltrim($fullName, '\\'));
            $alias = end($parts);
        }

        return [
            'alias' => $alias,
            'fullName' => ltrim($fullName, '\\'),
        ];
    }
}

<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\AttributeHelper;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;

class RequireReadOnlyClassSniff implements Sniff {
    /**
     * プロパティの型宣言を表すトークンコードのリスト
     */
    private function getTypeTokens(): array {
        return [
            T_STRING,           // クラス名、インターフェース名、ビルトイン型 (int, string, bool, float, array, object, mixed, callable, iterable, void, never など)
            T_NULLABLE,         // ? (nullable型)
            T_NS_SEPARATOR,     // \ (名前空間区切り)
            T_TYPE_UNION,       // | (union型)
            T_TYPE_INTERSECTION, // & (intersection型)
        ];
    }

    public function register() {
        return [
            T_CLASS,
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
        ];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();

        // T_CLASSトークンの場合はクラス全体をチェック
        if ($tokens[$stackPtr]["code"] === T_CLASS) {
            $this->processClass($phpcsFile, $stackPtr);
            return;
        }

        // プロパティの場合の処理
        $this->processProperty($phpcsFile, $stackPtr);
    }

    /**
     * クラス全体をチェックして、readonly classへの昇華が必要か判定する
     *
     * readonly classの制約:
     * - 型のついていないプロパティ、静的プロパティを宣言することはできない
     * - readonlyクラスをreadonlyクラスでextendsすることは可能だが、
     *   readonlyクラスを非readonlyクラスでextendsしたり、その逆は禁止
     *
     * 安全にreadonly classに変換できないケースではエラーを出さない
     */
    private function processClass(File $phpcsFile, int $classPtr): void {
        $tokens = $phpcsFile->getTokens();

        // 無名クラスはスキップ
        if ($tokens[$classPtr]["code"] === T_ANON_CLASS) {
            return;
        }

        // 既にreadonly classの場合はスキップ
        if ($this->isReadonlyClass($phpcsFile, $classPtr)) {
            return;
        }

        // 継承しているクラスの場合は安全に変換できないためスキップ
        // （親クラスがreadonlyかどうか静的解析で判断できないため）
        if ($this->isExtendingClass($phpcsFile, $classPtr)) {
            return;
        }

        // 継承可能なクラス（abstract または non-final）の場合は安全に変換できないためスキップ
        // （サブクラスもreadonlyにする必要があり影響範囲を検証できないため）
        if ($this->isInheritableClass($phpcsFile, $classPtr)) {
            return;
        }

        // クラスのプロパティを取得
        $properties = $this->getClassProperties($phpcsFile, $classPtr);

        // 各プロパティのreadonly状態をチェック
        $readonlyCount = 0;
        $nonStaticCount = 0;
        $hasStaticProperties = false;
        $hasUntypedProperties = false;

        foreach ($properties as $property) {
            if ($property['is_static']) {
                $hasStaticProperties = true;
                continue;
            }
            $nonStaticCount++;
            if ($property['has_readonly']) {
                $readonlyCount++;
            }
            if (!$property['has_type']) {
                $hasUntypedProperties = true;
            }
        }

        // readonly classではstaticプロパティを持てないためスキップ
        if ($hasStaticProperties) {
            return;
        }

        // readonly classでは全プロパティに型指定が必要なためスキップ
        if ($hasUntypedProperties) {
            return;
        }

        // 全てのnon-staticプロパティがreadonlyの場合のみ、readonly classへの昇華を提案（自動修正付き）
        if ($readonlyCount === $nonStaticCount && $readonlyCount > 0) {
            $fix = $phpcsFile->addFixableError(
                'All properties are readonly. Class should be declared as readonly and readonly modifiers should be removed from properties',
                $classPtr,
                'ShouldBeReadOnlyClass'
            );

            if ($fix === true) {
                $phpcsFile->fixer->beginChangeset();

                // classキーワードの前を遡って、final/abstractを探し、readonlyをそれらの前に追加する
                $insertBeforePtr = $classPtr;
                $checkPtr = $classPtr - 1;
                while ($checkPtr > 0) {
                    if ($tokens[$checkPtr]['code'] === T_WHITESPACE ||
                        $tokens[$checkPtr]['code'] === T_COMMENT ||
                        $tokens[$checkPtr]['code'] === T_DOC_COMMENT) {
                        $checkPtr--;
                        continue;
                    }

                    if ($tokens[$checkPtr]['code'] === T_FINAL ||
                        $tokens[$checkPtr]['code'] === T_ABSTRACT) {
                        $insertBeforePtr = $checkPtr;
                        $checkPtr--;
                        continue;
                    }

                    break;
                }

                $phpcsFile->fixer->addContentBefore($insertBeforePtr, 'readonly ');

                // 全てのプロパティからreadonlyを削除
                foreach ($properties as $property) {
                    if ($property['has_readonly'] && $property['readonly_ptr'] !== null) {
                        $phpcsFile->fixer->replaceToken($property['readonly_ptr'], '');
                        $nextPtr = $property['readonly_ptr'] + 1;
                        if (isset($tokens[$nextPtr]) && $tokens[$nextPtr]['code'] === T_WHITESPACE) {
                            $phpcsFile->fixer->replaceToken($nextPtr, '');
                        }
                    }
                }
                $phpcsFile->fixer->endChangeset();
            }
        }
    }

    /**
     * プロパティの個別チェック
     */
    private function processProperty(File $phpcsFile, int $stackPtr): void {
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

        // #[\Override] 属性が存在する場合はオーバーライドしたプロパティとみなす
        if (AttributeHelper::hasAttribute($phpcsFile, $kindPtr, "\\Override") === true) {
            return;
        }

        // プロパティが属するクラスを取得
        $classPtr = $this->findClassPointer($phpcsFile, $stackPtr);
        if ($classPtr === null) {
            return;
        }

        // 継承しているクラスのプロパティはスキップ
        if ($this->isExtendingClass($phpcsFile, $classPtr)) {
            return;
        }

        // readonly classの場合、プロパティにreadonlyは不要（あれば削除を提案）
        if ($this->isReadonlyClass($phpcsFile, $classPtr)) {
            $readonlyPtr = $this->findReadonlyModifier($phpcsFile, $stackPtr, $kindPtr);
            if ($readonlyPtr !== null) {
                $fix = $phpcsFile->addFixableError(
                    'Property in readonly class should not have readonly modifier',
                    $readonlyPtr,
                    'RedundantReadOnlyModifier'
                );

                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    $phpcsFile->fixer->replaceToken($readonlyPtr, '');
                    // 次のトークンが空白の場合は削除
                    $nextPtr = $readonlyPtr + 1;
                    if (isset($tokens[$nextPtr]) && $tokens[$nextPtr]['code'] === T_WHITESPACE) {
                        $phpcsFile->fixer->replaceToken($nextPtr, '');
                    }
                    $phpcsFile->fixer->endChangeset();
                }
            }
            return;
        }

        $varName = $tokens[$kindPtr]["content"];

        // staticプロパティはチェックしない
        $ptr = $stackPtr + 1;
        while (isset($tokens[$ptr]["code"]) === true && $tokens[$ptr]["code"] !== T_STRING) {
            if ($tokens[$ptr]["code"] === T_STATIC) {
                return;
            }
            $ptr++;
        }

        $ptr = $stackPtr - 1;
        while (isset($tokens[$ptr]["code"]) === true && $tokens[$ptr]["code"] === T_WHITESPACE) {
            $ptr--;
            if ($tokens[$ptr]["code"] === T_STATIC) {
                return;
            }
        }

        // readonlyキーワードがあるかチェック
        if ($this->findReadonlyModifier($phpcsFile, $stackPtr, $kindPtr) !== null) {
            return;
        }

        $phpcsFile->addError(
            "'readonly' modifier is required for %s. Or did you forget '{@inheritDoc}'?",
            $stackPtr,
            "RequireReadOnlyProperties",
            [$varName]
        );
    }

    /**
     * クラスがreadonly classかどうかを判定
     */
    private function isReadonlyClass(File $phpcsFile, int $classPtr): bool {
        $tokens = $phpcsFile->getTokens();

        // classキーワードの前にreadonlyがあるかチェック
        $ptr = $classPtr - 1;
        while ($ptr > 0) {
            if ($tokens[$ptr]['code'] === T_WHITESPACE || $tokens[$ptr]['code'] === T_COMMENT || $tokens[$ptr]['code'] === T_DOC_COMMENT) {
                $ptr--;
                continue;
            }

            if ($tokens[$ptr]['code'] === T_READONLY) {
                return true;
            }

            // abstract, final などの前にreadonlyがあるかもしれない
            if ($tokens[$ptr]['code'] === T_ABSTRACT || $tokens[$ptr]['code'] === T_FINAL) {
                $ptr--;
                continue;
            }

            break;
        }

        return false;
    }

    /**
     * クラスが他のクラスを継承しているかチェック
     */
    private function isExtendingClass(File $phpcsFile, int $classPtr): bool {
        $tokens = $phpcsFile->getTokens();

        if (!isset($tokens[$classPtr]['scope_opener'])) {
            return false;
        }

        $scopeOpener = $tokens[$classPtr]['scope_opener'];

        // classキーワードからスコープ開始までの間にextendsがあるかチェック
        $extendsPtr = $phpcsFile->findNext(T_EXTENDS, $classPtr + 1, $scopeOpener);

        return $extendsPtr !== false;
    }

    /**
     * クラスが継承可能かどうかをチェック（abstract または non-final）
     */
    private function isInheritableClass(File $phpcsFile, int $classPtr): bool {
        $tokens = $phpcsFile->getTokens();

        // classキーワードの前にabstractがあるかチェック
        $ptr = $classPtr - 1;
        while ($ptr > 0) {
            if ($tokens[$ptr]['code'] === T_WHITESPACE ||
                $tokens[$ptr]['code'] === T_COMMENT ||
                $tokens[$ptr]['code'] === T_DOC_COMMENT) {
                $ptr--;
                continue;
            }

            if ($tokens[$ptr]['code'] === T_ABSTRACT) {
                return true;
            }

            if ($tokens[$ptr]['code'] === T_FINAL) {
                return false;
            }

            // readonly の場合は続けて探索
            if ($tokens[$ptr]['code'] === T_READONLY) {
                $ptr--;
                continue;
            }

            break;
        }

        // final が見つからなかった場合は継承可能（non-final）
        return true;
    }

    /**
     * クラス内の全プロパティを取得
     */
    private function getClassProperties(File $phpcsFile, int $classPtr): array {
        $tokens = $phpcsFile->getTokens();

        if (!isset($tokens[$classPtr]['scope_opener']) || !isset($tokens[$classPtr]['scope_closer'])) {
            return [];
        }

        $scopeOpener = $tokens[$classPtr]['scope_opener'];
        $scopeCloser = $tokens[$classPtr]['scope_closer'];

        $properties = [];
        $ptr = $scopeOpener + 1;

        while ($ptr < $scopeCloser) {
            if ($tokens[$ptr]['code'] === T_VARIABLE) {
                // プロパティかどうか確認
                if (PropertyHelper::isProperty(phpcsFile: $phpcsFile, variablePointer: $ptr, promoted: true)) {
                    // アクセス修飾子を見つける
                    $modifierPtr = $phpcsFile->findPrevious([T_PUBLIC, T_PROTECTED, T_PRIVATE], $ptr - 1);

                    if ($modifierPtr !== false) {
                        $isStatic = false;
                        $hasReadonly = false;
                        $readonlyPtr = null;
                        $hasType = false;

                        // readonly と static と型をチェック
                        $checkPtr = $modifierPtr;
                        $typeTokens = $this->getTypeTokens();
                        while ($checkPtr < $ptr) {
                            if ($tokens[$checkPtr]['code'] === T_STATIC) {
                                $isStatic = true;
                            }
                            if ($tokens[$checkPtr]['code'] === T_READONLY) {
                                $hasReadonly = true;
                                $readonlyPtr = $checkPtr;
                            }
                            // 型ヒントをチェック
                            if (in_array($tokens[$checkPtr]['code'], $typeTokens, true)) {
                                $hasType = true;
                            }
                            $checkPtr++;
                        }

                        // アクセス修飾子の前もチェック
                        $beforePtr = $modifierPtr - 1;
                        while ($beforePtr > $scopeOpener) {
                            if ($tokens[$beforePtr]['code'] === T_WHITESPACE || $tokens[$beforePtr]['code'] === T_COMMENT || $tokens[$beforePtr]['code'] === T_DOC_COMMENT) {
                                $beforePtr--;
                                continue;
                            }
                            if ($tokens[$beforePtr]['code'] === T_READONLY) {
                                $hasReadonly = true;
                                $readonlyPtr = $beforePtr;
                            }
                            if ($tokens[$beforePtr]['code'] === T_STATIC) {
                                $isStatic = true;
                            }
                            break;
                        }

                        $properties[] = [
                            'ptr' => $ptr,
                            'modifier_ptr' => $modifierPtr,
                            'is_static' => $isStatic,
                            'has_readonly' => $hasReadonly,
                            'readonly_ptr' => $readonlyPtr,
                            'has_type' => $hasType,
                        ];
                    }
                }
            }
            $ptr++;
        }

        return $properties;
    }

    /**
     * プロパティのreadonlyモディファイアを検索
     */
    private function findReadonlyModifier(File $phpcsFile, int $modifierPtr, int $variablePtr): ?int {
        $tokens = $phpcsFile->getTokens();

        // アクセス修飾子と変数の間をチェック
        $ptr = $modifierPtr + 1;
        while ($ptr < $variablePtr) {
            if ($tokens[$ptr]['code'] === T_READONLY) {
                return $ptr;
            }
            $ptr++;
        }

        // アクセス修飾子の前をチェック
        $ptr = $modifierPtr - 1;
        while ($ptr > 0) {
            if ($tokens[$ptr]['code'] === T_WHITESPACE || $tokens[$ptr]['code'] === T_COMMENT || $tokens[$ptr]['code'] === T_DOC_COMMENT) {
                $ptr--;
                continue;
            }
            if ($tokens[$ptr]['code'] === T_READONLY) {
                return $ptr;
            }
            break;
        }

        return null;
    }

    /**
     * プロパティが属するクラスのポインタを取得
     */
    private function findClassPointer(File $phpcsFile, int $ptr): ?int {
        $tokens = $phpcsFile->getTokens();

        // 現在の位置から親のスコープを辿ってクラスを探す
        while ($ptr > 0) {
            if (isset($tokens[$ptr]['conditions'])) {
                foreach ($tokens[$ptr]['conditions'] as $conditionPtr => $conditionCode) {
                    if ($conditionCode === T_CLASS || $conditionCode === T_ANON_CLASS) {
                        return $conditionPtr;
                    }
                }
            }
            $ptr--;
        }

        return null;
    }
}

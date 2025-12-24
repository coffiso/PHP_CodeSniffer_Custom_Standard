<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;

class RequireReadOnlyClassSniff implements Sniff {
    public function register() {
        return [T_CLASS];
    }

    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();
        
        // クラスが継承しているかどうかをチェック
        if ($this->isExtendingClass($phpcsFile, $stackPtr)) {
            // 継承しているサブクラスは全てスキップ
            return;
        }

        // クラスがreadonly classかどうかをチェック
        $isReadOnlyClass = $this->isReadOnlyClass($phpcsFile, $stackPtr);

        // クラス内の全プロパティを取得
        $properties = $this->getClassProperties($phpcsFile, $stackPtr);
        
        if (empty($properties)) {
            // プロパティがない場合は何もしない
            return;
        }

        if ($isReadOnlyClass) {
            // readonly classの場合、プロパティにreadonlyキーワードがあれば削除を指摘
            $this->checkReadOnlyClassProperties($phpcsFile, $properties);
        } else {
            // readonly classではない場合、readonly classへの昇華を指摘
            $this->suggestReadOnlyClass($phpcsFile, $stackPtr, $properties);
        }
    }

    /**
     * クラスが他のクラスを継承しているかチェック
     */
    private function isExtendingClass(File $phpcsFile, int $stackPtr): bool {
        $tokens = $phpcsFile->getTokens();
        $classPtr = $stackPtr;
        
        // クラス名の次のトークンからチェック
        $extendsPtr = $phpcsFile->findNext([T_EXTENDS, T_OPEN_CURLY_BRACKET], $stackPtr + 1);
        
        if ($extendsPtr === false || $tokens[$extendsPtr]['code'] !== T_EXTENDS) {
            return false;
        }
        
        return true;
    }

    /**
     * クラスがreadonly classかチェック
     */
    private function isReadOnlyClass(File $phpcsFile, int $stackPtr): bool {
        $tokens = $phpcsFile->getTokens();
        
        // classキーワードの前方をチェック
        for ($i = $stackPtr - 1; $i >= 0; $i--) {
            if ($tokens[$i]['code'] === T_READONLY) {
                return true;
            }
            
            // abstract, final, classキーワードなど以外が出てきたら終了
            if ($tokens[$i]['code'] !== T_WHITESPACE 
                && $tokens[$i]['code'] !== T_ABSTRACT
                && $tokens[$i]['code'] !== T_FINAL
                && $tokens[$i]['code'] !== T_READONLY
            ) {
                break;
            }
        }
        
        return false;
    }

    /**
     * クラス内の全プロパティを取得
     */
    private function getClassProperties(File $phpcsFile, int $stackPtr): array {
        $tokens = $phpcsFile->getTokens();
        
        if (!isset($tokens[$stackPtr]['scope_opener']) || !isset($tokens[$stackPtr]['scope_closer'])) {
            return [];
        }
        
        $scopeOpener = $tokens[$stackPtr]['scope_opener'];
        $scopeCloser = $tokens[$stackPtr]['scope_closer'];
        
        $properties = [];
        $ptr = $scopeOpener + 1;
        
        while ($ptr < $scopeCloser) {
            // アクセス修飾子を探す
            if (in_array($tokens[$ptr]['code'], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $propertyInfo = $this->analyzeProperty($phpcsFile, $ptr);
                if ($propertyInfo !== null) {
                    $properties[] = $propertyInfo;
                    // 次のプロパティへ
                    $ptr = $propertyInfo['semicolonPtr'] ?? $ptr;
                }
            }
            $ptr++;
        }
        
        return $properties;
    }

    /**
     * プロパティを分析
     */
    private function analyzeProperty(File $phpcsFile, int $visibilityPtr): ?array {
        $tokens = $phpcsFile->getTokens();
        $ptr = $visibilityPtr + 1;
        
        $hasReadOnly = false;
        $hasStatic = false;
        $readOnlyPtr = null;
        $variablePtr = null;
        
        // プロパティの宣言部分を解析
        while (isset($tokens[$ptr]['code'])) {
            $code = $tokens[$ptr]['code'];
            
            if ($code === T_READONLY) {
                $hasReadOnly = true;
                $readOnlyPtr = $ptr;
            } elseif ($code === T_STATIC) {
                $hasStatic = true;
            } elseif ($code === T_VARIABLE) {
                $variablePtr = $ptr;
                break;
            } elseif ($code === T_CONST || $code === T_FUNCTION) {
                // プロパティではない
                return null;
            }
            
            $ptr++;
        }
        
        if ($variablePtr === null) {
            return null;
        }
        
        // staticプロパティはスキップ
        if ($hasStatic) {
            return null;
        }
        
        // PropertyHelperを使用してプロパティかどうか確認
        if (PropertyHelper::isProperty(phpcsFile: $phpcsFile, variablePointer: $variablePtr, promoted: true) === false) {
            return null;
        }
        
        /**
         * 継承してプロパティをオーバーライドしており、かつ親クラスのプロパティが非readonlyな場合、サブクラス側でreadonlyを指定できないため、
         * PHPDocに`inheritDoc`アノテーションが存在する場合はオーバーライドしたプロパティとみなし、スキップする。
         */
        if (DocCommentHelper::hasInheritdocAnnotation($phpcsFile, $variablePtr) === true) {
            return null;
        }
        
        // セミコロンの位置を探す
        $semicolonPtr = $phpcsFile->findNext([T_SEMICOLON], $variablePtr);
        
        return [
            'visibilityPtr' => $visibilityPtr,
            'readOnlyPtr' => $readOnlyPtr,
            'hasReadOnly' => $hasReadOnly,
            'variablePtr' => $variablePtr,
            'variableName' => $tokens[$variablePtr]['content'],
            'semicolonPtr' => $semicolonPtr,
        ];
    }

    /**
     * readonly classのプロパティにreadonlyキーワードがあればエラー
     */
    private function checkReadOnlyClassProperties(File $phpcsFile, array $properties): void {
        foreach ($properties as $property) {
            if ($property['hasReadOnly'] && $property['readOnlyPtr'] !== null) {
                $fix = $phpcsFile->addFixableError(
                    'Property %s in readonly class should not have readonly modifier',
                    $property['readOnlyPtr'],
                    'RedundantReadOnlyModifier',
                    [$property['variableName']]
                );
                
                if ($fix === true) {
                    $phpcsFile->fixer->beginChangeset();
                    
                    // readonlyキーワードとその後の空白を削除
                    $tokens = $phpcsFile->getTokens();
                    $phpcsFile->fixer->replaceToken($property['readOnlyPtr'], '');
                    
                    // 次のトークンが空白なら削除
                    $nextPtr = $property['readOnlyPtr'] + 1;
                    if (isset($tokens[$nextPtr]) && $tokens[$nextPtr]['code'] === T_WHITESPACE) {
                        $phpcsFile->fixer->replaceToken($nextPtr, '');
                    }
                    
                    $phpcsFile->fixer->endChangeset();
                }
            }
        }
    }

    /**
     * readonly classへの昇華を提案
     */
    private function suggestReadOnlyClass(File $phpcsFile, int $stackPtr, array $properties): void {
        $readOnlyCount = 0;
        $totalCount = count($properties);
        
        foreach ($properties as $property) {
            if ($property['hasReadOnly']) {
                $readOnlyCount++;
            }
        }
        
        // 1つでもreadonlyプロパティがあれば指摘
        if ($readOnlyCount === 0) {
            return;
        }
        
        $tokens = $phpcsFile->getTokens();
        
        if ($readOnlyCount === $totalCount) {
            // 全てのプロパティがreadonlyの場合は自動修正を提供
            $fix = $phpcsFile->addFixableError(
                'All properties are readonly. Consider making this a readonly class',
                $stackPtr,
                'ShouldBeReadOnlyClass',
                []
            );
            
            if ($fix === true) {
                $phpcsFile->fixer->beginChangeset();
                
                // classキーワードの前にreadonlyを追加
                $classPtr = $stackPtr;
                
                // abstract/finalの位置を探す
                $insertPtr = $stackPtr;
                for ($i = $stackPtr - 1; $i >= 0; $i--) {
                    if (in_array($tokens[$i]['code'], [T_ABSTRACT, T_FINAL], true)) {
                        $insertPtr = $i;
                        break;
                    }
                    if ($tokens[$i]['code'] !== T_WHITESPACE) {
                        break;
                    }
                }
                
                // classキーワードの直前にreadonlyを挿入
                $phpcsFile->fixer->addContentBefore($stackPtr, 'readonly ');
                
                // 各プロパティからreadonlyキーワードを削除
                foreach ($properties as $property) {
                    if ($property['hasReadOnly'] && $property['readOnlyPtr'] !== null) {
                        $phpcsFile->fixer->replaceToken($property['readOnlyPtr'], '');
                        
                        // 次のトークンが空白なら削除
                        $nextPtr = $property['readOnlyPtr'] + 1;
                        if (isset($tokens[$nextPtr]) && $tokens[$nextPtr]['code'] === T_WHITESPACE) {
                            $phpcsFile->fixer->replaceToken($nextPtr, '');
                        }
                    }
                }
                
                $phpcsFile->fixer->endChangeset();
            }
        } else {
            // 一部のプロパティのみreadonlyの場合は警告のみ
            $phpcsFile->addWarning(
                '%d of %d properties are readonly. Consider making this a readonly class',
                $stackPtr,
                'PartialReadOnlyProperties',
                [$readOnlyCount, $totalCount]
            );
        }
    }
}

<?php
namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class PreferFunctionOverClassSniff implements Sniff
{
    public function register()
    {
        return [T_CLASS];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // class name token
        $classNamePtr = $phpcsFile->findNext(T_STRING, $stackPtr + 1, null, false);
        if ($classNamePtr === false) {
            return;
        }

        // find class open brace
        $openBrace = $tokens[$stackPtr]['scope_opener'] ?? null;
        $closeBrace = $tokens[$stackPtr]['scope_closer'] ?? null;
        if ($openBrace === null || $closeBrace === null) {
            return;
        }

        // collect class members (methods and properties)
        // Avoid counting variables that appear inside method bodies or parameter lists.
        $members = [];
        for ($i = $openBrace + 1; $i < $closeBrace; $i++) {
            if ($tokens[$i]['code'] === T_FUNCTION) {
                $members[] = ['type' => 'method', 'ptr' => $i];
                continue;
            }
            if ($tokens[$i]['code'] === T_VARIABLE) {
                // If this variable is inside a function (parameter or local), skip it.
                if (!empty($tokens[$i]['conditions']) && in_array(T_FUNCTION, $tokens[$i]['conditions'], true)) {
                    continue;
                }
                $members[] = ['type' => 'property', 'ptr' => $i];
            }
        }

        $methodCount = 0;
        $nonStaticMethodCount = 0;
        $hasConstructor = false;
        $hasProperties = false;

        // Detect if the class declaration contains extends or implements by
        // scanning between the class name and the opening brace. The token
        // array indices 'extends'/'implements' are not reliably present,
        // so explicitly look for T_EXTENDS or T_IMPLEMENTS tokens.
        $hasExtendsImplements = false;
        for ($i = $classNamePtr + 1; $i < $openBrace; $i++) {
            if (isset($tokens[$i]['code']) && (
                $tokens[$i]['code'] === T_EXTENDS || $tokens[$i]['code'] === T_IMPLEMENTS
            )) {
                $hasExtendsImplements = true;
                break;
            }
        }

        foreach ($members as $m) {
            if ($m['type'] === 'property') {
                $hasProperties = true;
                break;
            }
            if ($m['type'] === 'method') {
                $methodCount++;
                $methodPtr = $m['ptr'];
                $methodNamePtr = $phpcsFile->findNext(T_STRING, $methodPtr + 1, null, false);
                $methodName = $methodNamePtr ? $tokens[$methodNamePtr]['content'] : '';
                if ($methodName === '__construct') {
                    $hasConstructor = true;
                }

                // find method modifiers (static/public/protected/private/var)
                $modifierPtr = $phpcsFile->findPrevious([T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR], $methodPtr - 1, $openBrace);
                $isStatic = false;
                if ($modifierPtr !== false) {
                    for ($p = $modifierPtr; $p > $openBrace; $p--) {
                        if ($tokens[$p]['code'] === T_STATIC) {
                            $isStatic = true;
                            break;
                        }
                        if (in_array($tokens[$p]['code'], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT], true)) {
                            break;
                        }
                    }
                }
                if (!$isStatic) {
                    $nonStaticMethodCount++;
                }
            }
        }

        // Determine conditions for suggesting functions
        // 1) utility static-only class: has methods and all are static, no properties, no constructor
        $isStaticOnly = ($methodCount > 0 && $nonStaticMethodCount === 0 && !$hasProperties && !$hasConstructor && !$hasExtendsImplements);

        // 2) single-method class without state/constructor/extends: one method (static or instance), no properties, no constructor, no extends/implements
        $isSingleMethod = ($methodCount === 1 && !$hasProperties && !$hasConstructor && !$hasExtendsImplements);



        if ($isStaticOnly || $isSingleMethod) {
            $className = $tokens[$classNamePtr]['content'];
            $message = 'Class %s appears to be a simple utility or single-method holder; consider using a function instead of a class.';
            $phpcsFile->addWarning(sprintf($message, $className), $stackPtr, 'PreferFunctionOverClass');
        }

        // DEBUG: always add a warning to verify this sniff runs (temporary)
        // $phpcsFile->addWarning('DEBUG PreferFunctionOverClass sniff reached', $stackPtr, 'PreferFunctionOverClassDebug');
    }
}

<?php

namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Prohibits the definition of global functions without a namespace.
 *
 * All functions must be defined within a namespace. This sniff detects
 * named functions defined in the global scope and reports an error.
 *
 * Closures, arrow functions, and class/interface/trait methods are allowed
 * as they are not global functions.
 */
class ForbidGlobalFunctionSniff implements Sniff {
    /**
     * Registers the tokens that this sniff wants to listen for.
     *
     * @return array<int>
     */
    public function register() {
        return [
            T_FUNCTION,
        ];
    }

    /**
     * Processes this sniff when a matching token is found.
     *
     * @param File $phpcsFile The file being scanned.
     * @param int  $stackPtr  The position of the current token in the stack.
     *
     * @return void
     */
    public function process(File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();
        
        // Get the function name to ensure it's not a closure or anonymous function
        $functionName = $phpcsFile->getDeclarationName($stackPtr);
        if ($functionName === null) {
            // This is a closure or anonymous function, skip it
            return;
        }
        
        // Check if this function is inside a class/interface/trait
        // If it is, it's a method, not a global function
        $conditions = $tokens[$stackPtr]["conditions"];
        foreach ($conditions as $conditionPtr => $conditionCode) {
            if ($conditionCode === T_CLASS
                || $conditionCode === T_INTERFACE
                || $conditionCode === T_TRAIT
                || $conditionCode === T_ANON_CLASS
                || $conditionCode === T_ENUM
            ) {
                // This is a method, not a global function
                return;
            }
        }
        
        // Now check if the function is inside a namespace
        // Search backwards from the function to find a namespace declaration
        $namespacePtr = $phpcsFile->findPrevious(T_NAMESPACE, $stackPtr);
        
        if ($namespacePtr === false) {
            // No namespace found, this is a global function
            $phpcsFile->addError(
                "Global function '%s' is forbidden. Functions must be defined within a namespace.",
                $stackPtr,
                "ForbiddenGlobalFunction",
                [$functionName],
            );
            return;
        }
        
        // We found a namespace, but we need to make sure the function is actually inside it
        // Check if there's a namespace scope that contains this function
        $namespaceStart = $namespacePtr;
        $namespaceEnd = null;
        
        // Find the opening brace of the namespace (if it uses braces)
        if (isset($tokens[$namespacePtr]["scope_opener"]) === true) {
            $namespaceStart = $tokens[$namespacePtr]["scope_opener"];
            $namespaceEnd = $tokens[$namespacePtr]["scope_closer"];
            
            // Check if the function is within the namespace scope
            if ($stackPtr > $namespaceStart && $stackPtr < $namespaceEnd) {
                // Function is inside the namespace
                return;
            }
        } else {
            // Namespace without braces - it applies to the rest of the file
            // or until another namespace declaration
            $nextNamespace = $phpcsFile->findNext(T_NAMESPACE, ($namespacePtr + 1));
            
            if ($nextNamespace === false) {
                // No other namespace, this namespace applies to the rest of the file
                if ($stackPtr > $namespacePtr) {
                    // Function is after the namespace declaration
                    return;
                }
            } else {
                // There's another namespace, check if function is before it
                if ($stackPtr > $namespacePtr && $stackPtr < $nextNamespace) {
                    // Function is in the current namespace scope
                    return;
                }
            }
        }
        
        // If we reach here, the function is not properly within a namespace
        $phpcsFile->addError(
            "Global function '%s' is forbidden. Functions must be defined within a namespace.",
            $stackPtr,
            "ForbiddenGlobalFunction",
            [$functionName],
        );
    }
}

<?php
namespace CustomStandard\Sniffs\Functions;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class StandaloneFunctionFileNameSniff implements Sniff
{
    public function register()
    {
        return [T_FUNCTION];
    }

    public function process(File $phpcsFile, $stackPtr)
    {
        $tokens = $phpcsFile->getTokens();

        // Only consider top-level functions (not inside classes / closures)
        $conditions = $tokens[$stackPtr]['conditions'] ?? [];
        if (!empty($conditions)) {
            foreach ($conditions as $cond => $ptr) {
                $type = $tokens[$cond]['type'] ?? '';
                if ($type === 'T_CLASS' || $type === 'T_INTERFACE' || $type === 'T_TRAIT' || $type === 'T_ANON_CLASS') {
                    return;
                }
            }
        }

        // Detect function name token (skip closures)
        $namePtr = $phpcsFile->findNext(T_STRING, $stackPtr, $stackPtr + 10, false, null, true);
        if ($namePtr === false) {
            return;
        }
        $functionName = $tokens[$namePtr]['content'];

        $fileName = $phpcsFile->getFilename();
        $baseName = basename($fileName, '.php');

        // Only allow lowerCamelCase: starts with lowercase, no underscores, letters/digits
        if (!preg_match('/^[a-z][A-Za-z0-9]*$/', $functionName)) {
            $phpcsFile->addError('Function name "%s" must be lowerCamelCase (start with lowercase, no underscores).', $namePtr, 'FunctionNameCase', [$functionName]);
        }
        if (!preg_match('/^[a-z][A-Za-z0-9]*$/', $baseName)) {
            $phpcsFile->addError('File name "%s" must be lowerCamelCase (start with lowercase, no underscores).', $stackPtr, 'FileNameCase', [$baseName]);
        }

        // Case-sensitive exact match between file basename and function name
        if ($baseName !== $functionName) {
            $phpcsFile->addError('File name "%s" must exactly match the top-level function name "%s" (case-sensitive).', $stackPtr, 'FileFunctionMismatch', [$baseName, $functionName]);
        }

        // Ensure there is only one top-level function in the file. We'll scan tokens.
        $topLevelFunctions = 0;
        $numTokens = count($tokens);
        for ($i = 0; $i < $numTokens; $i++) {
            if ($tokens[$i]['code'] === T_FUNCTION) {
                $conds = $tokens[$i]['conditions'] ?? [];
                $inClassLike = false;
                if (!empty($conds)) {
                    foreach ($conds as $cond => $ptr) {
                        $type = $tokens[$cond]['type'] ?? '';
                        if ($type === 'T_CLASS' || $type === 'T_INTERFACE' || $type === 'T_TRAIT' || $type === 'T_ANON_CLASS') {
                            $inClassLike = true;
                            break;
                        }
                    }
                }
                if (!$inClassLike) {
                    // Skip closures (no name token following)
                    $name = $phpcsFile->findNext(T_STRING, $i, $i + 10, false, null, true);
                    if ($name !== false) {
                        $topLevelFunctions++;
                    }
                }
            }
        }

        if ($topLevelFunctions > 1) {
            $phpcsFile->addError('Only one top-level function definition is allowed per file; %s found.', $stackPtr, 'MultipleTopLevelFunctions', [$topLevelFunctions]);
        }

        // Skip further processing for this file (we handled checks)
        return $phpcsFile->numTokens;
    }
}

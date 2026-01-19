<?php declare(strict_types=1);

namespace CustomStandard\Sniffs\Namespaces;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

/**
 * Forbid aliased namespace imports: `use Foo\Bar as Baz;`
 */
final class ForbidAliasedUseSniff implements Sniff
{
    public function register(): array
    {
        return [T_USE];
    }

    public function process(File $phpcsFile, $stackPtr): void
    {
        $tokens = $phpcsFile->getTokens();

        // Find the semicolon ending this use statement
        $semicolon = $phpcsFile->findNext(T_SEMICOLON, $stackPtr);
        if ($semicolon === false) {
            return;
        }

        $foundAs = false;
        for ($i = $stackPtr + 1; $i < $semicolon; $i++) {
            if ($tokens[$i]['code'] === T_AS) {
                $foundAs = true;
                break;
            }
        }

        if ($foundAs) {
            $phpcsFile->addError('Aliased namespace imports (use ... as ...) are forbidden. Use partial imports instead.', $stackPtr, 'AliasedNamespaceImport');
        }
    }
}

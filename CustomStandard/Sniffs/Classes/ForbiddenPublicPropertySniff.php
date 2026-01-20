<?php

namespace CustomStandard\Sniffs\Classes;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;
use SlevomatCodingStandard\Helpers\ClassHelper;
use SlevomatCodingStandard\Helpers\DocCommentHelper;
use SlevomatCodingStandard\Helpers\PropertyHelper;
use SlevomatCodingStandard\Helpers\StringHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;

/**
 * パブリックプロパティを禁止するルール
 * {@inheritDoc}が存在する場合は無視する
 */
class ForbiddenPublicPropertySniff implements Sniff
{
    public const CODE_FORBIDDEN_PUBLIC_PROPERTY = 'ForbiddenPublicProperty';

    /** @var bool */
    public $checkPromoted = false;

    /**
     * @return array<int, (int|string)>
     */
    public function register(): array
    {
        return [T_VARIABLE];
    }

    /**
     * @param int $variablePointer
     */
    public function process(File $file, $variablePointer): void
    {
        if (!PropertyHelper::isProperty($file, $variablePointer, $this->checkPromoted)) {
            return;
        }

        // {@inheritDoc}が存在する場合は無視
        if (DocCommentHelper::hasInheritdocAnnotation($file, $variablePointer) === true) {
            return;
        }

        // skip Sniff classes, they have public properties for configuration (unfortunately)
        if ($this->isSniffClass($file, $variablePointer)) {
            return;
        }

        $scopeModifierToken = $this->getPropertyScopeModifier($file, $variablePointer);
        if ($scopeModifierToken === null) {
            return;
        }
        
        if ($scopeModifierToken['code'] === T_PROTECTED || $scopeModifierToken['code'] === T_PRIVATE) {
            return;
        }

        $errorMessage = 'Do not use public properties. Use method access instead.';
        $file->addError($errorMessage, $variablePointer, self::CODE_FORBIDDEN_PUBLIC_PROPERTY);
    }

    private function isSniffClass(File $file, int $position): bool
    {
        $classTokenPosition = ClassHelper::getClassPointer($file, $position);
        if ($classTokenPosition === null) {
            return false;
        }

        $classNameToken = ClassHelper::getName($file, $classTokenPosition);

        return StringHelper::endsWith($classNameToken, 'Sniff');
    }

    /**
     * @return array{code: int|string}|null
     */
    private function getPropertyScopeModifier(File $file, int $position): ?array
    {
        $scopeModifierPosition = TokenHelper::findPrevious($file, array_merge([T_VAR], Tokens::$scopeModifiers), $position - 1);
        if ($scopeModifierPosition === null) {
            return null;
        }

        return $file->getTokens()[$scopeModifierPosition];
    }
}

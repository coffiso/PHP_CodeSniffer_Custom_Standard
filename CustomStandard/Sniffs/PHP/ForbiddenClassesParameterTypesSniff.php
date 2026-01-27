<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use SlevomatCodingStandard\Helpers\Annotation;
use SlevomatCodingStandard\Helpers\AnnotationHelper;
use SlevomatCodingStandard\Helpers\FixerHelper;
use SlevomatCodingStandard\Helpers\FunctionHelper;
use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;
use SlevomatCodingStandard\Helpers\TypeHint;
use SlevomatCodingStandard\Sniffs\PHP\ForbiddenClassesSniff;
use function array_key_exists;
use function array_merge;
use function count;
use function in_array;
use function preg_split;
use function sprintf;
use function strlen;
use function strtolower;
use const T_CLOSURE;
use const T_FN;
use const T_FUNCTION;
use const T_VARIABLE;

/**
 * Extended ForbiddenClassesSniff that also checks parameter type hints and PHPDoc types.
 *
 * This sniff inherits all functionality from the original ForbiddenClassesSniff
 * (checking new, double colon, extends, implements, trait use) and additionally
 * checks:
 * - Function/method/closure/arrow function parameter type hints
 * - PHPDoc @param annotations
 * - PHPDoc @return annotations
 * - PHPDoc @var annotations
 *
 * Additional option:
 * - forbiddenAsTypeHintOnly: Classes that are forbidden only as type hints,
 *   but allowed for new, extends, implements, trait use, and static access (::).
 */
class ForbiddenClassesParameterTypesSniff extends ForbiddenClassesSniff
{
	public const CODE_FORBIDDEN_PARAMETER_TYPE = 'ForbiddenParameterType';
	public const CODE_FORBIDDEN_RETURN_TYPE = 'ForbiddenReturnType';
	public const CODE_FORBIDDEN_PHPDOC_PARAMETER_TYPE = 'ForbiddenPhpDocParameterType';
	public const CODE_FORBIDDEN_PHPDOC_RETURN_TYPE = 'ForbiddenPhpDocReturnType';
	public const CODE_FORBIDDEN_PHPDOC_VAR_TYPE = 'ForbiddenPhpDocVarType';

	/**
	 * Classes forbidden only as type hints (parameter types, return types, PHPDoc types).
	 * Usage with new, extends, implements, trait use, and :: is allowed.
	 *
	 * @var array<string, (string|null)>
	 */
	public array $forbiddenAsTypeHintOnly = [];

	/** @var list<string> */
	private static array $simpleTypes = [
		'int',
		'integer',
		'float',
		'double',
		'string',
		'bool',
		'boolean',
		'array',
		'callable',
		'iterable',
		'object',
		'mixed',
		'void',
		'never',
		'null',
		'false',
		'true',
		'self',
		'parent',
		'static',
		'resource',
		'scalar',
		'numeric',
		'positive-int',
		'negative-int',
		'non-positive-int',
		'non-negative-int',
		'non-zero-int',
		'int-mask',
		'int-mask-of',
		'class-string',
		'callable-string',
		'numeric-string',
		'non-empty-string',
		'non-falsy-string',
		'truthy-string',
		'literal-string',
		'list',
		'non-empty-list',
		'non-empty-array',
		'array-key',
		'key-of',
		'value-of',
	];

	/** @var array<string, (string|null)>|null */
	private ?array $normalizedForbiddenClasses = null;

	/** @var array<string, (string|null)>|null */
	private ?array $normalizedForbiddenAsTypeHintOnly = null;

	/**
	 * @return array<int, (int|string)>
	 */
	public function register(): array
	{
		$parentTokens = parent::register();

		// Add function tokens to check parameter/return types when forbiddenClasses or forbiddenAsTypeHintOnly is configured
		if (count($this->forbiddenClasses) > 0 || count($this->forbiddenAsTypeHintOnly) > 0) {
			$functionTokens = [T_FUNCTION, T_CLOSURE, T_FN, T_VARIABLE];
			foreach ($functionTokens as $token) {
				if (!in_array($token, $parentTokens, true)) {
					$parentTokens[] = $token;
				}
			}
		}

		return $parentTokens;
	}

	public function process(File $phpcsFile, int $tokenPointer): void
	{
		$tokens = $phpcsFile->getTokens();
		$tokenCode = $tokens[$tokenPointer]['code'];

		// Check if this is a function/closure/arrow function token
		if (in_array($tokenCode, [T_FUNCTION, T_CLOSURE, T_FN], true)) {
			$this->checkParameterTypeHints($phpcsFile, $tokenPointer);
			return;
		}

		// Check @var annotations on variables/properties
		if ($tokenCode === T_VARIABLE) {
			$this->checkVarAnnotation($phpcsFile, $tokenPointer);
			return;
		}

		// For other tokens, delegate to parent
		parent::process($phpcsFile, $tokenPointer);
	}

	/**
	 * Check parameter type hints for forbidden classes.
	 */
	private function checkParameterTypeHints(File $phpcsFile, int $functionPointer): void
	{
		$forbiddenClasses = $this->getAllForbiddenTypesForTypeHints();

		if (count($forbiddenClasses) === 0) {
			return;
		}

		// Check native type hints
		$parametersTypeHints = FunctionHelper::getParametersTypeHints($phpcsFile, $functionPointer);

		foreach ($parametersTypeHints as $parameterName => $typeHint) {
			if ($typeHint === null) {
				continue;
			}

			$this->checkTypeHint($phpcsFile, $typeHint, $parameterName, $forbiddenClasses);
		}

		// Check native return type hint
		$this->checkReturnTypeHint($phpcsFile, $functionPointer, $forbiddenClasses);

		// Check PHPDoc @param annotations
		$this->checkPhpDocParameterTypes($phpcsFile, $functionPointer, $forbiddenClasses);

		// Check PHPDoc @return annotations
		$this->checkPhpDocReturnType($phpcsFile, $functionPointer, $forbiddenClasses);
	}

	/**
	 * Check PHPDoc @param annotations for forbidden classes.
	 *
	 * @param array<string, (string|null)> $forbiddenClasses
	 */
	private function checkPhpDocParameterTypes(File $phpcsFile, int $functionPointer, array $forbiddenClasses): void
	{
		$paramAnnotations = FunctionHelper::getParametersAnnotations($phpcsFile, $functionPointer);

		foreach ($paramAnnotations as $annotation) {
			if ($annotation->isInvalid()) {
				continue;
			}

			$value = $annotation->getValue();

			// Skip TypelessParamTagValueNode (e.g., @param $foo description)
			if (!$value instanceof ParamTagValueNode) {
				continue;
			}

			// Get all IdentifierTypeNode from the annotation type
			$identifierNodes = AnnotationHelper::getAnnotationNodesByType($value->type, IdentifierTypeNode::class);

			foreach ($identifierNodes as $identifierNode) {
				$typeName = $identifierNode->name;

				// Skip simple/built-in types
				if ($this->isSimpleType($typeName)) {
					continue;
				}

				// Resolve to fully qualified name
				$fullyQualifiedName = NamespaceHelper::resolveClassName($phpcsFile, $typeName, $functionPointer);

				if (!array_key_exists($fullyQualifiedName, $forbiddenClasses)) {
					continue;
				}

				$alternative = $forbiddenClasses[$fullyQualifiedName];

				if ($alternative === null) {
					$phpcsFile->addError(
						sprintf('Usage of %s in PHPDoc @param is forbidden.', $fullyQualifiedName),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_PARAMETER_TYPE,
					);
				} else {
					$phpcsFile->addError(
						sprintf(
							'Usage of %s in PHPDoc @param is forbidden, use %s instead.',
							$fullyQualifiedName,
							$alternative,
						),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_PARAMETER_TYPE,
					);
				}
			}
		}
	}

	/**
	 * Check PHPDoc @return annotations for forbidden classes.
	 *
	 * @param array<string, (string|null)> $forbiddenClasses
	 */
	private function checkPhpDocReturnType(File $phpcsFile, int $functionPointer, array $forbiddenClasses): void
	{
		$returnAnnotations = AnnotationHelper::getAnnotations($phpcsFile, $functionPointer, '@return');

		foreach ($returnAnnotations as $annotation) {
			if ($annotation->isInvalid()) {
				continue;
			}

			$value = $annotation->getValue();

			if (!$value instanceof ReturnTagValueNode) {
				continue;
			}

			// Get all IdentifierTypeNode from the annotation type
			$identifierNodes = AnnotationHelper::getAnnotationNodesByType($value->type, IdentifierTypeNode::class);

			foreach ($identifierNodes as $identifierNode) {
				$typeName = $identifierNode->name;

				// Skip simple/built-in types
				if ($this->isSimpleType($typeName)) {
					continue;
				}

				// Resolve to fully qualified name
				$fullyQualifiedName = NamespaceHelper::resolveClassName($phpcsFile, $typeName, $functionPointer);

				if (!array_key_exists($fullyQualifiedName, $forbiddenClasses)) {
					continue;
				}

				$alternative = $forbiddenClasses[$fullyQualifiedName];

				if ($alternative === null) {
					$phpcsFile->addError(
						sprintf('Usage of %s in PHPDoc @return is forbidden.', $fullyQualifiedName),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_RETURN_TYPE,
					);
				} else {
					$phpcsFile->addError(
						sprintf(
							'Usage of %s in PHPDoc @return is forbidden, use %s instead.',
							$fullyQualifiedName,
							$alternative,
						),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_RETURN_TYPE,
					);
				}
			}
		}
	}

	/**
	 * Check PHPDoc @var annotations for forbidden classes.
	 *
	 * @param array<string, (string|null)> $forbiddenClasses
	 */
	private function checkVarAnnotation(File $phpcsFile, int $variablePointer): void
	{
		$forbiddenClasses = $this->getAllForbiddenTypesForTypeHints();

		if (count($forbiddenClasses) === 0) {
			return;
		}

		$varAnnotations = AnnotationHelper::getAnnotations($phpcsFile, $variablePointer, '@var');

		foreach ($varAnnotations as $annotation) {
			if ($annotation->isInvalid()) {
				continue;
			}

			$value = $annotation->getValue();

			if (!$value instanceof VarTagValueNode) {
				continue;
			}

			// Get all IdentifierTypeNode from the annotation type
			$identifierNodes = AnnotationHelper::getAnnotationNodesByType($value->type, IdentifierTypeNode::class);

			foreach ($identifierNodes as $identifierNode) {
				$typeName = $identifierNode->name;

				// Skip simple/built-in types
				if ($this->isSimpleType($typeName)) {
					continue;
				}

				// Resolve to fully qualified name
				$fullyQualifiedName = NamespaceHelper::resolveClassName($phpcsFile, $typeName, $variablePointer);

				if (!array_key_exists($fullyQualifiedName, $forbiddenClasses)) {
					continue;
				}

				$alternative = $forbiddenClasses[$fullyQualifiedName];

				if ($alternative === null) {
					$phpcsFile->addError(
						sprintf('Usage of %s in PHPDoc @var is forbidden.', $fullyQualifiedName),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_VAR_TYPE,
					);
				} else {
					$phpcsFile->addError(
						sprintf(
							'Usage of %s in PHPDoc @var is forbidden, use %s instead.',
							$fullyQualifiedName,
							$alternative,
						),
						$annotation->getStartPointer(),
						self::CODE_FORBIDDEN_PHPDOC_VAR_TYPE,
					);
				}
			}
		}
	}

	/**
	 * Check native return type hint for forbidden classes.
	 *
	 * @param array<string, (string|null)> $forbiddenClasses
	 */
	private function checkReturnTypeHint(File $phpcsFile, int $functionPointer, array $forbiddenClasses): void
	{
		$tokens = $phpcsFile->getTokens();
		$returnTypeHint = FunctionHelper::findReturnTypeHint($phpcsFile, $functionPointer);

		if ($returnTypeHint === null) {
			return;
		}

		$startPointer = $returnTypeHint->getStartPointer();
		$endPointer = $returnTypeHint->getEndPointer();

		for ($pointer = $startPointer; $pointer <= $endPointer; $pointer++) {
			if (!in_array($tokens[$pointer]['code'], TokenHelper::NAME_TOKEN_CODES, true)) {
				continue;
			}

			$typeName = $tokens[$pointer]['content'];

			// Skip simple/built-in types
			if ($this->isSimpleType($typeName)) {
				continue;
			}

			// Resolve to fully qualified name
			$fullyQualifiedName = NamespaceHelper::resolveClassName($phpcsFile, $typeName, $pointer);

			if (!array_key_exists($fullyQualifiedName, $forbiddenClasses)) {
				continue;
			}

			$alternative = $forbiddenClasses[$fullyQualifiedName];

			if ($alternative === null) {
				$phpcsFile->addError(
					sprintf('Usage of %s as return type is forbidden.', $fullyQualifiedName),
					$pointer,
					self::CODE_FORBIDDEN_RETURN_TYPE,
				);
			} else {
				$fix = $phpcsFile->addFixableError(
					sprintf(
						'Usage of %s as return type is forbidden, use %s instead.',
						$fullyQualifiedName,
						$alternative,
					),
					$pointer,
					self::CODE_FORBIDDEN_RETURN_TYPE,
				);

				if ($fix) {
					$phpcsFile->fixer->beginChangeset();
					FixerHelper::change($phpcsFile, $pointer, $pointer, $alternative);
					$phpcsFile->fixer->endChangeset();
				}
			}
		}
	}

	/**
	 * Check a single type hint for forbidden classes.
	 *
	 * @param array<string, (string|null)> $forbiddenClasses
	 */
	private function checkTypeHint(
		File $phpcsFile,
		TypeHint $typeHint,
		string $parameterName,
		array $forbiddenClasses
	): void {
		$tokens = $phpcsFile->getTokens();
		$typeHintString = $typeHint->getTypeHintWithoutNullabilitySymbol();

		// Split the type hint into individual types (union and intersection)
		$types = preg_split('/[|&]/', $typeHintString);

		if ($types === false) {
			return;
		}

		// Find all NAME tokens within the type hint range
		$startPointer = $typeHint->getStartPointer();
		$endPointer = $typeHint->getEndPointer();

		for ($pointer = $startPointer; $pointer <= $endPointer; $pointer++) {
			if (!in_array($tokens[$pointer]['code'], TokenHelper::NAME_TOKEN_CODES, true)) {
				continue;
			}

			$typeName = $tokens[$pointer]['content'];

			// Skip simple/built-in types
			if ($this->isSimpleType($typeName)) {
				continue;
			}

			// Resolve to fully qualified name
			$fullyQualifiedName = NamespaceHelper::resolveClassName($phpcsFile, $typeName, $pointer);

			if (!array_key_exists($fullyQualifiedName, $forbiddenClasses)) {
				continue;
			}

			$alternative = $forbiddenClasses[$fullyQualifiedName];

			if ($alternative === null) {
				$phpcsFile->addError(
					sprintf('Usage of %s as parameter type is forbidden.', $fullyQualifiedName),
					$pointer,
					self::CODE_FORBIDDEN_PARAMETER_TYPE,
				);
			} else {
				$fix = $phpcsFile->addFixableError(
					sprintf(
						'Usage of %s as parameter type is forbidden, use %s instead.',
						$fullyQualifiedName,
						$alternative,
					),
					$pointer,
					self::CODE_FORBIDDEN_PARAMETER_TYPE,
				);

				if ($fix) {
					$phpcsFile->fixer->beginChangeset();
					FixerHelper::change($phpcsFile, $pointer, $pointer, $alternative);
					$phpcsFile->fixer->endChangeset();
				}
			}
		}
	}

	/**
	 * Check if a type name is a simple/built-in type.
	 */
	private function isSimpleType(string $typeName): bool
	{
		return in_array(strtolower($typeName), self::$simpleTypes, true);
	}

	/**
	 * Get normalized forbidden classes (cached).
	 *
	 * @return array<string, (string|null)>
	 */
	private function getNormalizedForbiddenClasses(): array
	{
		if ($this->normalizedForbiddenClasses !== null) {
			return $this->normalizedForbiddenClasses;
		}

		$this->normalizedForbiddenClasses = [];

		foreach ($this->forbiddenClasses as $forbiddenClass => $alternative) {
			$normalizedForbidden = $this->normalizeClassName((string) $forbiddenClass);
			$normalizedAlternative = $this->normalizeClassName($alternative);

			if ($normalizedForbidden !== null) {
				$this->normalizedForbiddenClasses[$normalizedForbidden] = $normalizedAlternative;
			}
		}

		return $this->normalizedForbiddenClasses;
	}

	/**
	 * Get normalized forbiddenAsTypeHintOnly classes (cached).
	 *
	 * @return array<string, (string|null)>
	 */
	private function getNormalizedForbiddenAsTypeHintOnly(): array
	{
		if ($this->normalizedForbiddenAsTypeHintOnly !== null) {
			return $this->normalizedForbiddenAsTypeHintOnly;
		}

		$this->normalizedForbiddenAsTypeHintOnly = [];

		foreach ($this->forbiddenAsTypeHintOnly as $forbiddenClass => $alternative) {
			$normalizedForbidden = $this->normalizeClassName((string) $forbiddenClass);
			$normalizedAlternative = $this->normalizeClassName($alternative);

			if ($normalizedForbidden !== null) {
				$this->normalizedForbiddenAsTypeHintOnly[$normalizedForbidden] = $normalizedAlternative;
			}
		}

		return $this->normalizedForbiddenAsTypeHintOnly;
	}

	/**
	 * Get all forbidden types for type hint checking.
	 * Merges forbiddenClasses and forbiddenAsTypeHintOnly.
	 *
	 * @return array<string, (string|null)>
	 */
	private function getAllForbiddenTypesForTypeHints(): array
	{
		return array_merge(
			$this->getNormalizedForbiddenClasses(),
			$this->getNormalizedForbiddenAsTypeHintOnly()
		);
	}

	/**
	 * Normalize a class name to fully qualified form.
	 */
	private function normalizeClassName(?string $typeName): ?string
	{
		if ($typeName === null || strlen($typeName) === 0 || strtolower($typeName) === 'null') {
			return null;
		}

		return NamespaceHelper::getFullyQualifiedTypeName($typeName);
	}
}

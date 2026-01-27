<?php declare(strict_types = 1);

namespace CustomStandard\Sniffs\PHP;

use PHP_CodeSniffer\Files\File;
use SlevomatCodingStandard\Helpers\FixerHelper;
use SlevomatCodingStandard\Helpers\FunctionHelper;
use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;
use SlevomatCodingStandard\Helpers\TypeHint;
use SlevomatCodingStandard\Sniffs\PHP\ForbiddenClassesSniff;
use function array_key_exists;
use function count;
use function in_array;
use function preg_split;
use function sprintf;
use function strlen;
use function strtolower;
use const T_CLOSURE;
use const T_FN;
use const T_FUNCTION;

/**
 * Extended ForbiddenClassesSniff that also checks parameter type hints.
 *
 * This sniff inherits all functionality from the original ForbiddenClassesSniff
 * (checking new, double colon, extends, implements, trait use) and additionally
 * checks function/method/closure/arrow function parameter type hints.
 */
class ForbiddenClassesParameterTypesSniff extends ForbiddenClassesSniff
{
	public const CODE_FORBIDDEN_PARAMETER_TYPE = 'ForbiddenParameterType';

	/** @var list<string> */
	private static array $simpleTypes = [
		'int',
		'float',
		'string',
		'bool',
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
	];

	/** @var array<string, (string|null)>|null */
	private ?array $normalizedForbiddenClasses = null;

	/**
	 * @return array<int, (int|string)>
	 */
	public function register(): array
	{
		$parentTokens = parent::register();

		// Always add function tokens to check parameter types when forbiddenClasses is configured
		if (count($this->forbiddenClasses) > 0) {
			$functionTokens = [T_FUNCTION, T_CLOSURE, T_FN];
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

		// For other tokens, delegate to parent
		parent::process($phpcsFile, $tokenPointer);
	}

	/**
	 * Check parameter type hints for forbidden classes.
	 */
	private function checkParameterTypeHints(File $phpcsFile, int $functionPointer): void
	{
		$parametersTypeHints = FunctionHelper::getParametersTypeHints($phpcsFile, $functionPointer);

		if (count($parametersTypeHints) === 0) {
			return;
		}

		$forbiddenClasses = $this->getNormalizedForbiddenClasses();

		if (count($forbiddenClasses) === 0) {
			return;
		}

		foreach ($parametersTypeHints as $parameterName => $typeHint) {
			if ($typeHint === null) {
				continue;
			}

			$this->checkTypeHint($phpcsFile, $typeHint, $parameterName, $forbiddenClasses);
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

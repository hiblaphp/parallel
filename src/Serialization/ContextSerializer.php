<?php

namespace Hibla\Parallel\Serialization;

/**
 * Handles serialization of context data
 */
class ContextSerializer
{
    private bool $advancedSerializationAvailable;

    public function __construct()
    {
        $this->advancedSerializationAvailable = class_exists('Opis\\Closure\\SerializableClosure');
    }

    /**
     * Serialize context data to PHP code
     *
     * @param array $context The context data to serialize
     * @return string PHP code that recreates the context
     * @throws SerializationException If serialization fails
     */
    public function serialize(array $context): string
    {
        if (empty($context)) {
            return '[]';
        }

        try {
            $exported = var_export($context, true);

            $this->validateSyntax($exported);

            return $exported;
        } catch (\Throwable $e) {
            return $this->fallbackSerialization($context);
        }
    }

    /**
     * Validate that exported PHP code is syntactically correct
     *
     * @param string $phpCode The PHP code to validate
     * @throws SerializationException If syntax is invalid
     */
    private function validateSyntax(string $phpCode): void
    {
        // Method 1: Try to evaluate the code in a safe way
        try {
            $testVar = null;

            // Use eval to test syntax (safe because we're only testing var_export output)
            $evalResult = @eval("\$testVar = {$phpCode}; return true;");

            if ($evalResult === false) {
                throw new SerializationException('Generated PHP code has syntax errors');
            }

            // Additional validation: ensure the result is the same type
            if (!is_array($testVar)) {
                throw new SerializationException('Generated PHP code does not produce expected array type');
            }
        } catch (\ParseError $e) {
            throw new SerializationException('PHP syntax error in generated code: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // If eval fails for any other reason, try alternative validation
            $this->alternativeValidation($phpCode);
        }
    }

    /**
     * Alternative validation method using token parsing
     *
     * @param string $phpCode The PHP code to validate
     * @throws SerializationException If validation fails
     */
    private function alternativeValidation(string $phpCode): void
    {
        // Method 2: Use token_get_all to validate syntax
        $fullCode = "<?php \$test = {$phpCode};";

        try {
            $tokens = token_get_all($fullCode);

            // Check for syntax errors by looking for T_INVALID tokens
            foreach ($tokens as $token) {
                if (is_array($token) && isset($token[0]) && $token[0] === T_BAD_CHARACTER) {
                    throw new SerializationException('Invalid characters in generated PHP code');
                }
            }

            // Additional check: ensure we have proper array syntax
            if (!$this->hasValidArrayStructure($phpCode)) {
                throw new SerializationException('Generated code does not have valid array structure');
            }
        } catch (\Throwable $e) {
            // If token parsing fails, do basic string validation
            $this->basicSyntaxValidation($phpCode);
        }
    }

    /**
     * Check if the code has valid array structure
     *
     * @param string $phpCode The PHP code to check
     * @return bool True if structure appears valid
     */
    private function hasValidArrayStructure(string $phpCode): bool
    {
        // Basic structural checks for var_export array output
        $trimmed = trim($phpCode);

        // Should start with 'array' keyword or '[' for short array syntax
        if (!preg_match('/^(array\s*\(|\[)/', $trimmed)) {
            return false;
        }

        // Should end with closing parenthesis or bracket
        if (!preg_match('/(\)|\])$/', $trimmed)) {
            return false;
        }

        // Count opening and closing brackets/parentheses
        $openParens = substr_count($phpCode, '(');
        $closeParens = substr_count($phpCode, ')');
        $openBrackets = substr_count($phpCode, '[');
        $closeBrackets = substr_count($phpCode, ']');

        return ($openParens === $closeParens) && ($openBrackets === $closeBrackets);
    }

    /**
     * Basic syntax validation as last resort
     *
     * @param string $phpCode The PHP code to validate
     * @throws SerializationException If basic validation fails
     */
    private function basicSyntaxValidation(string $phpCode): void
    {
        // Very basic checks for obviously invalid syntax
        $invalidPatterns = [
            '/[^\x20-\x7E\t\n\r]/', // Non-printable characters (except tabs/newlines)
            '/\$[^a-zA-Z_]/',        // Invalid variable names
            '/\bfunction\s*\(/',     // Embedded functions (security risk)
            '/\beval\s*\(/',         // eval calls (security risk)
            '/\bexec\s*\(/',         // exec calls (security risk)
        ];

        foreach ($invalidPatterns as $pattern) {
            if (preg_match($pattern, $phpCode)) {
                throw new SerializationException('Generated PHP code contains potentially unsafe content');
            }
        }

        // Check for balanced quotes
        $singleQuotes = substr_count($phpCode, "'") - substr_count($phpCode, "\\'");
        $doubleQuotes = substr_count($phpCode, '"') - substr_count($phpCode, '\\"');

        if ($singleQuotes % 2 !== 0 || $doubleQuotes % 2 !== 0) {
            throw new SerializationException('Unbalanced quotes in generated PHP code');
        }
    }

    /**
     * Fallback serialization using serialize/unserialize
     *
     * @param array $context The context to serialize
     * @return string PHP code using unserialize
     * @throws SerializationException If fallback also fails
     */
    private function fallbackSerialization(array $context): string
    {
        if (!$this->advancedSerializationAvailable) {
            throw new SerializationException('Context contains complex data that requires opis/closure');
        }

        try {
            $serialized = \Opis\Closure\serialize($context);
            return sprintf('\\Opis\\Closure\\unserialize(%s)', var_export($serialized, true));
        } catch (\Throwable $e) {
            throw new SerializationException('Failed to serialize context data: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Check if context can be serialized
     *
     * @param array $context The context to check
     * @return bool True if context can be serialized
     */
    public function canSerialize(array $context): bool
    {
        try {
            $this->serialize($context);
            return true;
        } catch (SerializationException $e) {
            return false;
        }
    }

    /**
     * Safe test of context serialization without throwing exceptions
     *
     * @param array $context The context to test
     * @return array Test result with details
     */
    public function testSerialization(array $context): array
    {
        $result = [
            'success' => false,
            'method' => null,
            'size' => 0,
            'errors' => [],
        ];

        try {
            // Test var_export method
            $exported = var_export($context, true);
            $this->validateSyntax($exported);

            $result['success'] = true;
            $result['method'] = 'var_export';
            $result['size'] = strlen($exported);
        } catch (\Throwable $e) {
            $result['errors'][] = 'var_export failed: ' . $e->getMessage();

            // Test fallback method
            try {
                $fallback = $this->fallbackSerialization($context);
                $result['success'] = true;
                $result['method'] = 'serialize/unserialize';
                $result['size'] = strlen($fallback);
            } catch (\Throwable $e2) {
                $result['errors'][] = 'fallback failed: ' . $e2->getMessage();
            }
        }

        return $result;
    }
}

<?php

namespace Hibla\Parallel\Serialization;

/**
 * Fallback closure serializer using source code extraction
 */
class FallbackClosureSerializer implements CallbackSerializerInterface
{
    public function canSerialize(callable $callback): bool
    {
        return $callback instanceof \Closure;
    }

    public function serialize(callable $callback): string
    {
        if (!$this->canSerialize($callback)) {
            throw new SerializationException('Not a closure');
        }

        $source = $this->extractClosureSource($callback);
        
        if ($source === null) {
            throw new SerializationException('Could not extract closure source code');
        }

        return $source;
    }

    private function extractClosureSource(\Closure $closure): ?string
    {
        try {
            $reflection = new \ReflectionFunction($closure);
            $filename = $reflection->getFileName();
            $startLine = $reflection->getStartLine();
            $endLine = $reflection->getEndLine();
            
            if (!$filename || !$startLine || !$endLine) {
                return null;
            }
            
            $source = file($filename);
            if ($source === false) {
                return null;
            }
            
            $closureLines = array_slice($source, $startLine - 1, $endLine - $startLine + 1);
            $closureSource = implode('', $closureLines);
            
            $patterns = [
                '/function\s*\([^)]*\)\s*use\s*\([^)]*\)\s*{.*}/s',
                '/function\s*\([^)]*\)\s*{.*}/s'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $closureSource, $matches)) {
                    return $matches[0];
                }
            }
            
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getPriority(): int
    {
        return 10; // Lowest priority - fallback only
    }
}
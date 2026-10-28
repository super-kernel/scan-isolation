<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Fixer\FixerInterface;
use PhpCsFixer\Fixer\Import\FullyQualifiedStrictTypesFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;

/** @return array<int, array{classIndex: int, usesTraits: bool}> Constructor token indexes and their owning classes. */
$constructors = static function (Tokens $tokens): array {
    $constructors = [];

    foreach ($tokens as $classIndex => $token) {
        if (!$token->isClassy()) {
            continue;
        }

        $openBrace = $tokens->getNextTokenOfKind($classIndex, [
            '(',
            '{',
        ]);

        if (null === $openBrace) {
            continue;
        }

        if ($tokens[$openBrace]->equals('(')) {
            $argumentsEnd = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openBrace);
            $openBrace = $tokens->getNextTokenOfKind($argumentsEnd, [
                '{',
            ]);
        }

        if (null === $openBrace) {
            continue;
        }

        $closeBrace = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $openBrace);
        $methods = [];
        $usesTraits = false;

        for ($index = $openBrace + 1; $index < $closeBrace; ++$index) {
            if ($tokens[$index]->isGivenKind(CT::T_USE_TRAIT)) {
                $usesTraits = true;
            }

            if ($tokens[$index]->isGivenKind(T_FUNCTION)) {
                $nameIndex = $tokens->getNextMeaningfulToken($index);

                if (null !== $nameIndex && '__construct' === strtolower($tokens[$nameIndex]->getContent())) {
                    $methods[] = $index;
                }
            }

            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);
            }
        }

        foreach ($methods as $index) {
            $constructors[$index] = [
                'classIndex' => $classIndex,
                'usesTraits' => $usesTraits,
            ];
        }
    }

    ksort($constructors);

    return $constructors;
};

$indentation = static function (Tokens $tokens, int $index): string {
    while (null !== ($index = $tokens->getPrevTokenOfKind($index, [
            [
                T_WHITESPACE,
            ],
        ]))) {
        $content = $tokens[$index]->getContent();
        $lineBreak = strrpos($content, "\n");

        if (false !== $lineBreak) {
            return substr($content, $lineBreak + 1);
        }

        if (0 < $index && $tokens[$index - 1]->isGivenKind([
                T_OPEN_TAG,
                T_COMMENT,
            ]) && str_ends_with($tokens[$index - 1]->getContent(), "\n")) {
            return $content;
        }
    }

    return '';
};

$fullyQualifiedStrictTypesFixer = new readonly class() implements WhitespacesAwareFixerInterface {
    private FullyQualifiedStrictTypesFixer $fixer;

    public function __construct()
    {
        $this->fixer = new FullyQualifiedStrictTypesFixer();
        $this->fixer->configure([
            'import_symbols' => true,
        ]);
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->fixer->setWhitespacesConfig($config);
    }

    public function getName(): string
    {
        return 'Wheakerd/fully_qualified_strict_types';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Import class names normally, preserving explicit class qualifiers in array expressions.',
            [
                new CodeSample(
                    '<?php namespace App; $types = [\Vendor\Item::class]; $item = new \Vendor\Item();',
                ),
            ],
        );
    }

    public function getPriority(): int
    {
        return $this->fixer->getPriority();
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $this->fixer->isCandidate($tokens);
    }

    public function isRisky(): bool
    {
        return $this->fixer->isRisky();
    }

    public function supports(SplFileInfo $file): bool
    {
        return $this->fixer->supports($file);
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        $prefix = '__SuperKernelArrayClass';
        $code = $tokens->generateCode();
        while (str_contains($code, $prefix)) {
            $prefix .= '_';
        }

        /** @var array<int, array{end: int, depth: int}> $arrays */
        $arrays = [];
        /** @var array<int, array{end: int, name: string, tokens: list<Token>}> $names */
        $names = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            while ([] !== $arrays && $arrays[array_key_last($arrays)]['end'] < $index) {
                array_pop($arrays);
            }

            if ($token->equals('{')) {
                ++$depth;
                continue;
            }
            if ($token->equals('}')) {
                --$depth;
                continue;
            }

            if ($token->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
                $arrays[] = [
                    'end'   => $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $index),
                    'depth' => $depth,
                ];
            } elseif ($token->isGivenKind(T_ARRAY)) {
                $open = $tokens->getNextMeaningfulToken($index);
                if (null !== $open && $tokens[$open]->equals('(')) {
                    $arrays[] = [
                        'end'   => $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open),
                        'depth' => $depth,
                    ];
                }
            }

            if ([] === $arrays || $arrays[array_key_last($arrays)]['depth'] !== $depth) {
                continue;
            }

            if ($token->isGivenKind(T_DOUBLE_COLON)) {
                $end = $tokens->getPrevMeaningfulToken($index);
                if (null === $end) {
                    continue;
                }
                $start = $end;
                while (0 < $start && $tokens[$start - 1]->isGivenKind([
                        T_STRING,
                        T_NS_SEPARATOR,
                    ])) {
                    --$start;
                }
            } elseif ($token->isGivenKind([
                T_NEW,
                T_INSTANCEOF,
            ])) {
                $start = $tokens->getNextMeaningfulToken($index);
                if (null === $start) {
                    continue;
                }
                $end = $start;
                while (isset($tokens[$end + 1]) && $tokens[$end + 1]->isGivenKind([
                        T_STRING,
                        T_NS_SEPARATOR,
                    ])) {
                    ++$end;
                }
            } else {
                continue;
            }

            if (!$tokens[$start]->isGivenKind([
                T_STRING,
                T_NS_SEPARATOR,
            ])) {
                continue;
            }
            $previous = $tokens->getPrevMeaningfulToken($start);
            if (null !== $previous && $tokens[$previous]->isGivenKind(CT::T_NAMESPACE_OPERATOR)) {
                continue;
            }
            if (!str_contains($tokens->generatePartialCode($start, $end), '\\')) {
                continue;
            }

            $original = [];
            for ($part = $start; $part <= $end; ++$part) {
                $original[] = $tokens[$part];
            }
            $names[$start] = [
                'end'    => $end,
                'name'   => $prefix . count($names),
                'tokens' => $original,
            ];
        }

        /** @var array<string, list<Token>> $originals */
        $originals = [];
        krsort($names);
        foreach ($names as $start => $name) {
            $originals[$name['name']] = $name['tokens'];
            $tokens->overrideRange($start, $name['end'], [
                new Token([
                    T_STRING,
                    $name['name'],
                ]),
            ]);
        }

        try {
            $this->fixer->fix($file, $tokens);
        } finally {
            for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
                $token = $tokens[$index];
                if ($token->isGivenKind(T_STRING) && isset($originals[$token->getContent()])) {
                    $tokens->overrideRange($index, $index, $originals[$token->getContent()]);
                }
            }
        }
    }
};

$namedFunctionBraceFixer = new class() implements WhitespacesAwareFixerInterface {
    public function __construct(private WhitespacesFixerConfig $whitespacesConfig = new WhitespacesFixerConfig())
    {
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Wheakerd/named_function_brace';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Named functions and methods must place their opening brace on the line after the signature.',
            [
                new CodeSample("<?php\nfunction example(\n    string \$value,\n) {\n}\n"),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_FUNCTION) && $tokens->isTokenKindFound('{');
    }

    public function getPriority(): int
    {
        return -10;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (0 === $tokens->count() || !$this->isCandidate($tokens) || !$this->supports($file)) {
            return;
        }

        for ($index = count($tokens) - 1; 0 <= $index; --$index) {
            if (!$tokens[$index]->isGivenKind(T_FUNCTION)) {
                continue;
            }

            $nameIndex = $tokens->getNextMeaningfulToken($index);

            if (null !== $nameIndex && $tokens[$nameIndex]->isGivenKind(CT::T_RETURN_REF)) {
                $nameIndex = $tokens->getNextMeaningfulToken($nameIndex);
            }

            if (null === $nameIndex || !$tokens[$nameIndex]->isGivenKind(T_STRING)) {
                continue;
            }

            $openBraceIndex = $tokens->getNextTokenOfKind($index, [
                '{',
                ';',
            ]);

            if (null === $openBraceIndex || !$tokens[$openBraceIndex]->equals('{')) {
                continue;
            }

            $tokens->ensureWhitespaceAtIndex(
                $openBraceIndex - 1,
                1,
                $this->whitespacesConfig->getLineEnding() . $this->indentation($tokens, $index),
            );
        }
    }

    private function indentation(Tokens $tokens, int $functionIndex): string
    {
        for ($index = $functionIndex - 1; 0 <= $index; --$index) {
            if (!$tokens[$index]->isWhitespace()) {
                continue;
            }

            if (1 === preg_match('/\R([\t ]*)\z/', $tokens[$index]->getContent(), $matches)) {
                return $matches[1];
            }
        }

        return '';
    }
};

$callableLayoutFixer = new class($constructors, $indentation) implements WhitespacesAwareFixerInterface {
    private const int MAX_LINE_LENGTH = 120;

    /**
     * @param Closure(Tokens): array<int, array{classIndex: int, usesTraits: bool}> $constructors
     * @param Closure(Tokens, int): string                                          $indentation
     */
    public function __construct(
        private readonly Closure       $constructors,
        private readonly Closure       $indentation,
        private WhitespacesFixerConfig $whitespacesConfig = new WhitespacesFixerConfig(),
    )
    {
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Wheakerd/callable_layout';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Constructors use compact signatures for at most three parameters within 120 columns; '
            . 'longer callable declarations and calls are fully multiline.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        final class Example
                        {
                            public function __construct(string $first, string $second, string $third, string $fourth)
                            {
                            }
                        }

                        PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound('(');
    }

    public function getPriority(): int
    {
        return 31;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (0 === $tokens->count() || !$this->isCandidate($tokens) || !$this->supports($file)) {
            return;
        }

        $this->fixConstructors($tokens);

        for ($index = 1; $index < $tokens->count(); ++$index) {
            if (!$tokens[$index]->equals('(') || !$this->isCallableParenthesis($tokens, $index)) {
                continue;
            }

            $closeParenthesis = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $index);

            if (
                $tokens->getNextMeaningfulToken($index) === $closeParenthesis
                || $this->isDefaultMatchArmThrow($tokens, $index)
                || $this->hasCommentOrLineBreak($tokens, $index, $closeParenthesis)
                || self::MAX_LINE_LENGTH >= $this->lineLength($tokens, $index)
            ) {
                continue;
            }

            $this->makeMultiline($tokens, $index, $closeParenthesis);
        }
    }

    private function fixConstructors(Tokens $tokens): void
    {
        foreach (array_reverse(array_keys(($this->constructors)($tokens))) as $functionIndex) {
            $nameIndex = $tokens->getNextMeaningfulToken($functionIndex);
            $openParenthesis = $tokens->getNextTokenOfKind($nameIndex, [
                '(',
            ]);
            $closeParenthesis = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openParenthesis);
            $parameterCount = $this->parameterCount($tokens, $openParenthesis, $closeParenthesis);

            if (0 === $parameterCount) {
                continue;
            }

            if (
                3 >= $parameterCount
                && !$this->hasComment($tokens, $openParenthesis, $closeParenthesis)
                && self::MAX_LINE_LENGTH >= $this->singleLineLength($tokens, $openParenthesis, $closeParenthesis)
            ) {
                $this->makeSingleLine($tokens, $openParenthesis, $closeParenthesis);

                continue;
            }

            $this->makeMultiline($tokens, $openParenthesis, $closeParenthesis);
        }
    }

    private function parameterCount(Tokens $tokens, int $openParenthesis, int $closeParenthesis): int
    {
        if ($tokens->getNextMeaningfulToken($openParenthesis) === $closeParenthesis) {
            return 0;
        }

        $count = 1;

        for ($index = $openParenthesis + 1; $index < $closeParenthesis; ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);

                continue;
            }

            if ($tokens[$index]->equals(',') && $tokens->getNextMeaningfulToken($index) !== $closeParenthesis) {
                ++$count;
            }
        }

        return $count;
    }

    private function isCallableParenthesis(Tokens $tokens, int $openParenthesis): bool
    {
        $previous = $tokens[$tokens->getPrevMeaningfulToken($openParenthesis)];

        return $previous->isGivenKind([
                T_STRING,
                T_VARIABLE,
                T_NAME_FULLY_QUALIFIED,
                T_NAME_QUALIFIED,
                T_NAME_RELATIVE,
                T_FUNCTION,
                T_FN,
                T_CLASS,
            ]) || $previous->equalsAny([
                ')',
                ']',
                [
                    CT::T_ARRAY_BRACKET_CLOSE,
                ],
            ]);
    }

    private function isDefaultMatchArmThrow(Tokens $tokens, int $openParenthesis): bool
    {
        $hasThrow = false;

        for ($index = $openParenthesis - 1; 0 <= $index; --$index) {
            if ($tokens[$index]->isWhitespace() && str_contains($tokens[$index]->getContent(), "\n")) {
                return false;
            }

            if ($tokens[$index]->isGivenKind(T_THROW)) {
                $hasThrow = true;

                continue;
            }

            if ($tokens[$index]->isGivenKind(T_DOUBLE_ARROW)) {
                $previous = $tokens->getPrevMeaningfulToken($index);

                return $hasThrow && $tokens[$previous]->isGivenKind(T_DEFAULT);
            }
        }

        return false;
    }

    private function hasComment(Tokens $tokens, int $openParenthesis, int $closeParenthesis): bool
    {
        for ($index = $openParenthesis + 1; $index < $closeParenthesis; ++$index) {
            if ($tokens[$index]->isComment()) {
                return true;
            }
        }

        return false;
    }

    private function hasCommentOrLineBreak(Tokens $tokens, int $openParenthesis, int $closeParenthesis): bool
    {
        for ($index = $openParenthesis + 1; $index < $closeParenthesis; ++$index) {
            if (
                $tokens[$index]->isComment()
                || ($tokens[$index]->isWhitespace() && str_contains($tokens[$index]->getContent(), "\n"))
            ) {
                return true;
            }
        }

        return false;
    }

    private function singleLineLength(Tokens $tokens, int $openParenthesis, int $closeParenthesis): int
    {
        $length = 0;

        for ($index = $openParenthesis - 1; 0 <= $index; --$index) {
            $content = $tokens[$index]->getContent();
            $lineBreak = strrpos($content, "\n");
            $length += false === $lineBreak ? strlen($content) : strlen(substr($content, $lineBreak + 1));

            if (false !== $lineBreak) {
                break;
            }
        }

        $signature = '';

        for ($index = $openParenthesis; $index <= $closeParenthesis; ++$index) {
            $signature .= $tokens[$index]->getContent();
        }

        return $length + strlen((string)preg_replace('/\s+/', ' ', $signature));
    }

    private function lineLength(Tokens $tokens, int $origin): int
    {
        $length = 0;

        for ($index = $origin; 0 <= $index; --$index) {
            $content = $tokens[$index]->getContent();
            $lineBreak = strrpos($content, "\n");
            $length += false === $lineBreak ? strlen($content) : strlen(substr($content, $lineBreak + 1));

            if (false !== $lineBreak) {
                break;
            }
        }

        for ($index = $origin + 1; $index < $tokens->count(); ++$index) {
            $content = $tokens[$index]->getContent();
            $lineBreak = strpos($content, "\n");
            $length += false === $lineBreak ? strlen($content) : strlen(substr($content, 0, $lineBreak));

            if (false !== $lineBreak) {
                break;
            }
        }

        return $length;
    }

    private function makeSingleLine(Tokens $tokens, int $openParenthesis, int $closeParenthesis): void
    {
        $lastMeaningful = $tokens->getPrevMeaningfulToken($closeParenthesis);

        if ($tokens[$lastMeaningful]->equals(',')) {
            $tokens->clearAt($lastMeaningful);
        }

        for ($index = $closeParenthesis - 1; $index > $openParenthesis; --$index) {
            if (!$tokens[$index]->isWhitespace() || !str_contains($tokens[$index]->getContent(), "\n")) {
                continue;
            }

            $tokens[$index] = new Token([
                T_WHITESPACE,
                ' ',
            ]);
        }

        if ($tokens[$openParenthesis + 1]->isWhitespace()) {
            $tokens->clearAt($openParenthesis + 1);
        }

        if ($tokens[$closeParenthesis - 1]->isWhitespace()) {
            $tokens->clearAt($closeParenthesis - 1);
        }
    }

    private function makeMultiline(Tokens $tokens, int $openParenthesis, int $closeParenthesis): void
    {
        $indent = ($this->indentation)($tokens, $openParenthesis);
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        $tokens->ensureWhitespaceAtIndex($closeParenthesis - 1, 1, $lineEnding . $indent);

        for ($index = $closeParenthesis - 1; $index > $openParenthesis; --$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && !$block['isStart']) {
                $index = $tokens->findBlockStart($block['type'], $index);

                continue;
            }

            if ($tokens[$index]->equals(',')) {
                $tokens->ensureWhitespaceAtIndex(
                    $index + 1,
                    0,
                    $lineEnding . $indent . $this->whitespacesConfig->getIndent(),
                );
            }
        }

        $tokens->ensureWhitespaceAtIndex(
            $openParenthesis + 1,
            0,
            $lineEnding . $indent . $this->whitespacesConfig->getIndent(),
        );
    }
};

$parameterAlignmentFixer = new class() implements FixerInterface {
    public function getName(): string
    {
        return 'Wheakerd/parameter_alignment';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Align parameter variable names in fully multiline callable declarations.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        final class Example
                        {
                            public function __construct(
                                private readonly LongType $first,
                                protected string $second,
                                public $third,
                                OtherType &$fourth,
                            )
                            {
                            }
                        }

                        PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([
            T_FUNCTION,
            T_FN,
        ]);
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        // Align after imports, type normalization, callable layout, and indentation.
        return -100;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; 0 <= $index; --$index) {
            if (!$tokens[$index]->isGivenKind([
                T_FUNCTION,
                T_FN,
            ])) {
                continue;
            }

            $open = $tokens->getNextMeaningfulToken($index);
            if (null !== $open && $tokens[$open]->isGivenKind(CT::T_RETURN_REF)) {
                $open = $tokens->getNextMeaningfulToken($open);
            }
            if (null !== $open && $tokens[$open]->isGivenKind(T_STRING)) {
                $open = $tokens->getNextMeaningfulToken($open);
            }
            if (null === $open || !$tokens[$open]->equals('(')) {
                continue;
            }

            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $open);
            $gaps = $this->parameterGaps($tokens, $open, $close);
            if (2 > count($gaps)) {
                continue;
            }

            $column = max(array_column($gaps, 'column'));
            foreach (array_reverse($gaps) as $gap) {
                $tokens->ensureWhitespaceAtIndex(
                    $gap['index'],
                    1,
                    $gap['prefix'] . str_repeat(' ', $column - $gap['base']),
                );
            }
        }
    }

    /** @return list<array{index: int, prefix: string, base: int, column: int}> */
    private function parameterGaps(Tokens $tokens, int $open, int $close): array
    {
        $gaps = [];
        $start = $open + 1;
        $hasVariable = false;

        for ($index = $start; $index < $close; ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);
            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);

                continue;
            }

            if ($tokens[$index]->equals(',')) {
                $start = $index + 1;
                $hasVariable = false;

                continue;
            }

            if ($hasVariable || !$tokens[$index]->isGivenKind(T_VARIABLE)) {
                continue;
            }

            if ($start === $index || !str_contains($tokens->generatePartialCode($start, $index - 1), "\n")) {
                return [];
            }

            $hasVariable = true;
            $variableStart = $index;
            while ($tokens[$variableStart - 1]->isGivenKind([
                T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                T_ELLIPSIS,
            ])) {
                --$variableStart;
            }

            $gapIndex = $variableStart - 1;
            $whitespace = $tokens[$gapIndex]->isWhitespace() ? $tokens[$gapIndex]->getContent() : '';
            $lineBreak = strrpos($whitespace, "\n");
            $prefix = false === $lineBreak ? '' : substr($whitespace, 0, $lineBreak + 1);
            $width = strlen($whitespace) - strlen($prefix);
            $base = $this->column($tokens, $index) - $width;
            $gaps[] = [
                'index'  => $gapIndex,
                'prefix' => $prefix,
                'base'   => $base,
                'column' => $base + (false === $lineBreak ? 1 : $width),
            ];
        }

        return $gaps;
    }

    private function column(Tokens $tokens, int $index): int
    {
        $column = 0;
        for (--$index; 0 <= $index; --$index) {
            $content = $tokens[$index]->getContent();
            $lineBreak = strrpos($content, "\n");
            if (false !== $lineBreak) {
                return $column + strlen($content) - $lineBreak - 1;
            }
            $column += strlen($content);
        }

        return $column;
    }
};

$multilineArrayFixer = new class($indentation) implements WhitespacesAwareFixerInterface {
    /** @param Closure(Tokens, int): string $indentation */
    public function __construct(
        private readonly Closure       $indentation,
        private WhitespacesFixerConfig $whitespacesConfig = new WhitespacesFixerConfig(),
    )
    {
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespacesConfig = $config;
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Wheakerd/multiline_array';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Every non-empty array must be multiline with one element per line.',
            [
                new CodeSample("<?php\n\$values = [1, 2];\n"),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(CT::T_ARRAY_BRACKET_OPEN);
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (0 === $tokens->count() || !$this->isCandidate($tokens) || !$this->supports($file)) {
            return;
        }

        for ($index = 0; $index < $tokens->count(); ++$index) {
            if (!$tokens[$index]->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
                continue;
            }

            $closeBracket = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $index);

            if ($tokens->getNextMeaningfulToken($index) === $closeBracket) {
                continue;
            }

            if ($this->isFullyMultiline($tokens, $index, $closeBracket)) {
                continue;
            }

            $this->makeMultiline($tokens, $index, $closeBracket);
        }
    }

    private function isFullyMultiline(Tokens $tokens, int $openBracket, int $closeBracket): bool
    {
        if (
            !$tokens[$openBracket + 1]->isWhitespace()
            || !str_contains($tokens[$openBracket + 1]->getContent(), "\n")
            || !$tokens[$closeBracket - 1]->isWhitespace()
            || !str_contains($tokens[$closeBracket - 1]->getContent(), "\n")
        ) {
            return false;
        }

        for ($index = $closeBracket - 1; $index > $openBracket; --$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && !$block['isStart']) {
                $index = $tokens->findBlockStart($block['type'], $index);

                continue;
            }

            if (
                $tokens[$index]->equals(',')
                && (!$tokens[$index + 1]->isWhitespace() || !str_contains($tokens[$index + 1]->getContent(), "\n"))
            ) {
                return false;
            }
        }

        return true;
    }

    private function makeMultiline(Tokens $tokens, int $openBracket, int $closeBracket): void
    {
        $indent = ($this->indentation)($tokens, $openBracket);
        $elementIndent = $indent . $this->whitespacesConfig->getIndent();
        $lineEnding = $this->whitespacesConfig->getLineEnding();

        $tokens->ensureWhitespaceAtIndex($closeBracket - 1, 1, $lineEnding . $indent);

        for ($index = $closeBracket - 1; $index > $openBracket; --$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && !$block['isStart']) {
                $index = $tokens->findBlockStart($block['type'], $index);

                continue;
            }

            if ($tokens[$index]->equals(',')) {
                $tokens->ensureWhitespaceAtIndex($index + 1, 0, $lineEnding . $elementIndent);
            }
        }

        $tokens->ensureWhitespaceAtIndex($openBracket + 1, 0, $lineEnding . $elementIndent);
    }
};

$noEmptyPublicConstructorFixer = new readonly class($constructors) implements FixerInterface {
    /** @param Closure(Tokens): array<int, array{classIndex: int, usesTraits: bool}> $constructors */
    public function __construct(private Closure $constructors)
    {
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Wheakerd/no_empty_public_constructor';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Final classes without a parent or imported trait must use implicit public construction.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        final class Example
                        {
                            public function __construct()
                            {
                            }
                        }

                        PHP,
                ),
            ],
            null,
            'Risky when an interface or reflection consumer requires the explicit constructor declaration.',
        );
    }

    public function getPriority(): int
    {
        return 40;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(T_CLASS)
            && $tokens->isTokenKindFound(T_FINAL)
            && $tokens->isTokenKindFound(T_FUNCTION);
    }

    public function isRisky(): bool
    {
        return true;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        if (0 === $tokens->count() || !$this->isCandidate($tokens) || !$this->supports($file)) {
            return;
        }

        foreach (array_reverse(($this->constructors)($tokens), true) as $functionIndex => $constructor) {
            $classIndex = $constructor['classIndex'];

            if (
                !$tokens[$classIndex]->isGivenKind(T_CLASS)
                || $constructor['usesTraits']
                || !$this->isFinal($tokens, $classIndex)
                || $this->hasParent($tokens, $classIndex)
            ) {
                continue;
            }

            $nameIndex = $tokens->getNextMeaningfulToken($functionIndex);
            $startIndex = $this->methodStart($tokens, $functionIndex);

            if ([] !== array_filter($tokens->findGivenKind([
                    T_PRIVATE,
                    T_PROTECTED,
                ], $startIndex, $functionIndex))) {
                continue;
            }

            if ($this->hasAttachedMetadata($tokens, $startIndex)) {
                continue;
            }

            $openParenthesis = $tokens->getNextTokenOfKind($nameIndex, [
                '(',
            ]);
            $closeParenthesis = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openParenthesis);

            if (!$this->hasOnlyWhitespace($tokens, $openParenthesis + 1, $closeParenthesis - 1)) {
                continue;
            }

            $openBrace = $tokens->getNextMeaningfulToken($closeParenthesis);

            if (!$tokens[$openBrace]->equals('{')) {
                continue;
            }

            $closeBrace = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_BRACE, $openBrace);

            if (!$this->hasOnlyWhitespace($tokens, $openBrace + 1, $closeBrace - 1)) {
                continue;
            }

            if ($tokens[$startIndex - 1]->isWhitespace()) {
                --$startIndex;
            }

            $tokens->clearRange($startIndex, $closeBrace);
        }
    }

    private function isFinal(Tokens $tokens, int $classIndex): bool
    {
        while (null !== ($classIndex = $tokens->getPrevMeaningfulToken($classIndex))) {
            if ($tokens[$classIndex]->isGivenKind(T_FINAL)) {
                return true;
            }

            if (!$tokens[$classIndex]->isGivenKind([
                T_ABSTRACT,
                T_READONLY,
            ])) {
                break;
            }
        }

        return false;
    }

    private function hasParent(Tokens $tokens, int $classIndex): bool
    {
        $declarationToken = $tokens->getNextTokenOfKind($classIndex, [
            '{',
            [
                T_EXTENDS,
            ],
        ]);

        return $tokens[$declarationToken]->isGivenKind(T_EXTENDS);
    }

    private function methodStart(Tokens $tokens, int $functionIndex): int
    {
        $startIndex = $functionIndex;
        $modifierKinds = [
            T_ABSTRACT,
            T_FINAL,
            T_PRIVATE,
            T_PROTECTED,
            T_PUBLIC,
            T_STATIC,
        ];
        $previousIndex = $tokens->getPrevMeaningfulToken($startIndex);

        while ($tokens[$previousIndex]->isGivenKind($modifierKinds)) {
            $startIndex = $previousIndex;
            $previousIndex = $tokens->getPrevMeaningfulToken($startIndex);
        }

        return $startIndex;
    }

    private function hasAttachedMetadata(Tokens $tokens, int $startIndex): bool
    {
        for ($index = $startIndex - 1; 0 <= $index; --$index) {
            if ($tokens->isEmptyAt($index) || $tokens[$index]->isWhitespace()) {
                continue;
            }

            return $tokens[$index]->isComment() || $tokens[$index]->isGivenKind(CT::T_ATTRIBUTE_CLOSE);
        }

        return false;
    }

    private function hasOnlyWhitespace(Tokens $tokens, int $startIndex, int $endIndex): bool
    {
        for ($index = $startIndex; $index <= $endIndex; ++$index) {
            if (!$tokens->isEmptyAt($index) && !$tokens[$index]->isWhitespace()) {
                return false;
            }
        }

        return true;
    }
};

$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([
        __FILE__,
    ]);

return new Config()
    ->registerCustomFixers([
        $fullyQualifiedStrictTypesFixer,
        $callableLayoutFixer,
        $parameterAlignmentFixer,
        $multilineArrayFixer,
        $namedFunctionBraceFixer,
        $noEmptyPublicConstructorFixer,
    ])
    ->setRiskyAllowed(true)
    ->setIndent('    ')
    ->setRules([
        '@PHP8x4Migration'        => true,
        '@PSR12'                  => true,
        'align_multiline_comment' => true,
        'array_syntax'            => [
            'syntax' => 'short',
        ],
        'binary_operator_spaces' => [
            'default'   => 'at_least_single_space',
            'operators' => [
                '='  => 'single_space',
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'blank_line_between_import_groups' => true,
        'class_definition'                 => [
            'inline_constructor_arguments' => false,
            'space_before_parenthesis'     => false,
        ],
        'declare_strict_types'         => true,
        'fully_qualified_strict_types' => false,
        'function_declaration'         => [
            'closure_fn_spacing'       => 'none',
            'closure_function_spacing' => 'one',
        ],
        'global_namespace_import' => [
            'import_classes'   => null,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'indentation_type'           => true,
        'new_expression_parentheses' => [
            'use_parentheses' => false,
        ],
        'native_constant_invocation' => [
            'scope'  => 'namespaced',
            'strict' => true,
        ],
        'native_function_invocation' => [
            'include' => [
                '@all',
            ],
            'scope'  => 'namespaced',
            'strict' => true,
        ],
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_phpdoc'             => true,
        'no_superfluous_phpdoc_tags'  => [
            'allow_hidden_params' => false,
            'allow_mixed'         => true,
            'allow_unused_params' => false,
            'remove_inheritdoc'   => false,
        ],
        'no_unused_imports'         => true,
        'nullable_type_declaration' => [
            'syntax' => 'question_mark',
        ],
        'ordered_imports' => [
            'imports_order' => [
                'class',
                'function',
                'const',
            ],
            'sort_algorithm' => 'alpha',
        ],
        'ordered_types' => [
            'null_adjustment' => 'always_last',
            'sort_algorithm'  => 'none',
        ],
        'phpdoc_align'                  => true,
        'phpdoc_annotation_without_dot' => true,
        'phpdoc_indent'                 => true,
        'phpdoc_no_access'              => true,
        'phpdoc_no_alias_tag'           => true,
        'phpdoc_no_duplicate_types'     => true,
        'phpdoc_no_empty_return'        => true,
        'phpdoc_no_package'             => true,
        'phpdoc_order'                  => [
            'order' => [
                'param',
                'return',
                'throws',
            ],
        ],
        'phpdoc_return_self_reference'                  => true,
        'phpdoc_scalar'                                 => true,
        'phpdoc_separation'                             => true,
        'phpdoc_single_line_var_spacing'                => true,
        'phpdoc_summary'                                => true,
        'phpdoc_tag_casing'                             => true,
        'phpdoc_tag_type'                               => true,
        'phpdoc_to_comment'                             => true,
        'phpdoc_trim'                                   => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types'                                  => true,
        'phpdoc_types_order'                            => [
            'null_adjustment' => 'always_last',
            'sort_algorithm'  => 'none',
        ],
        'phpdoc_var_annotation_correct_order'   => true,
        'phpdoc_var_without_name'               => true,
        'return_type_declaration'               => true,
        'single_import_per_statement'           => true,
        'single_quote'                          => true,
        'Wheakerd/fully_qualified_strict_types' => true,
        'Wheakerd/callable_layout'              => true,
        'Wheakerd/parameter_alignment'          => true,
        'Wheakerd/multiline_array'              => true,
        'Wheakerd/named_function_brace'         => true,
        'Wheakerd/no_empty_public_constructor'  => true,
        'trailing_comma_in_multiline'           => [
            'after_heredoc' => true,
            'elements'      => [
                'arguments',
                'array_destructuring',
                'arrays',
                'match',
                'parameters',
            ],
        ],
        // Preserve vertically aligned function and property declarations while normalizing typed constants.
        'type_declaration_spaces' => [
            'elements' => [
                'constant',
            ],
        ],
        'void_return' => [
            'fix_lambda' => true,
        ],
        'yoda_style' => [
            'always_move_variable' => false,
            'equal'                => true,
            'identical'            => true,
            'less_and_greater'     => true,
        ],
    ])
    ->setFinder($finder)
    ->setUsingCache(false);

<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * InputClassifier - Deterministic classification of user input
 *
 * This class categorizes user input into actionable types for the assistant.
 * It does NOT use AI/ML - only pattern matching for predictable behavior.
 *
 * The priority order of the checks is fixed here; the patterns for each type
 * and the category keywords come from config (understanding.patterns and
 * understanding.categories). The defaults below are generic English.
 */
class InputClassifier
{
    // Input classification types
    const TYPE_VALID = 'valid';
    const TYPE_EMPTY = 'empty';
    const TYPE_GREETING = 'greeting';
    const TYPE_VAGUE = 'vague';
    const TYPE_ABUSE_MILD = 'abuse_mild';
    const TYPE_ABUSE_SEVERE = 'abuse_severe';
    const TYPE_NOISE = 'noise';
    const TYPE_ESCALATION_REQUEST = 'escalation_request';

    /**
     * Default patterns per type. Use the /u flag: input is UTF-8.
     */
    public const DEFAULT_PATTERNS = [
        self::TYPE_GREETING => [
            '/^(hi|hello|hey|hii+|helo|hlo)[\s\!\.\?]*$/iu',
            '/^(good\s*(morning|afternoon|evening|night|day))[\s\!\.\?]*$/iu',
            '/^(howdy|sup|yo|hiya)[\s\!\.\?]*$/iu',
        ],
        self::TYPE_VAGUE => [
            '/^(help|help me|need help|i need help)[\s\!\.\?]*$/iu',
            '/^(issue|problem|error|not working)[\s\!\.\?]*$/iu',
            '/^(something (is )?(wrong|broken|not working))[\s\!\.\?]*$/iu',
            '/^(it\'?s? not working)[\s\!\.\?]*$/iu',
            '/^(please help)[\s\!\.\?]*$/iu',
        ],
        self::TYPE_NOISE => [
            '/^(test|testing|123|abc|xyz|qwerty|asdf)[\s]*$/iu',
            '/^([a-z])\1{2,}$/iu', // repeated chars like 'aaaa'
            '/^[\W\d\s]+$/iu', // only symbols, numbers, whitespace
            '/^.{1,2}$/iu', // 1-2 char inputs
        ],
        self::TYPE_ABUSE_MILD => [
            '/\b(damn|crap|sucks|stupid|useless|rubbish|pathetic|worst)\b/iu',
        ],
        self::TYPE_ABUSE_SEVERE => [
            '/\b(f+u+c+k+|shit|bastard|bitch|ass+hole)\b/iu',
            '/\b(kill|murder|die|threat)\b/iu',
        ],
        // Explicit user request for human support
        self::TYPE_ESCALATION_REQUEST => [
            '/\b(talk to (a\s*)?(human|agent|person|support|executive))\b/iu',
            '/\b(call me|call back|contact me)\b/iu',
            '/\b(escalate|escalation|raise (a\s*)?complaint)\b/iu',
            '/\b(speak to (a\s*)?(manager|supervisor))\b/iu',
            '/\b(need (a\s*)?(human|real person))\b/iu',
            '/\b(this (is\s*)?(not helping|useless))\b/iu',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    protected array $patterns;

    /**
     * @param  array<string, list<string>>  $patterns  Replaces the default list of each type it names
     * @param  array<string, list<string>>  $categories  Category tag => keywords (whole words). First match wins.
     */
    public function __construct(array $patterns = [], protected array $categories = [])
    {
        $this->patterns = array_replace(self::DEFAULT_PATTERNS, $patterns);
    }

    public function classify(string $input): array
    {
        $trimmed = trim($input);

        // 1. Empty check
        if (empty($trimmed)) {
            return $this->result(self::TYPE_EMPTY, false);
        }

        // Patterns are Unicode-aware (/u) and do not match invalid UTF-8 at all
        if (!mb_check_encoding($trimmed, 'UTF-8')) {
            return $this->result(self::TYPE_NOISE, false);
        }

        // 2. Severe abuse check
        if ($this->matches($trimmed, self::TYPE_ABUSE_SEVERE)) {
            return $this->result(self::TYPE_ABUSE_SEVERE, false);
        }

        // 3. Noise check
        if ($this->matches($trimmed, self::TYPE_NOISE)) {
            return $this->result(self::TYPE_NOISE, false);
        }

        // 4. Greeting-only check
        if ($this->matches($trimmed, self::TYPE_GREETING)) {
            return $this->result(self::TYPE_GREETING, false);
        }

        // 5. Vague input check; the category says which service it is about ("refund issue")
        if ($this->matches($trimmed, self::TYPE_VAGUE)) {
            return $this->result(self::TYPE_VAGUE, false, $this->detectCategory($trimmed));
        }

        // 6. Explicit escalation request check; the category goes with the ticket
        if ($this->matches($trimmed, self::TYPE_ESCALATION_REQUEST)) {
            return $this->result(self::TYPE_ESCALATION_REQUEST, true, $this->detectCategory($trimmed), true);
        }

        // 7. Mild abuse check (process normally)
        if ($this->matches($trimmed, self::TYPE_ABUSE_MILD)) {
            return $this->result(self::TYPE_ABUSE_MILD, true);
        }

        // 8. Category tagging, 9. Valid input
        return $this->result(self::TYPE_VALID, true, $this->detectCategory($trimmed));
    }

    protected function matches(string $input, string $type): bool
    {
        foreach ($this->patterns[$type] ?? [] as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        return false;
    }

    protected function detectCategory(string $input): ?string
    {
        foreach ($this->categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                // Whole words only ("vi" must not match "device"); a plural "s" is allowed
                if (preg_match('/\b' . preg_quote($keyword, '/') . 's?\b/iu', $input)) {
                    return (string) $category;
                }
            }
        }
        return null;
    }

    protected function result(
        string $type,
        bool $shouldProcess,
        ?string $category = null,
        bool $shouldEscalate = false
    ): array {
        return [
            'type' => $type,
            'should_process' => $shouldProcess,
            'category' => $category,
            'should_escalate' => $shouldEscalate,
        ];
    }
}

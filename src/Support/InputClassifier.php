<?php

namespace Subodh\SmartAiAssistant\Support;

/**
 * InputClassifier - Deterministic classification of user input
 * 
 * This class categorizes user input into actionable types for the assistant.
 * It does NOT use AI/ML - only pattern matching for predictable behavior.
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

    // Category types for tagging
    const CAT_PAN      = 'PAN';
    const CAT_RECHARGE = 'RECHARGE';
    const CAT_AEPS     = 'AEPS';
    const CAT_PAYOUT   = 'PAYOUT';
    const CAT_KYC      = 'KYC';
    const CAT_IRCTC    = 'IRCTC';
    const CAT_INFO     = 'INFO';

    /**
     * Greeting patterns
     */
    protected array $greetingPatterns = [
        '/^(hi|hello|hey|hii+|helo|hlo|namaste|namaskar)[\s\!\.\?]*$/iu',
        '/^(good\s*(morning|afternoon|evening|night|day))[\s\!\.\?]*$/iu',
        '/^(howdy|sup|yo|hiya)[\s\!\.\?]*$/iu',
    ];

    /**
     * Vague input patterns
     */
    protected array $vaguePatterns = [
        '/^(help|help me|need help|i need help)[\s\!\.\?]*$/iu',
        '/^(issue|problem|error|not working)[\s\!\.\?]*$/iu',
        '/^(something (is )?(wrong|broken|not working))[\s\!\.\?]*$/iu',
        '/^(it\'?s? not working)[\s\!\.\?]*$/iu',
        '/^(please help)[\s\!\.\?]*$/iu',
        '/^(kuch gadbad hai|kaam nahi kar raha)[\s\!\.\?]*$/iu',
    ];

    /**
     * Noise patterns (test, random, etc)
     */
    protected array $noisePatterns = [
        '/^(test|testing|123|abc|xyz|qwerty|asdf)[\s]*$/iu',
        '/^([a-z])\1{2,}$/iu', // repeated chars like 'aaaa'
        '/^[\W\d\s]+$/iu', // only symbols, numbers, whitespace
        '/^.{1,2}$/iu', // 1-2 char inputs
    ];

    /**
     * Mild abuse patterns
     */
    protected array $mildAbusePatterns = [
        '/\b(damn|crap|sucks|stupid|useless|rubbish|pathetic|worst)\b/iu',
        '/\b(bakwas|bekaar|wahiyat|ghatiya)\b/iu',
    ];

    /**
     * Severe abuse patterns
     */
    protected array $severeAbusePatterns = [
        '/\b(f+u+c+k+|shit|bastard|bitch|ass+hole)\b/iu',
        '/\b(kill|murder|die|threat)\b/iu',
        '/\b(madarch[o0]d|bhench[o0]d|chutiya|gandu|harami|saala|kutta|kamina)\b/iu',
        '/\b(randi|hijra|chakka)\b/iu',
    ];

    /**
     * Escalation request patterns (explicit user request for human support)
     */
    protected array $escalationPatterns = [
        '/\b(talk to (a\s*)?(human|agent|person|support|executive))\b/iu',
        '/\b(call me|call back|contact me)\b/iu',
        '/\b(escalate|escalation|raise (a\s*)?complaint)\b/iu',
        '/\b(speak to (a\s*)?(manager|supervisor))\b/iu',
        '/\b(need (a\s*)?(human|real person))\b/iu',
        '/\b(this (is\s*)?(not helping|useless))\b/iu',
    ];

    /**
     * Category keywords map
     */
    protected array $categoryMap = [
        self::CAT_PAN      => ['pan', 'nsdl', 'uti', 'correction', 'pan card'],
        self::CAT_RECHARGE => ['recharge', 'topup', 'jio', 'airtel', 'vi', 'vodafone', 'dth', 'mobile'],
        self::CAT_AEPS     => ['aeps', 'withdrawal', 'balance enquiry', 'mini statement', 'fingerprint', 'biometric', 'aadhaar pay'],
        self::CAT_PAYOUT   => ['payout', 'transfer', 'imps', 'neft', 'bank', 'account', 'beneficiary'],
        self::CAT_KYC      => ['kyc', 'document', 'aadhaar', 'verification', 'upload', 'ekyc'],
        self::CAT_IRCTC    => ['irctc', 'train', 'booking', 'cancellation', 'ticket', 'railway'],
    ];

    public function classify(string $input): array
    {
        $trimmed = trim($input);
        
        // 1. Empty check
        if (empty($trimmed)) {
            return $this->result(self::TYPE_EMPTY, false, $this->getEmptyResponse());
        }

        // Patterns are Unicode-aware (/u) and do not match invalid UTF-8 at all
        if (!mb_check_encoding($trimmed, 'UTF-8')) {
            return $this->result(self::TYPE_NOISE, false, $this->getNoiseResponse());
        }

        // 2. Severe abuse check
        if ($this->matchesPatterns($trimmed, $this->severeAbusePatterns)) {
            return $this->result(self::TYPE_ABUSE_SEVERE, false, $this->getSevereAbuseResponse());
        }

        // 3. Noise check
        if ($this->matchesPatterns($trimmed, $this->noisePatterns)) {
            return $this->result(self::TYPE_NOISE, false, $this->getNoiseResponse());
        }

        // 4. Greeting-only check
        if ($this->matchesPatterns($trimmed, $this->greetingPatterns)) {
            return $this->result(self::TYPE_GREETING, false, $this->getGreetingResponse());
        }

        // 5. Vague input check
        if ($this->matchesPatterns($trimmed, $this->vaguePatterns)) {
            return $this->result(self::TYPE_VAGUE, false, $this->getVagueResponse());
        }

        // 6. Explicit escalation request check
        if ($this->matchesPatterns($trimmed, $this->escalationPatterns)) {
            return $this->result(self::TYPE_ESCALATION_REQUEST, true, null, null, true);
        }

        // 7. Mild abuse check (process normally)
        if ($this->matchesPatterns($trimmed, $this->mildAbusePatterns)) {
            return $this->result(self::TYPE_ABUSE_MILD, true, null);
        }

        // 8. Category tagging
        $category = $this->detectCategory($trimmed);

        // 9. Valid input
        return $this->result(self::TYPE_VALID, true, null, $category);
    }

    protected function matchesPatterns(string $input, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        return false;
    }

    protected function detectCategory(string $input): ?string
    {
        $lowerInput = strtolower($input);
        foreach ($this->categoryMap as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerInput, $keyword)) {
                    return $category;
                }
            }
        }
        return null;
    }

    protected function result(
        string $type, 
        bool $shouldProcess, 
        ?string $response, 
        ?string $category = null,
        bool $shouldEscalate = false
    ): array {
        return [
            'type' => $type,
            'should_process' => $shouldProcess,
            'response' => $response,
            'category' => $category,
            'should_escalate' => $shouldEscalate,
        ];
    }

    // =========================================================================
    // RESPONSE TEMPLATES (Direct, no open questions)
    // =========================================================================

    /**
     * The canned reply for a non-processable input type, or null for types
     * that go on to escalation or knowledge lookup.
     */
    public function cannedResponse(string $type): ?string
    {
        return match ($type) {
            self::TYPE_EMPTY        => $this->getEmptyResponse(),
            self::TYPE_NOISE        => $this->getNoiseResponse(),
            self::TYPE_GREETING     => $this->getGreetingResponse(),
            self::TYPE_VAGUE        => $this->getVagueResponse(),
            self::TYPE_ABUSE_SEVERE => $this->getSevereAbuseResponse(),
            default                 => null,
        };
    }

    protected function getEmptyResponse(): string
    {
        return "Please type your issue message.";
    }

    protected function getNoiseResponse(): string
    {
        return "I am ready to help. Please state your issue.";
    }

    protected function getGreetingResponse(): string
    {
        return "Hello. Please state the issue you are facing.";
    }

    protected function getVagueResponse(): string
    {
        return "Please specify the error message or the service (e.g., AEPS, PAN) you are having trouble with.";
    }

    protected function getSevereAbuseResponse(): string
    {
        return "Support is available for technical issues. Please keep the conversation respectful.";
    }
}


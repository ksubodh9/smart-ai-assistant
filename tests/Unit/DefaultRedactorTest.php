<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Support\DefaultRedactor;

class DefaultRedactorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function texts(): array
    {
        return [
            'email'                 => ['mail me at asha.k+1@example.co.in please', 'mail me at [email] please'],
            'pan upper'             => ['PAN ABCDE1234F rejected', 'PAN [pan] rejected'],
            'pan lower'             => ['pan abcde1234f rejected', 'pan [pan] rejected'],
            'aadhaar spaced'        => ['aadhaar 2345 6789 0123 not linked', 'aadhaar [aadhaar] not linked'],
            'aadhaar hyphenated'    => ['aadhaar 2345-6789-0123', 'aadhaar [aadhaar]'],
            'aadhaar plain'         => ['aadhaar 234567890123 failed', 'aadhaar [number] failed'],
            'mobile'                => ['call 9876543210', 'call [phone]'],
            'mobile with +91'       => ['call +91 98765 43210 now', 'call [phone] now'],
            'mobile with 0'         => ['call 09876543210', 'call [phone]'],
            'account number'        => ['a/c 123456789012345 debited', 'a/c [number] debited'],
            'card number'           => ['card 4111111111111111', 'card [number]'],
            'short error code kept' => ['Error 1001: RD service not running', 'Error 1001: RD service not running'],
            'amount kept'           => ['Rs 25000 deducted on 12/05/2024', 'Rs 25000 deducted on 12/05/2024'],
            'eight digits kept'     => ['txn 12345678 failed', 'txn 12345678 failed'],
            'hindi kept'            => ['पैसा कट गया 9876543210', 'पैसा कट गया [phone]'],
            'nothing to redact'     => ['capture timeout', 'capture timeout'],
            'url path id'           => ['/txn/234567890123/receipt', '/txn/[number]/receipt'],
        ];
    }

    #[DataProvider('texts')]
    public function test_it_masks_personal_data(string $input, string $expected): void
    {
        $this->assertSame($expected, (new DefaultRedactor())->redact($input));
    }

    public function test_invalid_utf8_fails_closed(): void
    {
        $this->assertSame('[unreadable]', (new DefaultRedactor())->redact("\xC3\x28 9876543210"));
    }
}

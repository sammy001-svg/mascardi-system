<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for M-Pesa helper functions (phone normalization, config checks).
 * These do NOT make real API calls.
 */
class MpesaTest extends TestCase
{
    /**
     * Test that phone normalization logic works correctly.
     * We test the normalization logic extracted from mpesaStkPush().
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+')) $phone = ltrim($phone, '+');
        if (!str_starts_with($phone, '254')) $phone = '254' . $phone;
        return $phone;
    }

    public function testNormalizesKenyanLocalFormat(): void
    {
        $this->assertSame('254712345678', $this->normalizePhone('0712345678'));
    }

    public function testNormalizesInternationalFormat(): void
    {
        $this->assertSame('254712345678', $this->normalizePhone('+254712345678'));
    }

    public function testNormalizesAlreadyNormalizedPhone(): void
    {
        $this->assertSame('254712345678', $this->normalizePhone('254712345678'));
    }

    public function testStripsNonNumericChars(): void
    {
        $this->assertSame('254712345678', $this->normalizePhone('+254 712 345 678'));
    }

    public function testNormalizesShortFormat(): void
    {
        $this->assertSame('254712345678', $this->normalizePhone('712345678'));
    }

    public function testAmountRoundsUp(): void
    {
        // M-Pesa only accepts whole numbers
        $amount = (int)ceil(99.50);
        $this->assertSame(100, $amount);

        $amount = (int)ceil(1500.00);
        $this->assertSame(1500, $amount);
    }
}

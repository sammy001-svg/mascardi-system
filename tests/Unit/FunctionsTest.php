<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for helper functions in includes/functions.php
 */
class FunctionsTest extends TestCase
{
    // ── e() — XSS sanitization ──────────────────────────────────────────────────

    public function testEscapesHtmlSpecialChars(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    public function testEscapesDoubleQuotes(): void
    {
        $this->assertSame('&quot;quoted&quot;', e('"quoted"'));
    }

    public function testEscapesSingleQuotes(): void
    {
        $this->assertSame('&#039;single&#039;', e("'single'"));
    }

    public function testEscapesAmpersands(): void
    {
        $this->assertSame('A &amp; B', e('A & B'));
    }

    public function testPassesThroughSafeString(): void
    {
        $this->assertSame('Hello World 123', e('Hello World 123'));
    }

    // ── money() — currency formatting ───────────────────────────────────────────

    public function testMoneyFormatsPositiveAmount(): void
    {
        $this->assertSame('KES 1,500.00', money(1500));
    }

    public function testMoneyFormatsZero(): void
    {
        $this->assertSame('KES 0.00', money(0));
    }

    public function testMoneyFormatsDecimal(): void
    {
        $this->assertSame('KES 99.99', money(99.99));
    }

    // ── fmtDate() — date formatting ──────────────────────────────────────────────

    public function testFormatDateReturnsFormattedString(): void
    {
        $this->assertSame('01 Jan 2024', fmtDate('2024-01-01'));
    }

    public function testFormatDateReturnsEmptyDashForNull(): void
    {
        $this->assertSame('—', fmtDate(null));
    }

    public function testFormatDateReturnsEmptyDashForEmptyString(): void
    {
        $this->assertSame('—', fmtDate(''));
    }

    // ── statusBadge() ────────────────────────────────────────────────────────────

    public function testStatusBadgeReturnsBadgeHtml(): void
    {
        $badge = statusBadge('paid');
        $this->assertStringContainsString('bg-success', $badge);
        $this->assertStringContainsString('Paid', $badge);
    }

    public function testStatusBadgeFallsBackToSecondaryForUnknown(): void
    {
        $badge = statusBadge('unknown_status');
        $this->assertStringContainsString('bg-secondary', $badge);
    }

    // ── numberToWords() ──────────────────────────────────────────────────────────

    public function testNumberToWordsSimpleAmount(): void
    {
        $result = numberToWords(1000);
        $this->assertStringContainsString('One Thousand', $result);
        $this->assertStringContainsString('Shillings', $result);
    }

    public function testNumberToWordsWithCents(): void
    {
        $result = numberToWords(100.50);
        $this->assertStringContainsString('Fifty Cents', $result);
    }

    public function testNumberToWordsZero(): void
    {
        $result = numberToWords(0);
        $this->assertStringContainsString('Zero', $result);
    }

    // ── csrfToken() / csrfField() ─────────────────────────────────────────────────

    public function testCsrfFieldContainsHiddenInput(): void
    {
        // Set a known token in the session
        $_SESSION['csrf_token'] = 'test_token_abc123';
        $field = csrfField();
        $this->assertStringContainsString('<input type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString('test_token_abc123', $field);
    }

    public function testCsrfTokenReturnsSessionToken(): void
    {
        $_SESSION['csrf_token'] = 'my_csrf_token_xyz';
        $this->assertSame('my_csrf_token_xyz', csrfToken());
    }
}

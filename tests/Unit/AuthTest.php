<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for auth helper functions in includes/auth.php
 */
class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear session auth state before each test
        unset($_SESSION['auth_user']);
    }

    // ── isLoggedIn() ─────────────────────────────────────────────────────────────

    public function testIsLoggedInReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(isLoggedIn());
    }

    public function testIsLoggedInReturnsTrueWhenSessionSet(): void
    {
        $_SESSION['auth_user'] = ['id' => 1, 'role' => 'admin'];
        $this->assertTrue(isLoggedIn());
    }

    // ── authRole() ───────────────────────────────────────────────────────────────

    public function testAuthRoleReturnsEmptyStringWhenNotLoggedIn(): void
    {
        $this->assertSame('', authRole());
    }

    public function testAuthRoleReturnsCorrectRole(): void
    {
        $_SESSION['auth_user'] = ['id' => 1, 'role' => 'workshop_manager'];
        $this->assertSame('workshop_manager', authRole());
    }

    // ── hasRole() ────────────────────────────────────────────────────────────────

    public function testAdminHasAllRoles(): void
    {
        $_SESSION['auth_user'] = ['id' => 1, 'role' => 'admin'];
        $this->assertTrue(hasRole('mechanic'));
        $this->assertTrue(hasRole('sales_person'));
        $this->assertTrue(hasRole(['admin', 'manager']));
    }

    public function testNonAdminOnlyHasTheirRole(): void
    {
        $_SESSION['auth_user'] = ['id' => 2, 'role' => 'mechanic'];
        $this->assertTrue(hasRole('mechanic'));
        $this->assertFalse(hasRole('admin'));
        $this->assertFalse(hasRole('sales_person'));
    }

    public function testHasRoleAcceptsArray(): void
    {
        $_SESSION['auth_user'] = ['id' => 3, 'role' => 'sales_officer'];
        $this->assertTrue(hasRole(['sales_person', 'sales_officer']));
        $this->assertFalse(hasRole(['admin', 'mechanic']));
    }

    // ── canAccess() ──────────────────────────────────────────────────────────────

    public function testAdminCanAccessAllModules(): void
    {
        $_SESSION['auth_user'] = ['id' => 1, 'role' => 'admin'];
        $this->assertTrue(canAccess('cars'));
        $this->assertTrue(canAccess('invoices'));
        $this->assertTrue(canAccess('settings'));
        $this->assertTrue(canAccess('users'));
    }

    public function testMechanicCanAccessOnlyAllowedModules(): void
    {
        $_SESSION['auth_user'] = ['id' => 4, 'role' => 'mechanic'];
        $this->assertTrue(canAccess('jobs'));
        $this->assertTrue(canAccess('assessments'));
        $this->assertFalse(canAccess('invoices'));
        $this->assertFalse(canAccess('clients'));
    }

    public function testSalesOfficerCanAccessSalesModules(): void
    {
        $_SESSION['auth_user'] = ['id' => 5, 'role' => 'sales_officer'];
        $this->assertTrue(canAccess('invoices'));
        $this->assertTrue(canAccess('quotations'));
        $this->assertTrue(canAccess('payments'));
        $this->assertFalse(canAccess('mechanics'));
    }

    // ── canWrite() ───────────────────────────────────────────────────────────────

    public function testWorkshopManagerCanWriteJobs(): void
    {
        $_SESSION['auth_user'] = ['id' => 6, 'role' => 'workshop_manager'];
        $this->assertTrue(canWrite('jobs'));
        $this->assertFalse(canWrite('invoices'));
    }

    public function testSalesPersonCanWriteBookings(): void
    {
        $_SESSION['auth_user'] = ['id' => 7, 'role' => 'sales_person'];
        $this->assertTrue(canWrite('service_bookings'));
        $this->assertFalse(canWrite('jobs'));
    }

    // ── canEditDelete() ──────────────────────────────────────────────────────────

    public function testOnlyAdminCanDelete(): void
    {
        $_SESSION['auth_user'] = ['id' => 1, 'role' => 'admin'];
        $this->assertTrue(canEditDelete());

        $_SESSION['auth_user'] = ['id' => 2, 'role' => 'workshop_manager'];
        $this->assertFalse(canEditDelete());
    }
}

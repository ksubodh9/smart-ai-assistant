<?php

namespace Subodh\SmartAiAssistant\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Subodh\SmartAiAssistant\Core\Data\UserContext;

class UserContextTest extends TestCase
{
    public function test_guest_is_not_authenticated(): void
    {
        $guest = UserContext::guest('hi');

        $this->assertFalse($guest->isAuthenticated());
        $this->assertNull($guest->id);
        $this->assertSame('hi', $guest->locale);
        $this->assertSame([], $guest->attributes);
    }

    public function test_user_with_an_id_is_authenticated(): void
    {
        $user = new UserContext(id: '42', displayName: 'Asha', attributes: ['role' => 'retailer'], tenantId: 't1');

        $this->assertTrue($user->isAuthenticated());
        $this->assertSame('42', $user->id);
        $this->assertSame('Asha', $user->displayName);
        $this->assertSame('en', $user->locale);
        $this->assertSame(['role' => 'retailer'], $user->attributes);
        $this->assertSame('t1', $user->tenantId);
    }
}

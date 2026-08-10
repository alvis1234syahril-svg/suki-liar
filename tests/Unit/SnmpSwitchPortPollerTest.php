<?php

namespace Tests\Unit;

use App\Services\SnmpSwitchPortPoller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SnmpSwitchPortPollerTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function healthStates(): array
    {
        return [
            'link aktif' => ['up', 'up', 'healthy'],
            'link down saat admin aktif' => ['up', 'down', 'problematic'],
            'modul tidak tersedia saat admin aktif' => ['up', 'not-present', 'problematic'],
            'lapisan bawah down' => ['up', 'lower-layer-down', 'problematic'],
            'port sengaja dimatikan' => ['down', 'down', 'disabled'],
            'status tidak lengkap' => ['unknown', 'unknown', 'unknown'],
        ];
    }

    #[DataProvider('healthStates')]
    public function test_it_classifies_port_health_without_calling_a_link_down_damaged(
        string $admin,
        string $operational,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            (new SnmpSwitchPortPoller)->classifyHealth($admin, $operational)
        );
    }
}

<?php

namespace Tests\Unit;

use App\Services\HabitAssessmentService;
use PHPUnit\Framework\TestCase;

class HabitAssessmentServiceTest extends TestCase
{
    public function test_normal_work_pattern_stays_low_even_with_standard_activity(): void
    {
        $assessment = (new HabitAssessmentService())->assess([
            'total_access' => 100,
            'work_hours_count' => 95,
            'off_hours_count' => 5,
            'total_bytes' => 100000,
            'denied_count' => 0,
            'timeout_count' => 1,
            'reset_count' => 1,
            'nonstandard_port_count' => 0,
            'active_hour_count' => 8,
            'unique_apps' => 5,
            'unique_destinations' => 10,
        ], ['traffic_per_event' => 1000, 'unique_apps' => 5, 'unique_destinations' => 10]);

        $this->assertSame('low', $assessment['level']);
        $this->assertLessThan(30, $assessment['score']);
    }

    public function test_combined_off_hours_actions_ports_and_volume_raise_habit_score(): void
    {
        $assessment = (new HabitAssessmentService())->assess([
            'total_access' => 100,
            'work_hours_count' => 20,
            'off_hours_count' => 80,
            'total_bytes' => 1600000,
            'denied_count' => 15,
            'timeout_count' => 10,
            'reset_count' => 10,
            'nonstandard_port_count' => 60,
            'active_hour_count' => 20,
            'unique_apps' => 20,
            'unique_destinations' => 60,
        ], ['traffic_per_event' => 1000, 'unique_apps' => 5, 'unique_destinations' => 10]);

        $this->assertSame('high', $assessment['level']);
        $this->assertGreaterThanOrEqual(55, $assessment['score']);
        $this->assertGreaterThan(0, $assessment['components']['attention_actions']);
        $this->assertGreaterThan(0, $assessment['components']['nonstandard_ports']);
    }

    public function test_risk_and_habit_are_kept_as_two_dimensions(): void
    {
        $service = new HabitAssessmentService();

        $this->assertSame('risk', $service->combine(['level' => 'medium'], ['level' => 'low'])['key']);
        $this->assertSame('habit', $service->combine(['level' => 'low'], ['level' => 'medium'])['key']);
        $this->assertSame('priority', $service->combine(['level' => 'high'], ['level' => 'high'])['key']);
    }
}

<?php

namespace Tests\Unit;

use App\Services\RiskAssessmentService;
use PHPUnit\Framework\TestCase;

class RiskAssessmentServiceTest extends TestCase
{
    public function test_single_risky_event_does_not_label_user_high_risk(): void
    {
        $assessment = (new RiskAssessmentService())->assess([
            'total_access' => 1,
            'high_count' => 1,
            'denied_count' => 0,
        ]);

        $this->assertSame('low', $assessment['level']);
        $this->assertLessThan(30, $assessment['score']);
        $this->assertArrayHasKey('components', $assessment);
        $this->assertEqualsWithDelta(
            $assessment['score'],
            array_sum([
                $assessment['components']['application_exposure'],
                $assessment['components']['denied_activity'],
                $assessment['components']['repetition'],
            ]),
            0.2
        );
    }

    public function test_repeated_critical_and_denied_activity_is_high_risk(): void
    {
        $assessment = (new RiskAssessmentService())->assess([
            'total_access' => 100,
            'critical_count' => 60,
            'high_count' => 20,
            'denied_count' => 30,
        ]);

        $this->assertSame('high', $assessment['level']);
        $this->assertGreaterThanOrEqual(55, $assessment['score']);
    }

    public function test_unit_score_uses_highest_risk_segment_not_every_log(): void
    {
        $service = new RiskAssessmentService();
        $unit = $service->assessUnit([
            ['score' => 70, 'level' => 'high'],
            ['score' => 10, 'level' => 'low'],
            ['score' => 5, 'level' => 'low'],
            ['score' => 0, 'level' => 'low'],
            ['score' => 0, 'level' => 'low'],
        ]);

        $this->assertSame(70.0, $unit['score']);
        $this->assertSame(1, $unit['risky_clients']);
    }
}

<?php

namespace Tests\Unit;

use App\Services\AiDataAccessSettingService;
use App\Services\SettingService;
use Tests\TestCase;

class AiDataAccessSettingServiceTest extends TestCase
{
    public function test_admin_and_gm_always_manage_ai_data_while_configured_roles_follow_setting(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('get')->willReturn(json_encode(['operator', 'cms_editor']));
        $access = new AiDataAccessSettingService($settings);

        $this->assertTrue($access->roleMayManageData('admin'));
        $this->assertTrue($access->roleMayManageData('gm'));
        $this->assertTrue($access->roleMayManageData('operator'));
        $this->assertTrue($access->roleMayManageData('cms_editor'));
        $this->assertFalse($access->roleMayManageData('manager'));
    }

    public function test_it_only_saves_supported_staff_roles(): void
    {
        $settings = $this->createMock(SettingService::class);
        $settings->method('get')->willReturn(json_encode(['operator']));
        $settings->expects($this->once())
            ->method('set')
            ->with(
                AiDataAccessSettingService::AI_DATA_ALLOWED_ROLES_KEY,
                json_encode(['operator', 'cms_editor']),
                true,
                ['type' => 'json'],
            );

        (new AiDataAccessSettingService($settings))->setRoleAllowed('cms_editor', true);
    }
}

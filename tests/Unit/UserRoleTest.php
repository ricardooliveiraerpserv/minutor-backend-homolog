<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\TestHelpers;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /** @test */
    public function user_can_be_assigned_a_role()
    {
        $user = User::factory()->create();
        $role = Role::findByName('Administrator');
        
        $user->assignRole($role);
        
        $this->assertTrue($user->hasRole('Administrator'));
        $this->assertCount(1, $user->roles);
        $this->assertEquals('Administrator', $user->roles->first()->name);
    }

    /** @test */
    public function user_can_have_multiple_roles()
    {
        $user = User::factory()->create();
        
        $user->assignRole(['Administrator', 'Project Manager']);
        
        $this->assertTrue($user->hasRole('Administrator'));
        $this->assertTrue($user->hasRole('Project Manager'));
        $this->assertCount(2, $user->roles);
    }

    /** @test */
    public function user_can_be_removed_from_role()
    {
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        
        $this->assertTrue($user->hasRole('Administrator'));
        
        $user->removeRole('Administrator');
        
        $this->assertFalse($user->hasRole('Administrator'));
        $this->assertCount(0, $user->roles);
    }

    /** @test */
    public function user_roles_can_be_synchronized()
    {
        $user = User::factory()->create();
        $user->assignRole(['Administrator', 'Project Manager']);
        
        $this->assertCount(2, $user->roles);
        
        $user->syncRoles(['Consultant']);
        
        $this->assertCount(1, $user->roles);
        $this->assertTrue($user->hasRole('Consultant'));
        $this->assertFalse($user->hasRole('Administrator'));
        $this->assertFalse($user->hasRole('Project Manager'));
    }

    /** @test */
    public function user_inherits_permissions_from_roles()
    {
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        
        // Administrator should have all permissions
        $this->assertTrue($user->can('admin.full_access'));
        $this->assertTrue($user->can('roles.create'));
        $this->assertTrue($user->can('users.view'));
        $this->assertTrue($user->can('projects.create'));
    }

    /** @test */
    public function consultant_has_limited_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('Consultant');
        
        // Consultant should have limited permissions
        $this->assertTrue($user->can('projects.view'));
        $this->assertTrue($user->can('hours.create'));
        $this->assertTrue($user->can('hours.view_own'));
        
        // But NOT admin permissions
        $this->assertFalse($user->can('admin.full_access'));
        $this->assertFalse($user->can('roles.create'));
        $this->assertFalse($user->can('projects.create'));
        $this->assertFalse($user->can('hours.view_all'));
    }

    /** @test */
    public function project_manager_has_management_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('Project Manager');
        
        // Project Manager should have management permissions
        $this->assertTrue($user->can('projects.create'));
        $this->assertTrue($user->can('projects.assign_people'));
        $this->assertTrue($user->can('hours.approve'));
        $this->assertTrue($user->can('users.view'));
        
        // But NOT admin permissions
        $this->assertFalse($user->can('admin.full_access'));
        $this->assertFalse($user->can('roles.create'));
    }

    /** @test */
    public function user_without_roles_has_no_permissions()
    {
        $user = User::factory()->create();
        
        // User without roles should have no permissions
        $this->assertFalse($user->can('admin.full_access'));
        $this->assertFalse($user->can('roles.create'));
        $this->assertFalse($user->can('projects.view'));
        $this->assertFalse($user->can('hours.create'));
    }

    /** @test */
    public function user_can_get_all_permissions()
    {
        $adminUser = User::factory()->create();
        $adminUser->assignRole('Administrator');
        
        $consultantUser = User::factory()->create();
        $consultantUser->assignRole('Consultant');
        
        $adminPermissions = $adminUser->getAllPermissions();
        $consultantPermissions = $consultantUser->getAllPermissions();
        
        // Admin should have more permissions than consultant
        $this->assertGreaterThan($consultantPermissions->count(), $adminPermissions->count());
        
        // Verify specific permission counts  
        $totalPermissions = \Spatie\Permission\Models\Permission::count();
        $this->assertEquals($totalPermissions, $adminPermissions->count()); // All permissions
        $this->assertEquals(15, $consultantPermissions->count()); // Limited permissions
    }

    /** @test */
    public function user_can_check_specific_role()
    {
        $user = User::factory()->create();
        $user->assignRole('Project Manager');
        
        $this->assertTrue($user->hasRole('Project Manager'));
        $this->assertFalse($user->hasRole('Administrator'));
        $this->assertFalse($user->hasRole('Consultant'));
    }

    /** @test */
    public function user_can_check_any_role()
    {
        $user = User::factory()->create();
        $user->assignRole(['Project Manager', 'Consultant']);
        
        $this->assertTrue($user->hasAnyRole(['Administrator', 'Project Manager']));
        $this->assertTrue($user->hasAnyRole(['Consultant']));
        $this->assertFalse($user->hasAnyRole(['Administrator']));
    }

    /** @test */
    public function user_can_check_all_roles()
    {
        $user = User::factory()->create();
        $user->assignRole(['Project Manager', 'Consultant']);
        
        $this->assertTrue($user->hasAllRoles(['Project Manager', 'Consultant']));
        $this->assertFalse($user->hasAllRoles(['Administrator', 'Project Manager', 'Consultant']));
    }

    /** @test */
    public function user_can_get_role_names()
    {
        $user = User::factory()->create();
        $user->assignRole(['Administrator', 'Project Manager']);
        
        $roleNames = $user->getRoleNames();
        
        $this->assertCount(2, $roleNames);
        $this->assertTrue($roleNames->contains('Administrator'));
        $this->assertTrue($roleNames->contains('Project Manager'));
    }

    /** @test */
    public function user_permissions_are_cached()
    {
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        
        // First call should cache permissions
        $permissions1 = $user->getAllPermissions();
        
        // Second call should use cache
        $permissions2 = $user->getAllPermissions();
        
        $this->assertEquals($permissions1->count(), $permissions2->count());
        $this->assertEquals($permissions1->pluck('name')->sort(), $permissions2->pluck('name')->sort());
    }
}

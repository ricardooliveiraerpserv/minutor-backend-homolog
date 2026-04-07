<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\TestHelpers;
use App\Http\Middleware\CheckPermissionOrAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Mockery;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase, TestHelpers;

    protected CheckPermissionOrAdmin $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->middleware = new CheckPermissionOrAdmin();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function middleware_allows_access_for_administrator()
    {
        $admin = $this->createAdminUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $admin);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'roles.create');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $responseData = $response->getData(true);
        $this->assertTrue($responseData['success']);
    }

    /** @test */
    public function middleware_allows_access_for_user_with_required_permission()
    {
        $manager = $this->createManagerUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $manager);

        // Manager has users.view permission
        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'users.view');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $responseData = $response->getData(true);
        $this->assertTrue($responseData['success']);
    }

    /** @test */
    public function middleware_denies_access_for_user_without_permission()
    {
        $consultant = $this->createConsultantUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $consultant);

        // Consultant does NOT have roles.create permission
        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'roles.create');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(403, $response->getStatusCode());
        
        $responseData = $response->getData(true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('roles.create', $responseData['required_permission']);
        $this->assertContains('Consultant', $responseData['user_roles']);
    }

    /** @test */
    public function middleware_denies_access_for_unauthenticated_user()
    {
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => null);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'roles.create');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(401, $response->getStatusCode());
        
        $responseData = $response->getData(true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Não autenticado', $responseData['message']);
    }

    /** @test */
    public function middleware_returns_detailed_error_for_permission_denied()
    {
        $consultant = $this->createConsultantUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $consultant);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'admin.full_access');

        $responseData = $response->getData(true);
        
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('admin.full_access', $responseData['message']);
        $this->assertEquals('admin.full_access', $responseData['required_permission']);
        $this->assertIsArray($responseData['user_permissions']);
        $this->assertIsArray($responseData['user_roles']);
        $this->assertContains('Consultant', $responseData['user_roles']);
    }

    /** @test */
    public function middleware_works_with_different_permissions()
    {
        $manager = $this->createManagerUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $manager);

        // Test multiple permissions that manager should have
        $allowedPermissions = ['users.view', 'projects.create', 'hours.approve'];
        
        foreach ($allowedPermissions as $permission) {
            $response = $this->middleware->handle($request, function ($req) {
                return response()->json(['success' => true]);
            }, $permission);

            $responseData = $response->getData(true);
            $this->assertTrue($responseData['success'], "Manager should have {$permission} permission");
        }

        // Test permission that manager should NOT have
        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'roles.create');

        $responseData = $response->getData(true);
        $this->assertFalse($responseData['success'], "Manager should NOT have roles.create permission");
    }

    /** @test */
    public function middleware_handles_user_without_roles()
    {
        $basicUser = $this->createBasicUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $basicUser);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'projects.view');

        $this->assertEquals(403, $response->getStatusCode());
        
        $responseData = $response->getData(true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('projects.view', $responseData['required_permission']);
        $this->assertEmpty($responseData['user_roles']);
        $this->assertEmpty($responseData['user_permissions']);
    }

    /** @test */
    public function middleware_allows_admin_for_any_permission()
    {
        $admin = $this->createAdminUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $admin);

        // Test various permissions - admin should have access to all
        $permissions = [
            'admin.full_access',
            'roles.create',
            'roles.delete',
            'permissions.create',
            'users.update',
            'projects.create',
            'hours.approve'
        ];

        foreach ($permissions as $permission) {
            $response = $this->middleware->handle($request, function ($req) {
                return response()->json(['success' => true]);
            }, $permission);

            $responseData = $response->getData(true);
            $this->assertTrue($responseData['success'], "Administrator should have access to {$permission}");
        }
    }

    /** @test */
    public function middleware_preserves_request_data()
    {
        $admin = $this->createAdminUser();
        $request = Request::create('/test', 'POST', ['test_data' => 'test_value']);
        $request->setUserResolver(fn() => $admin);

        $capturedRequest = null;
        
        $this->middleware->handle($request, function ($req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return response()->json(['success' => true]);
        }, 'roles.create');

        $this->assertNotNull($capturedRequest);
        $this->assertEquals('test_value', $capturedRequest->input('test_data'));
    }

    /** @test */
    public function middleware_works_with_case_sensitive_permissions()
    {
        $manager = $this->createManagerUser();
        $request = Request::create('/test', 'GET');
        $request->setUserResolver(fn() => $manager);

        // Test exact case match
        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'users.view');

        $responseData = $response->getData(true);
        $this->assertTrue($responseData['success']);

        // Test with different case (should fail)
        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        }, 'Users.View');

        $responseData = $response->getData(true);
        $this->assertFalse($responseData['success']);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Env;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider as BaseFortifyServiceProvider;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_screen_shares_can_register_true_when_enabled()
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/Login')
                ->where('canRegister', true)
                ->where('canResetPassword', true));
    }

    public function test_registration_defaults_by_environment_and_env_override()
    {
        $this->withEnvOverrides(['APP_ENV' => 'local', 'REGISTRATION_ENABLED' => null], function (): void {
            $fortify = require config_path('fortify.php');
            $this->assertTrue($fortify['registration_enabled']);
            $this->assertContains(Features::registration(), $fortify['features']);
        });

        $this->withEnvOverrides(['APP_ENV' => 'testing', 'REGISTRATION_ENABLED' => null], function (): void {
            $fortify = require config_path('fortify.php');
            $this->assertTrue($fortify['registration_enabled']);
            $this->assertContains(Features::registration(), $fortify['features']);
        });

        $this->withEnvOverrides(['APP_ENV' => 'production', 'REGISTRATION_ENABLED' => null], function (): void {
            $fortify = require config_path('fortify.php');
            $this->assertFalse($fortify['registration_enabled']);
            $this->assertNotContains(Features::registration(), $fortify['features']);
        });

        $this->withEnvOverrides(['APP_ENV' => 'production', 'REGISTRATION_ENABLED' => 'true'], function (): void {
            $fortify = require config_path('fortify.php');
            $this->assertTrue($fortify['registration_enabled']);
            $this->assertContains(Features::registration(), $fortify['features']);
        });
    }

    public function test_registration_routes_return_404_and_login_hides_register_link_when_disabled()
    {
        $this->withEnvOverrides(['REGISTRATION_ENABLED' => 'false'], function (): void {
            $this->reloadFortifyRoutesAndConfig();

            $this->assertFalse(Features::enabled(Features::registration()));

            $this->get('/register')->assertNotFound();

            $this->post('/register', [
                'name' => 'Bloqueado',
                'email' => 'bloqueado@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertNotFound();

            $this->assertGuest();
            $this->assertDatabaseMissing('users', ['email' => 'bloqueado@example.com']);

            $this->get('/login')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/Login')
                    ->where('canRegister', false)
                    ->where('canResetPassword', true));

            $this->get('/forgot-password')->assertOk();

            $user = User::factory()->create();
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
        });
    }

    /**
     * @param  array<string, string|null>  $overrides
     */
    private function withEnvOverrides(array $overrides, callable $callback): void
    {
        $repository = Env::getRepository();
        $previous = [];

        foreach ($overrides as $key => $value)
        {
            $previous[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];

            if ($value === null)
            {
                $repository->clear($key);
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
            }
            else
            {
                $repository->set($key, $value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        try
        {
            $callback();
        }
        finally
        {
            foreach ($previous as $key => $snapshot)
            {
                if ($snapshot['env'] === null && $snapshot['server'] === null && $snapshot['getenv'] === false)
                {
                    $repository->clear($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                    putenv($key);
                }
                else
                {
                    $restored = (string) ($snapshot['env'] ?? $snapshot['server'] ?? $snapshot['getenv']);
                    $repository->set($key, $restored);
                    $_ENV[$key] = $restored;
                    $_SERVER[$key] = $restored;
                    putenv("{$key}={$restored}");
                }
            }

            $this->reloadFortifyRoutesAndConfig();
        }
    }

    private function reloadFortifyRoutesAndConfig(): void
    {
        config([
            'app' => require config_path('app.php'),
            'fortify' => require config_path('fortify.php'),
        ]);

        $router = $this->app->make('router');
        $router->setRoutes(new RouteCollection);

        (new BaseFortifyServiceProvider($this->app))->boot();
        require base_path('routes/web.php');

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}

<?php
use App\Models\User;
use App\Models\GameConfig;
use App\Filament\Pages\GameSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Livewire\Livewire;
final class AdminRuntimeTest extends TestCase
{
    use DatabaseTransactions;
    public function createApplication()
    {
        $app=require dirname(__DIR__,2).'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();return $app;
    }
    protected function setUp(): void
    {
        parent::setUp();Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
    private function actor(int $role, string $email='operator@example.invalid'): User
    {
        return User::create(['name'=>'Evaluation account','username'=>'eval_'.bin2hex(random_bytes(5)),'email'=>$email,'password'=>bin2hex(random_bytes(24)),'account_type'=>$role,'tokens'=>0]);
    }
    public function test_database_is_mysql_and_migrations_exist(): void
    {
        $this->assertSame('mysql',config('database.default'));
        $this->assertDatabaseHas('migrations',['migration'=>'0001_01_01_000000_create_users_table']);
    }
    public function test_guest_cannot_open_panel(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk();
    }
    public function test_non_admin_is_denied(): void
    {
        $this->actingAs($this->actor(0))->get('/admin')->assertForbidden();
    }
    public function test_old_email_exemption_does_not_grant_admin(): void
    {
        $u=$this->actor(0,'admin@ninjasage.test');
        $this->assertFalse($u->canAccessPanel(Filament::getPanel('admin')));
        $this->actingAs($u)->get('/admin')->assertForbidden();
    }
    public function test_admin_can_open_real_dashboard_and_operations(): void
    {
        $this->actingAs($this->actor(99));
        $this->get('/admin')->assertOk();
        $this->get('/admin/nsx-operations')->assertOk()->assertSee('Ninja Lost');
    }
    public function test_config_save_survives_component_reload(): void
    {
        $this->actingAs($this->actor(99));
        Livewire::test(GameSettings::class)->set('special_deals_visible',true)->call('save')->assertHasNoErrors();
        $this->assertTrue(GameConfig::get('game_settings')['special_deals_visible']);
        Livewire::test(GameSettings::class)->assertSet('special_deals_visible',true);
    }
    public function test_real_login_component_authenticates_admin(): void
    {
        $u=$this->actor(99);$password=bin2hex(random_bytes(24));$u->password=$password;$u->save();
        Livewire::test(Filament\Auth\Pages\Login::class)->fillForm(['email'=>$u->email,'password'=>$password])->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($u);
    }
    public function test_game_routes_are_not_published(): void
    {
        foreach(['/amf','/gateway.php','/clan/auth/login','/crew/auth/login'] as $path){$this->post($path)->assertNotFound();}
    }
}

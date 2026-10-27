<?php
namespace App\Console\Commands;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
class CreateEvaluationAdmin extends Command
{
    protected $signature='ninja-lost:create-admin';
    protected $description='Create a NEW admin in the isolated evaluation database';
    public function handle(): int
    {
        if (!app()->environment('local') || config('database.connections.'.config('database.default').'.database') !== 'ninja_lost_eval') {
            $this->error('Only APP_ENV=local and database ninja_lost_eval are allowed.');return self::FAILURE;
        }
        $input=['name'=>$this->ask('Name'),'username'=>$this->ask('New username'),'email'=>$this->ask('New email'),'password'=>$this->secret('New password (12+ characters)'),'password_confirmation'=>$this->secret('Confirm password')];
        $v=Validator::make($input,['name'=>'required|string|max:100','username'=>'required|alpha_dash|max:32|unique:users,username','email'=>'required|email|unique:users,email','password'=>'required|string|min:12|max:72|confirmed']);
        if($v->fails()){$this->error($v->errors()->first());return self::FAILURE;}
        unset($input['password_confirmation']);$input['account_type']=User::TYPE_ADMIN;$input['tokens']=0;
        User::create($input);$this->info('New admin created. No password was printed.');return self::SUCCESS;
    }
}

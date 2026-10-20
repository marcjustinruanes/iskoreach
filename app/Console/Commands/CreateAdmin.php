<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\InputRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name= : Administrator name} {--email= : New administrator email}';

    protected $description = 'Create a new local administrator without exposing a password in command arguments';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! $this->input->isInteractive()) {
            $this->error('Administrator provisioning requires an interactive local terminal.');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => $this->option('email') ?: $this->ask('Email'),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $data = InputRules::normalize($data, ['name', 'email'], ['email']);

        $validator = Validator::make($data, [
            'name' => InputRules::name(),
            'email' => InputRules::email(unique: true),
            'password' => InputRules::password(12),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($data): void {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->role = UserRole::Admin;
            $user->save();
            $user->activityLogs()->create([
                'action' => 'admin.created_locally',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'occurred_at' => now(),
            ]);
        });

        $this->info('Administrator created. Sign in through the regular login page.');

        return self::SUCCESS;
    }
}

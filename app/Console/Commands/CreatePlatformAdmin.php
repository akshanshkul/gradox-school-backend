<?php

namespace App\Console\Commands;

use App\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin
                            {--name= : Display name}
                            {--email= : Email address}
                            {--password= : Password (min 8 chars)}
                            {--role=owner : Role (owner|staff)}';

    protected $description = 'Create a new SaaS platform admin (the owner of the multi-school platform).';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->option('password') ?: $this->secret('Password (min 8 chars)');
        $role = $this->option('role') ?: 'owner';

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ], [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:platform_admins,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:owner,staff',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $admin = PlatformAdmin::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $role,
            'status' => 'active',
        ]);

        $this->info("Platform admin created: #{$admin->id} <{$admin->email}> ({$admin->role})");
        return self::SUCCESS;
    }
}

<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\DemoFurnitureArt;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

Artisan::command('marketplace:create-admin {email} {--name=Administrator}', function (string $email) {
    $email = mb_strtolower($email);
    $password = $this->secret('Password (min 12 characters, letters, numbers and symbols)');

    $validator = Validator::make(['email' => $email, 'password' => $password], [
        'email' => ['required', 'email'],
        'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $user = User::firstOrNew(['email' => $email]);
    if ($user->exists && ! $this->confirm("{$email} already exists as {$user->role->value}. Make it an administrator and reset its password?")) {
        return 1;
    }

    $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password]);
    $user->role = Role::Admin;
    $user->save();

    AuditLog::create(['action' => 'admin.created_via_cli', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id]);

    $this->info("Administrator {$email} is ready.");

    return 0;
})->purpose('Create or promote a marketplace administrator');

Artisan::command('marketplace:demo-pictures', function () {
    // Only pictures the demo seeder generated (products/{id}/demo-*.jpg); seller photos are never touched.
    $images = ProductImage::query()
        ->where('path', 'like', 'products/%/demo-%')
        ->where('path', 'not like', 'products/%/'.DemoSeeder::ART_PREFIX.'%')
        ->with('product:id,name,color')
        ->orderBy('id')
        ->get();

    $art = new DemoFurnitureArt;
    $redrawn = 0;
    foreach ($images as $image) {
        if (! $image->product || ! preg_match('#^products/\d+/demo-[A-Za-z0-9]+\.jpg$#', $image->path)) {
            continue;
        }
        $new = "products/{$image->product_id}/".DemoSeeder::ART_PREFIX.Str::random(8).'.jpg';
        Storage::disk('public')->put($new, $art->jpeg($image->product->name, $image->product->color, $image->product_id));
        $old = $image->path;
        $image->forceFill(['path' => $new])->save();
        Storage::disk('public')->delete($old);
        $redrawn++;
    }

    $this->info($redrawn ? "Redrew {$redrawn} demo ".Str::plural('picture', $redrawn).'.' : 'Demo pictures are up to date.');

    return 0;
})->purpose('Replace the old plain demo pictures with drawn furniture (demo data only)');

Artisan::command('marketplace:expire-payments', function (PaymentService $payments) {
    $counts = $payments->expireDue();
    $this->info("Expired {$counts['expired']} unpaid checkout(s); {$counts['paid']} turned out paid; {$counts['skipped']} waiting for the payment provider.");
})->purpose('Cancel online orders whose payment time ran out and release their stock');

// Needs the scheduler running in production: a cron entry for `php artisan schedule:run` every minute.
Schedule::command('marketplace:expire-payments')->everyFiveMinutes()->withoutOverlapping();

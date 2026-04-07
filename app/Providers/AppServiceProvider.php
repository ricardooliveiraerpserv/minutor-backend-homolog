<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use App\Listeners\EmailSentListener;
use App\Models\Project;
use App\Observers\ProjectObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Registrar listeners de email para debug
        Event::listen(MessageSending::class, EmailSentListener::class);
        Event::listen(MessageSent::class, EmailSentListener::class);

        // Registrar observers
        Project::observe(ProjectObserver::class);
    }
}

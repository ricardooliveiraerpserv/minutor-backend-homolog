<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use App\Events\ContractEventCreated;
use App\Events\OperationalFeedCreated;
use App\Listeners\ContractEventListener;
use App\Listeners\EmailSentListener;
use App\Listeners\RouteFeedToInbox;
use App\Models\Contract;
use App\Models\HourContribution;
use App\Models\Project;
use App\Models\Timesheet;
use App\Observers\ContractObserver;
use App\Observers\HourContributionObserver;
use App\Observers\ProjectObserver;
use App\Policies\ProjectPolicy;
use App\Policies\TimesheetPolicy;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Empresa ativa do request (multi-empresa) — 1 instância por request,
        // compartilhada entre o middleware e o global scope BelongsToCompany.
        $this->app->scoped(\App\Services\CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale('pt_BR');

        // Registrar listeners de email para debug
        Event::listen(MessageSending::class, EmailSentListener::class);
        Event::listen(MessageSent::class, EmailSentListener::class);

        // Snapshot em tempo real — reage à criação de eventos de contrato
        Event::listen(ContractEventCreated::class, ContractEventListener::class);

        // Operational Feed → Inbox: cada feed dispara NotificationEngine que entrega
        // mensagens nas conversations `bot` dos usuários conforme `bot_notification_rules`.
        Event::listen(OperationalFeedCreated::class, RouteFeedToInbox::class);

        // Registrar observers
        Project::observe(ProjectObserver::class);
        Contract::observe(ContractObserver::class);
        HourContribution::observe(HourContributionObserver::class);
        \App\Models\FollowUp::observe(\App\Observers\FollowUpObserver::class);

        // Registrar Policies
        Gate::policy(Timesheet::class, TimesheetPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);

        // Rate limiter do login keyed por e-mail+IP (throttle:login). Atrás do duplo proxy
        // (Cloudflare/Render), request->ip() colapsa no gateway; chavear por e-mail isola por conta.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email !== '' ? $email . '|' . $request->ip() : $request->ip()),
            ];
        });
    }
}

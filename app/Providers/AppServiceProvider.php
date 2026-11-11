<?php

namespace App\Providers;

use App\Http\Controllers\Api\ControleurMotDePasse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

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
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(
            \App\Models\PersonalAccessToken::class
        );

        // Le lien de réinitialisation ouvre le site public, en français.
        ResetPassword::toMailUsing(function ($utilisateur, string $jeton) {
            return (new MailMessage)
                ->subject('DOTO : choisissez votre mot de passe')
                ->greeting('Bonjour,')
                ->line('Une demande de mot de passe a été faite pour votre compte DOTO.')
                ->action('Choisir mon mot de passe', ControleurMotDePasse::lien($utilisateur->getEmailForPasswordReset(), $jeton))
                ->line('Ce lien est valable 60 minutes. Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.')
                ->salutation('L’équipe DOTO');
        });
    }
}

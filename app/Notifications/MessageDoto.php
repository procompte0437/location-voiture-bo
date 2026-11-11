<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail DOTO générique : un objet, quelques lignes, un bouton facultatif.
 * Chaque événement compose le sien dans App\Services\Notificateur.
 */
class MessageDoto extends Notification
{
    /**
     * @param  list<string>  $lignes
     */
    public function __construct(
        public readonly string $evenement,
        public readonly string $objet,
        public readonly array $lignes,
        public readonly ?string $bouton = null,
        public readonly ?string $lien = null,
    ) {}

    public function via(object $destinataire): array
    {
        return ['mail'];
    }

    public function toMail(object $destinataire): MailMessage
    {
        $message = (new MailMessage)->subject('DOTO : '.$this->objet)->greeting('Bonjour,');
        foreach ($this->lignes as $ligne) {
            $message->line($ligne);
        }
        if ($this->bouton && $this->lien) {
            $message->action($this->bouton, $this->lien);
        }

        return $message->salutation('L’équipe DOTO');
    }
}

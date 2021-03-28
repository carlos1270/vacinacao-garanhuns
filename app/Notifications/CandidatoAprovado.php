<?php

namespace App\Notifications;

use App\Models\Lote;
use App\Models\Candidato;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class CandidatoAprovado extends Notification
{
    use Queueable;

    public $candidato;
    public $data_chegada;
    public $texto;
    public $texto_dose_unica;
    public $texto_dose_dupla;
    public $lote;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Candidato $candidato, Lote $lote)
    {
        $this->candidato = $candidato;
        $this->lote = $lote;
        $this->data_chegada =  date('d/m/Y \à\s  H:i \h', strtotime($this->candidato->chegada));
        $this->texto_dose_unica = "
        Informamos que a sua solicitação de agendamento para vacinação foi aprovada pela Secretaria Municipal de Saúde de Garanhuns - PE.
        A seguir, encontram-se o dia, horário e local de aplicação da primeira e segunda dose para que registre ou imprima:
        ".$this->data_chegada."
        Agradecemos a sua atenção e ficamos à disposição para outros esclarecimentos que sejam necessários!
        Secretaria Municipal de Saúde (Garanhuns - PE)";

        $this->texto_dose_dupla = "
        Informamos que a sua solicitação de agendamento para vacinação foi aprovada pela Secretaria Municipal de Saúde de Garanhuns - PE.
        A seguir, encontram-se o dia, horário e local de aplicação da primeira e segunda dose para que registre ou imprima:
        ".$this->data_chegada."
        Agradecemos a sua atenção e ficamos à disposição para outros esclarecimentos que sejam necessários!
        Secretaria Municipal de Saúde (Garanhuns - PE)";

    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        // dd($this->lote);
        try {
            if(!$this->lote->dose_unica){
                return (new MailMessage)
                            ->from(env('MAIL_USERNAME'), 'Prefeitura Municipal de Garanhuns')
                            ->line('Sr(a) cidadão(ã),')
                            ->line($this->texto_dose_dupla)
                            ->action('Acessar site', url('/'))
                            ->line('Obrigador por utilizar nosso site!');

            }else{
                return (new MailMessage)
                            ->from(env('MAIL_USERNAME'), 'Prefeitura Municipal de Garanhuns')
                            ->line('Sr(a) cidadão(ã),')
                            ->line($this->texto_dose_unica)
                            ->action('Acessar site', url('/'))
                            ->line('Obrigador por utilizar nosso site!');

            }
        } catch (\Throwable $th) {
            return redirect()->route('dashboard')->withErrors([
                "message" => "Houve algum erro, entre em contato com a administração do site.",
            ])->withInput();
        }

    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'message' => 'Condidato de ID:'. $this->candidato->id. ' Aprovado'
        ];
    }

}

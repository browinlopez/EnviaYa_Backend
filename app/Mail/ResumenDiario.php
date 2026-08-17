<?php

namespace App\Mail;

use App\Models\Area;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * El correo con lo que un área tiene pendiente.
 *
 * El ASUNTO lleva lo urgente adelante y contado, porque es lo único que se ve
 * en la bandeja: "SST · 2 asuntos urgentes" se abre; "Resumen diario" no.
 */
class ResumenDiario extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Area $area,
        public array $asuntos,
        public string $urlPanel,
    ) {}

    public function envelope(): Envelope
    {
        $urgentes = count(array_filter($this->asuntos, fn ($a) => $a['urgente']));

        $asunto = $urgentes > 0
            ? "{$this->area->name} · {$urgentes} " . ($urgentes === 1 ? 'asunto urgente' : 'asuntos urgentes')
            : "{$this->area->name} · " . count($this->asuntos) . ' pendiente(s)';

        return new Envelope(subject: $asunto);
    }

    public function content(): Content
    {
        return new Content(view: 'correos.resumen-diario');
    }
}

<?php

namespace App\Helper;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReverbClient
{
    protected $url;
    public function __construct()
    {
        $this->url = env('REVERB_URL', 'http://localhost:8080') . '/message';
    }


    public function sendMessage(array $payload)
    {
        try {
            return Http::post($this->url, $payload)->throw();
        } catch (\Exception $e) {
            Log::error("Error enviando mensaje a Reverb: " . $e->getMessage());
            return false;
        }
    }
}

<?php declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class RecaptchaVerifier
{
    public function __construct(
        private string               $secretKey,
        private ?HttpClientInterface $httpClient = null,
    ) {}

    public function verify(?string $token, ?string $ip = null): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }
        if ($this->secretKey === '') {
            return false;
        }

        $payload = ['secret' => $this->secretKey, 'response' => $token];
        if ($ip) {
            $payload['remoteip'] = $ip;
        }

        try {
            if ($this->httpClient) {
                $response = $this->httpClient->request(
                    'POST',
                    'https://www.google.com/recaptcha/api/siteverify',
                    ['body' => $payload]
                );
                $data = $response->toArray(false);
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($payload),
                        'timeout' => 5,
                    ],
                ]);
                $raw  = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
                $data = json_decode($raw ?: 'null', true);
            }

            return is_array($data) && ($data['success'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }
}


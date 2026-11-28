<?php

namespace App\Http\Controllers;

use App\Models\HockeyMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class PublicMatchController extends Controller
{
    public function __construct(
        private readonly MatchPresenter $presenter,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $match = $this->publicMatch($token);

        $response = Inertia::render('public/matches/Show', $this->presenter->publicProps($match))
            ->withViewData(['openGraph' => $this->openGraph($match)])
            ->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    public function image(string $token): Response
    {
        $match = $this->publicMatch($token);
        $headers = [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300, s-maxage=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (! extension_loaded('gd'))
        {
            return response()->file(public_path('logo.png'), $headers);
        }

        $image = imagecreatetruecolor(1200, 630);
        if ($image === false)
        {
            throw new RuntimeException('Não foi possível criar a imagem do placar.');
        }

        $navy = $this->allocateColor($image, 8, 15, 30);
        $homeColor = $this->color($match->roster->home->color, $image);
        $awayColor = $this->color($match->roster->away->color, $image);
        $white = $this->allocateColor($image, 248, 250, 252);
        $muted = $this->allocateColor($image, 148, 163, 184);
        imagefill($image, 0, 0, $navy);
        imagefilledrectangle($image, 0, 0, 180, 630, $homeColor);
        imagefilledrectangle($image, 1020, 0, 1200, 630, $awayColor);

        imagestring($image, 5, 48, 42, 'HOCKEY GAME', $white);
        $this->centerText($image, $this->safeText($match->roster->home->name), 250, 190, 5, $white);
        $this->centerText($image, $this->safeText($match->roster->away->name), 950, 190, 5, $white);
        $this->centerText($image, $match->home_score.'  x  '.$match->away_score, 600, 280, 5, $white);
        $date = ($match->finished_at ?? $match->started_at)?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? 'Partida salva';
        $this->centerText($image, $this->safeText($date), 600, 375, 4, $muted);

        $scorers = $this->scorers($match);
        if ($scorers !== [])
        {
            imagestring($image, 3, 48, 465, 'ARTILHEIROS', $muted);
            $this->centerText($image, $this->safeText(implode(' · ', $scorers)), 600, 510, 4, $white);
        }

        imagestring($image, 3, 48, 565, 'Placar compartilhável', $muted);
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return response($contents, 200, $headers);
    }

    private function publicMatch(string $token): HockeyMatch
    {
        return HockeyMatch::withoutGlobalScope('team')
            ->where('public_token', $token)
            ->whereNotNull('public_enabled_at')
            ->firstOrFail();
    }

    /** @return array{title: string, description: string, url: string, image: string} */
    private function openGraph(HockeyMatch $match): array
    {
        $date = ($match->finished_at ?? $match->started_at)?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') ?? 'Data não informada';
        $description = 'Partida em '.$date.'.';
        $scorers = $this->scorers($match);
        if ($scorers !== [])
        {
            $description .= ' Gols: '.implode(', ', $scorers).'.';
        }

        return [
            'title' => $match->roster->home->name.' '.$match->home_score.' x '.$match->away_score.' '.$match->roster->away->name,
            'description' => $description,
            'url' => route('public.matches.show', ['token' => $match->public_token]),
            'image' => route('public.matches.image', ['token' => $match->public_token]),
        ];
    }

    /** @return list<string> */
    private function scorers(HockeyMatch $match): array
    {
        $props = $this->presenter->publicProps($match);
        $goals = $props['goals'] ?? [];
        if (! is_array($goals))
        {
            return [];
        }
        $scorers = [];
        foreach ($goals as $goal)
        {
            if (! is_array($goal))
            {
                continue;
            }
            $name = $goal['scorer_name'] ?? null;
            if (is_string($name) && $name !== '' && ! in_array($name, $scorers, true))
            {
                $scorers[] = $name;
            }
            if (count($scorers) === 3)
            {
                break;
            }
        }

        return $scorers;
    }

    private function color(string $hex, \GdImage $image): int
    {
        $hex = preg_match('/^#[0-9a-fA-F]{6}$/', $hex) === 1 ? $hex : '#166534';

        return $this->allocateColor(
            $image,
            $this->colorChannel((int) hexdec(substr($hex, 1, 2))),
            $this->colorChannel((int) hexdec(substr($hex, 3, 2))),
            $this->colorChannel((int) hexdec(substr($hex, 5, 2))),
        );
    }

    /** @return int<0, 255> */
    private function colorChannel(int $channel): int
    {
        return max(0, min(255, $channel));
    }

    /** @param int<0, 255> $red
     * @param  int<0, 255>  $green
     * @param  int<0, 255>  $blue
     */
    private function allocateColor(\GdImage $image, int $red, int $green, int $blue): int
    {
        $color = imagecolorallocate($image, $red, $green, $blue);
        if ($color === false)
        {
            throw new RuntimeException('Não foi possível compor a imagem do placar.');
        }

        return $color;
    }

    private function centerText(\GdImage $image, string $text, int $center, int $y, int $font, int $color): void
    {
        $x = max(20, $center - intdiv(strlen($text) * imagefontwidth($font), 2));
        imagestring($image, $font, $x, $y, $text, $color);
    }

    private function safeText(string $value): string
    {
        return substr(Str::ascii($value), 0, 52);
    }
}

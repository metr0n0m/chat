<?php
declare(strict_types=1);

namespace Chat\Chat;

class EmbedProcessor
{
    private const TIMEOUT      = 3;
    private const MAX_SIZE     = 5 * 1024 * 1024;
    private const IMAGE_EXTS   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const IMAGE_MIMES  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public static function process(string $text): ?array
    {
        if (!preg_match('~https?://\S+~i', $text, $m)) {
            return null;
        }
        $url = $m[0];

        if ($yt = self::youtube($url)) {
            return $yt;
        }
        if (self::isImageUrl($url)) {
            return self::processImage($url);
        }
        return self::openGraph($url);
    }

    private static function youtube(string $url): ?array
    {
        $patterns = [
            '~youtube\.com/watch\?(?:.*&)?v=([a-zA-Z0-9_-]{11})~',
            '~youtu\.be/([a-zA-Z0-9_-]{11})~',
            '~youtube\.com/embed/([a-zA-Z0-9_-]{11})~',
            '~youtube\.com/shorts/([a-zA-Z0-9_-]{11})~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                $videoId  = $m[1];
                $thumb    = 'https://img.youtube.com/vi/' . $videoId . '/hqdefault.jpg';
                $watchUrl = 'https://www.youtube.com/watch?v=' . $videoId;
                return [
                    'type'      => 'youtube',
                    'video_id'  => $videoId,
                    'thumb_url' => htmlspecialchars($thumb, ENT_QUOTES),
                    'url'       => htmlspecialchars($watchUrl, ENT_QUOTES),
                    'html'      => self::youtubeHtml($videoId, $thumb, $watchUrl),
                ];
            }
        }
        return null;
    }

    private static function youtubeHtml(string $id, string $thumb, string $url): string
    {
        $safeThumb = htmlspecialchars($thumb, ENT_QUOTES);
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES);
        return '<div class="embed-yt"><a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">'
             . '<img src="' . $safeThumb . '" alt="YouTube Video" class="embed-yt-thumb">'
             . '<span class="embed-yt-play"><i class="fa fa-play-circle"></i></span>'
             . '</a></div>';
    }

    private static function isImageUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTS, true);
    }

    private static function processImage(string $url): ?array
    {
        $headers = self::headRequest($url);
        if (!$headers) {
            return null;
        }
        $contentType = $headers['content-type'] ?? '';
        $size        = (int) ($headers['content-length'] ?? 0);

        $mime = strtolower(explode(';', $contentType)[0]);
        if (!in_array($mime, self::IMAGE_MIMES, true)) {
            return null;
        }
        if ($size > self::MAX_SIZE) {
            return null;
        }

        $safeUrl = htmlspecialchars($url, ENT_QUOTES);
        return [
            'type' => 'image',
            'url'  => $safeUrl,
            'html' => '<a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">'
                    . '<img src="' . $safeUrl . '" class="embed-image" referrerpolicy="no-referrer" alt="Image"></a>',
        ];
    }

    private static function openGraph(string $url): ?array
    {
        $html = self::fetchHtml($url);
        if (!$html) {
            return null;
        }

        $meta = self::parseOG($html);
        if (empty($meta['title'])) {
            return null;
        }

        $title       = htmlspecialchars(substr($meta['title'], 0, 200), ENT_QUOTES);
        $description = htmlspecialchars(substr($meta['description'] ?? '', 0, 500), ENT_QUOTES);
        $image       = !empty($meta['image']) ? htmlspecialchars($meta['image'], ENT_QUOTES) : null;
        $safeUrl     = htmlspecialchars($url, ENT_QUOTES);

        $imgHtml = $image
            ? '<img src="' . $image . '" class="embed-og-image" referrerpolicy="no-referrer" alt="">'
            : '';

        return [
            'type'        => 'opengraph',
            'title'       => $title,
            'description' => $description,
            'image'       => $image,
            'url'         => $safeUrl,
            'html'        => '<div class="embed-og"><a href="' . $safeUrl . '" target="_blank" rel="noopener noreferrer">'
                           . $imgHtml
                           . '<div class="embed-og-body">'
                           . '<div class="embed-og-title">' . $title . '</div>'
                           . '<div class="embed-og-desc">' . $description . '</div>'
                           . '</div></a></div>',
        ];
    }

    private static function parseOG(string $html): array
    {
        $meta = [];
        preg_match_all('/<meta[^>]+>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            $prop = self::attr($tag, 'property') ?? self::attr($tag, 'name');
            $cont = self::attr($tag, 'content');
            if (!$prop || $cont === null) {
                continue;
            }
            match (strtolower($prop)) {
                'og:title'       => $meta['title']       = $cont,
                'og:description' => $meta['description'] = $cont,
                'og:image'       => $meta['image']       = $cont,
                'description'    => $meta['description'] ??= $cont,
                default          => null,
            };
        }
        if (empty($meta['title'])) {
            if (preg_match('~<title[^>]*>([^<]+)</title>~i', $html, $m)) {
                $meta['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            }
        }
        if (!empty($meta['image']) && !filter_var($meta['image'], FILTER_VALIDATE_URL)) {
            unset($meta['image']);
        }
        return $meta;
    }

    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('/' . $name . '\s*=\s*["\']([^"\']*)["\']/', $tag, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        return null;
    }

    private static function headRequest(string $url): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'HEAD',
            'timeout'         => self::TIMEOUT,
            'follow_location' => true,
            'max_redirects'   => 3,
        ]]);
        @file_get_contents($url, false, $ctx);
        if (empty($http_response_header)) {
            return null;
        }
        $headers = [];
        foreach ($http_response_header as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }

    private static function fetchHtml(string $url): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => self::TIMEOUT,
            'follow_location' => true,
            'max_redirects'   => 3,
            'header'          => 'Accept: text/html',
        ]]);
        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            return null;
        }
        return substr($html, 0, 50000);
    }
}

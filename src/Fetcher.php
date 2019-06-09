<?php
namespace FullFeed;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . "/../lib/FileSystemCache/lib/FileSystemCache.php";

use PhpAnsiColor\Color;

define("USER_AGENT", "'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36'");

class Fetcher
{
    public static function fetch($url)
    {

        $link_url_parts = parse_url($url);
        $site = $link_url_parts['host'];

        $blacklist = file(__DIR__ . '/../blacklist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (in_array($site, $blacklist)) {
            error_log("Fetching: skipped ($site is blacklisted)");
            return "Full content is not available";
        }

        if (preg_match('/(pdf|jpg|png|gif|webm|mp4|mp3|mov)$/', $url)) {
            error_log("Fetching: skipped (binary)");
            return "";
        }

        $key_group = 'html/' . substr(str_replace('www.', '', $site), 0, 2);
        $key = \FileSystemCache::generateCacheKey($url, $key_group);
        if (false === ($html = \FileSystemCache::retrieve($key))) {
            $client = new \GuzzleHttp\Client(['cookies' => true]);
            $response = $client->request('GET', $url, [
                'headers' => [
                    'User-Agent' => USER_AGENT,
                ],
            ]);
            $code = $response->getStatusCode();
            if ($code >= 200 && $code < 300) {
                error_log("Fetching: success");
                $html = (string) $response->getBody();
                \FileSystemCache::store($key, $html);
                return $html;
            } else {
                error_log(Color::set("Fetching: error (HTTP $code)", "red"));
                return "";
            }
        } else {
            error_log(Color::set("Fetching: cached ($key)", "cyan"));
            return $html;
        }
        return "";
    }
}

<?php
namespace FullFeed;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/fivefilters-php-readability/Readability.php';

use Opengraph;
use PhpAnsiColor\Color;
use Sunra\PhpSimple\HtmlDomParser;

const ARTICLE_MAXSIZE = 1024000;

class Parser
{

    public static function extractFromCommonSite($url, $strHtml)
    {

        $commonSites = json_decode(file_get_contents(__DIR__ . '/../common-sites.json'), true);

        foreach ($commonSites as $commonSite) {
            $isCommonSite = preg_match($commonSite['pattern'], $url);
            if ($isCommonSite) {
                $dom = HtmlDomParser::str_get_html($strHtml);
                $domNode = $dom->find($commonSite['path']);
                if (count($domNode)) {
                    error_log(Color::set("Common site extractor", "cyan"));
                    return (string) $domNode[0];
                }
            }
        }

        return false;
    }

    public static function parse($url, $strHtml)
    {

        if (ARTICLE_MAXSIZE < strlen($strHtml)) {
            throw new \Exception("Cannot parse (HTML is too large)");
        }

        // Convert plain text to HTML
        if (preg_match('/\.txt$/', $url)) {
            return "<pre>" . htmlentities($strHtml, ENT_QUOTES, "UTF-8") . "</pre>";
        }

        $out = $strHtml;

        //check common site
        $commonSiteExtraction = Parser::extractFromCommonSite($url, $strHtml);
        if ($commonSiteExtraction !== false) {
            $out = $commonSiteExtraction;
        }

        // Purify
        $preConfig = \HTMLPurifier_Config::createDefault();
        $preConfig->set('HTML.TidyLevel', 'heavy');
        $preConfig->set('HTML.ForbiddenElements', array('style', 'script', 'link'));
        $preConfig->set('URI.Base', $url);
        $preConfig->set('URI.DefaultScheme', 'https');
        $preConfig->set('URI.MakeAbsolute', true);
        $prePurifier = new \HTMLPurifier($preConfig);
        $out = $prePurifier->purify($out);

        $readability = new \Readability($out, $url);
        $result = $readability->init();
        if ($result) {
            error_log("Extracting: readability success");
            $out = $readability->getContent()->innerHTML;
        } else {
            // error_log("Extracting: readability error");
            error_log(Color::set("Extracting: readability error", "red"));
            $out = '';
        }

        // Reformat bad HTML from Readability
        $postConfig = \HTMLPurifier_Config::createDefault();
        $postConfig->set('HTML.TidyLevel', 'none');
        $postPurifier = new \HTMLPurifier($postConfig);
        $out = $postPurifier->purify($out);

        return $out;
    }

    public static function extractImage($strHtml) {
        $reader = new Opengraph\Reader();
        $image = "";
        try {
            $reader->parse($strHtml);
            $og = $reader->getArrayCopy();
            if (array_key_exists('og:image', $og) && is_array($og['og:image']) && count($og['og:image']) > 0) {
                $image = $og['og:image'][0]["og:image:url"];
            }
        } catch (\RuntimeException $e) {
            error_log($e->getMessage());
        }
        return $image;
    }
}

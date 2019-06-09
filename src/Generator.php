<?php
namespace FullFeed;

class Generator
{
    public static function renderTemplateWithArticles($template, $articles, $options = [])
    {
        $mustache = new \Mustache_Engine(array(
            'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/templates'),
            'partials_loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/templates/partials'),
        ));
        $tpl = $mustache->loadTemplate($template);
        $out = $tpl->render([
            'title' => 'Hacker News',
            'lastupdate' => date('r'),
            "articles" => $articles
        ]);

        if ($options["gzip"]) {
            $out = gzencode($out);
        }

        return $out;
    }
    
    function generateManifest($path, $version) {
        $cachedfiles = array();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $filename) {
            $basename = basename($filename);
            if (preg_match("/^\./", $basename) || $basename === 'cache.manifest' || $basename === 'latest.html') {
                continue;
            }
            $cachedfiles[] = str_replace($path, "", $filename);
        }

        $mustache = new \Mustache_Engine(array(
            'loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/templates'),
            'partials_loader' => new \Mustache_Loader_FilesystemLoader(__DIR__ . '/templates/partials'),
        ));
        $tpl = $mustache->loadTemplate('fullhn.manifest');
        $out = $tpl->render([
            'version' => $version,
            'cachedfiles' => implode("\n", $cachedfiles),
        ]);
        return $out;
    }
}

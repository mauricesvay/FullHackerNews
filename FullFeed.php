<?php
include dirname(__FILE__)."/lib/SimplePie.compiled.php";
include dirname(__FILE__)."/lib/FileSystemCache/lib/FileSystemCache.php";
include dirname(__FILE__)."/lib/htmlpurifier-4.5.0/library/HTMLPurifier.auto.php";
include dirname(__FILE__).'/lib/fivefilters-php-readability/Readability.php';
include dirname(__FILE__).'/lib/url_to_absolute.php';
include dirname(__FILE__).'/lib/simple_html_dom.php';
include dirname(__FILE__)."/lib/amazon-s3-php-class/S3.php";
include dirname(__FILE__)."/lib/mustache.php/src/Mustache/Autoloader.php";

use Guzzle\Http\Client;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;

class FullFeed {

    private $outputFile;
    private $cacheFile;

    private $enable_gzip;

    private $purifier;
    private $readability;
    private $feed;
    private $articles;
    private $mustache;
    private $cookiePlugin;

    const ARTICLE_MAXSIZE = 100000;

    public function __construct($feedUrl, $enable_gzip) {
        $this->outputFile = dirname(__FILE__).'/www/index.html';
        $this->cacheFile = dirname(__FILE__).'/www/cache.manifest';

        $this->enable_gzip = $enable_gzip;

        //Feed
        $this->feed = new SimplePie();
        $this->feed->set_cache_duration(600);
        $this->feed->set_cache_location(dirname(__FILE__).'/cache');
        $this->feed->set_feed_url($feedUrl);

        //HTML Purifier
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.TidyLevel', 'heavy');
        $config->set('HTML.ForbiddenElements', array('style','script','link'));
        $this->purifier = new HTMLPurifier($config);

        //Mustache
        Mustache_Autoloader::register();
        $this->mustache = new Mustache_Engine(array(
            'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__).'/views'),
            'partials_loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__).'/views/partials')
        ));

        $this->cookiePlugin = new CookiePlugin(new ArrayCookieJar());
    }

    private function fetch($url) {
        $new = false;

        if (false !== strpos($url, '&amp;')) {
            $url = html_entity_decode($url);
        }

        $link_url_parts = parse_url($url);
        $site = $link_url_parts['host'];

        //Download content
        $key_group = "html/" . substr($site, 0, 2);
        $key = FileSystemCache::generateCacheKey($url, $key_group);

        if (preg_match('/(pdf|jpg|png|gif)$/', $url)) {
            //Do not download PDF or images
            $html = "";
        } else {
            if (false === ($html = FileSystemCache::retrieve($key))) {

                try {
                    $client = new Client($url);
                    // $client->setUserAgent('Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/29.0.1547.62 Safari/537.36');
                    $client->addSubscriber($this->cookiePlugin);
                    $response = $client->get()->send();
                    $html = (string) $response->getBody();
                } catch (Exception $e) {
                    $error = $e->getMessage();
                    file_put_contents('php://stderr', "Download failed: " . $url . "\n");
                    file_put_contents('php://stderr', $error . "\n");
                    $html = "";
                }

                if ($html) {
                    FileSystemCache::store($key, $html);
                    $new = true;
                }
            }
        }

        return array(
            'html' => $html,
            'new' => $new
        );
    }

    public function update() {
        if (!$this->feed->init()) {
            echo "Error fetching feed : ",$this->feed->error , "\n";
            exit;
        }

        $new = 0;
        $i = 0;
        foreach ($this->feed->get_items() as $item) {
            $url = $item->get_permalink();
            $link = $item->get_permalink();
            $link_url_parts = parse_url($link);
            $site = $link_url_parts['host'];
            $title = $item->get_title();
            $content = "";
            $html = "";

            $html = $this->fetch($url);
            $new += (int) $html['new'];
            $html = $html['html'];

            //Limit content size to be readability-fied
            if (FullFeed::ARTICLE_MAXSIZE < strlen($html)) {
                continue;
            }

            //Extract content
            //@TODO : implement oEmbed for tweets?
            $key_group = "articles/" . substr($site, 0, 2);
            $key = FileSystemCache::generateCacheKey($url, $key_group);

            if (false === ($content = FileSystemCache::retrieve($key))) {

                if (preg_match('/\.txt$/', $url)) {
                    //Content is a text file
                    $content = "<pre>" . $html . "</pre>";
                } else {
                    //Consider other content as HTML
                    $this->readability = new Readability($html, $url);
                    $result = $this->readability->init();
                    if ($result) {
                        $content = $this->purifier->purify($this->readability->getContent()->innerHTML);
                    } else {
                        $content = '';
                    }

                    //Resolve relative URL for images
                    $html = str_get_html($content, /*$lowercase=*/true, /*$forceTagsClosed=*/true, /*$target_charset = */DEFAULT_TARGET_CHARSET, /*$stripRN=*/false); //Preserve white space
                    if ($html) {
                        $imgs = $html->find('img');
                        foreach ($imgs as $img) {
                            if (!preg_match('/^http(s*):\/\//', $img->src)) {
                                $img->src = url_to_absolute($url, $img->src);
                            }
                        }
                        $content = (string) $html;
                    }
                }

                FileSystemCache::store($key, $content);
            }

            // echo " | HTML OK";

            $i++;
            $this->articles[] = array(
                'url' => $url,
                'link' => $link,
                'site' => $site,
                'title' => $title,
                'content' => $content,
                'index' => $i,
                'next' => ($i + 1),
                'prev' => ($i - 1)
            );

            // echo "\n";
        }

        return $new;
    }

    public function generateOutput() {
        $lastupdate = date('r');

        //Generate index.html
        $tpl = $this->mustache->loadTemplate('index');
        $out = $tpl->render(array(
            'title' => $this->feed->get_title(),
            'articles' => $this->articles,
            'lastupdate' => $lastupdate
        ));

        if ($this->enable_gzip) {
            $out = gzencode($out);
        }

        $index_ok = file_put_contents($this->outputFile, $out);

        //Generate cache manifest
        $cachedfiles = array();
        $path = dirname(__FILE__).'/www/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $filename) {
            //exclude "." and ".."
            if (preg_match("/\.{1,2}$/", $filename)) {
                continue;
            }
            //exclude cache manifest file itself
            if ($this->cacheFile == $filename) {
                continue;
            }
            $cachedfiles[] = str_replace($path, "", $filename);
        }
        $tpl = $this->mustache->loadTemplate('fullhn.manifest');
        $out = $tpl->render(array(
            'version' => $lastupdate,
            'cachedfiles' => implode("\n", $cachedfiles)
        ));
        $manifest_ok = file_put_contents($this->cacheFile, $out);

        return $index_ok && $manifest_ok;
    }

    public function upload($awsAccessKey, $awsSecretKey, $awsS3BucketName) {
        $s3 = new S3($awsAccessKey, $awsSecretKey);

        if ($this->enable_gzip) {
            $index_headers = array(
                "Content-Type" => "text/html",
                "Content-Encoding" => "gzip"
            );
        } else {
            $index_headers = array(
                "Content-Type" => "text/html"
            );
        }
        $index_ok = $s3->putObjectFile(
                        $this->outputFile, 
                        $awsS3BucketName, 
                        baseName($this->outputFile), 
                        S3::ACL_PUBLIC_READ,
                        array(),
                        $index_headers
                    );
        $manifest_ok = $s3->putObjectFile(
                        $this->cacheFile, 
                        $awsS3BucketName, 
                        baseName($this->cacheFile), 
                        S3::ACL_PUBLIC_READ,
                        array(),
                        array(
                            "Content-Type" => "text/cache-manifest"
                        )
                    );
        return $index_ok && $manifest_ok;
    }
}
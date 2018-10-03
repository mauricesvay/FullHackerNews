<?php
include __DIR__ . "/lib/SimplePie.compiled.php";
include __DIR__ . "/lib/FileSystemCache/lib/FileSystemCache.php";
include __DIR__ . "/lib/htmlpurifier-4.5.0/library/HTMLPurifier.auto.php";
include __DIR__ . '/lib/fivefilters-php-readability/Readability.php';
include __DIR__ . '/lib/url_to_absolute.php';
include __DIR__ . '/lib/simple_html_dom.php';
include __DIR__ . "/lib/amazon-s3-php-class/S3.php";
include __DIR__ . "/lib/mustache.php/src/Mustache/Autoloader.php";

use Guzzle\Http\Client;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;
use Guzzle\Plugin\Cookie\CookiePlugin;

class FullFeed
{

    private $outputFile;
    private $latestFile;
    private $cacheFile;

    private $enable_gzip;

    private $purifier;
    private $readability;
    private $feed;
    private $articles;
    private $mustache;
    private $cookiePlugin;

    private $blacklist;

    const ARTICLE_MAXSIZE = 1024000;

    public function __construct($feedUrl, $enable_gzip)
    {
        $this->outputFile = __DIR__ . '/www/index.html';
        $this->latestFile = __DIR__ . '/www/latest.html';
        $this->cacheFile = __DIR__ . '/www/cache.manifest';

        $this->enable_gzip = $enable_gzip;

        //Feed
        $this->feed = new SimplePie();
        $this->feed->set_cache_duration(600);
        $this->feed->set_cache_location(__DIR__ . '/cache');
        $this->feed->set_feed_url($feedUrl);

        //HTML Purifier
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.TidyLevel', 'heavy');
        $config->set('HTML.ForbiddenElements', array('style', 'script', 'link'));
        $this->purifier = new HTMLPurifier($config);

        //Mustache
        Mustache_Autoloader::register();
        $this->mustache = new Mustache_Engine(array(
            'loader' => new Mustache_Loader_FilesystemLoader(__DIR__ . '/views'),
            'partials_loader' => new Mustache_Loader_FilesystemLoader(__DIR__ . '/views/partials'),
        ));

        $this->blacklist = file(__DIR__ . '/blacklist.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->commonSites = json_decode(file_get_contents(__DIR__ . '/common-sites.json'), true);

        $this->cookiePlugin = new CookiePlugin(new ArrayCookieJar());
    }

    private function fetch($url, $options = array())
    {
        $defaults = array(
            'useragent' => '',
        );
        $options = array_merge($defaults, $options);
        $new = false;

        if (false !== strpos($url, '&amp;')) {
            $url = html_entity_decode($url);
        }

        $link_url_parts = parse_url($url);
        $site = $link_url_parts['host'];

        //Don't fetch blacklisted domains
        if (in_array($site, $this->blacklist)) {
            error_log("$site is blacklisted");
            return array(
                'html' => "Full article is not available",
                'new' => $new,
            );
        }

        //Download content
        $key_group = "html/" . substr($site, 0, 2);
        $key = FileSystemCache::generateCacheKey($url, $key_group);

        if (preg_match('/(pdf|jpg|png|gif|webm|mp4|mp3|mov)$/', $url)) {
            //Do not download PDF or images
            echo "fetching skipped (binary)\n";
            $html = "";
        } else {
            if (false === ($html = FileSystemCache::retrieve($key))) {

                if ($options['useragent'] !== '') {
                    echo "fetching with user agent\n";
                } else {
                    echo "fetching\n";
                }

                try {
                    $client = new Client($url);
                    if ($options['useragent']) {
                        $client->setUserAgent($options['useragent']);
                    }
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
            } else {
                echo "fetching skipped (cached)\n";
            }
        }

        return array(
            'html' => $html,
            'new' => $new,
        );
    }

    protected function relativeImagesToAbsolute($url, $content)
    {
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

        return $content;
    }

    protected function extractContent($url, $html)
    {

        $content = '';

        if ($html === '') {
            echo "extracting skipped (empty)\n";
            return $html;
        }

        // Plain text
        if (preg_match('/\.txt$/', $url)) {
            //Content is a text file
            echo "extracting (txt)\n";
            return "<pre>" . htmlentities($html, ENT_QUOTES, "UTF-8") . "</pre>";
        }

        // Try with common sites list
        foreach ($this->commonSites as $commonSite) {
            $isCommonSite = preg_match($commonSite['pattern'], $url);
            if ($isCommonSite) {
                $dom = str_get_html($html);
                $domNode = $dom->find($commonSite['path']);
                if (count($domNode)) {
                    echo "extracting (common site)\n";
                    $content = (string) $domNode[0];
                    break;
                }
            }
        }

        // Try with Readability
        if ($content === '') {
            $this->readability = new Readability($html, $url);
            $result = $this->readability->init();
            if ($result) {
                echo "extracting (readability)\n";
                $content = $this->readability->getContent()->innerHTML;
            } else {
                echo "Cannot extract content\n";
                $content = '';
            }
        }

        // Cleanup content
        if ($content !== '') {
            $content = $this->relativeImagesToAbsolute($url, $content);
            $content = $this->purifier->purify($content);
        }

        return $content;
    }

    public function update()
    {
        if (!$this->feed->init()) {
            echo "Error fetching feed : ", $this->feed->error, "\n";
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

            $comments = "";
            $description = str_get_html($item->get_description());
            if ($description) {
                $comment_link = $description->find('a');
            }
            if (count($comment_link)) {
                $comments = $comment_link[0]->href;
            }

            echo "-----------------------------------------------------------\n";
            echo "Processing $url\n";

            $content = "";
            $html = "";

            $html = $this->fetch($url);
            $new += (int) $html['new'];
            $html = $html['html'];

            //No luck ? Try as Google Chrome
            if (!$html) {
                $chromeUserAgentString = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/69.0.3497.100 Safari/537.36';
                $html = $this->fetch(
                    $url,
                    array(
                        'useragent' => $chromeUserAgentString,
                    )
                );
                $new += (int) $html['new'];
                $html = $html['html'];
            }

            //Limit content size to be readability-fied
            if (FullFeed::ARTICLE_MAXSIZE < strlen($html)) {
                echo "extracting skipped (filesizetoo large)\n";
                continue;
            }

            //Extract content
            //@TODO : implement oEmbed for tweets?
            $key_group = "articles/" . substr($site, 0, 2);
            $key = FileSystemCache::generateCacheKey($url, $key_group);

            if (false === ($content = FileSystemCache::retrieve($key))) {
                $content = $this->extractContent($url, $html);
                FileSystemCache::store($key, $content);
            } else {
                echo "extracting skipped (cached)\n";
            }

            $i++;
            $this->articles[] = array(
                'url' => $url,
                'link' => $link,
                'site' => $site,
                'title' => $title,
                'content' => $content,
                'comments' => $comments,
                'index' => $i,
                'next' => ($i + 1),
                'prev' => ($i - 1),
            );
        }

        return $new;
    }

    public function generateOutput()
    {
        $lastupdate = date('r');

        //Generate index.html
        $tpl = $this->mustache->loadTemplate('index');
        $out = $tpl->render(array(
            'title' => $this->feed->get_title(),
            'articles' => $this->articles,
            'lastupdate' => $lastupdate,
        ));

        if ($this->enable_gzip) {
            $out = gzencode($out);
        }

        $index_ok = file_put_contents($this->outputFile, $out);

        //Generate latest.html
        $tpl = $this->mustache->loadTemplate('latest');
        $out = $tpl->render(array(
            'title' => $this->feed->get_title(),
            'articles' => $this->articles,
            'lastupdate' => $lastupdate,
        ));

        if ($this->enable_gzip) {
            $out = gzencode($out);
        }

        $latest_ok = file_put_contents($this->latestFile, $out);

        //Generate cache manifest
        $cachedfiles = array();
        $path = __DIR__ . '/www/';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $filename) {
            //exclude dotfiles
            if (preg_match("/^\./", basename($filename))) {
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
            'cachedfiles' => implode("\n", $cachedfiles),
        ));
        $manifest_ok = file_put_contents($this->cacheFile, $out);

        return $index_ok && $manifest_ok;
    }

    public function upload($awsAccessKey, $awsSecretKey, $awsS3BucketName)
    {
        $s3 = new S3($awsAccessKey, $awsSecretKey);

        if ($this->enable_gzip) {
            $index_headers = array(
                "Content-Type" => "text/html",
                "Content-Encoding" => "gzip",
            );
        } else {
            $index_headers = array(
                "Content-Type" => "text/html",
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
        $latest_ok = $s3->putObjectFile(
            $this->latestFile,
            $awsS3BucketName,
            baseName($this->latestFile),
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
                "Content-Type" => "text/cache-manifest",
            )
        );
        return $index_ok && $manifest_ok;
    }
}

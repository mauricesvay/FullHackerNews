<?php
include dirname(__FILE__)."/lib/SimplePie.compiled.php";
include dirname(__FILE__)."/lib/FileSystemCache/lib/FileSystemCache.php";
include dirname(__FILE__)."/lib/htmlpurifier-4.5.0/library/HTMLPurifier.auto.php";
include dirname(__FILE__).'/lib/fivefilters-php-readability/Readability.php';
include dirname(__FILE__).'/lib/url_to_absolute.php';
include dirname(__FILE__).'/lib/simple_html_dom.php';
include dirname(__FILE__).'/lib/minify-2.1.5/min/lib/Minify/HTML.php';
include dirname(__FILE__)."/lib/amazon-s3-php-class/S3.php";
include dirname(__FILE__)."/lib/mustache.php/src/Mustache/Autoloader.php";

class FullFeed {

    private $purifier;
    private $readability;
    private $feed;
    private $articles;
    private $mustache;
    private $outputFile;

    public function __construct($feedUrl) {
        $this->outputFile = dirname(__FILE__).'/www/index.html';

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
            'loader' => new Mustache_Loader_FilesystemLoader(dirname(__FILE__).'/views')
        ));
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

            //Download content
            $key_group = "html/" . substr($site, 0, 2);
            $key = FileSystemCache::generateCacheKey($url, $key_group);

            if (preg_match('/(pdf|jpg|png|gif)$/', $url)) {
                //Do not download PDF or images
            } else {
                if (false === ($html = FileSystemCache::retrieve($key))) {
                    $html = file_get_contents($url);
                    FileSystemCache::store($key, $html);
                    $new++;
                }
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
        }

        return $new;
    }

    public function generateOutput() {
        $tpl = $this->mustache->loadTemplate('index');
        $out = $tpl->render(array(
            'title' => $this->feed->get_title(),
            'articles' => $this->articles,
            'lastupdate' => date('r')
        ));
        
        //Minify HTML
        // $out = Minify_HTML::minify($out);

        return file_put_contents($this->outputFile, $out);
    }

    public function upload($awsAccessKey, $awsSecretKey, $awsS3BucketName) {
        $s3 = new S3($awsAccessKey, $awsSecretKey);
        if ($s3->putObjectFile($this->outputFile, $awsS3BucketName, baseName($this->outputFile), S3::ACL_PUBLIC_READ)) {
            return true;
        }
        return false;
    }
}
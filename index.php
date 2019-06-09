<?php
date_default_timezone_set('UTC');
require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::create(__DIR__);
$dotenv->load();

require_once __DIR__ . "/src/Fetcher.php";
require_once __DIR__ . "/src/Parser.php";
require_once __DIR__ . "/src/Generator.php";
require_once __DIR__ . "/src/Uploader.php";
require_once __DIR__ . "/lib/ansi-color.php";

use PhpAnsiColor\Color;

$out_folder = __DIR__ . '/www';

$feed = new SimplePie();
$feed->set_cache_duration(600);
$feed->set_cache_location(__DIR__ . '/cache');
$feed->set_feed_url(getenv('FEED_URL'));
$feed->init();

$articles = [];

foreach ($feed->get_items() as $i => $item) {
    $parsed_url = parse_url($item->get_permalink());
    $comment_tags = $item->get_item_tags('', 'comments');
    $articles[] = [
        'index' => $i,
        'url' => $item->get_permalink(),
        'domain' => $parsed_url['host'],
        'title' => $item->get_title(),
        'comments' => count($comment_tags) ? $comment_tags[0]['data'] : '',
    ];
}

foreach ($articles as $i => $article) {
    error_log("================================================================================");
    error_log(Color::set($article['url'], "yellow"));
    error_log("title: " . $articles[$i]['title'] . " (" . $articles[$i]['domain'] . ")");
    try {
        $articles[$i]['content'] = FullFeed\Fetcher::fetch($article['url']);
        $articles[$i]['parsed'] = FullFeed\Parser::parse($article['url'], $articles[$i]['content']);
        $articles[$i]['image'] = FullFeed\Parser::extractImage($articles[$i]['content']);
    } catch (Exception $e) {
        error_log(Color::set($e->getMessage(), "red"));
        $articles[$i]['content'] = "";
        $articles[$i]['parsed'] = "";
        $articles[$i]['image'] = "";
    }
    error_log("comments: " . $articles[$i]['comments']);
    error_log("image: " . $articles[$i]['image']);
    error_log("content: " . strlen($articles[$i]['content']));
    error_log("parsed: " . strlen($articles[$i]['parsed']));
}

error_log("================================================================================");
error_log(Color::set("Uploading to S3", "yellow"));
$out_index = FullFeed\Generator::renderTemplateWithArticles('index', $articles);
file_put_contents($out_folder . '/index.html', $out_index);
$out_latest = FullFeed\Generator::renderTemplateWithArticles('latest', $articles);
file_put_contents($out_folder . '/latest.html', $out_latest);
$manifest = FullFeed\Generator::generateManifest($out_folder, date('r'));
file_put_contents($out_folder . '/cache.manifest', $manifest);

FullFeed\Uploader::upload($out_folder);
# FullHackerNews

Read all Hacker News articles in one single static page, optimized for reading.
I use it to load all articles for offline reading on my iPhone.

Can work with any other feed.

# Requirement
* PHP >= 5.3.15
* Amazon S3 account

# Installing

* Make the `cache` folder writable
* Create an S3 bucket, configured as a Web server
* upload the content of `www` to the S3 bucket
* copy `config-dist.php` to `config.php` and update the values
* install dependencies : `$ php composer.phar install`
* run `php cron.php` periodically
* enjoy

# License

This project is released under the BSD license.

It includes code from :

* https://github.com/tpyo/amazon-s3-php-class : BSD license
* https://github.com/jdorn/FileSystemCache : LGPL license
* http://code.fivefilters.org/php-readability/ : Apache license
* http://sourceforge.net/projects/simplehtmldom/ : MIT license
* http://sourceforge.net/projects/absoluteurl/ : BSD license
